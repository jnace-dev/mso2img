<?php
/**
 * 假 soffice：把输入文档换成一个 N 页 PDF，用于在没有 LibreOffice 的环境里验证转换链路。
 *
 * 用法： php fake_soffice.php --headless ... --convert-to pdf --outdir <目录> <源文件>
 */
$args = array_slice($argv, 1);
$outDir = null;
$source = null;
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--outdir') {
        $outDir = isset($args[$i + 1]) ? $args[$i + 1] : null;
        $i++;
    } elseif (strpos($args[$i], '--') !== 0) {
        $source = $args[$i];
    }
}

if (!$outDir || !$source) {
    fwrite(STDERR, "fake soffice: 参数不完整\n");
    exit(2);
}
if (!is_file($source)) {
    fwrite(STDERR, "fake soffice: 找不到源文件 " . $source . "\n");
    exit(3);
}

$pdf = mso2img_fake_pdf(3);
file_put_contents(rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . 'source.pdf', $pdf);
echo "convert " . basename($source) . " -> source.pdf\n";
exit(0);

/** 生成一个结构完整的 N 页 PDF（页数会被 PdfInfo 正确识别） */
function mso2img_fake_pdf($pages)
{
    $objects = array();
    $pageIds = array();
    $objNum = 3;
    for ($i = 0; $i < $pages; $i++) {
        $pageIds[] = $objNum;
        $objNum += 2;
    }
    $fontObj = $objNum;

    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $kidList = array();
    foreach ($pageIds as $pid) {
        $kidList[] = $pid . ' 0 R';
    }
    $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kidList) . "] /Count " . $pages . " >>";

    $n = 0;
    foreach ($pageIds as $pid) {
        $contentId = $pid + 1;
        $text = "BT /F1 24 Tf 80 700 Td (Fake page " . ($n + 1) . ") Tj ET";
        $objects[$pid] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
            . "/Contents " . $contentId . " 0 R /Resources << /Font << /F1 " . $fontObj . " 0 R >> >> >>";
        $objects[$contentId] = "<< /Length " . strlen($text) . " >>\nstream\n" . $text . "\nendstream";
        $n++;
    }
    $objects[$fontObj] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

    $pdf = "%PDF-1.4\n";
    $offsets = array();
    ksort($objects);
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xrefPos = strlen($pdf);
    $maxNum = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxNum + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $maxNum; $i++) {
        if (isset($offsets[$i])) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        } else {
            $pdf .= "0000000000 65535 f \n";
        }
    }
    $pdf .= "trailer\n<< /Size " . ($maxNum + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF\n";
    return $pdf;
}
