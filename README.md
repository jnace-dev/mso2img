# Mso2Img — Office 文档批量转图片（PHP）

把 `doc / docx / xls / xlsx / ppt / pptx`（以及 `rtf / csv / odt / ods / odp / wps / et / dps` 等）
批量转换为**每页一张图片**，并打包成 ZIP。

```
Office 文档 --(LibreOffice headless)--> PDF --(pdftoppm / pdftocairo / ImageMagick / Ghostscript)--> PNG --(可选 GD)--> JPG
```

设计原则：**不绑定任何单一环境**。两段引擎都可自动探测、可显式指定、可按项目实际情况换后端；
缺依赖时不静默失败，而是给出精确到命令行的安装指引。

---

## 1. 依赖

| 环节 | 依赖 | 说明 |
|---|---|---|
| 文档 → PDF | **LibreOffice**（`soffice`） | 必装，跨平台，格式覆盖面最广 |
| PDF → 图片 | **Poppler**（`pdftoppm`）或 **Ghostscript**（`gswin64c`）或 **ImageMagick**（`magick`） | 四选一即可，程序会自动探测并按优先级尝试 |
| ZIP 打包 | `ext-zip`（可选） | 未安装时自动改用内置纯 PHP 打包器，功能不打折 |
| JPG 输出 | `ext-gd`（可选） | 未安装时 JPG 自动退回 PNG |

### 安装

**Windows**

```powershell
winget install --id TheDocumentFoundation.LibreOffice -e
winget install --id oschwartz10612.Poppler -e
```

**Linux**

```bash
apt-get install -y libreoffice poppler-utils     # Debian / Ubuntu
yum install -y libreoffice poppler-utils         # CentOS / RHEL
```

**macOS**

```bash
brew install --cask libreoffice
brew install poppler
```

> 服务器环境若没有桌面环境，用 `libreoffice` 的无头模式即可，不需要 X11。
> 容器方案见文末「Docker」。

安装完先自检：

```bash
php bin/mso2img.php doctor
# 或浏览器访问 http://localhost/mso2img/tools/doctor.php
```

---

## 2. 命令行用法

```bash
# 单文件
php bin/mso2img.php convert ./季度报表.docx -o D:/out

# 整个目录（递归，支持全部受支持扩展名）
php bin/mso2img.php convert D:/docs -o D:/out

# 指定 ZIP 路径，图片与 PDF 放在同级目录
php bin/mso2img.php convert ./slides.pptx -o D:/out/demo.zip

# 输出 JPG、200 DPI
php bin/mso2img.php convert D:/docs -o D:/out -f jpg -d 200

# 只出 PDF 不出图
php bin/mso2img.php convert D:/docs -o D:/out --pdf-only
php bin/mso2img.php convert ./季度报表.docx -o D:/out --pdf-only

# 强制指定引擎与后端
php bin/mso2img.php convert ./a.docx -o D:/out --soffice "C:\Program Files\LibreOffice\program\soffice.com" --rasterizer pdftoppm
```

### 全部选项

| 选项 | 说明 |
|---|---|
| `-o, --out <目录\|ZIP>` | 输出位置，默认 `<源文件同级>/mso2img_out` |
| `-z, --zip <ZIP路径>` | 指定 ZIP 输出路径 |
| `-f, --format png\|jpg` | 图片格式，默认 `png` |
| `-d, --dpi <数值>` | 渲染 DPI，默认 `150`（50–600） |
| `--keep-pdf` / `--no-keep-pdf` | 是否保留中间 PDF，默认保留 |
| `--no-zip` | 不打包 ZIP |
| `--pdf-only` | 只转换出 PDF |
| `--timeout <秒>` | 单条外部命令超时，默认 `180` |
| `--soffice <路径>` | 指定 soffice 可执行文件 |
| `--rasterizer <名称>` | `auto\|pdftoppm\|pdftocairo\|magick\|gs`，默认 `auto` |
| `--filter-options <s>` | LibreOffice PDF 过滤器参数（JSON） |
| `--keep-work` | 保留临时工作目录（排障用） |
| `--config <文件>` | 载入 PHP 配置文件（返回数组） |
| `-q, --quiet` | 精简输出 |

### 输出样例

```
D:/out/
├── 季度报表_p001.png      每页一张
├── 季度报表_p002.png
├── 季度报表.pdf           中间 PDF（校对用）
└── 季度报表.zip           打包：全部图片 + PDF
```

---

## 3. 代码调用

