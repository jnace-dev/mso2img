<?php
/**
 * 假 pdftoppm：把 PDF 拆成 N 张 PNG，用于在没有 Poppler 的环境里验证光栅化链路。
 *
 * 用法： php fake_pdftoppm.php -png -r <dpi> ... <pdf> <输出前缀>
 */
$args = array_slice($argv, 1);
$dpi = 96;
$input = null;
$prefix = null;
$withValue = array('-r', '-aa', '-aaVector', '-f', '-l', '-scale-to', '-cropbox');

for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if (in_array($arg, $withValue, true)) {
        if ($arg === '-r') {
            $dpi = (int) $args[$i + 1];
        }
        $i++;
        continue;
    }
    if (strpos($arg, '-') === 0) {
        continue;
    }
    if ($input === null) {
        $input = $arg;
    } else {
        $prefix = $arg;
    }
}

if (!$prefix) {
    fwrite(STDERR, "fake pdftoppm: 参数不完整\n");
    exit(2);
}
if (!$input || !is_file($input)) {
    fwrite(STDERR, "fake pdftoppm: 找不到输入 " . $input . "\n");
    exit(3);
}

$pageCount = 3;
$content = file_get_contents($input);
if (preg_match_all('#/Type\s*/Page(?![sA-Za-z])#', $content, $m)) {
    $pageCount = max(1, count($m[0]));
}

$w = (int) round(595 / 72 * $dpi);
$h = (int) round(842 / 72 * $dpi);
for ($p = 1; $p <= $pageCount; $p++) {
    $image = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($image, 255, 255, 255);
    $fg = imagecolorallocate($image, 20, 20, 20);
    imagefill($image, 0, 0, $bg);
    imagestring($image, 5, 20, 20, "Fake page $p", $fg);
    imagepng($image, $prefix . '-' . $p . '.png');
    imagedestroy($image);
}
echo "rendered $pageCount pages\n";
exit(0);
