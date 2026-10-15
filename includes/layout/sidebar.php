<?php
/**
 * 左侧导航（blog_lab）
 * --------------------------------------------------------------------------
 * 实验环境仅有单一后台页面，这里保留骨架的导航外壳，便于后续扩展。
 * 可用变量：$active_menu（可选，用于高亮）
 */
if (!isset($active_menu)) {
    $active_menu = '';
}

$__menu = [
    ['key' => 'home', 'label' => '后台首页', 'url' => '/admin.php', 'icon' => 'layui-icon-home'],
    ['key' => 'verify', 'label' => '漏洞验证', 'url' => '/verify_vulns.php', 'icon' => 'layui-icon-survey'],
];
?>
<aside id="app-sidebar" class="app-sidebar" role="navigation" aria-label="主导航">
    <nav class="app-nav">
        <ul class="app-nav__list">
            <?php foreach ($__menu as $__item) { ?>
            <li class="app-nav__item">
                <a class="app-nav__link<?php echo $active_menu === $__item['key'] ? ' is-active' : ''; ?>"
                   href="<?php echo e($__item['url']); ?>"<?php echo $active_menu === $__item['key'] ? ' aria-current="page"' : ''; ?>>
                    <i class="layui-icon <?php echo e($__item['icon']); ?>" aria-hidden="true"></i>
                    <span><?php echo e($__item['label']); ?></span>
                </a>
            </li>
            <?php } ?>
        </ul>
    </nav>
</aside>
