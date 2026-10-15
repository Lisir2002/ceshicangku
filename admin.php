<?php
/**
 * 后台首页（admin.php）
 * --------------------------------------------------------------------------
 * 需要登录后访问；显示「欢迎 xxx」及基础账户信息，套用统一后台布局。
 */
require_once __DIR__ . '/includes/bootstrap.php';

require_login();

$user        = current_user();
$page_title  = '后台首页';
$active_menu = 'home';

include __DIR__ . '/includes/layout/header.php';
?>
<div class="app-content__inner">
    <div class="app-page-head">
        <h1 class="app-page-head__title">欢迎 <?php echo e($user['username']); ?></h1>
        <p class="app-page-head__desc">你已成功登录<?php echo e(config('app.name', '博客实验室')); ?>后台。</p>
    </div>

    <div class="layui-row layui-col-space16">
        <div class="layui-col-md6">
            <div class="app-card">
                <h2 class="app-card__title">账户信息</h2>
                <p class="app-card__line">用户名：<strong><?php echo e($user['username']); ?></strong></p>
                <p class="app-card__line">用户 ID：<?php echo e($user['id']); ?></p>
                <p class="app-card__line">登录时间：<?php echo e(date('Y-m-d H:i:s')); ?></p>
            </div>
        </div>
        <div class="layui-col-md6">
            <div class="app-card">
                <h2 class="app-card__title">说明</h2>
                <p class="app-card__line">
                    本页为登录成功后的后台入口，仅用于本地安全学习实验。
                </p>
                <p class="app-card__line">
                    点击右上角「退出」可结束当前会话。
                </p>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/layout/footer.php'; ?>
