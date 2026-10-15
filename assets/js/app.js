/**
 * 应用通用交互 · app.js
 * --------------------------------------------------------------------------
 * 职责：
 *   1) 移动端左侧菜单折叠（汉堡按钮开合，含遮罩、Esc 关闭、focus 可见）
 *   2) 初始化 Layui 表单模块
 * 仅包含与业务无关的通用交互。
 */
(function () {
  'use strict';

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

    function isMobile() {
      return window.matchMedia('(max-width: 768px)').matches;
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
      if (!isMobile()) {
        setOpen(false);
      }
    });
  }

  function initLayui() {
    if (window.layui && typeof window.layui.use === 'function') {
      window.layui.use(['form'], function () {
        /* 表单模块按需在此初始化 */
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initSidebarToggle();
      initLayui();
    });
  } else {
    initSidebarToggle();
    initLayui();
  }
})();
