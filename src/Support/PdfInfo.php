<?php
/**
 * 轻量 PDF 信息解析：只做「页数」和「版本」两件事，不引入额外依赖。
 *
 * 页数统计基于 /Type /Page 对象；遇到使用对象流（xref stream）压缩的 PDF 时
 * 可能统计不到，此时返回 null，由调用方退回「按光栅化产出文件数」判定。
 */
namespace Mso2Img\Support;

class PdfInfo
{
    /**
     * @param string $pdfPath
     * @return array{version:string|null,pages:int|null,encrypted:bool}
     */
    public static function inspect($pdfPath)
    {
        $info = array('version' => null, 'pages' => null, 'encrypted' => false);
        if (!is_file($pdfPath)) {
            return $info;
        }

        $handle = @fopen($pdfPath, 'rb');
        if (!$handle) {
            return $info;
        }
        $head = fread($handle, 1024);
        if (!is_string($head)) {
            fclose($handle);
            return $info;
        }
        if (preg_match('/%PDF-(\d+\.\d+)/', $head, $m)) {
            $info['version'] = $m[1];
        }
        if (strpos($head, '/Encrypt') !== false) {
            $info['encrypted'] = true;
        }
        fclose($handle);

        $content = @file_get_contents($pdfPath);
        if (!is_string($content)) {
            return $info;
        }

        // /Type /Page 但排除 /Type /Pages
        $count = preg_match_all('#/Type\s*/Page(?![sA-Za-z])#', $content);
        if ($count > 0) {
            $info['pages'] = (int) $count;
            return $info;
        }

        // 退化方案：统计 /Count N（页树根节点的页数）
        if (preg_match_all('#/Type\s*/Pages[^>]*?/Count\s+(\d+)#s', $content, $m)) {
            $counts = array_map('intval', $m[1]);
            $info['pages'] = $counts ? max($counts) : null;
        }

        return $info;
    }
}
