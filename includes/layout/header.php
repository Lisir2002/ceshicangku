<?php
/**
 * 页面头：HTML head + 顶部栏 + 打开布局容器
 * --------------------------------------------------------------------------
 * 可用变量（由调用页面可选设置）：
 *   $page_title   页面标题
 *   $active_menu  当前高亮的菜单权限 code
 */
if (!isset($page_title)) {
    $page_title = '';
}
if (!isset($active_menu)) {
    $active_menu = '';
}

$__app_name    = config('app.name', '后台管理');
$__user        = current_user();
$__display     = '';
if ($__user) {
    $__display = (isset($__user['display_name']) && $__user['display_name'] !== '')
        ? $__user['display_name']
        : $__user['username'];
}
$__full_title  = $page_title !== '' ? $page_title . ' - ' . $__app_name : $__app_name;
?>
<!DOCTYPE html>
<html lang="zh-CN" data-device="<?php echo e(device_class()); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#122c63">
    <title><?php echo e($__full_title); ?></title>
    <link rel="stylesheet" href="/assets/vendor/layui/css/layui.css">
    <link rel="stylesheet" href="/style.css">
    <script>
        /* 把视口高度写入 CSS 变量：部分内嵌 WebView 会将 100vh / 100svh 解析为 0，
           导致页面撑不满视口、底栏悬空。放在 head 里先于渲染执行，避免布局跳一下。 */
        document.documentElement.style.setProperty('--app-viewport-height', window.innerHeight + 'px');
    </script>
</head>
<body class="<?php echo e(body_class()); ?>">
<header class="app-header" role="banner">
    <button type="button" class="app-hamburger" id="app-sidebar-toggle"
            aria-controls="app-sidebar" aria-expanded="false" aria-label="切换导航菜单">
        <i class="layui-icon layui-icon-spread-left" aria-hidden="true"></i>
    </button>
    <a class="app-brand" href="/admin.php">
        <span class="app-brand__name"><?php echo e($__app_name); ?></span>
    </a>
    <div class="app-header__spacer"></div>
    <?php if ($__user) { ?>
    <div class="app-user">
        <span class="app-user__name" title="当前登录用户">
            <span class="app-user__avatar" aria-hidden="true">
                <img src="/assets/images/avatar.webp" alt="" width="28" height="28">
            </span>
            <?php echo e($__display); ?>
        </span>
        <form class="app-user__logout" method="post" action="/logout.php">
            <?php echo csrf_field(); ?>
            <button type="submit" class="layui-btn layui-btn-primary layui-btn-sm">退出</button>
        </form>
    </div>
    <?php } ?>
</header>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main id="app-main" class="app-main" role="main">
        <div class="app-content">
