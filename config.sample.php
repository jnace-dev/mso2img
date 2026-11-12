<?php
/**
 * 配置示例：复制为 config.php 后按需修改。
 *
 * CLI 用法： php bin/mso2img.php convert ./a.docx --config config.php
 * 代码用法： $config = include 'config.php';
 */
return array(
    // LibreOffice 可执行文件路径；null = 自动探测（PATH + 常见安装目录）
    // Windows 常见值：'C:\Program Files\LibreOffice\program\soffice.com'
    // Linux 常见值：  '/usr/bin/soffice'
    'soffice' => null,

    // PDF 光栅化后端：auto | pdftoppm | pdftocairo | magick | gs
    'rasterizer' => 'auto',

    // 渲染 DPI，越高越清晰、文件越大（150 适合屏幕，300 适合打印）
    'dpi' => 150,

    // 图片格式：png | jpg
    'format' => 'png',

    // JPG 质量 0-100
    'jpegQuality' => 90,

    // 单条外部命令超时（秒）；大文档/慢机器建议 300
    'timeout' => 180,

    // 是否保留中间 PDF
    'keepPdf' => true,

    // 是否打包 ZIP（内含所有页面图片 + 中间 PDF）
    'zip' => true,

    // 临时工作目录；null = 系统临时目录
    'workDir' => null,

    // LibreOffice PDF 过滤器参数（高级用法）
    // Excel 常用「每个工作表输出为一页」：
    // 'pdf:calc_pdf_Export:{"SinglePageSheets":{"type":"boolean","value":"true"}}'
    'pdfFilterOptions' => null,
);
