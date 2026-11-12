<?php
/**
 * 环境自检工具：CLI 输出文本，浏览器访问输出 HTML 报告。
 *
 *   php tools/doctor.php
 *   http://localhost/mso2img/tools/doctor.php
 */
$root = dirname(dirname(__FILE__));
require_once $root . '/src/Support/Process.php';
require_once $root . '/src/Support/BinaryFinder.php';
require_once $root . '/src/Support/PdfInfo.php';
require_once $root . '/src/Support/ZipWriter.php';
require_once $root . '/src/Mso2Img.php';

use Mso2Img\Mso2Img;

$converter = new Mso2Img();
$report = $converter->doctor();

if (PHP_SAPI === 'cli') {
    echo "Mso2Img v{$report['version']} 环境自检\n";
    echo str_repeat('-', 62) . "\n";
    foreach ($report['checks'] as $check) {
        echo ($check['ok'] ? '[OK]   ' : '[缺失] ') . $check['name'] . ': ' . $check['detail'] . "\n";
        if (!$check['ok'] && $check['hint'] !== '') {
            echo '        -> ' . $check['hint'] . "\n";
        }
    }
    echo str_repeat('-', 62) . "\n";
    $ready = $report['soffice'] !== null && !empty($report['rasterizers']);
    echo $ready ? "环境就绪。\n" : "环境不完整，转换会失败，请按上面提示安装依赖。\n";
    exit($ready ? 0 : 2);
}

header('Content-Type: text/html; charset=utf-8');
$ready = $report['soffice'] !== null && !empty($report['rasterizers']);
$rows = '';
foreach ($report['checks'] as $check) {
    $rows .= '<tr><td>' . ($check['ok'] ? '<span class="ok">OK</span>' : '<span class="bad">缺失</span>') . '</td>'
        . '<td>' . htmlspecialchars($check['name']) . '</td>'
        . '<td>' . htmlspecialchars($check['detail']) . '</td>'
        . '<td>' . htmlspecialchars($check['ok'] ? '' : $check['hint']) . '</td></tr>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>Mso2Img 环境自检</title>
<style>
body{font-family:-apple-system,"Segoe UI","Microsoft YaHei",sans-serif;background:#f5f6f8;margin:0;padding:32px;color:#222}
.card{max-width:900px;margin:0 auto;background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:24px}
h1{font-size:20px;margin:0 0 4px}
.sub{color:#666;font-size:13px;margin-bottom:18px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:left;padding:9px 10px;border-bottom:1px solid #eee;vertical-align:top}
th{background:#fafafa;color:#555;font-weight:600}
.ok{color:#16a34a;font-weight:700}
.bad{color:#dc2626;font-weight:700}
.banner{padding:12px 14px;border-radius:8px;margin-bottom:18px;font-weight:600}
.good{background:#dcfce7;color:#166534}
.poor{background:#fee2e2;color:#991b1b}
code{background:#f3f4f6;padding:1px 6px;border-radius:4px;font-size:12px}
</style>
</head>
<body>
<div class="card">
    <h1>Mso2Img v<?php echo htmlspecialchars($report['version']); ?> 环境自检</h1>
    <div class="sub">PHP <?php echo htmlspecialchars(PHP_VERSION); ?> / <?php echo htmlspecialchars(PHP_SAPI); ?> / <?php echo htmlspecialchars(PHP_OS); ?></div>
    <div class="banner <?php echo $ready ? 'good' : 'poor'; ?>">
        <?php echo $ready ? '环境就绪，可以开始转换文档。' : '环境不完整，转换会失败，请按下表提示安装依赖。'; ?>
    </div>
    <table>
        <tr><th>状态</th><th>组件</th><th>检测结果</th><th>处理建议</th></tr>
        <?php echo $rows; ?>
    </table>
</div>
</body>
</html>
