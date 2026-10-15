<?php
/**
 * 漏洞验证（后台页面 · verify_vulns.php）
 * --------------------------------------------------------------------------
 * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
 *
 * 登录后从左侧导航「漏洞验证」进入，套用统一后台布局。
 *
 * 作用：一页确认本靶场的三个故意漏洞【确实存在】——
 *   ① 验证码重放：用 /captcha.php 真实生成的一枚验证码，重复提交多次，
 *      验证码关卡每次都放行（login.php 校验后不销毁、不设过期）。
 *   ② 用户名探测：不存在的用户名 → “用户名不存在”；存在的用户 + 错口令 → “密码错误”。
 *   ③ 弱口令：预置账号 admin / admin123 直接登录成功。
 *
 * 实现说明（全程真实接口，不含任何模拟）：
 *   - 先向 /captcha.php 取回一枚真实验证码；
 *   - 再用「同一枚验证码」向 /login.php 发起若干次真实 POST，
 *     并读回服务端真实写入会话的一次性提示，逐项判定；
 *   - 这些攻击请求在**独立的「一次性会话」**里发起：因为 login_user() 会
 *     session_regenerate_id(true)，若共用会话会把正在浏览本页的管理员踢下线。
 *     验证结束即回收该会话并恢复原会话。
 *
 * 本页只是验证工具，不修改任何业务逻辑，也不会修补上述漏洞。
 */
require_once __DIR__ . '/includes/bootstrap.php';

require_login();

$user        = current_user();
$page_title  = '漏洞验证';
$active_menu = 'verify';

/* --------------------------------------------------------------------------
 * 极简 HTTP 客户端：优先用 curl 扩展，缺失时回退到 allow_url_fopen 流。
 * 全程只发本机请求、不跟随跳转（跟随会把一次性提示消费掉，导致读不到）。
 * -------------------------------------------------------------------------- */
