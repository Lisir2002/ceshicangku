/* ==========================================================================
   login.js · 登录页输入交互（配合 style.css 中的 .field 输入框组件）
   --------------------------------------------------------------------------
   职责：
     1. 校验时机：失焦校验 → 进入错误态后随输入实时纠正 → 提交时全量兜底
     2. 输入法（IME）适配：Enter 键字段流转；中文/日文等组合输入期间
        不校验、不误提交（拼音候选按 Enter 是「确认候选」，不是「提交」）
     3. 移动端键盘避让：用 visualViewport 计算键盘高度，稳定地让卡片避让
     4. 密码可见性切换（GOV.UK 模式）与验证码刷新
     5. AJAX 提交：fetch 提交 /login.php，服务端失败就地提示、不刷新页面
        （页面不重载，验证码图片不会被重新请求，会话中的验证码保持不变）
     6. 提交加载态：只作用于按钮（登录 → 验证中 → 登录中 → 跳转）

   【关键约束】iOS / WKWebView 的 keyboardDisplayRequiresUserAction 默认为 YES：
   只有「可信用户手势」内调用的 focus() 才会弹出键盘。因此本文件
     - 不在页面加载时自动聚焦（否则 Android 会一进页面就弹键盘，iOS 又不弹）；
     - 不在 setTimeout / 异步回调里 focus()（会被系统拦截，表现为「点不动」）；
   唤醒键盘一律交给用户点按输入框——这是唯一可靠的路径。
   ========================================================================== */
