<?php
/**
 * 登录页（index.php）
 * --------------------------------------------------------------------------
 * 显示登录表单：用户名、密码、验证码图片、登录按钮。
 * 表单提交到 login.php 处理；校验失败时由 login.php 写入一次性提示，
 * 本页读取后展示。
 *
 * 输入框交互由 /assets/js/login.js 负责（见该文件头部说明）：
 *   - 浮动标签（Material 3）：标签始终可见，聚焦/有内容时上浮为小字
 *   - 校验时机：失焦校验 → 出错后随输入实时纠正 → 提交时全量兜底
 *   - 输入法（IME）适配：组合输入期间 Enter 只用于确认候选，不流转、不提交
 *   - 键盘唤醒：不在页面加载时自动聚焦（iOS 会拦截、Android 会突兀弹键盘），
 *               唤醒键盘一律交给用户点按输入框
 *   - 键盘避让：visualViewport 计算键盘高度，写入 --keyboard-inset 由样式表处理
 *   - 密码可见性切换：原生按钮，文案在「显示 / 隐藏」间切换
 *   - 提交加载态：只做在按钮内（登录 → 验证中 → 登录中），不覆盖登录页
 *
 * 自动填充策略按设备区分（helpers.php 的 is_mobile_client()）：
 *   - 桌面端：username / current-password，便于密码管理器一键填充
 *   - 移动端：off / new-password，抑制系统密码保险箱反复弹出
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('/admin.php');
}

$error    = flash_get('login_error', '');
$username = flash_get('login_username', '');
$app_name = config('app.name', '博客实验室');

$is_mobile          = is_mobile_client();
$ac_username        = $is_mobile ? 'off' : 'username';
$ac_password        = $is_mobile ? 'new-password' : 'current-password';
$ac_form            = $is_mobile ? 'off' : 'on';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-device="<?php echo e(device_class()); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#122c63">
    <title>登录 - <?php echo e($app_name); ?></title>
    <link rel="stylesheet" href="/assets/vendor/layui/css/layui.css">
    <link rel="stylesheet" href="/style.css">
</head>
<body class="<?php echo e(body_class('app-body--auth')); ?>">
<main class="auth" role="main">
    <section class="auth__card">
        <div class="auth__brand">
            <span class="app-brand__mark app-brand__mark--avatar" aria-hidden="true">
                <img src="/assets/images/avatar.webp" alt="" width="44" height="44">
            </span>
        </div>
        <h1 class="auth__title"><?php echo e($app_name); ?></h1>
        <p class="auth__sub">请登录后进入后台</p>

        <div class="auth__error" id="auth-error" role="alert"<?php echo $error === '' ? ' hidden' : ''; ?>>
            <i class="layui-icon layui-icon-close-fill" aria-hidden="true"></i>
            <span id="auth-error-text"><?php echo e($error); ?></span>
        </div>

        <form class="auth__form" method="post" action="/login.php"
              autocomplete="<?php echo e($ac_form); ?>" novalidate>
            <?php echo csrf_field(); ?>

            <p class="auth__guide">用户名、密码、验证码均为必填；验证码看不清时，点击图片可换一张。</p>

            <!-- 用户名 -->
            <div class="field" data-field="username">
                <div class="field__control">
                    <input class="field__input" type="text" id="username" name="username"
                           placeholder=" " required aria-required="true"
                           value="<?php echo e($username); ?>"
                           autocomplete="<?php echo e($ac_username); ?>"
                           autocapitalize="off" autocorrect="off" spellcheck="false"
                           enterkeyhint="next" aria-describedby="msg-username">
                    <label class="field__label" for="username">用户名</label>
                </div>
                <p class="field__msg" id="msg-username" role="alert" hidden></p>
            </div>

            <!-- 密码 -->
            <div class="field" data-field="password">
                <div class="field__control">
                    <input class="field__input" type="password" id="password" name="password"
                           placeholder=" " required aria-required="true"
                           autocomplete="<?php echo e($ac_password); ?>"
                           enterkeyhint="next" aria-describedby="msg-password">
                    <label class="field__label" for="password">密码</label>
                    <button type="button" class="field__toggle" id="password-toggle">显示</button>
                </div>
                <p class="field__msg" id="msg-password" role="alert" hidden></p>
            </div>

            <!-- 验证码 -->
            <div class="field" data-field="captcha">
                <div class="field__control">
                    <input class="field__input" type="text" id="captcha" name="captcha"
                           placeholder=" " required aria-required="true"
                           inputmode="numeric" maxlength="4" pattern="[0-9]{4}"
                           autocomplete="off" enterkeyhint="go" aria-describedby="msg-captcha">
                    <label class="field__label" for="captcha">验证码</label>
                    <button type="button" class="field__captcha" id="captcha-refresh"
                            aria-label="看不清，换一张验证码图片">
                        <img id="captcha-img" src="/captcha.php" alt="验证码图片" width="120" height="44">
                    </button>
                </div>
                <p class="field__msg" id="msg-captcha" role="alert" hidden></p>
            </div>

            <button type="submit" id="login-submit" class="layui-btn layui-btn-fluid auth__submit">
                <span class="app-spinner auth__submit-spinner" aria-hidden="true"></span>
                <span id="login-submit-label" aria-live="polite">登录</span>
            </button>
        </form>
    </section>
</main>
<script src="/assets/js/login.js"></script>
</body>
</html>
