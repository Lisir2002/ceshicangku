<?php
/**
 * 应用引导
 * --------------------------------------------------------------------------
 * 页面统一在首行 require 本文件：
 *   1) 加载配置（config.php，缺失时回退 config.sample.php）
 *   2) 加载公共 include（helpers / csrf / db / auth）
 *   3) 设置时区
 *   4) 配置会话安全参数并启动会话（session.cookie_httponly 等必须在
 *      session_start() 之前设置）
 */

if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

// 1. 加载配置
$__config_file = __DIR__ . '/../config.php';
$GLOBALS['APP_CONFIG'] = require $__config_file;

// 2. 加载公共 include（顺序：先 helpers，因为 config() 定义于此）
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lab-mode.php';
require_once __DIR__ . '/auth.php';

// 3. 时区
date_default_timezone_set(config('app.timezone', 'Asia/Shanghai'));

// 4. 会话安全配置与启动
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

$__session_name = config('app.session_name', 'APPSESSID');
if ($__session_name) {
    session_name($__session_name);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
