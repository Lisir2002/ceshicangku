<?php
/**
 * 通用助手函数
 * --------------------------------------------------------------------------
 * 仅提供与具体业务无关的基础能力：配置读取、HTML 转义、跳转、闪现消息、
 * 请求判定与统一错误页渲染。
 */

if (!function_exists('config')) {
    /**
     * 读取点号分隔的配置项，例如 config('db.host', '127.0.0.1')。
     */
    function config($key, $default = null)
    {
        if (empty($GLOBALS['APP_CONFIG']) || !is_array($GLOBALS['APP_CONFIG'])) {
            return $default;
        }
        $value = $GLOBALS['APP_CONFIG'];
        foreach (explode('.', $key) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }
}

if (!function_exists('e')) {
    /**
     * 输出到 HTML 时的统一转义助手，防止 XSS。
     */
    function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    /**
     * 302 跳转并终止脚本。
     */
    function redirect($path)
    {
        header('Location: ' . $path);
        exit;
    }
}

if (!function_exists('request_is_post')) {
    function request_is_post()
    {
        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
    }
}

if (!function_exists('flash_set')) {
    /**
     * 写入一次性提示消息。
     */
    function flash_set($key, $message)
    {
        $_SESSION['_flash'][$key] = $message;
    }
}

if (!function_exists('flash_get')) {
    /**
     * 取出并清除一次性提示消息。
     */
    function flash_get($key, $default = null)
    {
        if (isset($_SESSION['_flash'][$key])) {
            $message = $_SESSION['_flash'][$key];
            unset($_SESSION['_flash'][$key]);

            return $message;
        }

        return $default;
    }
}

if (!function_exists('is_mobile_client')) {
    /**
     * 判断当前请求是否来自移动端。
     * ----------------------------------------------------------------------
     * 用途：登录页按设备决定「自动填充策略」——
     *   桌面端：给出标准 autocomplete 令牌（username / current-password），
     *           便于密码管理器一键填充（Google/Chromium/Material 的推荐做法）；
     *   移动端：改用 off / new-password，抑制系统密码保险箱反复弹出。
     *
     * 判定顺序：优先读取 UA 客户端提示 Sec-CH-UA-Mobile（Chrome/Edge 会发送），
     *          其余浏览器回退到 User-Agent 关键字匹配。
     */
    function is_mobile_client()
    {
        if (isset($_SERVER['HTTP_SEC_CH_UA_MOBILE'])) {
            return $_SERVER['HTTP_SEC_CH_UA_MOBILE'] === '?1';
        }

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';

        return $ua !== '' && preg_match('/android|iphone|ipad|ipod|windows phone|harmonyos|mobile/', $ua) === 1;
    }
}

if (!function_exists('render_error_page')) {
    /**
     * 渲染统一风格的状态页（如 403/404/500）。
     * 已登录时套用完整布局；未登录时输出独立的极简页面。
     */
    function render_error_page($code, $title, $message = '')
    {
        http_response_code((int) $code);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        $error_code    = $code;
        $error_title   = $title;
        $error_message = $message;

        if (function_exists('is_logged_in') && is_logged_in()) {
            $page_title = $title;
            $active_menu = '';
            include __DIR__ . '/layout/header.php';
            ?>
            <div class="app-content__inner">
                <div class="app-error">
                    <div class="app-error__code"><?php echo e($error_code); ?></div>
                    <h1 class="app-error__title"><?php echo e($error_title); ?></h1>
                    <p class="app-error__message"><?php echo e($error_message); ?></p>
                    <a class="layui-btn layui-btn-normal" href="/admin.php">返回后台首页</a>
                </div>
            </div>
            <?php
            include __DIR__ . '/layout/footer.php';
            exit;
        }

        $app_name = config('app.name', '后台管理');
        ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo e($error_title . ' - ' . $app_name); ?></title>
            <link rel="stylesheet" href="/assets/vendor/layui/css/layui.css">
            <link rel="stylesheet" href="/style.css">
        </head>
        <body class="app-body">
            <main class="auth">
                <div class="auth__card app-error">
                    <div class="app-error__code"><?php echo e($error_code); ?></div>
                    <h1 class="app-error__title"><?php echo e($error_title); ?></h1>
                    <p class="app-error__message"><?php echo e($error_message); ?></p>
                    <a class="layui-btn layui-btn-normal" href="/index.php">返回登录</a>
                </div>
            </main>
        </body>
        </html>
        <?php
        exit;
    }
}
