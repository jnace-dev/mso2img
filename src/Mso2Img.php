<?php
/**
 * Mso2Img —— 把 Office 文档（doc/docx/xls/xlsx/ppt/pptx 等）批量转换为图片。
 *
 * 转换链路：
 *   Office 文档 --(LibreOffice headless)--> PDF --(pdftoppm/pdftocairo/ImageMagick/Ghostscript)--> PNG --(可选 GD)--> JPG
 *
 * 依赖均为外部程序，类内负责自动探测路径；缺少依赖时会返回明确的安装指引。
 *
 * 兼容 PHP 5.6 ~ 8.x（未使用 PHP7+ 语法）。
 */
namespace Mso2Img;

use Mso2Img\Support\BinaryFinder;
use Mso2Img\Support\PdfInfo;
use Mso2Img\Support\Process;
use Mso2Img\Support\ZipWriter;

class Mso2Img
{
    const VERSION = '1.0.0';

    /**
     * 支持的扩展名。doc/docx/xls/xlsx/ppt/pptx 为主，其余为顺带支持。
     */
    public static $extensions = array(
        // Word
        'doc', 'docx', 'docm', 'dot', 'dotx', 'rtf',
        // Excel
        'xls', 'xlsx', 'xlsm', 'xlsb', 'xlt', 'xltx', 'csv',
        // PowerPoint
        'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'pot', 'potx',
        // OpenDocument
        'odt', 'ods', 'odp', 'odg', 'odf',
        // WPS / 其它
        'wps', 'et', 'dps', 'wpt', 'html', 'htm', 'txt',
    );

    /** @var array 运行配置 */
    private $options = array();

    /** @var array 本次运行的日志 */
    private $log = array();

    /** @var array 执行过的命令，便于排障 */
    private $commands = array();

    /** @var array 默认配置 */
    private static $defaults = array(
        // soffice 可执行文件路径，null = 自动探测
        'soffice' => null,
        // 光栅化后端：auto|pdftoppm|pdftocairo|magick|gs
        'rasterizer' => 'auto',
        // 渲染 DPI
        'dpi' => 150,
        // 输出图片格式：png|jpg
        'format' => 'png',
        // JPG 质量
        'jpegQuality' => 90,
        // 单条外部命令超时（秒）
        'timeout' => 180,
        // 是否保留中间 PDF
        'keepPdf' => true,
        // 只输出 PDF，不生成图片
        'pdfOnly' => false,
        // 是否打包 ZIP
        'zip' => true,
        // 临时工作目录，null = 系统临时目录
        'workDir' => null,
        // 是否保留临时目录（调试用）
        'keepWorkDir' => false,
        // 传给 LibreOffice 的 PDF 过滤器参数，例：
        // 'pdf:calc_pdf_Export:{"SinglePageSheets":{"type":"boolean","value":"true"}}'
        'pdfFilterOptions' => null,
    );

    public function __construct(array $options = array())
    {
        $this->options = array_merge(self::$defaults, $options);
        $this->options['dpi'] = max(50, min(600, (int) $this->options['dpi']));
        $this->options['format'] = strtolower($this->options['format']) === 'jpg' ? 'jpg' : 'png';
    }

    /**
     * 修改配置。
     *
     * @param string $key
     * @param mixed  $value
     * @return $this
     */
    public function setOption($key, $value)
    {
        if (array_key_exists($key, self::$defaults)) {
            $this->options[$key] = $value;
        }
        return $this;
    }

