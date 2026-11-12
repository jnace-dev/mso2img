<?php
/**
 * 纯 PHP ZIP 打包器（ext-zip 不可用时的兜底实现）。
 *
 * 优先使用 ZipArchive；没有扩展时用本类，功能足够：把若干文件打成标准 ZIP。
 */
namespace Mso2Img\Support;

class ZipWriter
{
    /** @var string 目标文件 */
    private $target;
    /** @var resource 文件句柄 */
    private $handle;
    /** @var array 中央目录条目 */
    private $entries = array();
    /** @var int 已写入字节数 */
    private $offset = 0;

    public function __construct($target)
    {
        $this->target = $target;
        $handle = @fopen($target, 'wb');
        if (!$handle) {
            throw new \RuntimeException('无法写入 ZIP 文件: ' . $target);
        }
        $this->handle = $handle;
    }

    /** 添加一个文件（entryName 为 ZIP 内路径） */
    public function addFile($sourcePath, $entryName = null)
    {
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('待打包文件不存在: ' . $sourcePath);
        }
        $entryName = $entryName === null ? basename($sourcePath) : str_replace('\\', '/', $entryName);
        $data = file_get_contents($sourcePath);
        if ($data === false) {
            throw new \RuntimeException('读取文件失败: ' . $sourcePath);
        }
        $this->addString($data, $entryName);
    }

    /** 添加内存中的内容 */
    public function addString($data, $entryName)
    {
        $entryName = str_replace('\\', '/', $entryName);
        $crc = crc32($data);
        $uncompressedSize = strlen($data);
        $compressed = function_exists('gzdeflate') ? gzdeflate($data, 6) : false;
        $useDeflate = ($compressed !== false && strlen($compressed) < $uncompressedSize);
        $payload = $useDeflate ? $compressed : $data;
        $method = $useDeflate ? 8 : 0;
        $compressedSize = strlen($payload);

        $nameBytes = $entryName;
        $flags = 0x0800; // UTF-8 文件名
        $dosTime = self::dosTime();
        $dosDate = self::dosDate();

        $localHeader = pack('VvvvvvVVVvv',
            0x04034b50,
            20,               // version needed
            $flags,
            $method,
            $dosTime,
            $dosDate,
            $crc,
            $compressedSize,
            $uncompressedSize,
            strlen($nameBytes),
            0
        );
        $this->write($localHeader . $nameBytes . $payload);

        $this->entries[] = array(
            'name' => $nameBytes,
            'flags' => $flags,
            'method' => $method,
            'dosTime' => $dosTime,
            'dosDate' => $dosDate,
            'crc' => $crc,
            'compressedSize' => $compressedSize,
            'uncompressedSize' => $uncompressedSize,
            'offset' => $this->offset - strlen($localHeader) - strlen($nameBytes) - strlen($payload),
        );
    }

    /** 写中央目录并关闭 */
    public function close()
    {
        $centralStart = $this->offset;
        foreach ($this->entries as $entry) {
            $header = pack('VvvvvvvVVVvvvvvVV',
                0x02014b50,
                0x031e,          // version made by (UNIX)
                20,              // version needed
                $entry['flags'],
                $entry['method'],
                $entry['dosTime'],
                $entry['dosDate'],
                $entry['crc'],
                $entry['compressedSize'],
                $entry['uncompressedSize'],
                strlen($entry['name']),
                0,               // extra len
                0,               // comment len
                0,               // disk number
                0,               // internal attrs
                0100644 << 16,   // external attrs (0644)
                $entry['offset']
            );
            $this->write($header . $entry['name']);
        }
        $centralSize = $this->offset - $centralStart;
        $end = pack('VvvvvVVv',
            0x06054b50,
            0,
            0,
            count($this->entries),
            count($this->entries),
            $centralSize,
            $centralStart,
            0
        );
        $this->write($end);
        fclose($this->handle);
        $this->handle = null;
    }

    private function write($bytes)
    {
        $written = fwrite($this->handle, $bytes);
        if ($written === false) {
            throw new \RuntimeException('ZIP 写入失败: ' . $this->target);
        }
        $this->offset += $written;
    }

    private static function dosTime()
    {
        $parts = getdate();
        return ($parts['hours'] << 11) | ($parts['minutes'] << 5) | ($parts['seconds'] >> 1);
    }

    private static function dosDate()
    {
        $parts = getdate();
        return (($parts['year'] - 1980) << 9) | ($parts['mon'] << 5) | $parts['mday'];
    }
}
