<?php
/**
 * CSRF 防护
 * --------------------------------------------------------------------------
 * 所有表单必须输出 csrf_field()，并在服务端用 verify_csrf() / require_csrf() 校验。
 * 令牌通过 openssl_random_pseudo_bytes() 生成（PHP 5.6 兼容），
 * 校验使用 hash_equals() 做恒定时间比较。
 */

if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * 输出隐藏的 CSRF 表单域。
     */
    function csrf_field()
    {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf($token)
    {
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            return false;
        }
        if (!is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($_SESSION['_csrf_token'], $token);
    }
}

if (!function_exists('require_csrf')) {
    /**
     * 校验当前 POST 请求的 CSRF 令牌，失败时输出 403 页面并终止。
     */
    function require_csrf()
    {
        $token = isset($_POST['_csrf']) ? $_POST['_csrf'] : '';
        if (!verify_csrf($token)) {
            render_error_page(403, '请求被拒绝', 'CSRF 校验失败，请刷新页面后重试。');
        }
    }
}