function vv_http($url, $fields = null)
{
    $cookie = session_name() . '=' . session_id();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        if ($fields !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $raw   = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($raw === false) {
            return array('code' => 0, 'location' => '', 'body' => '');
        }
        $head = substr($raw, 0, $hsize);
        $body = substr($raw, $hsize);
    } else {
        $opts = array('http' => array(
            'method'          => ($fields === null) ? 'GET' : 'POST',
            'header'          => "Cookie: " . $cookie . "\r\n",
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'timeout'         => 8,
        ));
        if ($fields !== null) {
            $opts['http']['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $opts['http']['content'] = http_build_query($fields);
        }
        $body = @file_get_contents($url, false, stream_context_create($opts));
        $head = (isset($http_response_header) && is_array($http_response_header))
              ? implode("\r\n", $http_response_header) : '';
        $code = 0;
        if (preg_match('#HTTP/\S+\s+(\d{3})#', $head, $m)) {
            $code = (int) $m[1];
        }
        if ($body === false) {
            $body = '';
        }
    }

    $location = '';
    if (preg_match('/^Location:\s*(.+)$/im', $head, $m)) {
        $location = trim($m[1]);
    }

    return array('code' => $code, 'location' => $location, 'body' => $body);
}

/* --------------------------------------------------------------------------
 * 本页所属站点根地址（兼容放在 WWW 子目录的情况）
 * -------------------------------------------------------------------------- */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$dir    = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/'));
$dir    = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
$root   = $dir;                            /* 站内链接前缀（站点在 WWW 根目录时为空串） */
$base   = $scheme . '://' . $host . $dir;

/* --------------------------------------------------------------------------
 * 切换到「一次性会话」：本页后台会话先关闭并记住 ID，验证结束后再恢复。
 * 关闭严格模式后，PHP 才允许用自定义 ID 建一个独立会话。
 * -------------------------------------------------------------------------- */
$admin_sid = session_id();
session_write_close();
ini_set('session.use_strict_mode', '0');

$attack_sid = 'v' . md5(uniqid((string) mt_rand(), true));
session_id($attack_sid);
session_start();

$checks   = array();
$attempts = array();
$fatal    = '';

/* 前置：让 /captcha.php 真实生成一枚验证码（须先释放会话锁，它才能写入 session） */
session_write_close();
vv_http($base . '/captcha.php');
@session_start();

$code  = isset($_SESSION['captcha']) ? (string) $_SESSION['captcha'] : '';
$genOk = (bool) preg_match('/^[0-9]{4}$/', $code);
$checks[] = array(
    'ok'     => $genOk,
    'label'  => '前置：/captcha.php 真实生成验证码',
    'detail' => $genOk ? ('本轮验证码 = ' . $code . '（后续所有尝试固定用它）')
                       : '未能取到验证码，请确认 GD 扩展已开启、且站点可本机自访问。',
);
if (!$genOk) {
    $fatal = '前置步骤失败，已跳过后续验证。';
}

if ($fatal === '') {
    $token = csrf_token();                 /* 一次性会话内的令牌，提交时固定不变 */
    $wrong = ($code === '0000') ? '1111' : '0000';

    $plan = array(
        array('label' => '基线：错误验证码',            'u' => 'nosuchuser', 'p' => 'x',        'c' => $wrong, 'last' => false),
        array('label' => '重放①：不存在的用户',          'u' => 'nosuchuser', 'p' => 'x',        'c' => $code,  'last' => false),
        array('label' => '重放②：存在的用户 + 错口令',   'u' => 'admin',      'p' => 'wrongpw',  'c' => $code,  'last' => false),
        array('label' => '重放③：预置弱口令（末次成功）', 'u' => 'admin',      'p' => 'admin123', 'c' => $code,  'last' => true),
    );

    foreach ($plan as $i => $a) {
        session_write_close();             /* 释放会话锁，login.php 才能读到 session */
        $resp = vv_http($base . '/login.php', array(
            '_csrf'    => $token,
            'username' => $a['u'],
            'password' => $a['p'],
            'captcha'  => $a['c'],
        ));

        $flash = '';
        if (!$a['last']) {
            @session_start();              /* 重新读回 login.php 写入的一次性提示 */
            if (isset($_SESSION['_flash']['login_error'])) {
                $flash = (string) $_SESSION['_flash']['login_error'];
            }
            unset($_SESSION['_flash']['login_error'], $_SESSION['_flash']['login_username']);
        }

        $attempts[$i] = array(
            'label'    => $a['label'],
            'u'        => $a['u'],
            'p'        => $a['p'],
            'c'        => $a['c'],
            'flash'    => $flash,
            'code'     => $resp['code'],
            'location' => $resp['location'],
        );
    }

    /* ---------------- 判定 ---------------- */

    /* 基线：错误验证码必须被拒（避免“自证循环”） */
    $baseOk = ($attempts[0]['flash'] === '验证码错误');
    $checks[] = array(
        'ok'     => $baseOk,
        'label'  => '基线：错误验证码被拒绝',
        'detail' => '错误码 ' . $wrong . ' → 服务端提示「'
                  . ($attempts[0]['flash'] !== '' ? $attempts[0]['flash'] : '（无提示）') . '」',
    );

    /* 漏洞一：同一枚验证码连续通过多次 */
    $passed = 0;
    if ($attempts[1]['flash'] !== '' && $attempts[1]['flash'] !== '验证码错误') { $passed++; }
    if ($attempts[2]['flash'] !== '' && $attempts[2]['flash'] !== '验证码错误') { $passed++; }
    $loginOk = (strpos($attempts[3]['location'], 'admin.php') !== false);
    if ($loginOk) { $passed++; }

    $checks[] = array(
        'ok'     => ($passed === 3),
        'label'  => '漏洞一 · 验证码重放',
        'detail' => '同一枚验证码 ' . $code . ' 连续提交 3 次，通过验证码关卡的次数 = ' . $passed . ' / 3'
                  . '（安全实现下第 2 次起应提示「验证码错误」）；'
                  . '明细：' . $attempts[1]['flash'] . ' → ' . $attempts[2]['flash']
                  . ' → ' . ($loginOk ? '302 登录成功' : '未成功'),
    );

    /* 漏洞二：两种提示文案不同 */
    $checks[] = array(
        'ok'     => ($attempts[1]['flash'] === '用户名不存在' && $attempts[2]['flash'] === '密码错误'),
        'label'  => '漏洞二 · 用户名探测',
        'detail' => '不存在的用户 nosuchuser → 「' . $attempts[1]['flash'] . '」；'
                  . '存在的用户 admin + 错口令 → 「' . $attempts[2]['flash'] . '」'
                  . '（两种文案明显不同，可据此枚举真实账号）',
    );

    /* 漏洞三：弱口令直接登录成功 */
    $checks[] = array(
        'ok'     => $loginOk,
        'label'  => '漏洞三 · 弱口令',
        'detail' => 'admin / admin123 → ' . ($loginOk
                    ? '登录成功，跳转 ' . $attempts[3]['location']
                    : '未跳转（Location: ' . $attempts[3]['location'] . '）'),
    );
}

/* --------------------------------------------------------------------------
 * 回收一次性会话，恢复后台当前会话（确保管理员不被踢下线）
 * -------------------------------------------------------------------------- */
@session_start();                          /* 重新打开一次性会话 */
$_SESSION = array();
session_destroy();                         /* 删除该会话文件 */

ini_set('session.use_strict_mode', '1');
session_id($admin_sid);
@session_start();                          /* 恢复后台会话 */

$allOk = ($fatal === '' && count($checks) > 0);
foreach ($checks as $c) {
    if (!$c['ok']) {
        $allOk = false;
        break;
    }
}

include __DIR__ . '/includes/layout/header.php';
?>
<div class="app-content__inner">
    <div class="app-page-head">
        <h1 class="app-page-head__title">三处故意漏洞 · 一键验证</h1>
        <p class="app-page-head__desc">
            本页用<b>真实接口</b>验证靶场漏洞是否存在：先由
            <a href="<?php echo e($root); ?>/captcha.php" title="直接查看验证码接口的真实输出（图片）">/captcha.php</a>
            生成一枚真实验证码，再用同一枚验证码重复提交给
            <a href="<?php echo e($root); ?>/login.php" title="/login.php 仅接受 POST，GET 会跳回登录页">/login.php</a>，
            并读取服务端真实返回的提示文案。验证在独立会话中进行，不影响你当前的后台登录状态。
        </p>
    </div>

    <div class="app-card">
        <h2 class="app-card__title">验证结论<?php echo $allOk ? '：三处漏洞均实际存在且可复现' : '：存在未通过的检查项'; ?></h2>
        <ul class="setup__list">
            <?php foreach ($checks as $c) { ?>
            <li class="setup__item setup__item--<?php echo $c['ok'] ? 'ok' : 'fail'; ?>">
                <i class="layui-icon <?php echo $c['ok'] ? 'layui-icon-ok-circle' : 'layui-icon-close-fill'; ?>" aria-hidden="true"></i>
                <span><strong><?php echo e($c['label']); ?></strong>：<?php echo e($c['detail']); ?></span>
            </li>
            <?php } ?>
        </ul>
        <?php if ($fatal !== '') { ?>
        <p class="setup__foot">
            <strong><?php echo e($fatal); ?></strong>
            请确认已先访问 <a href="<?php echo e($root); ?>/init_db.php">init_db.php</a> 完成初始化。
        </p>
        <?php } else { ?>
        <p class="setup__foot">
            <?php if (!$allOk) { ?>
            <strong>存在未通过的检查项。</strong>请确认已执行 <a href="<?php echo e($root); ?>/init_db.php">init_db.php</a> 初始化，且 config.php 的数据库账号正确。<br>
            <?php } ?>
            本页仅为本地验证工具，<b>不会修补</b>上述漏洞。
        </p>
        <?php } ?>
        <p class="setup__actions">
            <a class="layui-btn layui-btn-sm layui-btn-normal" href="<?php echo e($root); ?>/verify_vulns.php">重新验证一次</a>
            <a class="layui-btn layui-btn-sm layui-btn-primary" href="<?php echo e($root); ?>/index.php">前往登录页</a>
            <a class="layui-btn layui-btn-sm layui-btn-primary" href="<?php echo e($root); ?>/admin.php">前往后台首页</a>
        </p>
    </div>

    <?php if ($attempts) { ?>
    <div class="app-card">
        <h2 class="app-card__title">原始请求明细（真实 POST /login.php）</h2>
        <ul class="setup__list">
            <?php foreach ($attempts as $a) { ?>
            <li class="setup__item">
                <i class="layui-icon layui-icon-form" aria-hidden="true"></i>
                <span>
                    <strong><?php echo e($a['label']); ?></strong>
                    <span class="setup__code">username=<?php echo e($a['u']); ?>&amp;password=<?php echo e($a['p']); ?>&amp;captcha=<?php echo e($a['c']); ?></span>
                    → HTTP <?php echo e($a['code']); ?>
                    <?php if ($a['location'] !== '') { ?>
                    跳转 <a href="<?php echo e($a['location']); ?>"><?php echo e($a['location']); ?></a>
                    <?php } ?>
                    <?php echo $a['flash'] !== '' ? ('，提示「' . e($a['flash']) . '」') : ''; ?>
                </span>
            </li>
            <?php } ?>
        </ul>
        <p class="setup__foot">
            攻击请求都在独立会话中发起，因此可安全重复刷新本页；刷新一次即重新验证一次。
            每条明细中「跳转」后面的路径都是<b>可点击链接</b>，点击即可一键跳转到该请求的真实目标页。
        </p>
    </div>
    <?php } ?>
</div>
<?php include __DIR__ . '/includes/layout/footer.php'; ?>
