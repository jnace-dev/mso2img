<?php
/**
 * 冒烟测试：用假引擎跑通完整链路，不依赖 LibreOffice / Poppler。
 *
 * 原理：
 *  - 假的 soffice：把输入文件换成一个多页 PDF；
 *  - 假的 pdftoppm：把 PDF 拆成 N 张 PNG；
 *  - 两者都通过 PATH 注入，程序因此走的是与真实环境完全相同的代码路径。
 *
 * 运行： php tests/smoke.php
 */
$root = dirname(dirname(__FILE__));
require_once $root . '/src/Support/Process.php';
require_once $root . '/src/Support/BinaryFinder.php';
require_once $root . '/src/Support/PdfInfo.php';
require_once $root . '/src/Support/ZipWriter.php';
require_once $root . '/src/Mso2Img.php';

use Mso2Img\Mso2Img;

$pass = 0;
$fail = 0;

function check($label, $condition, $detail = '')
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  [通过] {$label}\n";
    } else {
        $fail++;
        echo "  [失败] {$label}" . ($detail !== '' ? ' —— ' . $detail : '') . "\n";
    }
}

// ------------------------------------------------------------------
// 1. 准备假引擎
// ------------------------------------------------------------------
$fakeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mso2img_fake_' . getmypid();
if (!is_dir($fakeDir)) {
    mkdir($fakeDir, 0777, true);
}

$phpBin = PHP_BINARY;
$isWindows = DIRECTORY_SEPARATOR === '\\';

// 用共享的假引擎安装脚本（tests/fakes/install.php）
$fakeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mso2img_fake_' . getmypid();
@mkdir($fakeDir, 0777, true);
$installOutput = array();
$installCode = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__FILE__) . '/fakes/install.php') . ' ' . escapeshellarg($fakeDir), $installOutput, $installCode);
if ($installCode !== 0) {
    fwrite(STDERR, "假引擎安装失败\n");
    exit(1);
}

$oldPath = getenv('PATH');
putenv('PATH=' . $fakeDir . PATH_SEPARATOR . $oldPath);

echo "== 假引擎注入: {$fakeDir}\n\n";

// ------------------------------------------------------------------
// 2. 准备输入文件
// ------------------------------------------------------------------
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mso2img_test_' . getmypid();
Mso2Img::removeTree($work);
mkdir($work, 0777, true);

$sampleDocx = $root . '/samples/mso2img_sample.docx';
if (!is_file($sampleDocx)) {
    // 现场生成一个最小 docx
    $zip = new ZipArchive();
    $zip->open($sampleDocx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>test</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
}
$inputDocx = $work . '/测试文档.docx';
copy($sampleDocx, $inputDocx);

// ------------------------------------------------------------------
// 3. 端到端转换
// ------------------------------------------------------------------
echo "== 端到端转换（PNG + ZIP）\n";
$converter = new Mso2Img(array('dpi' => 96));
$result = $converter->convert($inputDocx, $work . '/out');

check('转换成功', $result['ok'], $result['error']);
check('页数为 3', $result['pageCount'] === 3, '实际 ' . $result['pageCount']);
check('生成了 3 个页面文件', count($result['pages']) === 3);
check('页面文件按顺序命名', isset($result['pages'][0])
    && $result['pages'][0]['name'] === '测试文档_p001.png'
    && $result['pages'][2]['name'] === '测试文档_p003.png',
    isset($result['pages'][0]) ? $result['pages'][0]['name'] : '-');
check('页面文件真实存在', $result['ok']
    && is_file($result['pages'][0]['path'])
    && filesize($result['pages'][0]['path']) > 0);
check('保留了中间 PDF', !empty($result['pdf']) && is_file($result['pdf']));
check('生成了 ZIP', !empty($result['zip']) && is_file($result['zip']));
check('记录了执行过的命令', count($result['commands']) >= 2);
check('命令日志含 soffice', strpos(implode('|', $result['commands']), 'soffice') !== false);

// ZIP 内容校验
if (!empty($result['zip'])) {
    $entries = array();
    $opened = false;
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($result['zip']) === true) {
            $opened = true;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entries[] = $zip->getNameIndex($i);
            }
            $zip->close();
        }
    }
    if ($opened) {
        check('ZIP 内含 3 张图 + 1 个 PDF', count($entries) === 4, '实际 ' . count($entries) . ': ' . implode(',', $entries));
    } else {
        // 没有 ext-zip、或部分 PHP 版本读不了非 ASCII 路径下的 ZIP：直接解析中央目录
        $zipData = file_get_contents($result['zip']);
        $count = preg_match_all('#PK\x01\x02#', $zipData, $m);
        check('ZIP 中央目录含 4 个条目', $count === 4, '实际 ' . $count);
        check('ZIP 内含图片文件名', strpos($zipData, '_p001.png') !== false);
        check('ZIP 内含 PDF 文件名', strpos($zipData, '.pdf') !== false);
    }
}

// ------------------------------------------------------------------
// 4. JPG 输出
// ------------------------------------------------------------------
echo "\n== JPG 输出\n";
$converterJpg = new Mso2Img(array('format' => 'jpg', 'dpi' => 96));
$resultJpg = $converterJpg->convert($inputDocx, $work . '/out_jpg');
check('JPG 转换成功', $resultJpg['ok'], $resultJpg['error']);
check('产出 .jpg 文件', $resultJpg['ok']
    && strtolower(pathinfo($resultJpg['pages'][0]['name'], PATHINFO_EXTENSION)) === 'jpg',
    $resultJpg['ok'] ? $resultJpg['pages'][0]['name'] : '-');

