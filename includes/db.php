<?php
/**
 * 数据库连接
 * --------------------------------------------------------------------------
 * db() 返回进程内共享的 mysqli 连接，建连后立即 set_charset('utf8mb4')。
 * 所有 SQL 一律使用预处理语句（prepare + bind_param），禁止拼接 SQL。
 */

if (!function_exists('db')) {
    function db()
    {
        static $conn = null;

        if ($conn instanceof mysqli) {
            return $conn;
        }

        $cfg = config('db', []);

        $host    = isset($cfg['host']) ? $cfg['host'] : '127.0.0.1';
        $port    = isset($cfg['port']) ? (int) $cfg['port'] : 3306;
        $name    = isset($cfg['name']) ? $cfg['name'] : '';
        $user    = isset($cfg['user']) ? $cfg['user'] : '';
        $pass    = isset($cfg['pass']) ? $cfg['pass'] : '';
        $charset = isset($cfg['charset']) ? $cfg['charset'] : 'utf8mb4';

        $conn = @new mysqli($host, $user, $pass, $name, $port);

        if ($conn->connect_errno) {
            error_log('[db] connect error: ' . $conn->connect_error);
            render_error_page(500, '服务暂不可用', '数据库连接失败，请稍后重试。');
        }

        $conn->set_charset($charset);

        return $conn;
    }
}
