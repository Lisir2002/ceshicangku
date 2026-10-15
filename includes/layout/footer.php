<?php
/**
 * 页面脚：关闭布局容器 + 页脚 + 引入脚本
 * --------------------------------------------------------------------------
 * 使用本地 Layui 副本，不依赖外网 CDN。
 */
?>
        </div><!-- /.app-content -->
    </main>
</div><!-- /.app-shell -->
<footer class="app-footer" role="contentinfo">
    <span>&copy; <?php echo date('Y'); ?> <?php echo e(config('app.name', '后台管理')); ?></span>
    <span class="app-footer__meta">PHP 5.6 · Layui 2.13 · blog_lab</span>
</footer>
<script src="/assets/vendor/layui/layui.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>
