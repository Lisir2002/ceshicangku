<?php
/**
 * 漏洞验证与修复对照（后台页面 · verify_vulns.php）
 * --------------------------------------------------------------------------
 * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
 *
 * 登录后从左侧导航「漏洞验证」进入。页面按**漏洞**组织：
 *   每个漏洞一张卡片，卡内分三段（漏洞说明 / 操作 / 测试结果），
 *   最后一张是总结卡片。
 *
 * 交互方式（与登录页一致）：按钮全部走 AJAX —— 前端 `fetch` 提交，
 * 服务端处理完返回 JSON，前端就地替换卡片内容，**页面不刷新**；
 * 请求期间按钮进入「切换中」加载态。禁用 JS 时回退为普通表单提交 + 302。
 *
 * 攻击请求都在**独立的「一次性会话」**中发起（因为 login_user() 会
 * session_regenerate_id(true)，共用会话会把正在浏览本页的管理员踢下线），
 * 跑完即回收并恢复原会话。修复效果来自 login.php / captcha.php 里的
 * lab_vuln_fix() 分支；本页自身不修改任何业务逻辑。
 */
require_once __DIR__ . '/includes/bootstrap.php';

require_login();

$user        = current_user();
$page_title  = '漏洞验证';
$active_menu = 'verify';

/* --------------------------------------------------------------------------
 * 站内根地址（兼容放在 WWW 子目录的情况）
 * -------------------------------------------------------------------------- */
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$dir    = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/'));
$dir    = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
$root   = $dir;
$base   = $scheme . '://' . $host . $dir;

/* --------------------------------------------------------------------------
 * 三处漏洞的元信息
 * -------------------------------------------------------------------------- */
$VULNS = array(
    'replay' => array(
        'no'    => '漏洞一',
        'name'  => '验证码重放',
        'what'  => '验证码通过校验后不被销毁、也不设有效期，同一个值可反复用于多次登录尝试。',
        'why'   => '验证码若「一次一用」，攻击者每猜一次口令都要重新识别验证码，自动化成本被抬高几个数量级；可重放等于把防自动化的闸门拆掉。',
        'fix'   => '校验通过后立即销毁验证码，并给它加签发时间与有效期（本靶场设为 5 分钟）。',
        'code'  => "// login.php（lab_vuln_fix('replay')）\nunset(\$_SESSION['captcha'], \$_SESSION['captcha_time']);\n\$expired = (time() - \$issued) > LAB_FIX_CAPTCHA_TTL;",
    ),
    'enum' => array(
        'no'    => '漏洞二',
        'name'  => '用户名探测',
        'what'  => '用户名不存在与口令错误返回「不同」提示，据此可枚举出系统内真实存在的账号。',
        'why'   => '它把「用户名 × 口令」的盲爆破降维成「已知账号的口令爆破」，缩小搜索空间、提高命中率；两条分支耗时不同还会形成时序侧信道。',
        'fix'   => '两种失败统一提示；账号不存在时补做一次同成本的 bcrypt 校验，抹平时序差异。',
        'code'  => "// login.php（lab_vuln_fix('enum')）\npassword_verify(\$password, LAB_FIX_DUMMY_HASH);\nlogin_fail('用户名或密码错误', null);",
    ),
    'weak' => array(
        'no'    => '漏洞三',
        'name'  => '弱口令与无限制',
        'what'  => '预置弱口令账号，且登录失败不计数、无锁定、验证码刷新也无频率限制。',
        'why'   => '没有失败限制，爆破就没有天花板、也不会被阻断；配合另外两条漏洞，三道防线同时失效。',
        'fix'   => '失败次数限制 + 锁定 + 验证码刷新频率限制（生产还应按账号/来源 IP 持久化计数，并强制口令强度）。',
        'code'  => "// lab-mode.php 常量\nLAB_FIX_MAX_FAILS = 5;          // 5 次失败后锁定\nLAB_FIX_LOCK_SECONDS = 300;     // 锁定 5 分钟\nLAB_FIX_CAPTCHA_INTERVAL = 10;  // 验证码 10 秒内不重复生成",
    ),
);

/* --------------------------------------------------------------------------
 * 极简 HTTP 客户端：优先用 curl 扩展，缺失时回退到 allow_url_fopen 流。
 * 全程只发本机请求、不跟随跳转（跟随会把一次性提示消费掉，导致读不到）。
 * -------------------------------------------------------------------------- */