(function () {
  'use strict';

  var form = document.querySelector('.auth__form');
  if (!form) {
    return;
  }

  /* ------------------------------------------------------------------
     字段定义：数组顺序即出错时的聚焦优先级
     ------------------------------------------------------------------ */
  var FIELDS = [
    { id: 'username', empty: '请输入用户名' },
    { id: 'password', empty: '请输入密码' },
    {
      id: 'captcha',
      empty: '请输入验证码',
      format: '验证码需为 4 位数字',
      pattern: /^[0-9]{4}$/
    }
  ];

  function rowOf(id) {
    return document.querySelector('.field[data-field="' + id + '"]');
  }

  function inputOf(id) {
    return document.getElementById(id);
  }

  /* ------------------------------------------------------------------
     1. 校验：提示文案改为写在字段下方的 .field__msg 内
     ------------------------------------------------------------------ */
  function messageOf(field) {
    var value = (inputOf(field.id).value || '').replace(/^\s+|\s+$/g, '');
    if (value === '') {
      return field.empty;
    }
    if (field.pattern && !field.pattern.test(value)) {
      return field.format;
    }
    return '';
  }

  function render(field, message) {
    var el = inputOf(field.id);
    var row = rowOf(field.id);
    var msg = document.getElementById('msg-' + field.id);

    if (message) {
      row.classList.add('is-invalid');
      el.setAttribute('aria-invalid', 'true');
      if (msg) { msg.textContent = message; msg.hidden = false; }
    } else {
      row.classList.remove('is-invalid');
      el.removeAttribute('aria-invalid');
      if (msg) { msg.textContent = ''; msg.hidden = true; }
    }
  }

  function revalidate(field) {
    render(field, messageOf(field));
  }

  function isInvalid(id) {
    var row = rowOf(id);
    return !!row && row.classList.contains('is-invalid');
  }

  /* 组合输入状态（每个字段独立记录） */
  var composing = {};
  var composingSafe = {};

  function indexOfField(id) {
    for (var i = 0; i < FIELDS.length; i++) {
      if (FIELDS[i].id === id) { return i; }
    }
    return -1;
  }

  /* 聚焦一律发生在用户手势（点按 / 提交）内，iOS 才会弹出键盘 */
  function focusField(id) {
    var el = inputOf(id);
    if (el) { el.focus(); }
  }

  FIELDS.forEach(function (field) {
    var el = inputOf(field.id);
    if (!el) {
      return;
    }

    /* 组合输入开始 / 结束 */
    el.addEventListener('compositionstart', function () {
      composing[field.id] = true;
      composingSafe[field.id] = true;
    });
    el.addEventListener('compositionend', function () {
      composing[field.id] = false;
      /* Safari 会先触发 compositionend、再触发确认用的 keydown；
         延后一拍再解除标记，避免把「确认候选」误判成「提交」 */
      window.setTimeout(function () {
        composingSafe[field.id] = false;
      }, 0);
      revalidate(field);
    });

    /* 失焦：校验并提示（用户离开字段后才需要被告知对错） */
    el.addEventListener('blur', function () {
      revalidate(field);
    });

    /* 输入：组合期间不校验；仅在已进入错误态时实时纠正 */
    el.addEventListener('input', function () {
      if (composing[field.id]) {
        return;
      }
      if (isInvalid(field.id)) {
        revalidate(field);
      }
    });

    /* Enter 键流转：用户名 → 密码 → 验证码；验证码交给表单隐式提交 */
    el.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') {
        return;
      }
      /* isComposing / keyCode 229 / Safari 标记：输入法组合态。
         此时 Enter 是「确认候选」，必须阻止其任何默认行为——既不能流转到
         下一字段，也不能触发最后一个字段的表单隐式提交。 */
      if (event.isComposing || event.keyCode === 229 || composingSafe[field.id]) {
        event.preventDefault();
        return;
      }
      var i = indexOfField(field.id);
      if (i > -1 && i < FIELDS.length - 1) {
        event.preventDefault();
        focusField(FIELDS[i + 1].id);
      }
    });
  });

  function validateAll() {
    var firstInvalid = null;
    FIELDS.forEach(function (field) {
      revalidate(field);
      if (firstInvalid === null && isInvalid(field.id)) {
        firstInvalid = field.id;
      }
    });
    if (firstInvalid !== null) {
      focusField(firstInvalid);
    }
    return firstInvalid === null;
  }

  /* ------------------------------------------------------------------
     2. 密码可见性切换
     GOV.UK 模式：原生 button + 文案在「显示 / 隐藏」间切换，
     不叠加 aria-pressed，激活后不移动焦点。
     ------------------------------------------------------------------ */
  var toggle = document.getElementById('password-toggle');
  var password = document.getElementById('password');
  if (toggle && password) {
    /* 阻止按下时的默认行为：原生 button 会在按下瞬间抢走输入框焦点，
       preventDefault 后焦点仍留在输入框内，键盘与光标位置都不跳动。
       （键盘用户按 Tab 聚焦、再按 Enter/Space 触发 click，不受影响。） */
    toggle.addEventListener('mousedown', function (event) {
      event.preventDefault();
    });
    toggle.addEventListener('click', function () {
      var shown = password.type === 'text';
      password.type = shown ? 'password' : 'text';
      toggle.textContent = shown ? '显示' : '隐藏';
    });
  }

  /* ------------------------------------------------------------------
     3. 验证码刷新
     ------------------------------------------------------------------ */
  var refresh = document.getElementById('captcha-refresh');
  var captchaImg = document.getElementById('captcha-img');
  if (refresh && captchaImg) {
    refresh.addEventListener('click', function () {
      captchaImg.src = '/captcha.php?t=' + (new Date()).getTime();
    });
  }

  /* ------------------------------------------------------------------
     4. 移动端键盘避让
     ------------------------------------------------------------------
     软键盘改变的是「视觉视口」（visualViewport），不是布局视口，
     所以用 visualViewport 计算键盘占据的高度，写入 --keyboard-inset，
     由样式表决定卡片如何避让。

     稳定性处理：键盘弹出/收起有 250~300ms 动画，期间会连发大量
     resize 事件；这里做 120ms 防抖，动画结束后只更新一次布局，
     避免逐帧改动造成的闪烁与跳动。
     ------------------------------------------------------------------ */
  var vv = window.visualViewport;
  /* 键盘避让只在移动端启用：端由 UA 决定（见 <html data-device> / body 的 app-body--mobile） */
  var isMobileDevice = document.documentElement.getAttribute('data-device') === 'mobile';
  var root = document.documentElement;
  var timer = null;

  if (vv && isMobileDevice) {
    var apply = function () {
      timer = null;
      var inset = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
      /* 小于 150px 的变化多为地址栏伸缩，忽略；典型软键盘在 250px 以上 */
      var open = inset > 150;
      root.style.setProperty('--keyboard-inset', (open ? inset : 0) + 'px');
      root.classList.toggle('is-keyboard-open', open);
    };
    var schedule = function () {
      if (timer) {
        window.clearTimeout(timer);
      }
      timer = window.setTimeout(apply, 120);
    };

    vv.addEventListener('resize', schedule);
    vv.addEventListener('scroll', schedule);
    apply();
  }

  /* ------------------------------------------------------------------
     5. 提交：AJAX 提交 + 加载态只做在按钮内
     ------------------------------------------------------------------ */
  var submit = document.getElementById('login-submit');
  var label = document.getElementById('login-submit-label');
  var banner = document.getElementById('auth-error');
  var bannerText = document.getElementById('auth-error-text');
  var busy = false;

  function setState(state) {
    if (state === 'idle') {
      submit.classList.remove('is-loading');
      submit.disabled = false;
      label.textContent = '登录';
      return;
    }
    submit.classList.add('is-loading');
    submit.disabled = true;
    label.textContent = (state === 'checking') ? '验证中' : '登录中';
  }

  function showBanner(message) {
    if (!banner) {
      return;
    }
    bannerText.textContent = message;
    banner.hidden = false;
  }

  function hideBanner() {
    if (banner) {
      banner.hidden = true;
    }
  }

  /* 服务端失败提示：能对应到字段就就地显示，否则退回顶部横幅 */
  function showServerError(fieldId, message) {
    hideBanner();
    if (fieldId && inputOf(fieldId)) {
      render({ id: fieldId }, message);
      return;
    }
    showBanner(message);
  }

  function postLogin() {
    fetch('/login.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new FormData(form)
    }).then(function (response) {
      return response.json();
    }).catch(function () {
      return null;
    }).then(function (data) {
      if (data && data.ok) {
        window.location.href = data.redirect || '/admin.php';
        return;
      }
      busy = false;
      setState('idle');
      if (data) {
        showServerError(data.field || null, data.error || '登录失败，请重试。');
      } else {
        showBanner('网络或服务异常，请稍后重试。');
      }
    });
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (busy) {
      return;
    }
    /* 提交发生在用户手势内，聚焦第一个错误字段能正常唤起键盘 */
    if (!validateAll()) {
      return;
    }
    busy = true;
    hideBanner();
    setState('checking');
    window.setTimeout(function () {
      setState('submitting');
      postLogin();
    }, 500);
  });
})();