    /**
     * 读取配置。
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function getOption($key, $default = null)
    {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }

    /**
     * 转换单个文件。
     *
     * @param string $input  源文件路径
     * @param string $output 输出目录，或以 .zip 结尾的 ZIP 路径；null = 源文件同级 mso2img_out 目录
     * @param array  $options 本次调用的临时配置覆盖
     * @return array 结果数组
     */
    public function convert($input, $output = null, array $options = array())
    {
        if (!empty($options)) {
            foreach ($options as $key => $value) {
                $this->setOption($key, $value);
            }
        }

        $this->log = array();
        $this->commands = array();
        $startedAt = microtime(true);

        $result = array(
            'ok' => false,
            'input' => $input,
            'type' => null,
            'outputDir' => null,
            'pdf' => null,
            'zip' => null,
            'pages' => array(),
            'pageCount' => 0,
            'commands' => array(),
            'messages' => array(),
            'error' => null,
            'duration' => 0.0,
        );

        // ---------- 1. 校验输入 ----------
        $real = self::resolveExistingPath($input);
        if (!$real) {
            $result['error'] = '输入文件不存在: ' . $input;
            return $this->finish($result, $startedAt);
        }
        $result['input'] = $real;

        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if (!in_array($extension, self::$extensions, true)) {
            $result['error'] = '不支持的文件类型 .' . $extension . '，支持: '
                . implode('/', self::$extensions);
            return $this->finish($result, $startedAt);
        }
        $result['type'] = $extension;

        $detected = self::detectRealType($real);
        if ($detected !== null && self::familyOf($extension) !== $detected) {
            $this->log[] = '提示: 扩展名 .' . $extension . ' 与实际内容（'
                . $detected . '）不一致，将按实际内容尝试转换。';
        }

        // ---------- 2. 解析输出位置 ----------
        $base = self::sanitizeName(pathinfo($real, PATHINFO_FILENAME));
        // 文件系统用的基名：与输出目录保持同一套字节（Windows 中文路径）
        $baseFs = self::normalizePath($base);
        $zipPath = null;
        if ($output === null || $output === '') {
            $outDir = dirname($real) . DIRECTORY_SEPARATOR . 'mso2img_out';
        } elseif (substr(strtolower($output), -4) === '.zip') {
            $output = self::normalizePath($output);
            $zipPath = $output;
            $outDir = dirname($output);
        } else {
            $outDir = $output;
        }
        // 逐级创建输出目录，并用同一个字符串校验（Windows 非 ASCII 路径的必要处理）
        $outDir = self::ensureDirectory($outDir);
        if ($outDir === false) {
            $result['error'] = '无法创建输出目录: ' . $output;
            return $this->finish($result, $startedAt);
        }
        $outDir = realpath($outDir);
        $result['outputDir'] = $outDir;

        // ---------- 3. 准备工作目录 ----------
        $workDir = $this->makeWorkDir();
        if ($workDir === null) {
            $result['error'] = '无法创建临时工作目录';
            return $this->finish($result, $startedAt);
        }
        $this->log[] = '工作目录: ' . $workDir;

        $sourceCopy = $workDir . DIRECTORY_SEPARATOR . 'source.' . $extension;
        if (!@copy($real, $sourceCopy)) {
            $result['error'] = '无法复制源文件到工作目录';
            $this->cleanup($workDir);
            return $this->finish($result, $startedAt);
        }

        try {
            // ---------- 4. Office -> PDF ----------
            $pdf = $this->officeToPdf($sourceCopy, $workDir);
            if ($pdf === null) {
                $result['error'] = '文档转 PDF 失败';
                return $this->finish($result, $startedAt);
            }
            $this->log[] = '中间 PDF: ' . $pdf;

            $pdfMeta = PdfInfo::inspect($pdf);
            if ($pdfMeta['pages']) {
                $this->log[] = 'PDF 页数: ' . $pdfMeta['pages'];
            }

            // ---------- 4.5 只出 PDF 的模式 ----------
            if ($this->options['pdfOnly']) {
                $pdfTarget = $outDir . DIRECTORY_SEPARATOR . $baseFs . '.pdf';
                if (!@copy($pdf, $pdfTarget)) {
                    $result['error'] = '无法写出 PDF 文件: ' . $pdfTarget;
                    return $this->finish($result, $startedAt);
                }
                $result['pdf'] = $pdfTarget;
                $result['pageCount'] = $pdfMeta['pages'] ? (int) $pdfMeta['pages'] : 0;
                $result['ok'] = true;
                $this->cleanup($workDir);
                return $this->finish($result, $startedAt);
            }

            // ---------- 5. PDF -> 图片 ----------
            $raster = $this->rasterize($pdf, $workDir);
            if (!$raster['ok']) {
                $result['error'] = $raster['error'];
                return $this->finish($result, $startedAt);
            }
            $this->log[] = '光栅化后端: ' . $raster['tool'] . '，共 ' . count($raster['files']) . ' 页';

            if (!$raster['files']) {
                $result['error'] = 'PDF 未生成任何页面图片';
                return $this->finish($result, $startedAt);
            }

            // ---------- 6. 输出到目标目录 ----------
            $pageIndex = 0;
            foreach ($raster['files'] as $pageFile) {
                $pageIndex++;
                $targetName = sprintf('%s_p%03d.%s', $base, $pageIndex, $this->options['format']);
                // 目录与文件名使用同一套字节，Windows 中文路径才不会出问题
                $targetPath = $outDir . DIRECTORY_SEPARATOR . $baseFs
                    . sprintf('_p%03d.%s', $pageIndex, $this->options['format']);

                if ($this->options['format'] === 'jpg') {
                    $converted = $this->pngToJpeg($pageFile, $targetPath);
                    if ($converted === null) {
                        // GD 不可用时退回 PNG
                        $targetName = sprintf('%s_p%03d.png', $base, $pageIndex);
                        $targetPath = $outDir . DIRECTORY_SEPARATOR . $targetName;
                        if (!@copy($pageFile, $targetPath)) {
                            continue;
                        }
                    }
                } else {
                    if (!@copy($pageFile, $targetPath)) {
                        $this->log[] = '警告: 页面写入失败 ' . $targetPath;
                        continue;
                    }
                }

                $result['pages'][] = array(
                    'index' => $pageIndex,
                    'name' => $targetName,
                    'path' => $targetPath,
                    'size' => (int) @filesize($targetPath),
                    // 打包时使用工作目录里的原始文件（纯 ASCII 路径，避开中文路径兼容问题）
                    'source' => $pageFile,
                );
            }
            $result['pageCount'] = count($result['pages']);

            // ---------- 7. 中间 PDF ----------
            if ($this->options['keepPdf']) {
                $pdfTarget = $outDir . DIRECTORY_SEPARATOR . $baseFs . '.pdf';
                if (@copy($pdf, $pdfTarget)) {
                    $result['pdf'] = $pdfTarget;
                }
            }

            // ---------- 8. 打包 ZIP ----------
            if ($this->options['zip']) {
                if ($zipPath === null) {
                    $zipPath = $outDir . DIRECTORY_SEPARATOR . $baseFs . '.zip';
                }
                if ($this->createZip($zipPath, $result['pages'], array($pdf, $baseFs . '.pdf'))) {
                    $result['zip'] = $zipPath;
                    $this->log[] = 'ZIP 包: ' . $zipPath;
                } else {
                    $this->log[] = '警告: ZIP 打包失败';
                }
            }

            $result['ok'] = $result['pageCount'] > 0;
            if (!$result['ok']) {
                $result['error'] = '未生成任何图片';
            }
        } catch (\Exception $e) {
            $result['error'] = '转换异常: ' . $e->getMessage();
        }

        $this->cleanup($workDir);
        return $this->finish($result, $startedAt);
    }

