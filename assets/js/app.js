/**
 * 应用通用交互 · app.js
 * --------------------------------------------------------------------------
 * 职责：
 *   1) 视口高度变量：把 window.innerHeight 写入 --app-viewport-height，
 *      供布局计算「撑满视口」的高度；旋屏 / 改窗口大小时同步更新。
 *   2) 移动端左侧菜单折叠（汉堡按钮开合，含遮罩、Esc 关闭、focus 可见）。
 * 仅包含与业务无关的通用交互。
 */
(function () {
  'use strict';

  function initViewportHeight() {
    var root = document.documentElement;

    function apply() {
      root.style.setProperty('--app-viewport-height', window.innerHeight + 'px');
    }

    apply();
    window.addEventListener('resize', apply);
    window.addEventListener('orientationchange', apply);
  }

  function initSidebarToggle() {
    var toggle = document.getElementById('app-sidebar-toggle');
    var sidebar = document.getElementById('app-sidebar');
    if (!toggle || !sidebar) {
      return;
    }

    // 移动端遮罩层
    var backdrop = document.createElement('div');
    backdrop.className = 'app-sidebar-backdrop';
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.appendChild(backdrop);

    /* 侧栏是否处于「抽屉」形态：交给 CSS 决定（移动端抽屉；PC 端始终常驻），
       JS 用 computed position 判断，避免再复制一份断点逻辑。 */
    function isDrawer() {
      return window.getComputedStyle(sidebar).position === 'fixed';
    }

    function setOpen(open) {
      sidebar.classList.toggle('is-open', open);
      backdrop.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function toggleMenu() {
      setOpen(!sidebar.classList.contains('is-open'));
    }

    toggle.addEventListener('click', function (event) {
      event.preventDefault();
      toggleMenu();
    });

    backdrop.addEventListener('click', function () {
      setOpen(false);
      toggle.focus();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
        setOpen(false);
        toggle.focus();
      }
    });

    window.addEventListener('resize', function () {
      if (!isDrawer()) {
        setOpen(false);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initViewportHeight();
      initSidebarToggle();
    });
  } else {
    initViewportHeight();
    initSidebarToggle();
  }
})();
