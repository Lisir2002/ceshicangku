<?php
/**
 * 靶场漏洞开关（blog_lab）
 * --------------------------------------------------------------------------
 * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
 *
 * 三处故意漏洞各自有一个独立开关（verify_vulns.php 的每张漏洞卡片控制）：
 *   - 'lab'（默认）靶场模式：该漏洞按原样保留，用于复现与学习；
 *   - 'fix' 修复模式：该漏洞改为安全实现，用于对照。
 *
 *   replay · 验证码重放   -> 一次一用 + 有效期
 *   enum   · 用户名探测   -> 失败提示统一 + 补做同成本校验抹平时序
 *   weak   · 弱口令/无限制 -> 失败计数与锁定 + 验证码刷新频率限制
 *
 * 状态存运行时文件（系统临时目录下的 blog_lab_mode.json，不在 Web 根内）；
 * 文件缺失、损坏或某项缺省时，该项一律回退为靶场模式 —— 保证靶场默认仍可复现漏洞。
 */

if (!defined('LAB_FIX_CAPTCHA_TTL')) {
    define('LAB_FIX_CAPTCHA_TTL', 300);      /* 修复模式：验证码有效期（秒） */
    define('LAB_FIX_MAX_FAILS', 5);          /* 修复模式：同会话允许的连续失败次数 */
    define('LAB_FIX_LOCK_SECONDS', 300);     /* 修复模式：触发后的锁定时长（秒） */
    define('LAB_FIX_CAPTCHA_INTERVAL', 10);  /* 修复模式：验证码两次生成的最小间隔（秒） */
    define('LAB_FIX_DUMMY_HASH', '$2y$10$Wd6dYh8p4rgNL1vEH9VyJ.bHGSOu0PQzOMoMSbTEXe3KSli.//aU6');
}

if (!function_exists('lab_vulns')) {
    /** 三处漏洞的键名（顺序即展示顺序） */
    function lab_vulns()
    {
        return array('replay', 'enum', 'weak');
    }
}

if (!function_exists('lab_mode_file')) {
    function lab_mode_file()
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'blog_lab_mode.json';
    }
}

if (!function_exists('lab_state')) {
    /**
     * 读出三处漏洞的当前模式，返回 array('replay' => 'lab'|'fix', ...)。
     * 缺省、缺项、文件损坏一律回退 'lab'。
     */
    function lab_state()
    {
        $state = array();
        foreach (lab_vulns() as $k) {
            $state[$k] = 'lab';
        }

        $file = lab_mode_file();
        if (!is_readable($file)) {
            return $state;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) {
            return $state;
        }

        if (isset($data['vulns']) && is_array($data['vulns'])) {
            foreach (lab_vulns() as $k) {
                if (isset($data['vulns'][$k]) && $data['vulns'][$k] === 'fix') {
                    $state[$k] = 'fix';
                }
            }

            return $state;
        }

        /* 兼容早期的整体开关 {"mode":"fix"} */
        if (isset($data['mode']) && $data['mode'] === 'fix') {
            foreach (lab_vulns() as $k) {
                $state[$k] = 'fix';
            }
        }

        return $state;
    }
}

if (!function_exists('lab_state_save')) {
    function lab_state_save($state)
    {
        $clean = array();
        foreach (lab_vulns() as $k) {
            $clean[$k] = (isset($state[$k]) && $state[$k] === 'fix') ? 'fix' : 'lab';
        }

        return (bool) @file_put_contents(
            lab_mode_file(),
            json_encode(array('vulns' => $clean, 'updated_at' => date('c')))
        );
    }
}

if (!function_exists('lab_vuln_mode')) {
    function lab_vuln_mode($key)
    {
        $state = lab_state();

        return isset($state[$key]) ? $state[$key] : 'lab';
    }
}

if (!function_exists('lab_vuln_fix')) {
    /** 该漏洞是否处于「修复模式」 */
    function lab_vuln_fix($key)
    {
        return lab_vuln_mode($key) === 'fix';
    }
}

if (!function_exists('lab_vuln_set')) {
    function lab_vuln_set($key, $mode)
    {
        if (!in_array($key, lab_vulns(), true)) {
            return false;
        }
        $state = lab_state();
        $state[$key] = ($mode === 'fix') ? 'fix' : 'lab';

        return lab_state_save($state);
    }
}

if (!function_exists('lab_mode_set')) {
    /** 一次性设置全部漏洞的模式（'lab' / 'fix'） */
    function lab_mode_set($mode)
    {
        $state = array();
        foreach (lab_vulns() as $k) {
            $state[$k] = ($mode === 'fix') ? 'fix' : 'lab';
        }

        return lab_state_save($state);
    }
}

if (!function_exists('lab_fix_enabled')) {
    /** 三处漏洞是否全部处于修复模式 */
    function lab_fix_enabled()
    {
        foreach (lab_vulns() as $k) {
            if (lab_vuln_mode($k) !== 'fix') {
                return false;
            }
        }

        return true;
    }
}

/* --------------------------------------------------------------------------
 * 修复模式 · 登录失败计数与锁定（对应漏洞三 'weak'）
 * 按「会话」维度计数（演示用，无需数据库 schema 变更）；
 * 生产实现应按账号 / 来源 IP 维度持久化计数。
 * -------------------------------------------------------------------------- */

if (!function_exists('lab_login_lock_remaining')) {
    function lab_login_lock_remaining()
    {
        $fails = isset($_SESSION['lab_fail_count']) ? (int) $_SESSION['lab_fail_count'] : 0;
        $last  = isset($_SESSION['lab_fail_at']) ? (int) $_SESSION['lab_fail_at'] : 0;

        if ($fails < LAB_FIX_MAX_FAILS || $last <= 0) {
            return 0;
        }

        $left = LAB_FIX_LOCK_SECONDS - (time() - $last);

        return $left > 0 ? $left : 0;
    }
}

if (!function_exists('lab_login_register_fail')) {
    function lab_login_register_fail()
    {
        $fails = isset($_SESSION['lab_fail_count']) ? (int) $_SESSION['lab_fail_count'] : 0;
        $_SESSION['lab_fail_count'] = $fails + 1;
        $_SESSION['lab_fail_at']    = time();
    }
}

if (!function_exists('lab_login_reset_fails')) {
    function lab_login_reset_fails()
    {
        unset($_SESSION['lab_fail_count'], $_SESSION['lab_fail_at']);
    }
}
