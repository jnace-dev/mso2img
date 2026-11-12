<?php
/**
 * Mso2Img 命令行工具。
 *
 * 用法:
 *   php bin/mso2img.php convert <文件|目录> [选项]
 *   php bin/mso2img.php doctor
 *   php bin/mso2img.php help
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "本脚本仅支持命令行运行。\n");
    exit(1);
}

$root = dirname(dirname(__FILE__));
require_once $root . '/src/Support/Process.php';
require_once $root . '/src/Support/BinaryFinder.php';
require_once $root . '/src/Support/PdfInfo.php';
require_once $root . '/src/Support/ZipWriter.php';
require_once $root . '/src/Mso2Img.php';

use Mso2Img\Mso2Img;

$GLOBALS['mso2img_version'] = Mso2Img::VERSION;

function mso2img_usage()
{
    $bin = 'php ' . basename(__FILE__);
    echo <<<TXT
Mso2Img v{$GLOBALS['mso2img_version']} —— Office 文档批量转图片

用法:
  {$bin} convert <文件|目录> [选项]     转换文档为图片（每页一张 + ZIP 打包）
  {$bin} doctor                        检查运行环境与依赖
  {$bin} help                          显示本帮助

选项:
  -o, --out <目录|ZIP>     输出位置；默认 <源文件同级>/mso2img_out 目录
  -z, --zip <ZIP路径>      指定 ZIP 输出路径（输出位置改为目录）
  -f, --format png|jpg     图片格式，默认 png
  -d, --dpi <数值>         渲染 DPI，默认 150（范围 50-600）
      --keep-pdf           保留中间 PDF（默认开启）
      --no-keep-pdf        不保留中间 PDF
      --no-zip             不打包 ZIP
      --pdf-only           只转换出 PDF，不生成图片
      --timeout <秒>       单条外部命令超时，默认 180
      --soffice <路径>     指定 soffice 可执行文件
      --rasterizer <名称>   auto|pdftoppm|pdftocairo|magick|gs，默认 auto
      --filter-options <s>  LibreOffice PDF 过滤器参数（JSON）
      --keep-work          保留临时工作目录（调试用）
      --config <文件>      载入 PHP 配置文件（返回数组）
  -q, --quiet              精简输出
  -h, --help               显示帮助

示例:
  {$bin} convert ./季度报表.docx -o D:/out
  {$bin} convert D:/docs -o D:/out -f jpg -d 200
  {$bin} convert ./slides.pptx -o D:/out/demo.zip

TXT;
}

/**
 * 解析命令行参数。
 *
 * @return array{command:string,args:array,options:array}
 */
function mso2img_parse_argv($argv)
{
    $command = '';
    $args = array();
    $options = array();

    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = $argv[$i];
        if (strpos($arg, '--') === 0) {
            $name = substr($arg, 2);
            $value = null;
            $pos = strpos($name, '=');
            if ($pos !== false) {
                $value = substr($name, $pos + 1);
                $name = substr($name, 0, $pos);
            } elseif (in_array($name, array('out', 'zip', 'format', 'dpi', 'timeout', 'soffice',
                'rasterizer', 'filter-options', 'config'), true)) {
                $i++;
                $value = isset($argv[$i]) ? $argv[$i] : null;
            }
            $options[$name] = $value === null ? true : $value;
        } elseif (strpos($arg, '-') === 0 && strlen($arg) > 1) {
            $name = substr($arg, 1);
            $map = array('o' => 'out', 'z' => 'zip', 'f' => 'format', 'd' => 'dpi', 'q' => 'quiet', 'h' => 'help');
            $long = isset($map[$name]) ? $map[$name] : $name;
            if (in_array($long, array('out', 'zip', 'format', 'dpi'), true)) {
                $i++;
                $options[$long] = isset($argv[$i]) ? $argv[$i] : null;
            } else {
                $options[$long] = true;
            }
        } elseif ($command === '') {
            $command = $arg;
        } else {
            $args[] = $arg;
        }
    }

    return array('command' => $command, 'args' => $args, 'options' => $options);
}