// ------------------------------------------------------------------
// 5. 只出 PDF
// ------------------------------------------------------------------
echo "\n== 只出 PDF 模式\n";
$converterPdf = new Mso2Img(array('pdfOnly' => true));
$resultPdf = $converterPdf->convert($inputDocx, $work . '/out_pdf');
check('PDF 模式成功', $resultPdf['ok'], $resultPdf['error']);
check('PDF 文件存在', !empty($resultPdf['pdf']) && is_file($resultPdf['pdf']));
check('PDF 模式不产出图片', empty($resultPdf['pages']));

// ------------------------------------------------------------------
// 6. 错误处理
// ------------------------------------------------------------------
echo "\n== 错误处理\n";
$badFile = $work . '/bad.xyz';
file_put_contents($badFile, 'hello');
$badResult = $converter->convert($badFile, $work . '/out_bad');
check('拒绝不支持的扩展名', !$badResult['ok'] && strpos($badResult['error'], '不支持') !== false);

$missingResult = $converter->convert($work . '/not_exist.docx', $work . '/out_missing');
check('文件不存在时报错', !$missingResult['ok'] && strpos($missingResult['error'], '不存在') !== false);

// ------------------------------------------------------------------
// 7. 批量转换
// ------------------------------------------------------------------
echo "\n== 批量转换\n";
// 中文多层路径：整条路径归一化后使用，目录与文件保持同一套字节
$cnRoot = Mso2Img::normalizePath($work . DIRECTORY_SEPARATOR . '批量目录');
$cnSub = Mso2Img::ensureDirectory(Mso2Img::normalizePath(
    $work . DIRECTORY_SEPARATOR . '批量目录' . DIRECTORY_SEPARATOR . '子目录'
));
check('逐级建目录支持中文多层路径', $cnSub !== false);
$cnDir = Mso2Img::ensureDirectory($cnRoot);
$cnFileA = Mso2Img::normalizePath(
    $work . DIRECTORY_SEPARATOR . '批量目录' . DIRECTORY_SEPARATOR . '子目录' . DIRECTORY_SEPARATOR . '第二个.docx'
);
$cnFileB = Mso2Img::normalizePath(
    $work . DIRECTORY_SEPARATOR . '批量目录' . DIRECTORY_SEPARATOR . '第一个.docx'
);
copy($sampleDocx, $cnFileA);
copy($sampleDocx, $cnFileB);

$batch = $converter->convertBatch($cnDir, $work . '/out_batch');
check('批量转换处理了全部文件', count($batch) === 2, '实际 ' . count($batch));
check('批量结果全部成功', $batch && $batch[0]['ok'] && $batch[1]['ok']);

// ------------------------------------------------------------------
// 8. 纯 PHP ZIP 打包器单独校验
// ------------------------------------------------------------------
echo "\n== 纯 PHP ZIP 打包器\n";
$zipTarget = $work . '/pure.zip';
$writer = new \Mso2Img\Support\ZipWriter($zipTarget);
$writer->addString('hello mso2img', 'readme.txt');
$writer->addString(str_repeat('A', 5000), 'big.txt');
$writer->close();
$isValid = file_exists($zipTarget) && filesize($zipTarget) > 0;
if ($isValid && class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    $openOk = $zip->open($zipTarget) === true;
    if ($openOk) {
        $isValid = $zip->numFiles === 2
            && $zip->getFromName('readme.txt') === 'hello mso2img'
            && strlen($zip->getFromName('big.txt')) === 5000;
        $zip->close();
    } else {
        $isValid = false;
    }
}
check('纯 PHP ZIP 可被 ZipArchive 正常读取', $isValid);

// ------------------------------------------------------------------
// 9. PDF 页数解析
// ------------------------------------------------------------------
echo "\n== PDF 页数解析\n";
if (!empty($result['pdf'])) {
    $meta = \Mso2Img\Support\PdfInfo::inspect($result['pdf']);
    check('解析出 3 页', $meta['pages'] === 3, '实际 ' . var_export($meta['pages'], true));
    check('识别 PDF 版本', $meta['version'] === '1.4', var_export($meta['version'], true));
}

// ------------------------------------------------------------------
// 10. 命令行入口
// ------------------------------------------------------------------
echo "\n== 命令行入口\n";
$cli = $root . '/bin/mso2img.php';
$cliOut = $work . DIRECTORY_SEPARATOR . 'cli_out';
$cliLines = array();
$cliCode = -1;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' convert '
    . escapeshellarg($sampleDocx) . ' -o ' . escapeshellarg($cliOut) . ' -q 2>&1',
    $cliLines,
    $cliCode
);
$cliText = implode("\n", $cliLines);
check('CLI 转换退出码为 0', $cliCode === 0, $cliText);
check('CLI 产出 3 张 PNG', count(glob($cliOut . DIRECTORY_SEPARATOR . '*.png')) === 3);
check('CLI 产出 1 个 ZIP', count(glob($cliOut . DIRECTORY_SEPARATOR . '*.zip')) === 1);

$doctorLines = array();
$doctorCode = -1;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' doctor 2>&1', $doctorLines, $doctorCode);
check('CLI doctor 可执行', $doctorCode === 0 || $doctorCode === 2, implode("\n", $doctorLines));
check('doctor 输出含自检结果', strpos(implode("\n", $doctorLines), '环境自检') !== false);

$helpLines = array();
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli) . ' help 2>&1', $helpLines, $helpCode);
check('CLI help 可执行', $helpCode === 0 && strpos(implode("\n", $helpLines), 'convert') !== false);

// ------------------------------------------------------------------
// 收尾
// ------------------------------------------------------------------
Mso2Img::removeTree($work);
Mso2Img::removeTree($fakeDir);
putenv('PATH=' . $oldPath);

echo "\n==================================================\n";
echo "通过 {$pass} 项，失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);
