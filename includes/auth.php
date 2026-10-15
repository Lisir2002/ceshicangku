<?php
/**
 * 认证（blog_lab）
 * --------------------------------------------------------------------------
 * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
 *
 * users 表结构：id, username, password（password 为 bcrypt 哈希）
 * - current_user()          当前登录用户（来自 $_SESSION）
 * - is_logged_in()          是否已登录
 * - find_user_by_username() 预处理语句按用户名查询，命中返回用户行、否则 null
 * - login_user()            登录成功写入会话（含防会话固定处理）
 * - logout()                清空会话
 * - require_login()         未登录则跳转登录页
 */

if (!function_exists('current_user')) {
    function current_user()
    {
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            return $_SESSION['user'];
        }

        return null;
    }
}

if (!function_exists('is_logged_in')) {
    function is_logged_in()
    {
        return current_user() !== null;
    }
}

if (!function_exists('find_user_by_username')) {
    /**
     * 按用户名查询用户（预处理语句，禁止拼接 SQL）。
     *
     * 支撑【故意漏洞 2：用户名探测】：
     * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
     * 把“用户是否存在”的能力单独暴露出来，供 login.php 给出差异化提示。
     *
     * @param  string     $username 用户名
     * @return array|null 命中返回 users 行，未命中返回 null
     */
    function find_user_by_username($username)
    {
        $conn = db();

        $sql  = 'SELECT id, username, password FROM users WHERE username = ? LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log('[auth] prepare failed: ' . $conn->error);

            return null;
        }

        $stmt->bind_param('s', $username);
        $stmt->execute();

        $result = $stmt->get_result();
        $row    = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $row ? $row : null;
    }
}

if (!function_exists('login_user')) {
    /**
     * 登录成功后的会话写入。
     *
     * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
     * 保留防会话固定处理（重建会话 ID），该问题不属于本靶场故意漏洞范围。
     *
     * @param array $row users 表命中的一行
     */
    function login_user(array $row)
    {
        // 防会话固定：登录成功后重建会话 ID。
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'       => (int) $row['id'],
            'username' => $row['username'],
        ];
    }
}

if (!function_exists('logout')) {
    function logout()
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                isset($params['path']) ? $params['path'] : '/',
                isset($params['domain']) ? $params['domain'] : '',
                !empty($params['secure']),
                !empty($params['httponly'])
            );
        }

        session_destroy();
    }
}

if (!function_exists('require_login')) {
    function require_login()
    {
        if (!is_logged_in()) {
            redirect('/index.php');
        }
    }
}
