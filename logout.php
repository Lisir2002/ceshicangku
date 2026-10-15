<?php
/**
 * 退出登录：仅接受 POST + CSRF 令牌
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (!request_is_post()) {
    // 非 POST 访问：直接回到合适页面，不做登出动作。
    redirect(is_logged_in() ? '/admin.php' : '/index.php');
}

require_login();
require_csrf();
logout();

redirect('/index.php');