```php
require_once __DIR__ . '/src/Support/Process.php';
require_once __DIR__ . '/src/Support/BinaryFinder.php';
require_once __DIR__ . '/src/Support/PdfInfo.php';
require_once __DIR__ . '/src/Support/ZipWriter.php';
require_once __DIR__ . '/src/Mso2Img.php';

use Mso2Img\Mso2Img;

$converter = new Mso2Img(array(
    'dpi'     => 150,
    'format'  => 'png',
    'timeout' => 180,
));

$result = $converter->convert('D:/docs/季度报表.docx', 'D:/out');

if ($result['ok']) {
    echo "共 {$result['pageCount']} 页\n";
    foreach ($result['pages'] as $page) {
        echo "  {$page['name']}  ({$page['size']} bytes)\n";
    }
    echo "PDF: {$result['pdf']}\n";
    echo "ZIP: {$result['zip']}\n";
} else {
    echo "失败: {$result['error']}\n";
}
```

批量：

```php
$results = $converter->convertBatch('D:/docs', 'D:/out');
```

### 返回结构

```php
array(
    'ok'        => true,
    'input'     => 'D:/docs/季度报表.docx',
    'type'      => 'docx',
    'outputDir' => 'D:/out',
    'pdf'       => 'D:/out/季度报表.pdf',
    'zip'       => 'D:/out/季度报表.zip',
    'pages'     => array(
        array('index' => 1, 'name' => '季度报表_p001.png', 'path' => '...', 'size' => 102400),
    ),
    'pageCount' => 2,
    'messages'  => array('工作目录: ...', 'PDF 页数: 2', ...),
    'commands'  => array('实际执行过的命令行，便于排障'),
    'error'     => null,
    'duration'  => 3.21,
);
```

### 配置项

| 键 | 默认 | 说明 |
|---|---|---|
| `soffice` | `null` | soffice 路径，`null` 为自动探测 |
| `rasterizer` | `auto` | `auto\|pdftoppm\|pdftocairo\|magick\|gs` |
| `dpi` | `150` | 渲染 DPI |
| `format` | `png` | `png\|jpg` |
| `jpegQuality` | `90` | JPG 质量 |
| `timeout` | `180` | 单条命令超时（秒） |
| `keepPdf` | `true` | 保留中间 PDF |
| `pdfOnly` | `false` | 只出 PDF |
| `zip` | `true` | 打包 ZIP |
| `workDir` | `null` | 临时工作目录 |
| `keepWorkDir` | `false` | 保留临时目录（排障） |
| `pdfFilterOptions` | `null` | LibreOffice PDF 过滤器参数 |

---

## 4. 实现要点

- **独立 LibreOffice 配置目录**：每次转换都带 `-env:UserInstallation=file:///...` 指向临时目录，
  避免「本机已经开着 LibreOffice 导致转换静默失败」，也避免污染用户配置。
- **Windows 路径转义**：`cmd.exe /c` 会吃掉首尾引号，含空格的路径（`C:\Program Files\...`）会被截断。
  `Process` 类在 Windows 下对整条命令行额外包一层引号，已针对该问题处理。
- **等待 PDF 落盘**：`soffice.exe` 可能先于写盘返回，`waitForPdf()` 会轮询直到文件大小稳定。
- **页码排序**：`page-1.png / page-2.png / page-10.png` 按数字排序，不按字符串。
- **多后端兜底**：自动模式下按 `pdftoppm → pdftocairo → magick → gs` 依次尝试，
  某个后端失败会自动换下一个，并在日志里说明失败原因。
- **无 ext-zip 也能打包**：`ZipWriter` 实现了完整的 ZIP 写入（local header + central directory + EOCD），
  可正确解压。

---

## 5. 常见问题

**中文乱码 / 字形不对**
LibreOffice 依赖系统字体渲染。服务器上没有中文字体时会出现方块或缺字。
装 `fonts-noto-cjk`（Debian/Ubuntu）或 `wqy-zenhei` 即可：

```bash
apt-get install -y fonts-noto-cjk fonts-wqy-zenhei
```

**提示「未找到 LibreOffice」但明明装了**
先用 `doctor` 看探测结果；或显式指定（Windows 建议用 `soffice.com`，它会等待转换完成再退出）：

```bash
php bin/mso2img.php convert a.docx -o out --soffice "C:\Program Files\LibreOffice\program\soffice.com"
```

**转换超时**
大文档或慢机器加 `--timeout 300`；同时确认没有其他 LibreOffice 实例在跑。

**Excel 表格被切成很多页**
每个工作表默认按打印分页。想「一个工作表一页」：