function vv_http($url, $fields = null)
{
    $cookie = session_name() . '=' . session_id();
    $t0     = microtime(true);
    $body   = '';
    $head   = '';
    $code   = 0;

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
        if ($raw !== false) {
            $head = substr($raw, 0, $hsize);
            $body = substr($raw, $hsize);
        }
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

    return array(
        'code'     => $code,
        'location' => $location,
        'body'     => $body,
        'ms'       => (microtime(true) - $t0) * 1000,
    );
}

/* --------------------------------------------------------------------------
 * 「一次性会话」辅助：本页后台会话先关闭并记住 ID，用完恢复。
 * -------------------------------------------------------------------------- */
function vv_attack_begin()
{
    $admin_sid = session_id();
    session_write_close();
    ini_set('session.use_strict_mode', '0');

    session_id('v' . md5(uniqid((string) mt_rand(), true)));
    session_start();

    return $admin_sid;
}

function vv_attack_end($admin_sid)
{
    @session_start();
    $_SESSION = array();
    session_destroy();

    ini_set('session.use_strict_mode', '1');
    session_id($admin_sid);
    @session_start();
}

/** 在当前（一次性）会话中取一枚验证码 + CSRF 令牌 */
function vv_new_captcha($base)
{
    session_write_close();
    vv_http($base . '/captcha.php');
    @session_start();

    return array(
        'code'  => isset($_SESSION['captcha']) ? (string) $_SESSION['captcha'] : '',
        'token' => csrf_token(),
    );
}

/** 提交一次登录并读回服务端提示 */
function vv_post_login($base, $token, $u, $p, $code)
{
    session_write_close();
    $resp = vv_http($base . '/login.php', array(
        '_csrf'    => $token,
        'username' => $u,
        'password' => $p,
        'captcha'  => $code,
    ));
    @session_start();
    $flash = isset($_SESSION['_flash']['login_error']) ? (string) $_SESSION['_flash']['login_error'] : '';
    unset($_SESSION['_flash']['login_error'], $_SESSION['_flash']['login_username']);

    return array(
        'u'        => $u,
        'p'        => $p,
        'c'        => $code,
        'flash'    => $flash,
        'code'     => $resp['code'],
        'location' => $resp['location'],
        'ms'       => $resp['ms'],
    );
}

/** 验证码关卡是否放行（即走到了账号/口令判定阶段） */
function vv_gate_passed($flash)
{
    return in_array($flash, array('用户名不存在', '密码错误', '用户名或密码错误'), true);
}

/** 明细行的判读文案 */
function vv_verdict($row)
{
    $f = $row['flash'];
    if ($f === '' && strpos($row['location'], 'admin.php') !== false) {
        return '登录成功，跳转后台';
    }
    if (strpos($f, '尝试次数过多') === 0) {
        return '触发失败锁定（未进入验证码校验）';
    }
    if ($f === '验证码错误') {
        return '验证码关卡拦截';
    }
    if ($f === '用户名不存在') {
        return '账号不存在（验证码关卡已通过）';
    }
    if ($f === '密码错误') {
        return '口令错误（验证码关卡已通过）';
    }
    if ($f === '用户名或密码错误') {
        return '统一提示（未泄露账号是否存在）';
    }

    return $f !== '' ? $f : '未读取到提示';
}

/* --------------------------------------------------------------------------
 * 漏洞一 · 验证码重放：同一枚验证码连续提交 3 次
 * -------------------------------------------------------------------------- */
function vv_test_replay($base)
{
    $admin_sid = vv_attack_begin();
    $cap       = vv_new_captcha($base);
    $code      = $cap['code'];

    $rows = array();
    $gate = 0;
    for ($i = 1; $i <= 3; $i++) {
        $row = vv_post_login($base, $cap['token'], 'admin', 'wrong' . $i, $code);
        if (vv_gate_passed($row['flash'])) {
            $gate++;
        }
        $rows[] = $row;
    }
    vv_attack_end($admin_sid);

    $vuln = ($gate >= 2);

    return array(
        'vuln'     => $vuln,
        'headline' => $vuln
            ? '漏洞存在：同一枚验证码被接受 ' . $gate . ' 次（第 2、3 次仍通过验证码关卡）'
            : '已修复：同一枚验证码只被接受 ' . $gate . ' 次（首次通过后即销毁，第 2 次起提示「验证码错误」）',
        'lines'    => array(
            '测试方式：取一枚真实验证码，用同一枚连续提交 3 次错误口令。',
            '通过验证码关卡的次数：' . $gate . ' / 3',
            '本轮验证码：' . $code,
        ),
        'rows'     => $rows,
    );
}