/** 把 CLI 选项映射成类库配置 */
function mso2img_build_options(array $cli)
{
    $options = array();
    if (!empty($cli['soffice'])) {
        $options['soffice'] = $cli['soffice'];
    }
    if (!empty($cli['rasterizer'])) {
        $options['rasterizer'] = $cli['rasterizer'];
    }
    if (!empty($cli['format'])) {
        $options['format'] = $cli['format'];
    }
    if (isset($cli['dpi']) && $cli['dpi'] !== true) {
        $options['dpi'] = (int) $cli['dpi'];
    }
    if (isset($cli['timeout']) && $cli['timeout'] !== true) {
        $options['timeout'] = (int) $cli['timeout'];
    }
    if (isset($cli['keep-pdf'])) {
        $options['keepPdf'] = (bool) $cli['keep-pdf'];
    }
    if (isset($cli['no-keep-pdf'])) {
        $options['keepPdf'] = false;
    }
    if (isset($cli['no-zip'])) {
        $options['zip'] = false;
    }
    if (isset($cli['filter-options']) && $cli['filter-options'] !== true) {
        $options['pdfFilterOptions'] = $cli['filter-options'];
    }
    if (isset($cli['keep-work'])) {
        $options['keepWorkDir'] = true;
    }
    if (isset($cli['pdf-only']) && $cli['pdf-only'] !== false) {
        $options['pdfOnly'] = true;
    }
    return $options;
}

$parsed = mso2img_parse_argv($argv);
$command = $parsed['command'] === '' ? 'help' : $parsed['command'];
$quiet = !empty($parsed['options']['quiet']);

if (isset($parsed['options']['help']) || $command === 'help' || $command === '--help' || $command === '-h') {
    mso2img_usage();
    exit(0);
}

// 配置文件
if (!empty($parsed['options']['config'])) {
    $configFile = $parsed['options']['config'];
    if (!is_file($configFile)) {
        fwrite(STDERR, "配置文件不存在: {$configFile}\n");
        exit(1);
    }
    $config = include $configFile;
    if (is_array($config)) {
        $parsed['options'] = array_merge($config, $parsed['options']);
    }
}

if ($command === 'doctor') {
    $converter = new Mso2Img(mso2img_build_options($parsed['options']));
    $report = $converter->doctor();
    echo "Mso2Img v{$report['version']} 环境自检\n";
    echo str_repeat('-', 60) . "\n";
    foreach ($report['checks'] as $check) {
        $status = $check['ok'] ? '[OK]  ' : '[缺失]';
        echo "{$status} {$check['name']}: {$check['detail']}\n";
        if (!$check['ok'] && $check['hint'] !== '') {
            echo "       -> {$check['hint']}\n";
        }
    }
    echo str_repeat('-', 60) . "\n";
    $ready = $report['soffice'] !== null && !empty($report['rasterizers']);
    echo $ready ? "环境就绪，可以开始转换。\n" : "环境不完整，转换会失败，请按上面的提示安装依赖。\n";
    exit($ready ? 0 : 2);
}

if ($command !== 'convert') {
    fwrite(STDERR, "未知命令: {$command}\n\n");
    mso2img_usage();
    exit(1);
}

if (empty($parsed['args'])) {
    fwrite(STDERR, "请指定要转换的文件或目录。\n\n");
    mso2img_usage();
    exit(1);
}

$input = $parsed['args'][0];
$output = isset($parsed['options']['out']) && $parsed['options']['out'] !== true
    ? $parsed['options']['out']
    : null;
if (isset($parsed['options']['zip']) && $parsed['options']['zip'] !== true) {
    $output = $parsed['options']['zip'];
}

$converter = new Mso2Img(mso2img_build_options($parsed['options']));

$targets = array();
if (is_dir($input)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($input, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && Mso2Img::isSupported($file->getFilename())) {
            $targets[] = $file->getPathname();
        }
    }
    sort($targets);
    if (!$targets) {
        fwrite(STDERR, "目录中没有找到受支持的文档。\n");
        exit(1);
    }
} else {
    $targets[] = $input;
}

$exit = 0;
foreach ($targets as $target) {
    $start = microtime(true);
    $result = $converter->convert($target, $output);
    $elapsed = round(microtime(true) - $start, 2);

    if (!$quiet) {
        echo "\n=== " . $result['input'] . " ===\n";
        foreach ($result['messages'] as $message) {
            echo "  · {$message}\n";
        }
    }

    if ($result['ok']) {
        echo "[成功] " . basename($result['input']) . " -> {$result['pageCount']} 张图片 ({$elapsed}s)\n";
        if (!$quiet) {
            foreach ($result['pages'] as $page) {
                echo "       {$page['name']}\n";
            }
            if ($result['pdf']) {
                echo "       PDF: {$result['pdf']}\n";
            }
            if ($result['zip']) {
                echo "       ZIP: {$result['zip']}\n";
            }
        }
    } else {
        $exit = 1;
        echo "[失败] " . basename($result['input']) . ": " . $result['error'] . "\n";
    }
}

exit($exit);
