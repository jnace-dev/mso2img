<?php
/**
 * 生成测试用的最小 Office 文档（docx / xlsx / pptx）。
 *
 * 用途：在没有 Office、也没装 LibreOffice 的情况下也能验证
 * 「文件类型识别 / 转换链路 / 打包」这些逻辑是否正常。
 *
 *   php tools/make_samples.php [输出目录]
 */
$root = dirname(dirname(__FILE__));

$target = isset($argv[1]) ? $argv[1] : $root . '/samples';
if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
    fwrite(STDERR, "无法创建目录: {$target}\n");
    exit(1);
}

/**
 * 把一组 路径 => 内容 写成 ZIP（OOXML 包）。
 */
function mso2img_write_ooxml($path, array $parts)
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('无法创建: ' . $path);
    }
    foreach ($parts as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    return filesize($path);
}

$xmlHeader = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n";

// ---------------------------------------------------------------- DOCX
$docxParts = array(
    '[Content_Types].xml' => $xmlHeader . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>',
    '_rels/.rels' => $xmlHeader . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>',
    'word/document.xml' => $xmlHeader
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>'
        . '<w:p><w:r><w:t>Mso2Img 测试文档</w:t></w:r></w:p>'
        . '<w:p><w:r><w:t>这是第一页的内容，用于验证分页与渲染是否正常。</w:t></w:r></w:p>'
        . '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
        . '<w:p><w:r><w:t>这是第二页，页码由 LibreOffice 渲染后生成。</w:t></w:r></w:p>'
        . '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
        . '<w:p><w:r><w:t>第三页结束。</w:t></w:r></w:p>'
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
        . '</w:body></w:document>',
);

// ---------------------------------------------------------------- XLSX
$xlsxParts = array(
    '[Content_Types].xml' => $xmlHeader . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>',
    '_rels/.rels' => $xmlHeader . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>',
    'xl/workbook.xml' => $xmlHeader
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="销售表" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>',
    'xl/_rels/workbook.xml.rels' => $xmlHeader
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>',
    'xl/worksheets/sheet1.xml' => $xmlHeader
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="inlineStr"><is><t>产品</t></is></c><c r="B1" t="inlineStr"><is><t>数量</t></is></c>'
        . '<c r="C1" t="inlineStr"><is><t>单价</t></is></c></row>'
        . '<row r="2"><c r="A2" t="inlineStr"><is><t>显示器</t></is></c><c r="B2"><v>12</v></c><c r="C2"><v>1299</v></c></row>'
        . '<row r="3"><c r="A3" t="inlineStr"><is><t>键盘</t></is></c><c r="B3"><v>40</v></c><c r="C3"><v>199</v></c></row>'
        . '<row r="4"><c r="A4" t="inlineStr"><is><t>鼠标</t></is></c><c r="B4"><v>60</v></c><c r="C4"><v>89</v></c></row>'
        . '</sheetData></worksheet>',
);

// ---------------------------------------------------------------- PPTX
$slideRels = $xmlHeader . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
    . '</Relationships>';

$slide = function ($index, $title) use ($xmlHeader) {
    return $xmlHeader
        . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld><p:spTree>'
        . '<p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr/>'
        . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="标题"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
        . '<p:spPr><a:xfrm><a:off x="838200" y="1825625"/><a:ext cx="7467600" cy="1325563"/></a:xfrm></p:spPr>'
        . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="zh-CN" sz="3200"/>'
        . '<a:t>' . htmlspecialchars($title, ENT_XML1, 'UTF-8') . '</a:t></a:r></a:p></p:txBody>'
        . '</p:sp>'
        . '</p:spTree></p:cSld><p:clrMapOvr><a:overrideClrMapping/></p:clrMapOvr></p:sld>';
};

