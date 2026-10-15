<?php
/**
 * 数据库连接配置（blog_lab 实验环境）
 * --------------------------------------------------------------------------
 * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
 *
 * 目标环境：phpStudy 2016（Apache + MySQL），代码放入 WWW 目录运行。
 * 本文件是整个站点的配置入口，返回一个数组：
 *   - db   数据库连接（初始化与运行共用；默认 root / root，请按本地 phpStudy 配置修改）
 *   - lab  初始化时写入的弱口令用户清单（仅用于本地安全实验）
 *   - app  应用信息
 *
 * 说明：本环境是本地安全学习靶场，请勿对外网暴露。
 */

return [
    // 数据库连接（默认按 phpStudy 2016 的 root / root；如本地不同请修改此处）
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'blog_lab',
        'user'    => 'root',
        'pass'    => 'root',
        'charset' => 'utf8mb4',
    ],

    // 初始化写入的弱口令用户
    // 【故意漏洞 3：弱口令】教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
    // 下列账号口令极弱，配合“用户名探测 + 验证码重放”即可离线/在线爆破练习。
    'lab' => [
        'users' => [
            ['username' => 'admin', 'password' => 'admin123'],
            ['username' => 'test',  'password' => '123456'],
            ['username' => 'user',  'password' => 'password'],
        ],
    ],

    // 应用信息
    'app' => [
        'name'         => '博客实验室',
        'session_name' => 'BLOGSESSID',
        'timezone'     => 'Asia/Shanghai',
        'env'          => 'development',
    ],
];