/* --------------------------------------------------------------------------
 * 漏洞二 · 用户名探测：两种失败各用一枚全新验证码，比较返回提示
 * -------------------------------------------------------------------------- */
function vv_test_enum($base)
{
    $rows = array();
    foreach (array(array('nosuchuser', 'x'), array('admin', 'wrongpw')) as $pair) {
        $admin_sid = vv_attack_begin();
        $cap       = vv_new_captcha($base);
        $rows[]    = vv_post_login($base, $cap['token'], $pair[0], $pair[1], $cap['code']);
        vv_attack_end($admin_sid);
    }

    $a    = $rows[0];
    $b    = $rows[1];
    $vuln = ($a['flash'] !== '' && $b['flash'] !== '' && $a['flash'] !== $b['flash']);

    return array(
        'vuln'     => $vuln,
        'headline' => $vuln
            ? '漏洞存在：两种失败提示不同 —— 「' . $a['flash'] . '」 vs 「' . $b['flash'] . '」，可据此枚举真实账号'
            : '已修复：两种失败提示一致 —— 均为「' . $a['flash'] . '」，无法区分账号是否存在',
        'lines'    => array(
            '测试方式：不存在的用户与存在的用户各用一枚全新验证码（避免被验证码消耗干扰）。',
            '不存在的用户：提示「' . $a['flash'] . '」，耗时 ' . round($a['ms']) . ' ms',
            '存在的用户 + 错口令：提示「' . $b['flash'] . '」，耗时 ' . round($b['ms']) . ' ms',
            '耗时差：' . round(abs($a['ms'] - $b['ms'])) . ' ms（存在账号会走一次 bcrypt 校验，构成时序侧信道；修复模式用 dummy 校验抹平）',
        ),
        'rows'     => $rows,
    );
}

/* --------------------------------------------------------------------------
 * 漏洞三 · 弱口令与无限制：连续错误口令后是否触发锁定，正确口令是否仍可登录
 * -------------------------------------------------------------------------- */
function vv_test_weak($base)
{
    $admin_sid = vv_attack_begin();
    $cap       = vv_new_captcha($base);
    $code      = $cap['code'];

    $rows  = array();
    $lock  = '';
    $fails = 6;
    for ($i = 1; $i <= $fails; $i++) {
        $row = vv_post_login($base, $cap['token'], 'admin', 'bad' . $i, $code);
        if (strpos($row['flash'], '尝试次数过多') === 0) {
            $lock = $row['flash'];
        }
        $rows[] = $row;
    }

    /* 连续失败之后，再用正确弱口令试试还能不能登进去 */
    $last   = vv_post_login($base, $cap['token'], 'admin', 'admin123', $code);
    $rows[] = $last;
    $loginOk = (strpos($last['location'], 'admin.php') !== false);

    vv_attack_end($admin_sid);

    $vuln = ($lock === '');

    return array(
        'vuln'     => $vuln,
        'headline' => $vuln
            ? '漏洞存在：连续 ' . $fails . ' 次错误口令后仍无任何限制，且弱口令 admin/admin123 仍可登录'
            : '已修复：连续失败达阈值后触发锁定 —— 「' . $lock . '」',
        'lines'    => array(
            '测试方式：连续提交 ' . $fails . ' 次错误口令，再用预置弱口令 admin/admin123 试一次。',
            '失败计数与锁定：' . ($lock !== '' ? '已触发（' . $lock . '）' : '未触发 —— 可无限次尝试'),
            '预置弱口令 admin/admin123：' . ($loginOk ? '仍登录成功' : '已无法登录'),
            '说明：预置弱口令账号本身仍在库中；「修复」针对的是失败计数/锁定与刷新频率，生产环境还应强制口令强度。',
        ),
        'rows'     => $rows,
    );
}

/* --------------------------------------------------------------------------
 * 跑一次指定漏洞的测试，并盖上前后的模式戳
 * -------------------------------------------------------------------------- */
function vv_run_test($key, $base)
{
    if ($key === 'replay') {
        $r = vv_test_replay($base);
    } elseif ($key === 'enum') {
        $r = vv_test_enum($base);
    } else {
        $r = vv_test_weak($base);
    }

    $r['at']   = date('H:i:s');
    $r['mode'] = lab_vuln_mode($key);

    return $r;
}