$pptxParts = array(
    '[Content_Types].xml' => $xmlHeader . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
        . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
        . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
        . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
        . '<Override PartName="/ppt/slides/slide1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>'
        . '<Override PartName="/ppt/slides/slide2.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>'
        . '</Types>',
    '_rels/.rels' => $xmlHeader . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
        . '</Relationships>',
    'ppt/presentation.xml' => $xmlHeader
        . '<p:presentation xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
        . '<p:sldIdLst><p:sldId id="256" r:id="rId2"/><p:sldId id="257" r:id="rId3"/></p:sldIdLst>'
        . '<p:sldSz cx="9144000" cy="6858000"/><p:notesSz cx="6858000" cy="9144000"/>'
        . '</p:presentation>',
    'ppt/_rels/presentation.xml.rels' => $xmlHeader
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide2.xml"/>'
        . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>'
        . '</Relationships>',
    'ppt/slideMasters/slideMaster1.xml' => $xmlHeader
        . '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr/></p:spTree></p:cSld>'
        . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2"'
        . ' accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6"'
        . ' hlink="hlink" folHlink="folHlink"/>'
        . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
        . '</p:sldMaster>',
    'ppt/slideMasters/_rels/slideMaster1.xml.rels' => $xmlHeader
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>'
        . '</Relationships>',
    'ppt/slideLayouts/slideLayout1.xml' => $xmlHeader
        . '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank">'
        . '<p:cSld name="空白"><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr/></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>',
    'ppt/slideLayouts/_rels/slideLayout1.xml.rels' => $xmlHeader
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'
        . '</Relationships>',
    'ppt/theme/theme1.xml' => $xmlHeader
        . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office">'
        . '<a:themeElements><a:clrScheme name="Office">'
        . '<a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1>'
        . '<a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>'
        . '<a:dk2><a:srgbClr val="44546A"/></a:dk2><a:lt2><a:srgbClr val="E7E6E6"/></a:lt2>'
        . '<a:accent1><a:srgbClr val="4472C4"/></a:accent1><a:accent2><a:srgbClr val="ED7D31"/></a:accent2>'
        . '<a:accent3><a:srgbClr val="A5A5A5"/></a:accent3><a:accent4><a:srgbClr val="FFC000"/></a:accent4>'
        . '<a:accent5><a:srgbClr val="5B9BD5"/></a:accent5><a:accent6><a:srgbClr val="70AD47"/></a:accent6>'
        . '<a:hlink><a:srgbClr val="0563C1"/></a:hlink><a:folHlink><a:srgbClr val="954F72"/></a:folHlink>'
        . '</a:clrScheme>'
        . '<a:fontScheme name="Office"><a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface="宋体"/><a:cs typeface=""/></a:majorFont>'
        . '<a:minorFont><a:latin typeface="Calibri"/><a:ea typeface="宋体"/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
        . '<a:fmtScheme name="Office">'
        . '<a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>'
        . '<a:lnStyleLst><a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
        . '<a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
        . '<a:ln w="19050"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst>'
        . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle>'
        . '<a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
        . '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst>'
        . '</a:fmtScheme></a:themeElements></a:theme>',
    'ppt/slides/slide1.xml' => $slide(1, 'Mso2Img 测试演示'),
    'ppt/slides/slide2.xml' => $slide(2, '第二页幻灯片'),
    'ppt/slides/_rels/slide1.xml.rels' => $slideRels,
    'ppt/slides/_rels/slide2.xml.rels' => $slideRels,
);

$targets = array(
    'mso2img_sample.docx' => $docxParts,
    'mso2img_sample.xlsx' => $xlsxParts,
    'mso2img_sample.pptx' => $pptxParts,
);

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "缺少 ext-zip，无法生成 OOXML 样例文件。\n");
    exit(1);
}

foreach ($targets as $name => $parts) {
    $path = $target . DIRECTORY_SEPARATOR . $name;
    try {
        $size = mso2img_write_ooxml($path, $parts);
        echo "已生成: {$path} ({$size} bytes)\n";
    } catch (Exception $e) {
        fwrite(STDERR, '生成失败: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "\n提示: 这些是结构最简的测试文档；若本机装了 LibreOffice，可直接转换验证链路：\n";
echo "  php bin/mso2img.php convert {$target} -o {$target}/out\n";