    /**
     * 批量转换（目录或文件列表）。
     *
     * @param array|string $inputs  文件列表，或目录路径
     * @param string        $outDir  输出目录
     * @param array         $options 配置覆盖
     * @return array 每个文件的结果
     */
    public function convertBatch($inputs, $outDir = null, array $options = array())
    {
        $files = array();

        if (is_string($inputs)) {
            $dir = self::resolveExistingDirectory($inputs);
            if ($dir !== false) {
                try {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                    );
                    foreach ($iterator as $file) {
                        if ($file->isFile() && self::isSupported($file->getFilename())) {
                            $files[] = $file->getPathname();
                        }
                    }
                    sort($files);
                } catch (\Exception $e) {
                    $this->log[] = '读取目录失败: ' . $e->getMessage();
                }
            } elseif (is_file($inputs)) {
                $files[] = $inputs;
            }
        } elseif (is_array($inputs)) {
            foreach ($inputs as $input) {
                if (is_string($input) && is_dir($input)) {
                    $sub = $this->convertBatch($input, $outDir, $options);
                    foreach ($sub as $one) {
                        $files[] = $one;
                    }
                } elseif (is_string($input) && is_file($input)) {
                    $files[] = $input;
                }
            }
        }

        $results = array();
        foreach ($files as $file) {
            $results[] = $this->convert($file, $outDir, $options);
        }
        return $results;
    }

    /**
     * 环境自检：列出依赖状态与安装建议。
     *
     * @return array
     */
    public function doctor()
    {
        $checks = array();

        $checks[] = array(
            'name' => 'PHP 版本',
            'ok' => version_compare(PHP_VERSION, '5.6.0', '>='),
            'detail' => PHP_VERSION,
            'hint' => '需要 PHP >= 5.6（推荐 7.x / 8.x）',
        );
        $checks[] = array(
            'name' => 'proc_open',
            'ok' => function_exists('proc_open'),
            'detail' => function_exists('proc_open') ? '可用' : '被禁用',
            'hint' => '在 php.ini 中移除 disable_functions 里的 proc_open',
        );
        $checks[] = array(
            'name' => 'exec',
            'ok' => function_exists('exec'),
            'detail' => function_exists('exec') ? '可用' : '被禁用',
            'hint' => '在 php.ini 中移除 disable_functions 里的 exec',
        );
        $checks[] = array(
            'name' => 'ext-zip',
            'ok' => class_exists('ZipArchive'),
            'detail' => class_exists('ZipArchive') ? '可用' : '未安装（改用内置纯 PHP 打包）',
            'hint' => '启用 extension=zip，可获得更快的打包速度',
        );
        $checks[] = array(
            'name' => 'ext-gd',
            'ok' => function_exists('imagecreatefrompng'),
            'detail' => function_exists('imagecreatefrompng') ? '可用' : '未安装（无法输出 JPG）',
            'hint' => '启用 extension=gd 需要 outputs JPG 时使用',
        );

        $soffice = $this->resolveSoffice();
        $checks[] = array(
            'name' => 'LibreOffice (soffice)',
            'ok' => $soffice !== null,
            'detail' => $soffice === null ? '未找到' : $soffice,
            'hint' => self::installHint('libreoffice'),
        );

        $rasterizers = $this->availableRasterizers();
        $checks[] = array(
            'name' => 'PDF 光栅化后端',
            'ok' => !empty($rasterizers),
            'detail' => empty($rasterizers) ? '未找到' : implode('、', array_keys($rasterizers)),
            'hint' => self::installHint('poppler'),
        );

        $tmp = sys_get_temp_dir();
        $checks[] = array(
            'name' => '临时目录可写',
            'ok' => is_writable($tmp),
            'detail' => $tmp,
            'hint' => '确保 PHP 进程对临时目录有写权限',
        );

        return array(
            'ok' => true,
            'version' => self::VERSION,
            'checks' => $checks,
            'soffice' => $soffice,
            'rasterizers' => array_keys($rasterizers),
        );
    }

    // ------------------------------------------------------------------
    // 内部实现
    // ------------------------------------------------------------------

    /** 记录日志并收尾 */
    private function finish(array $result, $startedAt)
    {
        $result['messages'] = $this->log;
        $result['commands'] = $this->commands;
        $result['duration'] = round(microtime(true) - $startedAt, 3);
        return $result;
    }

    /** 创建临时工作目录 */
    private function makeWorkDir()
    {
        $root = $this->options['workDir'];
        if (!$root) {
            $root = sys_get_temp_dir();
        }
        if (!is_dir($root) && !self::ensureDirectory($root)) {
            return null;
        }
        $dir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR
            . 'mso2img_' . getmypid() . '_' . mt_rand(100000, 999999);
        if (!@mkdir($dir, 0777)) {
            return null;
        }
        return $dir;
    }

    /** 清理临时目录 */
    private function cleanup($dir)
    {
        if ($this->options['keepWorkDir'] || !$dir || !is_dir($dir)) {
            return;
        }
        self::removeTree($dir);
    }

    /**
     * 路径编码归一化（仅 Windows 生效）。
     *
     * PHP 在 Windows 上的路径处理分两个时代：
     *  - PHP < 7.1：底层 Win32 调用不做 UTF-8 -> ANSI 转换，路径字节被原样使用。
     *    这种情况下「源码里拼出来的 UTF-8 中文路径」会落盘成乱码名，
     *    而且当中文占用的字节数为奇数时，连 mkdir 都会直接失败。
     *    所以这里主动把整条路径转成 ANSI（CP936）字节。
     *  - PHP >= 7.1：PHP 自己会做 UTF-8 -> ANSI 转换，直接用原字符串即可，
     *    再转一次反而会出错。
     *
     * 对来自操作系统本身的路径（本来就是 ANSI 字节，例如 opendir 读到的文件名）保持原样。
     *
     * @param string $path
     * @return string
     */
    public static function normalizePath($path)
    {
        if (DIRECTORY_SEPARATOR !== '\\' || $path === null || $path === '') {
            return $path;
        }
        if (preg_match('/^[\x20-\x7E]*$/', $path)) {
            return $path; // 纯 ASCII，无需处理
        }
        if (!function_exists('mb_convert_encoding') || !function_exists('mb_check_encoding')) {
            return $path; // 没有 mbstring 就交给 PHP 自己处理
        }
        if (!@mb_check_encoding($path, 'UTF-8')) {
            return $path; // 已经是 ANSI 字节（例如来自 opendir），保持原样
        }
        if (version_compare(PHP_VERSION, '7.1.0', '>=')) {
            return $path; // PHP 自己会转换
        }
        $converted = @mb_convert_encoding($path, 'CP936', 'UTF-8');
        return ($converted === false || $converted === '') ? $path : $converted;
    }

    /**
     * 解析输入文件路径。
     *
     * 先按原样查找；找不到时，若路径是 UTF-8 中文，再试一次 ANSI 归一化后的路径，
     * 这样「PHP 源码里写的中文路径」也能命中 Windows 上以 ANSI 名字存在的文件。
     *
     * @param string $input
     * @return string|false
     */
    public static function resolveExistingPath($input)
    {
        $real = is_file($input) ? realpath($input) : false;
        if ($real) {
            return $real;
        }
        $normalized = self::normalizePath($input);
        if ($normalized !== $input && is_file($normalized)) {
            return realpath($normalized);
        }
        return false;
    }

    /**
     * 解析目录路径（与 resolveExistingPath 同样的容错思路）。
     *
     * @param string $dir
     * @return string|false
     */
    public static function resolveExistingDirectory($dir)
    {
        $normalized = self::normalizePath($dir);
        if ($normalized !== $dir && is_dir($normalized)) {
            return $normalized;
        }
        if (is_dir($dir)) {
            return $dir;
        }
        return false;
    }

    /**
     * 逐级创建目录，并返回「经校验确实可用」的那个路径字符串。
     *
     * 为什么要返回字符串而不是布尔值：
     * PHP 在 Windows 上对非 ASCII 路径的处理在不同版本里并不一致
     * （5.6 直接按字节用，7.4+ 会转成 ANSI 代码页），同一个字符串在不同
     * 函数里还可能解析成不同名字。所以这里只认「用哪个字符串建的，
     * 就用同一个字符串去校验」，保证后续所有操作与创建时完全一致。
     *
     * 另外两个 Windows 坑也一并规避：
     *  - 递归 mkdir() 在含中文的路径上会直接失败；
     *  - 带结尾分隔符的 mkdir() 会被判定为 "File exists" 而返回 false。
     *
     * @param string $path
     * @return string|false 可用的路径；失败返回 false
     */
    public static function ensureDirectory($path)
    {
        if ($path === null || $path === '') {
            return false;
        }
        if (is_dir($path)) {
            return $path;
        }

        // Windows：优先用「整条路径转成 ANSI 字节」的版本创建，
        // 这样目录名落盘正确，且与后续所有文件操作使用的字节完全一致。
        $candidates = array();
        $normalized = self::normalizePath($path);
        if ($normalized !== $path) {
            $candidates[] = $normalized;
        }
        $candidates[] = $path;

        foreach ($candidates as $candidate) {
            self::createDirectoryTree($candidate);
            if (is_dir($candidate)) {
                return $candidate;
            }
        }
        return false;
    }

    /** 逐级 mkdir（每级都不带结尾分隔符） */
    private static function createDirectoryTree($path)
    {
        $normalized = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        if ($normalized === '') {
            return;
        }

        $segments = explode(DIRECTORY_SEPARATOR, $normalized);
        $current = '';
        $first = true;

        if (isset($segments[0]) && preg_match('/^[A-Za-z]:$/', $segments[0])) {
            $current = $segments[0] . DIRECTORY_SEPARATOR;
            array_shift($segments);
        } elseif (substr($normalized, 0, 1) === DIRECTORY_SEPARATOR) {
            $current = DIRECTORY_SEPARATOR;
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            if (!$first && substr($current, -1) !== DIRECTORY_SEPARATOR) {
                $current .= DIRECTORY_SEPARATOR;
            }
            $current .= $segment;
            $first = false;
            if (!is_dir($current)) {
                @mkdir($current, 0777);
            }
        }
    }

    /** 递归删除目录 */
    public static function removeTree($dir)    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * 定位 soffice 可执行文件。
     *
     * @return string|null
     */
    public function resolveSoffice()
    {
        $configured = $this->options['soffice'];
        if ($configured) {
            if (BinaryFinder::isExecutable($configured)) {
                return $configured;
            }
            $found = BinaryFinder::which($configured);
            if ($found) {
                return $found;
            }
            return null;
        }
        return BinaryFinder::find('soffice', self::sofficeSearchPaths());
    }

    /** soffice 搜索路径（Windows 优先 soffice.com，等待转换完成更稳） */
    private static function sofficeSearchPaths()
    {
        $paths = array();
        if (DIRECTORY_SEPARATOR === '\\') {
            $paths[] = 'C:\\Program Files\\LibreOffice\\program\\soffice.com';
            $paths[] = 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.com';
        }
        $paths = array_merge($paths, BinaryFinder::sofficeCandidates());
        return $paths;
    }

    /**
     * Office 文档 -> PDF。
     *
     * @return string|null PDF 路径
     */
    private function officeToPdf($sourcePath, $workDir)
    {
        $soffice = $this->resolveSoffice();
        if ($soffice === null) {
            $this->log[] = '错误: 未找到 LibreOffice(soffice)，无法转换文档。';
            $this->log[] = '安装建议: ' . self::installHint('libreoffice');
            return null;
        }

        $profileDir = $workDir . DIRECTORY_SEPARATOR . 'loprofile';
        self::ensureDirectory($profileDir);

        $convertTo = 'pdf';
        if ($this->options['pdfFilterOptions']) {
            $convertTo = $this->options['pdfFilterOptions'];
        }

        $args = array(
            $soffice,
            '--headless',
            '--invisible',
            '--nologo',
            '--norestore',
            '--nolockcheck',
            '--nodefault',
            '--nofirststartwizard',
            '-env:UserInstallation=' . self::fileUrl($profileDir),
            '--convert-to',
            $convertTo,
            '--outdir',
            $workDir,
            $sourcePath,
        );

        $process = Process::run($args, array(
            'timeout' => $this->options['timeout'],
            'cwd' => $workDir,
        ));
        $this->commands[] = $process->command;

        $output = trim($process->output());
        if ($output !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $output) as $line) {
                $this->log[] = 'soffice: ' . $line;
            }
        }

        $pdf = $this->waitForPdf($workDir, $process->timedOut ? 5 : 30);
        if ($pdf === null) {
            if ($process->timedOut) {
                $this->log[] = '错误: LibreOffice 转换超时（' . $this->options['timeout'] . ' 秒）。';
            } else {
                $this->log[] = '错误: LibreOffice 未产出 PDF（退出码 ' . $process->exitCode . '）。';
            }
            return null;
        }
        return $pdf;
    }

    /** 等待 PDF 落盘（soffice.exe 可能先于写盘返回） */
    private function waitForPdf($dir, $maxSeconds)
    {
        $deadline = microtime(true) + $maxSeconds;
        $pdf = null;
        $lastSize = -1;
        while (microtime(true) < $deadline) {
            $files = glob($dir . DIRECTORY_SEPARATOR . '*.pdf');
            if ($files && is_file($files[0])) {
                $size = filesize($files[0]);
                if ($size > 0 && $size === $lastSize) {
                    return $files[0];
                }
                $lastSize = $size;
                $pdf = $files[0];
            }
            usleep(150000);
        }
        return $pdf;
    }

    /**
     * 探测可用的光栅化后端。
     *
     * @return array 名称 => 可执行路径
     */
    public function availableRasterizers()
    {
        $found = array();
        $map = array(
            'pdftoppm' => array('pdftoppm', BinaryFinder::popplerCandidates()),
            'pdftocairo' => array('pdftocairo', BinaryFinder::popplerCandidates()),
            'magick' => array('magick', BinaryFinder::imagemagickCandidates()),
            'gs' => array('gswin64c', BinaryFinder::ghostscriptCandidates()),
        );
        foreach ($map as $key => $item) {
            $path = BinaryFinder::find($item[0], $item[1]);
            if ($path !== null) {
                $found[$key] = $path;
            }
        }
        // Windows 上 gs 兜底再找一次 gswin32c
        if (!isset($found['gs']) && DIRECTORY_SEPARATOR === '\\') {
            $path = BinaryFinder::find('gswin32c', BinaryFinder::ghostscriptCandidates());
            if ($path !== null) {
                $found['gs'] = $path;
            }
        }
        return $found;
    }

    /**
     * PDF -> PNG 页序列。
     *
     * @return array{ok:bool,files:array,tool:string,error:string}
     */
    private function rasterize($pdfPath, $workDir)
    {
        $available = $this->availableRasterizers();
        $configured = strtolower((string) $this->options['rasterizer']);

        $order = array();
        if ($configured !== 'auto') {
            $order[] = $configured;
        } else {
            $order = array('pdftoppm', 'pdftocairo', 'magick', 'gs');
        }

        if (empty($available)) {
            return array(
                'ok' => false,
                'files' => array(),
                'tool' => '',
                'error' => '未找到 PDF 光栅化工具（pdftoppm / pdftocairo / magick / gs）。'
                    . '安装建议: ' . self::installHint('poppler'),
            );
        }

        $prefix = $workDir . DIRECTORY_SEPARATOR . 'page';
        $errors = array();

        foreach ($order as $tool) {
            if (!isset($available[$tool])) {
                if ($configured !== 'auto') {
                    $errors[] = '指定的 ' . $tool . ' 不可用';
                }
                continue;
            }

            $args = $this->buildRasterArgs($tool, $available[$tool], $pdfPath, $prefix);
            $process = Process::run($args, array(
                'timeout' => $this->options['timeout'],
                'cwd' => $workDir,
            ));
            $this->commands[] = $process->command;

            $files = $this->collectPages($prefix);
            if ($files) {
                return array('ok' => true, 'files' => $files, 'tool' => $tool . ' (' . $available[$tool] . ')', 'error' => '');
            }

            $message = trim($process->output());
            $errors[] = $tool . ' 未产出图片' . ($message !== '' ? '：' . $message : '');
        }

        return array(
            'ok' => false,
            'files' => array(),
            'tool' => '',
            'error' => 'PDF 转图片失败。' . implode('；', $errors),
        );
    }

    /** 组装光栅化命令行 */
    private function buildRasterArgs($tool, $binary, $pdfPath, $prefix)
    {
        $dpi = (int) $this->options['dpi'];
        switch ($tool) {
            case 'pdftoppm':
                return array($binary, '-png', '-r', $dpi, '-aa', 'yes', '-aaVector', 'yes', $pdfPath, $prefix);
            case 'pdftocairo':
                return array($binary, '-png', '-r', $dpi, $pdfPath, $prefix);
            case 'magick':
                return array(
                    $binary,
                    '-density', $dpi,
                    $pdfPath,
                    '-background', 'white',
                    '-alpha', 'remove',
                    '-alpha', 'off',
                    $prefix . '-%03d.png',
                );
            case 'gs':
            default:
                return array(
                    $binary,
                    '-dNOPAUSE', '-dBATCH', '-dSAFER',
                    '-sDEVICE=png16m',
                    '-r' . $dpi,
                    '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
                    '-sOutputFile=' . $prefix . '-%03d.png',
                    $pdfPath,
                );
        }
    }

    /** 收集并按页码排序 PNG 文件 */
    private function collectPages($prefix)
    {
        $files = glob($prefix . '*');
        if (!$files) {
            return array();
        }
        $pages = array();
        foreach ($files as $file) {
            if (is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'png') {
                $pages[] = $file;
            }
        }
        if (!$pages) {
            return array();
        }
        usort($pages, array(__CLASS__, 'comparePageFiles'));
        return $pages;
    }

    /** 页码文件名排序：page-1.png < page-2.png < page-10.png */
    public static function comparePageFiles($a, $b)
    {
        $na = self::pageNumber($a);
        $nb = self::pageNumber($b);
        if ($na === $nb) {
            return strcmp($a, $b);
        }
        return ($na < $nb) ? -1 : 1;
    }

    /** 提取文件名尾部页码 */
    private static function pageNumber($path)
    {
        if (preg_match('/(\d+)(?=\.[A-Za-z0-9]+$)/', $path, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /** PNG -> JPG（依赖 GD） */
    private function pngToJpeg($pngPath, $targetPath)
    {
        if (!function_exists('imagecreatefrompng')) {
            return null;
        }
        $image = @imagecreatefrompng($pngPath);
        if (!$image) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        $ok = @imagejpeg($canvas, $targetPath, (int) $this->options['jpegQuality']);
        imagedestroy($image);
        imagedestroy($canvas);
        return $ok ? $targetPath : null;
    }

    /** 打包 ZIP。$pdfEntry = array(源文件路径, ZIP 内条目名) */
    private function createZip($zipPath, array $pages, $pdfEntry = null)
    {
        $entries = array();
        foreach ($pages as $page) {
            // 优先用工作目录里的原始文件（ASCII 路径），条目名用可读文件名
            $source = isset($page['source']) && is_file($page['source']) ? $page['source'] : $page['path'];
            $entries[] = array($source, $page['name']);
        }
        if (is_array($pdfEntry) && isset($pdfEntry[0]) && is_file($pdfEntry[0])) {
            $entries[] = array($pdfEntry[0], $pdfEntry[1]);
        }
        if (!$entries) {
            return false;
        }

        // 目标路径含非 ASCII 字符时（部分 PHP 版本的 ZipArchive 处理不了），
        // 先在临时目录用纯 ASCII 路径生成，再复制到目标位置
        $asciiTarget = (preg_match('/^[\x20-\x7E]*$/', $zipPath) === 1);
        $buildPath = $zipPath;
        $tempFile = null;
        if (!$asciiTarget) {
            $tempFile = @tempnam(sys_get_temp_dir(), 'mso2imgzip');
            if ($tempFile === false) {
                $tempFile = null;
                $asciiTarget = true;
            } else {
                $buildPath = $tempFile;
            }
        }

        try {
            if (class_exists('ZipArchive')) {
                $zip = new \ZipArchive();
                if ($zip->open($buildPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    if ($tempFile !== null) {
                        @unlink($tempFile);
                    }
                    return false;
                }
                foreach ($entries as $entry) {
                    $zip->addFile($entry[0], $entry[1]);
                }
                $closed = $zip->close();
            } else {
                $writer = new ZipWriter($buildPath);
                foreach ($entries as $entry) {
                    $writer->addFile($entry[0], $entry[1]);
                }
                $writer->close();
                $closed = true;
            }
        } catch (\Exception $e) {
            $this->log[] = 'ZIP 异常: ' . $e->getMessage();
            if ($tempFile !== null) {
                @unlink($tempFile);
            }
            return false;
        }

        if (!$closed) {
            if ($tempFile !== null) {
                @unlink($tempFile);
            }
            return false;
        }

        if ($tempFile !== null) {
            $ok = @copy($tempFile, $zipPath);
            @unlink($tempFile);
            return $ok;
        }
        return true;
    }

    // ------------------------------------------------------------------
    // 静态工具
    // ------------------------------------------------------------------

    /** 路径转 file:// URL */
    public static function fileUrl($path)
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('#^[A-Za-z]:#', $path)) {
            return 'file:///' . str_replace(' ', '%20', $path);
        }
        return 'file://' . str_replace(' ', '%20', $path);
    }

    /** 文件名安全化（按字节处理，兼容 ANSI 字节形式的中文名） */
    public static function sanitizeName($name)
    {
        $name = preg_replace('/[\\\\\/:\*\?"<>\|\s]+/', '_', $name);
        if ($name === null) {
            $name = 'document';
        }
        $name = trim($name, '._');
        return $name === '' ? 'document' : $name;
    }

    /** 是否是支持的文件名 */
    public static function isSupported($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::$extensions, true);
    }

    /**
     * 通过文件头判断真实类型。
     *
     * @return string|null word|excel|powerpoint|odf|zip|unknown
     */
    public static function detectRealType($path)
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return null;
        }
        $head = fread($handle, 8);
        fclose($handle);
        if (!is_string($head) || strlen($head) < 4) {
            return null;
        }
        if (substr($head, 0, 4) === "\xD0\xCF\x11\xE0") {
            return 'ole'; // doc/xls/ppt 二进制格式
        }
        if (substr($head, 0, 2) === 'PK') {
            return 'zip'; // docx/xlsx/pptx/odf
        }
        if (substr($head, 0, 5) === '%PDF-') {
            return 'pdf';
        }
        return null;
    }

    /** 扩展名归属的文档族 */
    public static function familyOf($extension)
    {
        $extension = strtolower($extension);
        $map = array(
            'doc' => 'word', 'docx' => 'word', 'docm' => 'word', 'dot' => 'word', 'dotx' => 'word', 'rtf' => 'word',
            'xls' => 'excel', 'xlsx' => 'excel', 'xlsm' => 'excel', 'xlsb' => 'excel',
            'xlt' => 'excel', 'xltx' => 'excel', 'csv' => 'excel',
            'ppt' => 'powerpoint', 'pptx' => 'powerpoint', 'pptm' => 'powerpoint',
            'pps' => 'powerpoint', 'ppsx' => 'powerpoint', 'pot' => 'powerpoint', 'potx' => 'powerpoint',
        );
        return isset($map[$extension]) ? $map[$extension] : null;
    }

    /** 依赖安装提示 */
    public static function installHint($which)
    {
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        if ($which === 'libreoffice') {
            if ($isWindows) {
                return 'winget install --id TheDocumentFoundation.LibreOffice -e'
                    . '（或从 https://www.libreoffice.org 下载安装）';
            }
            if (stripos(PHP_OS, 'darwin') !== false) {
                return 'brew install --cask libreoffice';
            }
            return 'apt-get install -y libreoffice  或  yum install -y libreoffice';
        }
        if ($which === 'poppler') {
            if ($isWindows) {
                return 'winget install --id oschwartz10612.Poppler -e';
            }
            if (stripos(PHP_OS, 'darwin') !== false) {
                return 'brew install poppler';
            }
            return 'apt-get install -y poppler-utils  或  yum install -y poppler-utils';
        }
        return '';
    }
}