/* ==========================================================================
 * 渲染（服务端唯一真源：首屏与 AJAX 局部刷新共用同一份模板）
 * ========================================================================== */

/** 单张漏洞卡片的内部 HTML */
function vv_render_card($key, $v, $is_fix, $res, $root)
{
    ob_start();
    ?>
    <div class="lab-card__head">
        <h2 class="app-card__title"><?php echo e($v['no'] . ' · ' . $v['name']); ?></h2>
        <span class="lab-badge lab-badge--<?php echo $is_fix ? 'fix' : 'lab'; ?>">
            <?php echo $is_fix ? '修复模式：已改为安全实现' : '靶场模式：漏洞存在'; ?>
        </span>
    </div>

    <div class="lab-section">
        <h3 class="lab-section__title">漏洞说明</h3>
        <div class="lab-row"><span class="lab-row__k">是什么</span><span class="lab-row__v"><?php echo e($v['what']); ?></span></div>
        <div class="lab-row"><span class="lab-row__k">为什么是漏洞</span><span class="lab-row__v"><?php echo e($v['why']); ?></span></div>
        <div class="lab-row"><span class="lab-row__k">修复做法</span><span class="lab-row__v"><?php echo e($v['fix']); ?><code class="lab-fixcode"><?php echo e($v['code']); ?></code></span></div>
    </div>

    <div class="lab-section">
        <h3 class="lab-section__title">操作</h3>
        <form class="lab-card__actions" method="post" action="<?php echo e($root); ?>/verify_vulns.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="vv_vuln" value="<?php echo e($key); ?>">
            <button type="submit" name="vv_action" value="test" data-vv-loading="测试中…"
                    class="layui-btn layui-btn-sm layui-btn-normal">测试漏洞</button>
            <button type="submit" name="vv_action" value="fix" data-vv-loading="修复中…"
                    class="layui-btn layui-btn-sm"><?php echo $is_fix ? '重新修复 / 复测' : '一键修复'; ?></button>
            <?php if ($is_fix) { ?>
            <button type="submit" name="vv_action" value="reset" data-vv-loading="切换中…"
                    class="layui-btn layui-btn-sm layui-btn-primary">恢复靶场</button>
            <?php } ?>
        </form>
        <p class="lab-card__error" hidden></p>
    </div>

    <div class="lab-section">
        <h3 class="lab-section__title">测试结果</h3>
        <?php if ($res === null) { ?>
        <p class="lab-note">尚未测试。点上面的「测试漏洞」跑一遍。</p>
        <?php } else { ?>
        <div class="lab-result lab-result--<?php echo $res['vuln'] ? 'bad' : 'good'; ?>">
            <div class="lab-result__title">
                <?php echo $res['vuln'] ? '漏洞成立' : '未复现（已被拦截）'; ?>
            </div>
            <p class="lab-result__headline"><?php echo e($res['headline']); ?></p>
            <ul class="lab-result__lines">
                <?php foreach ($res['lines'] as $line) { ?>
                <li><?php echo e($line); ?></li>
                <?php } ?>
            </ul>
            <div class="lab-result__meta">
                测试时间 <?php echo e($res['at']); ?> · 测试时模式：<?php echo $res['mode'] === 'fix' ? '修复模式' : '靶场模式'; ?>
            </div>
            <?php if (!empty($res['rows'])) { ?>
            <details class="lab-details">
                <summary>原始请求明细（<?php echo count($res['rows']); ?> 条）</summary>
                <div class="lab-attempts">
                    <?php foreach ($res['rows'] as $row) {
                        $ok = ($row['flash'] === '' && strpos($row['location'], 'admin.php') !== false);
                    ?>
                    <div class="lab-attempt <?php echo $ok ? 'lab-attempt--ok' : ''; ?>">
                        <div class="lab-attempt__head">
                            <span class="setup__code">username=<?php echo e($row['u']); ?>&amp;password=<?php echo e($row['p']); ?>&amp;captcha=<?php echo e($row['c']); ?></span>
                            <span class="lab-badge">HTTP <?php echo e($row['code']); ?></span>
                            <span class="lab-badge"><?php echo e(round($row['ms'])); ?> ms</span>
                        </div>
                        <div class="lab-attempt__verdict">判读：<?php echo e(vv_verdict($row)); ?></div>
                    </div>
                    <?php } ?>
                </div>
            </details>
            <?php } ?>
        </div>
        <?php } ?>
    </div>
    <?php
    return ob_get_clean();
}

