# blog_lab · 本地安全学习靶场

> **教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。**
> 本项目仅用于**本地授权**的安全学习与教学，严禁用于任何未授权系统。

一个 PHP + MySQL 小型登录靶场：登录页（用户名 + 密码 + GD 4 位数字验证码）→ 登录成功进入后台页并显示“欢迎 xxx”。
项目**故意**内置三个漏洞（验证码重放、用户名探测、弱口令），用于漏洞复现与防御对照练习。

## 技术栈

| 项 | 说明 |
| --- | --- |
| 运行环境 | **phpStudy 2016**（Apache + MySQL），代码放入 `WWW` 目录运行 |
| 服务端 | PHP 5.6 及以上（需 mysqli / gd / openssl 扩展） |
| 数据库 | MySQL（utf8mb4） |
| 前端 | Layui 2.13.8（本地副本）+ 自定义 CSS（设计令牌层） |

## 目录结构

```text
blog_lab/
├── README.md                 # 项目说明（本文件）
├── config.php                # ★ 数据库与靶场配置
├── init_db.php               # ★ 初始化建库建表 + 写入弱口令用户
├── index.php                 # ★ 登录页（用户名 / 密码 / 验证码）
├── captcha.php               # ★ GD 4 位数字验证码
├── login.php                 # ★ 处理登录请求
├── admin.php                 # ★ 登录成功后台页（显示“欢迎 xxx”）
├── logout.php                # 退出登录
├── style.css                 # ★ 页面样式入口（@import 令牌层 + app.css）
├── docs/                     # 文档目录
│   ├── 协同文档.md            # 协作基线文档（规则 / 决策 / 技术点）
│   ├── 运行说明.md            # phpStudy 2016 部署与运行步骤
│   └── 靶场漏洞说明.md         # 三项故意漏洞的说明与利用步骤
├── includes/                 # 服务端公共代码
│   ├── bootstrap.php         # 引导：配置加载 / 会话 / 助手函数装载
│   ├── db.php                # mysqli 连接（预处理、utf8mb4）
│   ├── csrf.php              # CSRF 令牌
│   ├── auth.php              # 登录 / 会话（含故意漏洞）
│   ├── helpers.php           # 通用助手（转义 / 跳转 / 统一错误页）
│   └── layout/               # 布局片段（header / sidebar / footer）
└── assets/                   # 静态资源
    ├── css/                  # tokens.css（设计令牌层） + app.css
    ├── js/                   # app.js（交互增强）
    ├── images/               # bg-pc.webp / bg-mobile.webp（天空背景）
    └── vendor/               # 第三方依赖
        └── layui/            # Layui 2.13.8 本地副本
```

> ★ = 需求指定的固定文件，均保持在项目根目录。目录约定的完整说明见 [协同文档 · 7.3](docs/协同文档.md)。

## 快速开始（phpStudy 2016）

1. 将代码放入 phpStudy 2016 的 `WWW` 目录下。
2. 启动 **Apache** 和 **MySQL**。
3. 浏览器访问 `init_db.php` 初始化数据库。
4. 浏览器访问 `index.php` 开始测试。
5. 默认数据库账号 **root / root**（可按本地 phpStudy 配置修改 [config.php](config.php)）。

> 完整步骤、常见问题与账号清单见 [运行说明](docs/运行说明.md)。

## 预置账号（弱口令）

| 用户名 | 密码 |
| --- | --- |
| admin | admin123 |
| test | 123456 |
| user | password |

> 由 `init_db.php` 写入，库内以 bcrypt 存储；明文仅用于本地初始化。

## 文档

- [协同文档](docs/协同文档.md)：协作规则、决策日志、技术栈基线与设计规范。
- [靶场漏洞说明](docs/靶场漏洞说明.md)：三个故意漏洞的说明、利用步骤与防御对照。

## 安全边界

- SQL 全部使用 mysqli 预处理语句，**无注入点**；本靶场漏洞范围仅限上述三项。
- CSRF 与会话固定防护保留。
- **再次提醒：教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。**
