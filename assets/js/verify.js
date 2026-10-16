/* ==========================================================================
   verify.js · 漏洞验证页的交互（与登录页一致的「后端验证、前端不变」模式）
   --------------------------------------------------------------------------
   职责：
     1. 拦截漏洞卡片 / 总结卡片里的按钮提交，改用 fetch 提交（带
        X-Requested-With: XMLHttpRequest），**不刷新页面**；
     2. 请求期间被点的按钮进入「切换中」加载态（旋转 + 文案变化），
        同表单其余按钮一并禁用，避免重复提交；
     3. 服务端返回 JSON（受影响卡片的 HTML + 总结 HTML），就地替换内容；
     4. 失败时恢复按钮并在卡片内就地提示。

   无 JS 时表单会按原生方式提交（POST + 302 回本页），功能不丢。
   ========================================================================== */
(function () {
  'use strict';

  var page = document.querySelector('[data-lab-page]');
  if (!page || typeof window.fetch !== 'function') {
    return;
  }

  function formButtons(form) {
    return Array.prototype.slice.call(form.querySelectorAll('button'));
  }

  function setLoading(form, btn) {
    formButtons(form).forEach(function (b) {
      b.disabled = true;
      b.classList.remove('is-loading');
    });
    if (!btn) {
      return;
    }
    btn.setAttribute('data-vv-original', btn.textContent);
    btn.classList.add('is-loading');
    var loadingText = btn.getAttribute('data-vv-loading');
    if (loadingText) {
      btn.textContent = loadingText;
    }
  }

  function clearLoading(form, btn) {
    formButtons(form).forEach(function (b) {
      b.disabled = false;
    });
    if (!btn) {
      return;
    }
    btn.classList.remove('is-loading');
    var original = btn.getAttribute('data-vv-original');
    if (original) {
      btn.textContent = original;
    }
  }

  function showError(form, message) {
    var card = form.closest('.lab-card') || page;
    var el = card.querySelector('.lab-card__error');
    if (!el) {
      return;
    }
    if (message) {
      el.textContent = message;
      el.hidden = false;
    } else {
      el.textContent = '';
      el.hidden = true;
    }
  }

  function replace(html, selector) {
    var el = page.querySelector(selector);
    if (el && typeof html === 'string') {
      el.innerHTML = html;
    }
  }

  /* 记录被点击的提交按钮：FormData 默认不含提交按钮的 name/value */
  page.addEventListener('click', function (event) {
    var el = event.target;
    while (el && el !== page) {
      if (el.tagName === 'BUTTON' && el.type === 'submit') {
        if (el.form) {
          el.form.__vvSubmitter = el;
        }
        return;
      }
      el = el.parentNode;
    }
  }, true);

  page.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.classList || !form.classList.contains('lab-card__actions')) {
      return;
    }
    event.preventDefault();

    var btn = form.__vvSubmitter || form.querySelector('button[type="submit"]');
    form.__vvSubmitter = null;

    var data = new FormData(form);
    if (btn && btn.name) {
      data.append(btn.name, btn.value);
    }

    showError(form, '');
    setLoading(form, btn);

    fetch(form.action, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: data
    }).then(function (response) {
      return response.json();
    }).catch(function () {
      return null;
    }).then(function (res) {
      if (!res || !res.ok) {
        clearLoading(form, btn);
        showError(form, (res && res.error) || '操作失败，请稍后重试。');
        return;
      }

      var cards = res.cards || {};
      Object.keys(cards).forEach(function (key) {
        replace(cards[key], '[data-lab-card="' + key + '"]');
      });
      replace(res.summary, '[data-lab-summary]');

      if (res.error) {
        var notice = page.querySelector('.lab-page-notice');
        if (notice) {
          notice.hidden = false;
        }
      }
    });
  });
})();