```bash
php bin/mso2img.php convert a.xlsx -o out --filter-options 'pdf:calc_pdf_Export:{"SinglePageSheets":{"type":"boolean","value":"true"}}'
```

**图片太大 / 太小**
调 `--dpi`（150 适合屏幕，300 适合印刷）。A4 在 150 DPI 下约为 1240×1754 像素。

**Web 环境调用（Apache/Nginx + PHP）**
`proc_open` / `exec` 不能被禁用；PHP-FPM 用户需要有临时目录写权限。
FastCGI 下建议把转换放到队列里跑，不要占用 Web 请求。

---

## 6. 测试

本机没有 Office / LibreOffice 也能验证流程——仓库内置了一套「假引擎」：
假的 `soffice` 会把文档换成一个多页 PDF，假的 `pdftoppm` 会把 PDF 拆成 PNG，
走的是与真实环境**完全相同的代码路径**。

```bash
php tests/smoke.php        # 端到端冒烟测试（29~31 项断言）
php tools/make_samples.php # 生成 docx / xlsx / pptx 测试文件
php bin/mso2img.php doctor # 环境自检
```

冒烟测试覆盖：文档→PDF→图片→ZIP 全链路、JPG 输出、只出 PDF 模式、
批量转换、中文多层目录、错误处理、纯 PHP ZIP 打包器、PDF 页数解析、CLI 入口。

已验证通过的 PHP 版本：**5.6 / 7.0 / 7.1 / 7.2 / 7.3 / 7.4 / 8.2**。

装了 LibreOffice 之后再跑一次真实链路：

```bash
php bin/mso2img.php convert samples -o samples/out
```

---

## 7. Windows 注意事项（已实测）

**中文路径**。PHP 在 Windows 上对非 ASCII 路径的处理分两个时代，库内部已分别适配：

| PHP 版本 | 底层是否自动做 UTF-8 → ANSI 转换 | 库的处理 |
|---|---|---|
| < 7.1 | 否（路径字节原样使用） | 库主动把整条路径转成 CP936 字节，保证落盘名正确、mkdir 不失败 |
| >= 7.1 | 是 | 库保持原字符串不动，避免重复转换 |

配套规避了三个 Windows 坑：

- `mkdir($path, 0777, true)` 在含中文的路径上会直接失败 → 改为逐级创建；
- 带结尾分隔符的 `mkdir()` 会被判定为 "File exists" → 每级都不带分隔符；
- 目录创建成功后**用同一个字符串**校验，后续所有操作沿用该字符串，保证前后一致。

**中文文件名**。输出的图片名跟随源文件名，落盘在资源管理器里显示正常（已实测 `转换结果\子目录\mso2img_sample_p001.png`）。

**命令调用**。PHP >= 7.4 的 `proc_open` 支持参数数组，库会自动改用数组形式，
完全绕开 `cmd.exe` 的引号解析（含空格的 `C:\Program Files\...` 路径不会被截断）；
PHP < 7.4 则用字符串形式并在 Windows 下额外包一层引号。

**ZIP 兼容**。`ZipArchive` 在部分版本下打不开/读不了非 ASCII 路径，
库的做法是：打包源始终用工作目录里的纯 ASCII 路径，条目名单独指定可读文件名；
目标路径含非 ASCII 时先在临时目录生成再复制过去。

---

## 8. Docker（服务器无 GUI 环境）

```dockerfile
FROM php:8.2-cli
RUN apt-get update && apt-get install -y --no-install-recommends \
        libreoffice poppler-utils fonts-noto-cjk \
    && rm -rf /var/lib/apt/lists/*
COPY . /app
```

---

## 9. 目录结构

```
mso2img/
├── bin/mso2img.php            CLI 入口
├── src/Mso2Img.php            主类：全链路转换
├── src/Support/Process.php    跨平台子进程执行（超时、引用处理）
├── src/Support/BinaryFinder.php 可执行程序探测
├── src/Support/PdfInfo.php    PDF 页数解析
├── src/Support/ZipWriter.php  纯 PHP ZIP 打包（ext-zip 兜底）
├── tools/doctor.php           环境自检（CLI + 浏览器）
├── tools/make_samples.php     测试样例生成
├── tests/smoke.php            端到端冒烟测试（内置假引擎，免装依赖）
├── tests/fakes/               假 soffice / pdftoppm
├── config.sample.php          配置示例
└── README.md
```

兼容 **PHP 5.6 ~ 8.x**（未使用 PHP7+ 语法，可直接丢进任何老项目）。
