<?php
/**
 * 外部可执行程序探测。
 *
 * 依次在：显式配置路径 -> PATH 环境变量 -> 各平台常见安装目录 中查找。
 */
namespace Mso2Img\Support;

class BinaryFinder
{
    /** LibreOffice / OpenOffice 的常见安装位置 */
    public static function sofficeCandidates()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return array(
                'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
                'D:\\Program Files\\LibreOffice\\program\\soffice.exe',
                'C:\\Program Files\\OpenOffice 4\\program\\soffice.exe',
                'C:\\Program Files (x86)\\OpenOffice 4\\program\\soffice.exe',
            );
        }
        return array(
            '/usr/bin/soffice',
            '/usr/local/bin/soffice',
            '/usr/bin/libreoffice',
            '/usr/local/bin/libreoffice',
            '/snap/bin/libreoffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        );
    }

    /** Poppler（pdftoppm / pdftocairo）常见位置 */
    public static function popplerCandidates()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $paths = array();
            foreach (array('C:\\Program Files', 'C:\\Program Files (x86)', 'D:\\Program Files') as $root) {
                $patterns = array($root . '\\poppler*\\Library\\bin', $root . '\\poppler*\\bin', $root . '\\poppler*');
                foreach ($patterns as $pattern) {
                    foreach (glob($pattern) ?: array() as $dir) {
                        $paths[] = $dir . '\\pdftoppm.exe';
                        $paths[] = $dir . '\\pdftocairo.exe';
                    }
                }
            }
            return $paths;
        }
        return array('/usr/bin/pdftoppm', '/usr/local/bin/pdftoppm', '/usr/bin/pdftocairo');
    }

    /** Ghostscript 常见位置 */
    public static function ghostscriptCandidates()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $paths = array();
            foreach (glob('C:\\Program Files\\gs\\*\\bin\\gswin64c.exe') ?: array() as $file) {
                $paths[] = $file;
            }
            foreach (glob('C:\\Program Files (x86)\\gs\\*\\bin\\gswin32c.exe') ?: array() as $file) {
                $paths[] = $file;
            }
            $paths[] = 'C:\\Program Files\\gs\\gs10.04.0\\bin\\gswin64c.exe';
            return $paths;
        }
        return array('/usr/bin/gs', '/usr/local/bin/gs');
    }

    /** ImageMagick 常见位置 */
    public static function imagemagickCandidates()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $paths = array();
            foreach (array('C:\\Program Files\\ImageMagick-*', 'D:\\Program Files\\ImageMagick-*') as $pattern) {
                foreach (glob($pattern) ?: array() as $dir) {
                    $paths[] = $dir . '\\magick.exe';
                    $paths[] = $dir . '\\convert.exe';
                }
            }
            return $paths;
        }
        return array('/usr/bin/magick', '/usr/local/bin/magick', '/usr/bin/convert');
    }

    /**
     * 查找可执行文件。
     *
     * @param string $name      可执行文件名，如 soffice / pdftoppm
     * @param array  $candidates 额外候选路径
     * @return string|null 绝对路径；未找到返回 null
     */
    public static function find($name, array $candidates = array())
    {
        foreach ($candidates as $candidate) {
            if (self::isExecutable($candidate)) {
                return $candidate;
            }
        }
        return self::which($name);
    }

    /** 在 PATH 中查找 */
    public static function which($name)
    {
        if ($name === '' || $name === null) {
            return null;
        }
        if (strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            return self::isExecutable($name) ? $name : null;
        }

        $path = getenv('PATH');
        if ($path === false || $path === '') {
            return null;
        }

        $extensions = (DIRECTORY_SEPARATOR === '\\')
            ? array('', '.exe', '.cmd', '.bat', '.com')
            : array('');

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $dir = trim($dir, '"');
            if ($dir === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $extension;
                if (self::isExecutable($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /** 是否为可执行文件 */
    public static function isExecutable($path)
    {
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return true;
        }
        return is_executable($path);
    }
}