/** 总结卡片的内部 HTML */
function vv_render_summary($VULNS, $state, $last, $root)
{
    $fix_count  = 0;
    $test_count = 0;
    foreach ($VULNS as $k => $v) {
        if ($state[$k] === 'fix') {
            $fix_count++;
        }
        if (isset($last[$k])) {
            $test_count++;
        }
    }

    ob_start();
    ?>
    <div class="lab-card__head">
        <h2 class="app-card__title">总结</h2>
        <span class="lab-badge">已修复 <?php echo $fix_count; ?> / 3 · 已测试 <?php echo $test_count; ?> / 3</span>
    </div>

    <div class="lab-section">
        <h3 class="lab-section__title">当前状态</h3>
        <ul class="setup__list">
            <?php foreach ($VULNS as $k => $v) {
                $is_fix = ($state[$k] === 'fix');
                $res    = isset($last[$k]) ? $last[$k] : null;
            ?>
            <li class="setup__item setup__item--<?php echo $is_fix ? 'ok' : 'fail'; ?>">
                <i class="layui-icon <?php echo $is_fix ? 'layui-icon-ok-circle' : 'layui-icon-close-fill'; ?>" aria-hidden="true"></i>
                <span>
                    <strong><?php echo e($v['no'] . ' · ' . $v['name']); ?></strong> ——
                    <?php echo $is_fix ? '修复模式' : '靶场模式'; ?>
                    <?php if ($res !== null) { ?>
                        ；最近测试：<?php echo $res['vuln'] ? '<b>漏洞成立</b>' : '已被拦截'; ?>
                    <?php } else { ?>
                        ；尚未测试
                    <?php } ?>
                </span>
            </li>
            <?php } ?>
        </ul>
    </div>

    <div class="lab-section">
        <h3 class="lab-section__title">批量操作</h3>
        <form class="lab-card__actions" method="post" action="<?php echo e($root); ?>/verify_vulns.php">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="vv_vuln" value="all">
            <button type="submit" name="vv_action" value="test" data-vv-loading="测试中…"
                    class="layui-btn layui-btn-sm layui-btn-normal">测试全部</button>
            <button type="submit" name="vv_action" value="fix" data-vv-loading="修复中…"
                    class="layui-btn layui-btn-sm">一键修复全部</button>
            <button type="submit" name="vv_action" value="reset" data-vv-loading="切换中…"
                    class="layui-btn layui-btn-sm layui-btn-primary">全部恢复靶场</button>
        </form>
        <p class="lab-card__error" hidden></p>
    </div>

    <div class="lab-section">
        <h3 class="lab-section__title">要点</h3>
        <div class="lab-row"><span class="lab-row__k">组合攻击链</span><span class="lab-row__v">
            ① 枚举真实账号（漏洞二）→ ② 固定一枚验证码反复提交（漏洞一）→ ③ 对已确认的账号无限次试弱口令（漏洞三）→ 命中即登录。
            三条单看都只是「不够安全」，串起来就是一条完整的自动化入侵链，所以修复时必须三条一起修。
        </span></div>
        <div class="lab-row"><span class="lab-row__k">研究结论</span><span class="lab-row__v">
            信息泄露的价值在于「降维」（二维盲爆破 → 一维口令爆破）；「可区分」不止文案，还有时序侧信道；
            验证码必须「一次一用 + 有有效期」；修复要成体系，缺一环就会被绕过。
        </span></div>
    </div>

    <p class="setup__foot">
        本页仅为本地验证与教学工具，<b>不会修补</b>任何漏洞；所有「修复效果」都来自漏洞开关，
        点「恢复靶场」即可回到含漏洞状态。
    </p>
    <p class="setup__actions">
        <a class="layui-btn layui-btn-sm layui-btn-primary" href="<?php echo e($root); ?>/index.php">前往登录页</a>
        <a class="layui-btn layui-btn-sm layui-btn-primary" href="<?php echo e($root); ?>/admin.php">前往后台首页</a>
    </p>
    <?php
    return ob_get_clean();
}

