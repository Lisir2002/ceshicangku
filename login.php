<?php
/**
 * 处理登录请求（login.php）
 * --------------------------------------------------------------------------
 * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
 *
 * 仅接受 POST：
 *   1) 校验 CSRF 令牌
 *   2) 校验验证码 —— 【故意漏洞 1：验证码重放】
 *      校验通过后不销毁 session 中的验证码、也不设置过期时间，
 *      同一个验证码可被反复提交，多次用于登录尝试。
 *   3) 查询 MySQL（预处理语句）校验用户名与密码 —— 【故意漏洞 2：用户名探测】
 *      用户名不存在返回“用户名不存在”，密码错误返回“密码错误”，两种提示明显不同。
 *      并体现【故意漏洞 3：弱口令】：预置弱口令账号，登录失败不计数、无锁定、无频率限制。
 *   4) 成功 -> 设置会话并跳转 admin.php；失败 -> 写一次性提示并回登录页
 *
 * 边界说明：SQL 一律使用预处理语句（SQL 注入不属于本靶场的漏洞范围），
 * 密码以 password_verify 校验（bcrypt）。
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (!request_is_post()) {
    redirect('/index.php');
}

require_csrf();

$username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
$password = isset($_POST['password']) ? (string) $_POST['password'] : '';
$captcha  = isset($_POST['captcha'])  ? trim((string) $_POST['captcha'])  : '';

/* 空值校验：按字段分别提示（此时不消耗验证码，避免用户重填后验证码失效）。 */
if ($username === '') {
    flash_set('login_error', '请输入用户名');
    redirect('/index.php');
}
if ($password === '') {
    flash_set('login_error', '请输入密码');
    flash_set('login_username', $username);
    redirect('/index.php');
}
if ($captcha === '') {
    flash_set('login_error', '请输入验证码');
    flash_set('login_username', $username);
    redirect('/index.php');
}

/* ==========================================================================
 * 【故意漏洞 1：验证码重放】
 * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
 *
 * 与安全实现相比，这里刻意：
 *   - 校验通过后不执行 unset($_SESSION['captcha'])；
 *   - 不为验证码设置任何过期时间。
 * 结果：一旦攻击者解出一次验证码，即可在后续多次登录尝试中重复提交同一值，
 *       配合抓包工具（如 Burp）自动化爆破，验证码形同虚设。
 * ========================================================================== */
$expected = isset($_SESSION['captcha']) ? (string) $_SESSION['captcha'] : '';

if ($expected === '' || !hash_equals($expected, $captcha)) {
    flash_set('login_error', '验证码错误');
    flash_set('login_username', $username);
    redirect('/index.php');
}
/* 注意：此处刻意不做 unset，验证码继续保留在 session 中，可被重放。 */

/* ==========================================================================
 * 【故意漏洞 2：用户名探测】
 * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
 *
 * 先按用户名查询，再单独校验密码，并对两种失败原因给出不同提示：
 *   - 用户不存在 -> “用户名不存在”
 *   - 密码错误   -> “密码错误”
 * 攻击者可据此枚举出系统内真实存在的用户名，为后续爆破缩小范围。
 * ========================================================================== */
$user = find_user_by_username($username); // 预处理语句查询，无 SQL 注入

if ($user === null) {
    flash_set('login_error', '用户名不存在');
    flash_set('login_username', $username);
    redirect('/index.php');
}

if (!password_verify($password, $user['password'])) {
    flash_set('login_error', '密码错误');
    flash_set('login_username', $username);
    redirect('/index.php');
}

login_user($user);
redirect('/admin.php');
