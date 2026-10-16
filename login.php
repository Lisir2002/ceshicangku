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
 * 请求形态：
 *   - 普通表单提交：失败时 302 回登录页，由 index.php 显示一次性提示（无 JS 降级路径）；
 *   - fetch(AJAX) 提交（请求头 X-Requested-With: XMLHttpRequest）：以 JSON 返回结果，
 *     前端就地提示、不刷新页面 —— 验证码图片不会被重新请求，会话中的验证码保持不变。
 *
 * 边界说明：SQL 一律使用预处理语句（SQL 注入不属于本靶场的漏洞范围），
 * 密码以 password_verify 校验（bcrypt）。
 *
 * 漏洞开关（见 includes/lab-mode.php）：三处漏洞各有一个独立开关
 *   - 靶场模式（默认）：该漏洞按原样保留，用于复现与学习；
 *   - 修复模式：该漏洞改为安全实现。
 *   本文件涉及：'replay' 验证码一次一用 + 有效期；'enum' 失败提示统一 +
 *   补做同成本校验抹平时序；'weak' 失败计数与锁定。
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (!request_is_post()) {
    redirect('/index.php');
}

/* 前端以 fetch 提交时携带此请求头，用于区分 AJAX 与普通表单提交。 */
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

/**
 * 登录失败统一出口：
 *   AJAX -> 返回 JSON {ok:false, error, field}，由前端就地提示；
 *   普通 -> 写入一次性提示并回登录页。
 */
function login_fail($message, $field)
{
    global $is_ajax, $username;

    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => $message, 'field' => $field));
        exit;
    }

    flash_set('login_error', $message);
    flash_set('login_username', $username);
    redirect('/index.php');
}

/* CSRF 校验：AJAX 下以 JSON 返回 403，普通提交沿用统一错误页。 */
if (!verify_csrf(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'CSRF 校验失败，请刷新页面后重试。', 'field' => null));
        exit;
    }
    render_error_page(403, '请求被拒绝', 'CSRF 校验失败，请刷新页面后重试。');
}

$username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
$password = isset($_POST['password']) ? (string) $_POST['password'] : '';
$captcha  = isset($_POST['captcha'])  ? trim((string) $_POST['captcha'])  : '';

/* 空值校验：按字段分别提示（此时不消耗验证码，避免用户重填后验证码失效）。 */
if ($username === '') {
    login_fail('请输入用户名', 'username');
}
if ($password === '') {
    login_fail('请输入密码', 'password');
}
if ($captcha === '') {
    login_fail('请输入验证码', 'captcha');
}

/* 漏洞三（weak）修复模式：凭据校验前先看当前会话是否已被锁定。 */
if (lab_vuln_fix('weak')) {
    $lock_left = lab_login_lock_remaining();
    if ($lock_left > 0) {
        login_fail('尝试次数过多，请 ' . $lock_left . ' 秒后再试', null);
    }
}

/* ==========================================================================
 * 【故意漏洞 1：验证码重放】
 * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
 *
 * 靶场模式下刻意：
 *   - 校验通过后不执行 unset($_SESSION['captcha'])；
 *   - 不为验证码设置任何过期时间。
 * （修复模式下改为一次一用 + 有效期，见下方 lab_fix_enabled() 分支。）
 * 结果：一旦攻击者解出一次验证码，即可在后续多次登录尝试中重复提交同一值，
 *       配合抓包工具（如 Burp）自动化爆破，验证码形同虚设。
 * ========================================================================== */
$expected = isset($_SESSION['captcha']) ? (string) $_SESSION['captcha'] : '';

/* 漏洞一（replay）修复模式：验证码带有效期（签发超过 LAB_FIX_CAPTCHA_TTL 即视为过期）。 */
$expired = false;
if (lab_vuln_fix('replay')) {
    $issued  = isset($_SESSION['captcha_time']) ? (int) $_SESSION['captcha_time'] : 0;
    $expired = ($issued <= 0) || ((time() - $issued) > LAB_FIX_CAPTCHA_TTL);
}

if ($expected === '' || $expired || !hash_equals($expected, $captcha)) {
    if (lab_vuln_fix('weak')) {
        lab_login_register_fail();
    }
    login_fail('验证码错误', 'captcha');
}

/* 漏洞一（replay）修复模式：一次一用 —— 校验通过后立即销毁，并清除签发时间。 */
if (lab_vuln_fix('replay')) {
    unset($_SESSION['captcha'], $_SESSION['captcha_time']);
}
/* 靶场模式：此处刻意不做 unset，验证码继续保留在 session 中，可被重放。 */

/* ==========================================================================
 * 【故意漏洞 2：用户名探测】
 * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
 *
 * 先按用户名查询，再单独校验密码，并对两种失败原因给出不同提示：
 *   - 用户不存在 -> “用户名不存在”
 *   - 密码错误   -> “密码错误”
 * 攻击者可据此枚举出系统内真实存在的用户名，为后续爆破缩小范围。
 * （修复模式下改为统一提示“用户名或密码错误”，并补做一次同成本校验抹平时序。）
 * ========================================================================== */
$user = find_user_by_username($username); // 预处理语句查询，无 SQL 注入

if ($user === null) {
    if (lab_vuln_fix('weak')) {
        lab_login_register_fail();
    }
    if (lab_vuln_fix('enum')) {
        /* 补做一次 bcrypt 校验：让“用户不存在”与“密码错误”耗时相当，消除时序侧信道。 */
        password_verify($password, LAB_FIX_DUMMY_HASH);
        login_fail('用户名或密码错误', null);
    }
    login_fail('用户名不存在', 'username');
}

if (!password_verify($password, $user['password'])) {
    if (lab_vuln_fix('weak')) {
        lab_login_register_fail();
    }
    if (lab_vuln_fix('enum')) {
        login_fail('用户名或密码错误', null);
    }
    login_fail('密码错误', 'password');
}

login_user($user);

if (lab_vuln_fix('weak')) {
    lab_login_reset_fails();
}

if ($is_ajax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => true, 'redirect' => '/admin.php'));
    exit;
}

redirect('/admin.php');
