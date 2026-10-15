<?php
/**
 * 初始化数据库与用户表（blog_lab 实验环境）
 * --------------------------------------------------------------------------
 * 执行步骤：
 *   1) 以 config.php 中的数据库账号（默认 root / root）连接 MySQL
 *   2) 创建数据库 blog_lab（utf8mb4）
 *   3) 创建 users 表（id / username / password）
 *   4) 写入弱口令用户（密码以 bcrypt 存储）
 *
 * 运行方式（phpStudy 2016）：
 *   - 浏览器：http://localhost/init_db.php（仅限本机访问）
 *   - 命令行：php init_db.php
 *
 * 幂等性：可重复执行，不会删除已有数据；弱口令用户的密码会被重置为初始值。
 */

require_once __DIR__ . '/includes/bootstrap.php';

/* 仅允许本机运行，避免远程误触发初始化。 */
$__remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
if (PHP_SAPI !== 'cli' && !in_array($__remote, array('127.0.0.1', '::1'), true)) {
    render_error_page(403, '禁止访问', '初始化脚本仅允许在本机运行。');
}

$report = array();
$failed = false;

function init_step($ok, $label)
{
    $GLOBALS['report'][] = array('ok' => (bool) $ok, 'label' => $label);
    if (!$ok) {
        $GLOBALS['failed'] = true;
    }
}

$db        = config('db', array());
$dbname    = isset($db['name']) ? $db['name'] : 'blog_lab';
$dbhost    = isset($db['host']) ? $db['host'] : '127.0.0.1';
$dbport    = isset($db['port']) ? (int) $db['port'] : 3306;
$dbuser    = isset($db['user']) ? $db['user'] : 'root';
$dbpass    = isset($db['pass']) ? $db['pass'] : '';
$lab_users = config('lab.users', array());

$conn = null;
$ready = true;

// 1. 连接 MySQL（不选择库，便于稍后创建数据库）
$conn = @new mysqli($dbhost, $dbuser, $dbpass, null, $dbport);
if ($conn->connect_errno) {
    init_step(false, 'MySQL 连接失败：' . $conn->connect_error);
    $ready = false;
} else {
    $conn->set_charset('utf8mb4');
    init_step(true, '已连接 MySQL：' . $dbuser . '@' . $dbhost . ':' . $dbport);
}

// 2. 创建数据库
if ($ready) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbname)) {
        init_step(false, '数据库名不合法：' . $dbname);
        $ready = false;
    } else {
        $sql = 'CREATE DATABASE IF NOT EXISTS `' . $dbname . '` '
             . 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        if ($conn->query($sql)) {
            init_step(true, '数据库就绪：' . $dbname . '（utf8mb4）');
        } else {
            init_step(false, '创建数据库失败：' . $conn->error);
            $ready = false;
        }
    }
}

// 3. 选择库 + 创建 users 表
if ($ready) {
    if (!$conn->select_db($dbname)) {
        init_step(false, '选择数据库失败：' . $conn->error);
        $ready = false;
    } else {
        $sql = 'CREATE TABLE IF NOT EXISTS users ('
             . ' id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
             . ' username VARCHAR(50) NOT NULL,'
             . ' password VARCHAR(255) NOT NULL,'
             . ' PRIMARY KEY (id),'
             . ' UNIQUE KEY uq_users_username (username)'
             . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        if ($conn->query($sql)) {
            init_step(true, '用户表就绪：users（id / username / password）');
        } else {
            init_step(false, '创建用户表失败：' . $conn->error);
            $ready = false;
        }
    }
}

// 4. 写入弱口令用户（bcrypt）
if ($ready && count($lab_users) > 0) {
    $stmt = $conn->prepare(
        'INSERT INTO users (username, password) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE password = VALUES(password)'
    );
    if (!$stmt) {
        init_step(false, '准备写入语句失败：' . $conn->error);
    } else {
        foreach ($lab_users as $u) {
            $username = isset($u['username']) ? (string) $u['username'] : '';
            $plain    = isset($u['password']) ? (string) $u['password'] : '';
            if ($username === '' || $plain === '') {
                continue;
            }
            $hash = password_hash($plain, PASSWORD_DEFAULT);
            $stmt->bind_param('ss', $username, $hash);
            if ($stmt->execute()) {
                init_step(true, '弱口令用户：' . $username . ' / ' . $plain . '（库内以 bcrypt 存储）');
            } else {
                init_step(false, '写入用户 ' . $username . ' 失败：' . $stmt->error);
            }
        }
        $stmt->close();
    }
}

// ---- 输出 ----
$app_name = config('app.name', '博客实验室');

if (PHP_SAPI === 'cli') {
    echo "\n=== init_db.php 执行结果 ===\n";
    foreach ($report as $r) {
        echo ($r['ok'] ? '[OK]   ' : '[FAIL] ') . $r['label'] . "\n";
    }
    echo $failed ? "\n存在失败项，请检查后重试。\n" : "\n全部完成。\n";
    exit($failed ? 1 : 0);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>初始化数据库 - <?php echo e($app_name); ?></title>
    <link rel="stylesheet" href="/assets/vendor/layui/css/layui.css">
    <link rel="stylesheet" href="/style.css">
</head>
<body class="app-body">
<main class="setup">
    <section class="setup__card">
        <h1 class="setup__title">初始化数据库</h1>
        <p class="setup__desc">数据库 <code><?php echo e($dbname); ?></code> 与用户表创建结果：</p>
        <ul class="setup__list">
            <?php foreach ($report as $r) { ?>
            <li class="setup__item setup__item--<?php echo $r['ok'] ? 'ok' : 'fail'; ?>">
                <i class="layui-icon <?php echo $r['ok'] ? 'layui-icon-ok-circle' : 'layui-icon-close-fill'; ?>" aria-hidden="true"></i>
                <span><?php echo e($r['label']); ?></span>
            </li>
            <?php } ?>
        </ul>
        <p class="setup__foot">
            <?php if ($failed) { ?>
            存在失败项，请检查配置后重试。
            <?php } else { ?>
            初始化完成，请前往 <a href="/index.php">登录页</a>。
            <?php } ?>
        </p>
    </section>
</main>
</body>
</html>
