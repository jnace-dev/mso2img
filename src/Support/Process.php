<?php
/**
 * 跨平台子进程执行封装。
 *
 * 设计要点：
 *  - 使用 proc_open，支持超时强制终止（Windows 下终止整棵进程树）；
 *  - Windows 走 cmd.exe，需要对整条命令行额外包一层引号，否则含空格的
 *    可执行文件路径（C:\Program Files\...）会被 cmd 截断；
 *  - 同时捕获 stdout / stderr，并保留完整命令行用于日志与排障。
 */
namespace Mso2Img\Support;

class ProcessResult
{
    /** @var string 实际执行的命令行 */
    public $command = '';
    /** @var int 退出码，超时或启动失败为 -1 */
    public $exitCode = -1;
    /** @var string 标准输出 */
    public $stdout = '';
    /** @var string 标准错误 */
    public $stderr = '';
    /** @var bool 是否因超时被终止 */
    public $timedOut = false;
    /** @var bool 是否成功启动 */
    public $started = false;
    /** @var float 耗时（秒） */
    public $duration = 0.0;

    public function ok()
    {
        return $this->started && !$this->timedOut && $this->exitCode === 0;
    }

    /** 合并后的可读日志 */
    public function output()
    {
        $out = trim($this->stdout);
        $err = trim($this->stderr);
        if ($out !== '' && $err !== '') {
            return $out . "\n" . $err;
        }
        return $out !== '' ? $out : $err;
    }
}

class Process
{
    /**
     * 执行一条命令。
     *
     * @param string|array $command  字符串命令行，或参数数组（自动按平台加引号）
     * @param array        $options timeout(秒)、cwd、env
     * @return ProcessResult
     */
    public static function run($command, array $options = array())
    {
        $result = new ProcessResult();
        $result->command = is_array($command) ? self::build($command) : $command;

        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 180;
        $cwd = isset($options['cwd']) ? $options['cwd'] : null;
        $env = isset($options['env']) ? $options['env'] : null;

        if (!function_exists('proc_open')) {
            $result->stderr = 'PHP 禁用了 proc_open，无法调用外部程序。';
            return $result;
        }

        // PHP >= 7.4 支持把参数数组直接交给 proc_open：完全绕开 cmd.exe 的引号解析，
        // 含空格/引号的路径与参数都能原样送达，是最稳的调用方式。
        // PHP < 7.4 只能传字符串，此时在 Windows 上额外包一层引号，
        // 避免 cmd.exe /c 把首尾引号吃掉导致 "C:\Program Files\..." 被截断。
        if (is_array($command) && version_compare(PHP_VERSION, '7.4.0', '>=')) {
            $cmdline = $command;
        } else {
            $cmdline = is_array($command) ? self::build($command) : $command;
            if (DIRECTORY_SEPARATOR === '\\') {
                $cmdline = '"' . $cmdline . '"';
            }
        }

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $process = @proc_open($cmdline, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            $result->stderr = '无法启动进程: ' . $cmdline;
            return $result;
        }
        $result->started = true;

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $start = microtime(true);

        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if ((microtime(true) - $start) > $timeout) {
                $result->timedOut = true;
                self::terminate($process, isset($status['pid']) ? $status['pid'] : 0);
                usleep(300000);
                break;
            }

            usleep(20000);
        }

        // 收尾再抽一次，防止最后一次写入丢失
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result->stdout = $stdout;
        $result->stderr = $stderr;
        $result->exitCode = $result->timedOut ? -1 : $exitCode;
        $result->duration = round(microtime(true) - $start, 3);

        return $result;
    }

    /**
     * 终止进程。Windows 下 taskkill /T 会连同 soffice.bin 等子进程一起结束，
     * 避免残留的 LibreOffice 进程占住用户配置目录。
     */
    private static function terminate($process, $pid)
    {
        if (DIRECTORY_SEPARATOR === '\\' && $pid > 0) {
            @exec('taskkill /F /T /PID ' . (int) $pid . ' 2>nul');
        }
        @proc_terminate($process, 9);
    }

    /** 参数数组 -> 命令行字符串 */
    public static function build(array $args)
    {
        $parts = array();
        foreach ($args as $arg) {
            $parts[] = self::quote((string) $arg);
        }
        return implode(' ', $parts);
    }

    /** 单个参数按平台加引号 */
    public static function quote($arg)
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            if ($arg === '') {
                return '""';
            }
            if (preg_match('/^[A-Za-z0-9_\-\.\\\\\/]+$/', $arg)) {
                return $arg;
            }
            return '"' . str_replace('"', '\"', $arg) . '"';
        }
        return escapeshellarg($arg);
    }
}
