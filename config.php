<?php
/**
 * 应用配置文件
 *
 * 此文件包含：
 * - 业务常量定义
 * - 辅助函数
 *
 * 核心初始化（数据库连接、Session配置、数据库迁移）
 * 已移动到 conn.php
 */

require_once __DIR__ . '/conn.php';

// ========================================
// 网站配置（注意：UPLOAD_DIR和ATTACHMENT_DIR已在conn.php中定义）
// ========================================

define('SITE_URL', getenv('SITE_URL') ?: 'http://localhost/notes');

define('ENVIRONMENT', getenv('ENVIRONMENT') ?: 'development');

// 安全配置
define('CSRF_TOKEN_NAME', 'csrf_token');
define('MAX_UPLOAD_SIZE', 10485760); // 10MB
define('ALLOWED_FILE_TYPES', ['pdf', 'doc', 'docx', 'txt', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'gif', 'webp']);

// ========================================
// 业务常量定义
// ========================================

// 笔记相关
define('NOTE_MAX_VERSIONS', 50);                    // 单个笔记最大版本数
define('NOTE_RETENTION_DAYS', 30);                  // 回收站保留天数
define('NOTE_CONTENT_TYPE_MARKDOWN', 'markdown');
define('NOTE_CONTENT_TYPE_RICHTEXT', 'richtext');

// 用户相关
define('USER_ROLE_ADMIN', 'admin');
define('USER_ROLE_USER', 'user');
define('USER_STATUS_ACTIVE', 'active');
define('USER_STATUS_SUSPENDED', 'suspended');
define('USER_STATUS_DISABLED', 'disabled');
define('MIN_PASSWORD_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);                    // 最大登录尝试次数
define('LOGIN_LOCKOUT_DURATION', 900);              // 登录锁定时长（秒，15分钟）

// 邀请码相关
define('INVITE_CODE_LENGTH', 32);                   // 邀请码长度
define('INVITE_CODE_DEFAULT_EXPIRY', 30);          // 邀请码默认过期天数

// 验证码相关
define('CAPTCHA_LENGTH', 4);                         // 验证码长度
define('CAPTCHA_EXPIRY', 300);                      // 验证码有效期（秒，5分钟）

// 登录日志相关
define('LOGIN_LOG_RETENTION_DAYS', 90);            // 登录日志保留天数

// API速率限制
define('API_RATE_LIMIT_DEFAULT', 60);              // 默认API请求限制（每分钟）
define('API_RATE_LIMIT_WINDOW', 60);               // 速率限制时间窗口（秒）
define('API_RATE_LIMIT_LOGIN', 10);               // 登录接口请求限制（每分钟）

// 文件上传相关
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_DOCUMENT_TYPES', ['pdf', 'doc', 'docx', 'txt']);
define('ALLOWED_ARCHIVE_TYPES', ['zip', 'rar']);
define('DANGEROUS_EXTENSIONS', ['php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'bat', 'sh', 'cmd', 'com', 'vbs']);

// ========================================
// 错误报告配置
// ========================================

if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
    // 生产环境使用统一的错误处理器
    require_once __DIR__ . '/ErrorHandler.php';
    ErrorHandler::init(false);
} else {
    // 开发环境显示错误
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    // 开发环境也使用统一错误处理器，但开启调试模式
    require_once __DIR__ . '/ErrorHandler.php';
    ErrorHandler::init(true);
}

// ========================================
// 安全辅助函数
// ========================================

/**
 * 生成CSRF Token
 */
function generateCSRFToken() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * 验证CSRF Token
 */
function verifyCSRFToken($token) {
    if (!isset($_SESSION[CSRF_TOKEN_NAME]) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

/**
 * 输入过滤 - 移除危险字符
 *
 * 注意：此函数同时执行 strip_tags() 和 htmlspecialchars()，适用于输出到 HTML 上下文。
 * 若返回值会再次被 htmlspecialchars() 处理（如模板引擎自动转义），将导致双重编码。
 * 对于 HTML 属性上下文，请直接使用 htmlspecialchars()；
 * 对于 JavaScript 上下文，请使用 json_encode()；
 * 对于 URL 上下文，请使用 urlencode()。
 */
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }

    // 移除前后空格
    $input = trim($input);

    // 移除HTML标签（保留基本格式）
    $input = strip_tags($input);

    // 转义特殊字符
    $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return $input;
}

/**
 * 验证邮箱格式
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * 验证用户名格式（只允许字母、数字、下划线，3-20位）
 */
function validateUsername($username) {
    return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username);
}

/**
 * 验证密码强度
 * 要求：至少8位，包含大小写字母、数字和特殊字符
 */
function validatePassword($password) {
    // 最小长度8位
    if (strlen($password) < MIN_PASSWORD_LENGTH) {
        return ['valid' => false, 'message' => '密码至少需要' . MIN_PASSWORD_LENGTH . '位'];
    }

    // 检查是否包含大写字母
    if (!preg_match('/[A-Z]/', $password)) {
        return ['valid' => false, 'message' => '密码需要包含大写字母'];
    }

    // 检查是否包含小写字母
    if (!preg_match('/[a-z]/', $password)) {
        return ['valid' => false, 'message' => '密码需要包含小写字母'];
    }

    // 检查是否包含数字
    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'message' => '密码需要包含数字'];
    }

    // 检查是否包含特殊字符
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        return ['valid' => false, 'message' => '密码需要包含特殊字符'];
    }

    return ['valid' => true, 'message' => '密码强度符合要求'];
}

/**
 * 加密敏感数据
 */
function encryptData($data, $key = null) {
    if ($key === null) {
        if (!defined('ENCRYPTION_KEY')) {
            throw new Exception('Encryption key not configured');
        }
        $key = ENCRYPTION_KEY;
    }

    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * 解密敏感数据
 */
function decryptData($data, $key = null) {
    if ($key === null) {
        if (!defined('ENCRYPTION_KEY')) {
            throw new Exception('Encryption key not configured');
        }
        $key = ENCRYPTION_KEY;
    }

    $data = base64_decode($data);
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);

    return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
}

/**
 * 安全的文件名生成
 */
function generateSecureFilename($originalName) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $basename = pathinfo($originalName, PATHINFO_FILENAME);

    // 移除危险字符
    $basename = preg_replace('/[^a-zA-Z0-9_-]/', '', $basename);

    // 生成随机文件名
    $randomName = bin2hex(random_bytes(16)) . '_' . time();

    return $randomName . '.' . $ext;
}

/**
 * 验证文件类型是否允许上传
 */
function isAllowedFileType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ALLOWED_FILE_TYPES);
}

/**
 * 验证MIME类型是否允许
 */
function isAllowedMimeType($mimeType) {
    $allowedMimeTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
        'application/zip',
        'application/x-zip-compressed',
        'application/x-rar-compressed',
        'application/vnd.rar',
        'image/jpeg',
        'image/pjpeg',  // 旧版 IE
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml'
    ];

    return in_array($mimeType, $allowedMimeTypes);
}

/**
 * 记录安全日志
 */
function logSecurityEvent($event, $details = []) {
    $logFile = __DIR__ . '/logs/security.log';
    $logDir = dirname($logFile);

    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_id' => $_SESSION['user_id'] ?? 'guest',
        'event' => $event,
        'details' => $details
    ];

    file_put_contents($logFile, json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
?>
