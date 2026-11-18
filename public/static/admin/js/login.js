/* ============================================================
   MIUCMS 后台登录页 · 主题切换 + 表单提交（独立文件）
   依赖：jQuery、layui（layer 弹窗）
   全局变量（由模板内联脚本注入）：
     LOGIN_SUBMIT_URL  登录提交接口
     LOGIN_INDEX_URL   登录成功后跳转地址
   ============================================================ */
(function ($) {
  'use strict';

  var layer = null;
  var THEME_KEY = 'login_theme';
  var USER_KEY  = 'login_username';
  var PASS_KEY  = 'login_password';
  var REM_KEY   = 'login_remember';

  /* ============ 主题切换 ============ */
  var $menu = $('.theme-menu');

  function switchTheme(key) {
    var $scene = $('#scene-' + key);
    if (!$scene.length) return;
    $('.scene').removeClass('active');
    $scene.addClass('active');
    $('.theme-item').removeClass('active');
    $('.theme-item[data-theme="' + key + '"]').addClass('active');
    loadCaptcha($scene);
    $menu.removeClass('open');
    try { localStorage.setItem(THEME_KEY, key); } catch (e) {}
  }

  $('.theme-btn').on('click', function (e) {
    e.stopPropagation();
    $menu.toggleClass('open');
  });
  $(document).on('click', function () {
    $menu.removeClass('open');
  });
  $menu.on('click', function (e) {
    e.stopPropagation();
  });
  $('.theme-item').on('click', function () {
    switchTheme($(this).data('theme'));
  });

  /* ============ 验证码（按激活场景惰性加载） ============ */
  function captchaUrl(img) {
    var base = $(img).data('src') || '';
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + Math.random();
  }
  function loadCaptcha(scope) {
    scope = scope || $('.scene.active');
    scope.find('.captcha-img').each(function () {
      $(this).attr('src', captchaUrl(this));
    });
  }
  $(document).on('click', '.captcha-box', function () {
    loadCaptcha($(this).closest('.scene'));
  });

  /* ============ 密码可见性切换 ============ */
  $(document).on('click', '.pw-toggle', function () {
    var $input = $(this).prev('input');
    var show = $input.attr('type') === 'password';
    $input.attr('type', show ? 'text' : 'password');
    $(this).attr('aria-pressed', String(show));
  });

  /* ============ 国风印章：点击重新盖印 ============ */
  var inkSeal = document.querySelector('.ink-seal');
  if (inkSeal && inkSeal.animate) {
    inkSeal.addEventListener('click', function () {
      inkSeal.animate(
        [
          { transform: 'translate3d(8vw, -120vh, 0) rotate(-18deg) scale(1.22)', opacity: 0, filter: 'blur(4px)', offset: 0 },
          { transform: 'translate3d(0, 0, 0) rotate(5deg) scale(.82, .82)', opacity: 1, filter: 'blur(0)', offset: 0.62 },
          { transform: 'translate3d(0, 0, 0) rotate(2deg) scale(1.1, .88)', offset: 0.73 },
          { transform: 'translate3d(0, 0, 0) rotate(4deg) scale(.96, 1.05)', offset: 0.82 },
          { transform: 'translate3d(0, 0, 0) rotate(2.6deg) scale(1.02, .98)', offset: 0.91 },
          { transform: 'translate3d(0, 0, 0) rotate(3deg) scale(1, 1)', opacity: 1, filter: 'blur(0)', offset: 1 }
        ],
        { duration: 2500, easing: 'cubic-bezier(.25,.68,.3,1)' }
      );
    });
  }

  /* ============ 记住账号密码（回填） ============ */
  function fillCredentials() {
    try {
      var user = localStorage.getItem(USER_KEY);
      var pass = localStorage.getItem(PASS_KEY);
      var rem  = localStorage.getItem(REM_KEY);
      if (user) $('[name="username"]').val(user);
      if (pass) $('[name="password"]').val(pass);
      if (rem === '1') $('[name="remember"]').prop('checked', true);
    } catch (e) {}
  }

  function saveCredentials(user, pass, remember) {
    try {
      if (remember) {
        localStorage.setItem(USER_KEY, user);
        localStorage.setItem(PASS_KEY, pass);
        localStorage.setItem(REM_KEY, '1');
      } else {
        localStorage.removeItem(USER_KEY);
        localStorage.removeItem(PASS_KEY);
        localStorage.removeItem(REM_KEY);
      }
    } catch (e) {}
  }

  /* ============ 表单提交 ============ */
  function mark($el, bad) {
    $el.toggleClass('err', !!bad);
  }

  $(document).on('submit', '.login-form', function (e) {
    e.preventDefault();
    var $form = $(this);
    var $btn = $form.find('button[type="submit"]');
    var $user = $form.find('[name="username"]');
    var $pass = $form.find('[name="password"]');
    var $cap = $form.find('[name="captcha"]');

    // 客户端非空校验
    var ok = true;
    if (!$user.val().trim()) { mark($user, true); ok = false; } else { mark($user, false); }
    if (!$pass.val()) { mark($pass, true); ok = false; } else { mark($pass, false); }
    if (!$cap.val().trim()) { mark($cap, true); ok = false; } else { mark($cap, false); }
    if (!ok) {
      layer.msg('请填写完整的登录信息', { icon: 2 });
      return;
    }

    // 保存按钮原始文案（不同风格按钮文字不同）
    if (!$btn.attr('data-original')) {
      $btn.attr('data-original', $btn.text());
    }
    $btn.prop('disabled', true).text('登录中...');

    $.ajax({
      type: 'post',
      url: LOGIN_SUBMIT_URL,
      data: $form.serialize(),
      dataType: 'json',
      success: function (res) {
        $btn.prop('disabled', false).text($btn.attr('data-original'));
        if (res.code == 0) {
          // 记住账号密码
          var remember = $form.find('[name="remember"]').is(':checked');
          saveCredentials($user.val().trim(), $pass.val(), remember);

          layer.msg('登录成功，正在跳转...', { icon: 1, time: 1200 }, function () {
            window.top.location.href = LOGIN_INDEX_URL;
          });
        } else {
          layer.msg(res.msg || '登录失败，请重试', { icon: 2 });
          loadCaptcha($form.closest('.scene'));
        }
      },
      error: function () {
        $btn.prop('disabled', false).text($btn.attr('data-original'));
        layer.msg('操作失败，请联系管理员', { icon: 2 });
      }
    });
  });

  /* ============ 初始化 ============ */
  layui.use(['layer'], function () {
    layer = layui.layer;

    // 恢复上次保存的主题；没有保存记录时使用页面默认的商务蓝。
    try {
      var savedTheme = localStorage.getItem(THEME_KEY);
      if (savedTheme && $('#scene-' + savedTheme).length) {
        switchTheme(savedTheme);
      }
    } catch (e) {}

    // 回填保存的账号密码
    fillCredentials();

    // 加载当前场景验证码
    loadCaptcha($('.scene.active'));
  });

})(jQuery);
