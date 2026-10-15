<?php
/**
 * 验证码图片（GD 库）
 * --------------------------------------------------------------------------
 * 教学靶场，故意包含漏洞，仅限本地实验，禁止公网部署。
 *
 * 生成 4 位数字验证码，明文写入 $_SESSION['captcha']，并输出 PNG 图片。
 * - 数字随机取自 mt_rand
 * - 叠加噪点与干扰线，降低 OCR 识别成功率
 * - 尺寸在 60x22 画布绘制后放大 2 倍，无需外部字体文件
 *
 * 【故意漏洞 1：验证码重放】与【故意漏洞 3：无刷新限制】
 * - 本文件只负责“生成并写入 session”，不为验证码设置任何过期时间；
 *   验证码的失效由 login.php 决定，而那里刻意不做销毁 —— 见 login.php。
 * - 刷新验证码没有任何次数/频率限制，可被反复请求，配合抓包工具爆破。
 */

require_once __DIR__ . '/includes/bootstrap.php';

// 生成 4 位数字
$code = '';
for ($i = 0; $i < 4; $i++) {
    $code .= (string) mt_rand(0, 9);
}
$_SESSION['captcha'] = $code;

$width  = 120;
$height = 44;

// 主画布
$img = imagecreatetruecolor($width, $height);
$bg  = imagecolorallocate($img, 245, 247, 250);
imagefilledrectangle($img, 0, 0, $width, $height, $bg);

// 在小画布上绘制数字，再放大，等效于更大的字符
$sw = 60;
$sh = 22;
$small = imagecreatetruecolor($sw, $sh);
$sbg   = imagecolorallocate($small, 245, 247, 250);
imagefilledrectangle($small, 0, 0, $sw, $sh, $sbg);
$sink  = imagecolorallocate($small, 31, 41, 55);
imagestring($small, 5, 4, 4, $code, $sink);

// 小画布噪点
for ($i = 0; $i < 180; $i++) {
    $noise = imagecolorallocate($small, mt_rand(140, 200), mt_rand(140, 200), mt_rand(140, 200));
    imagesetpixel($small, mt_rand(0, $sw - 1), mt_rand(0, $sh - 1), $noise);
}

imagecopyresampled($img, $small, 6, 6, 0, 0, 108, 32, $sw, $sh);
imagedestroy($small);

// 干扰线
for ($i = 0; $i < 5; $i++) {
    $line = imagecolorallocate($img, mt_rand(130, 190), mt_rand(130, 190), mt_rand(130, 190));
    imageline($img, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), $line);
}

// 输出
if (!headers_sent()) {
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

imagepng($img);
imagedestroy($img);