/* ==========================================================================
 * 动作处理：测试 / 一键修复 / 恢复靶场
 *   AJAX（X-Requested-With）-> 返回 JSON（卡片 + 总结的 HTML），前端就地替换；
 *   普通表单提交 -> 302 回本页（无 JS 降级路径）。
 * ========================================================================== */
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function vv_json($payload, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if (request_is_post() && isset($_POST['vv_action'], $_POST['vv_vuln'])) {
    if (!verify_csrf(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
        if ($is_ajax) {
            vv_json(array('ok' => false, 'error' => 'CSRF 校验失败，请刷新页面后重试。'), 403);
        }
        render_error_page(403, '请求被拒绝', 'CSRF 校验失败，请刷新页面后重试。');
    }

    $action  = (string) $_POST['vv_action'];
    $target  = (string) $_POST['vv_vuln'];
    $targets = ($target === 'all') ? array_keys($VULNS) : array($target);

    $write_ok = true;
    $failed   = array();
    foreach ($targets as $k) {
        if (!isset($VULNS[$k])) {
            continue;
        }
        if ($action === 'fix') {
            if (!lab_vuln_set($k, 'fix')) {
                $write_ok = false;
                $failed[] = $k;
            }
            $_SESSION['vv_last'][$k] = vv_run_test($k, $base);   /* 修复后立即复测 */
        } elseif ($action === 'reset') {
            if (!lab_vuln_set($k, 'lab')) {
                $write_ok = false;
                $failed[] = $k;
            }
            unset($_SESSION['vv_last'][$k]);
        } else {
            $_SESSION['vv_last'][$k] = vv_run_test($k, $base);
        }
    }
    $_SESSION['vv_write_ok'] = $write_ok;

    if ($is_ajax) {
        $state = lab_state();
        $last  = isset($_SESSION['vv_last']) && is_array($_SESSION['vv_last']) ? $_SESSION['vv_last'] : array();

        $cards = array();
        foreach ($targets as $k) {
            if (isset($VULNS[$k])) {
                $cards[$k] = vv_render_card($k, $VULNS[$k], $state[$k] === 'fix', isset($last[$k]) ? $last[$k] : null, $root);
            }
        }

        vv_json(array(
            'ok'      => true,
            'cards'   => $cards,
            'summary' => vv_render_summary($VULNS, $state, $last, $root),
            'state'   => $state,
            'error'   => $write_ok ? null : ('开关状态写入失败，请检查 ' . lab_mode_file()),
        ));
    }

    redirect('/verify_vulns.php');
}

/* ==========================================================================
 * 首屏渲染
 * ========================================================================== */
$state    = lab_state();
$last     = isset($_SESSION['vv_last']) && is_array($_SESSION['vv_last']) ? $_SESSION['vv_last'] : array();
$write_ok = !isset($_SESSION['vv_write_ok']) || $_SESSION['vv_write_ok'];

include __DIR__ . '/includes/layout/header.php';
?>
<div class="app-content__inner" data-lab-page>
    <div class="app-page-head">
        <h1 class="app-page-head__title">三处故意漏洞 · 逐个测试与修复</h1>
        <p class="app-page-head__desc">
            每个漏洞一张卡片：点<b>「测试漏洞」</b>用真实接口跑一遍攻击，看漏洞是否成立；
            点<b>「一键修复」</b>把该漏洞切到安全实现并立即复测；点<b>「恢复靶场」</b>切回含漏洞状态。
            三个开关相互独立，可自由组合。所有操作都在当前页面内完成（不刷新页面），
            测试在独立会话中发起，不影响你当前的后台登录状态。
        </p>
    </div>

    <div class="app-card lab-page-notice" <?php echo $write_ok ? 'hidden' : ''; ?>>
        <ul class="setup__list">
            <li class="setup__item setup__item--fail">
                <i class="layui-icon layui-icon-close-fill" aria-hidden="true"></i>
                <span><strong>开关状态写入失败</strong>：无法写入
                    <span class="setup__code"><?php echo e(lab_mode_file()); ?></span>，
                    请检查该目录是否可写。</span>
            </li>
        </ul>
    </div>

    <?php foreach ($VULNS as $k => $v) { ?>
    <div class="app-card lab-card" data-lab-card="<?php echo e($k); ?>">
        <?php echo vv_render_card($k, $v, $state[$k] === 'fix', isset($last[$k]) ? $last[$k] : null, $root); ?>
    </div>
    <?php } ?>

    <div class="app-card lab-card" data-lab-summary>
        <?php echo vv_render_summary($VULNS, $state, $last, $root); ?>
    </div>
</div>
<script src="<?php echo e($root); ?>/assets/js/verify.js"></script>
<?php include __DIR__ . '/includes/layout/footer.php'; ?>
