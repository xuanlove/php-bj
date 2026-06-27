<?php
/**
 * 验证码API入口
 * 此文件是验证码API的入口文件
 * 实际类定义在CaptchaClass.php中
 * 
 * 注意：Windows下Captcha.php和captcha.php是同一个文件
 * 前端引用Captcha.php（大写C）
 */

// Session配置（必须与config.php保持完全一致）
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
               || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
               || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && $_SERVER['HTTP_FRONT_END_HTTPS'] === 'on')
               || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443');
    ini_set('session.cookie_secure', $isHttps ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    
    session_start();
}

// 设置session初始化标记（与config.php保持一致）
if (!isset($_SESSION['initiated'])) {
    $_SESSION['initiated'] = true;
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}

// 清理任何意外的输出缓冲
while (ob_get_level()) {
    ob_end_clean();
}

require_once 'CaptchaClass.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'generate':
        try {
            $captcha = new Captcha();
            $captcha->generate();
        } catch (Exception $e) {
            header('Content-Type: image/png');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            $img = @imagecreatetruecolor(120, 40);
            if ($img !== false) {
                $bg = imagecolorallocate($img, 15, 14, 14);
                $text_color = imagecolorallocate($img, 244, 67, 54);
                imagefill($img, 0, 0, $bg);
                imagestring($img, 2, 10, 12, 'Captcha Error', $text_color);
                imagepng($img);
                imagedestroy($img);
            } else {
                header('Content-Type: image/png');
                echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
            }
        }
        break;
        
    case 'refresh':
        Captcha::refresh();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true]);
        break;
        
    case 'verify':
        $code = $_POST['code'] ?? '';
        $result = Captcha::verify($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;
        
    default:
        // 默认也生成验证码（兼容直接访问Captcha.php的情况）
        try {
            $captcha = new Captcha();
            $captcha->generate();
        } catch (Exception $e) {
            header('Content-Type: image/png');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            $img = @imagecreatetruecolor(120, 40);
            if ($img !== false) {
                $bg = imagecolorallocate($img, 15, 14, 14);
                $text_color = imagecolorallocate($img, 244, 67, 54);
                imagefill($img, 0, 0, $bg);
                imagestring($img, 2, 10, 12, 'Captcha Error', $text_color);
                imagepng($img);
                imagedestroy($img);
            } else {
                header('Content-Type: image/png');
                echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
            }
        }
        break;
}