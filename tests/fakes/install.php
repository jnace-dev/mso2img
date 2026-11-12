<?php
/**
 * 安装「假引擎」到指定目录。
 *
 * 会在目标目录生成 soffice(.cmd) 与 pdftoppm(.cmd) 两个可执行包装器，
 * 把目标目录放进 PATH 后，Mso2Img 就会走与真实环境完全相同的代码路径。
 *
 * 用法： php tests/fakes/install.php <目标目录>
 *      返回：目标目录绝对路径
 */
$target = isset($argv[1]) ? $argv[1] : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mso2img_fakes';
if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
    fwrite(STDERR, "无法创建目录: {$target}\n");
    exit(1);
}

$phpBin = PHP_BINARY;
$here = dirname(__FILE__);

if (DIRECTORY_SEPARATOR === '\\') {
    // 注意用双引号字符串，保证 \r\n 是真实换行而不是字面量
    file_put_contents(
        $target . DIRECTORY_SEPARATOR . 'soffice.cmd',
        "@echo off\r\n\"" . $phpBin . "\" \"" . $here . DIRECTORY_SEPARATOR . "fake_soffice.php\" %*\r\n"
    );
    file_put_contents(
        $target . DIRECTORY_SEPARATOR . 'pdftoppm.cmd',
        "@echo off\r\n\"" . $phpBin . "\" \"" . $here . DIRECTORY_SEPARATOR . "fake_pdftoppm.php\" %*\r\n"
    );
} else {
    file_put_contents(
        $target . DIRECTORY_SEPARATOR . 'soffice',
        "#!/bin/sh\nexec '" . $phpBin . "' '" . $here . "/fake_soffice.php' \"\$@\"\n"
    );
    file_put_contents(
        $target . DIRECTORY_SEPARATOR . 'pdftoppm',
        "#!/bin/sh\nexec '" . $phpBin . "' '" . $here . "/fake_pdftoppm.php' \"\$@\"\n"
    );
    chmod($target . DIRECTORY_SEPARATOR . 'soffice', 0755);
    chmod($target . DIRECTORY_SEPARATOR . 'pdftoppm', 0755);
}

echo realpath($target);
