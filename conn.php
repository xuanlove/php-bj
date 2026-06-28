<?php
/**
 * 数据库连接与初始化配置
 *
 * 此文件包含：
 * - 数据库配置
 * - 数据库连接类（单例模式）
 * - Session安全配置
 * - 数据库表自动迁移
 *
 * 其他PHP文件应通过 require_once 'config.php' 引入此文件
 */

// ========================================
// 加载 .env 文件（如果存在）
// ========================================

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        // 支持 `export KEY=value` 前缀
        if (strpos($key, 'export ') === 0) {
            $key = trim(substr($key, 7));
        }
        $value = trim($value);
        // 去除行内注释（仅当 # 前有空格且值未被引号包裹时）
        if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
            $hashPos = strpos($value, ' #');
            if ($hashPos !== false) {
                $value = trim(substr($value, 0, $hashPos));
            }
        }
        // 去除包裹引号并处理转义
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $quote = $value[0];
            $value = substr($value, 1, -1);
            if ($quote === '"') {
                $value = stripcslashes($value);
            }
        }
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

// ========================================
// 数据库配置
// ========================================

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'note');
define('DB_USER', getenv('DB_USER') ?: 'note');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// ========================================
// 加密密钥 — 用于备份凭证等敏感数据加密
// ⚠️ 生产环境必须设置，否则系统无法正常运行
// ========================================
define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: '');

if (empty(ENCRYPTION_KEY)) {
    // 检查是否是安装向导请求（install.php 不需要加密密钥）
    $isInstallRequest = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'install.php') !== false;
    // 未安装时（install.lock 不存在）也放行，由 api.php 统一拦截并引导到安装页
    $systemNotInstalled = !file_exists(__DIR__ . '/install.lock');

    if (!$isInstallRequest && !$systemNotInstalled) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => '系统配置错误：加密密钥未设置',
            'error_code' => 'ENCRYPTION_KEY_NOT_CONFIGURED',
            'hint' => '请在 .env 文件中设置 ENCRYPTION_KEY'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========================================
// Session配置
// ========================================

define('SESSION_LIFETIME', 86400);                  // Session有效期（秒，24小时）
define('SESSION_COOKIE_NAME', 'php_notes_session');

// ========================================
// 创建上传目录
// ========================================

define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('ATTACHMENT_DIR', __DIR__ . '/attachments/');

if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
if (!file_exists(ATTACHMENT_DIR)) {
    mkdir(ATTACHMENT_DIR, 0755, true);
}

// ========================================
// 数据库连接类（单例模式）
// ========================================

class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch(PDOException $e) {
            @error_log("数据库连接失败: " . $e->getMessage());
            header('Content-Type: application/json; charset=utf-8');
            // 区分"数据库不存在"（未安装）和其他数据库错误
            $errorMsg = $e->getMessage();
            $notInstalled = false;
            if (strpos($errorMsg, 'Unknown database') !== false ||
                strpos($errorMsg, '1049') !== false ||
                strpos($errorMsg, 'Access denied') !== false ||
                strpos($errorMsg, '1045') !== false ||
                strpos($errorMsg, 'Connection refused') !== false ||
                strpos($errorMsg, '2002') !== false) {
                $notInstalled = true;
            }
            echo json_encode([
                'success' => false,
                'message' => $notInstalled ? '系统未安装，请先完成安装' : '系统错误，请联系管理员',
                'not_installed' => $notInstalled
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    // 防止克隆
    private function __clone() {}

    // 防止反序列化
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// ========================================
// Session安全配置
// ========================================

if (session_status() === PHP_SESSION_NONE) {
    // 设置安全的Session cookie参数
    ini_set('session.cookie_httponly', 1);  // 防止JavaScript访问
    // 仅在真正HTTPS环境下启用cookie_secure
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
               || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && $_SERVER['HTTP_FRONT_END_HTTPS'] === 'on')
               || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443');
    ini_set('session.cookie_secure', $isHttps ? 1 : 0);
    // 注意：使用use_strict_mode防止会话固定攻击
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');  // Lax允许同站GET请求携带cookie

    session_start();

    // 初始化Session标记（不使用session_regenerate_id，避免验证码等数据丢失）
    if (!isset($_SESSION['initiated'])) {
        $_SESSION['initiated'] = true;
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

    } elseif (($_SESSION['ip_address'] ?? '') !== ($_SERVER['REMOTE_ADDR'] ?? '') ||
              ($_SESSION['user_agent'] ?? '') !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
        // IP或User-Agent变化，只记录日志不销毁session（NAT/代理环境下IP可能变化）
        if (function_exists('logSecurityEvent')) {
            logSecurityEvent('session_ip_ua_change', [
                'old_ip' => $_SESSION['ip_address'] ?? '',
                'new_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'old_ua' => $_SESSION['user_agent'] ?? '',
                'new_ua' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        }
        // 更新为新IP/UA，而不是销毁session
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
}

// ========================================
// 时区设置
// ========================================

date_default_timezone_set('Asia/Shanghai');

// ========================================
// 数据库表存在性检查（仅限新装完整数据库）
// 如有缺失表则记录警告，不自动创建或迁移
// 建表请通过 install.php 执行 database.sql
// ========================================

function ensureTablesExist($db) {
    $requiredTables = [
        'users', 'invitation_codes', 'notes', 'folders', 'attachments',
        'ai_interactions', 'system_settings', 'login_logs', 'note_versions',
        'shared_notes', 'search_history', 'user_settings', 'note_templates',
        'note_shares', 'comments', 'notifications', 'api_keys', 'system_logs',
        'rate_limits', 'backup_configs', 'backup_logs', 'note_tags',
        'note_tag_mapping', 'teams', 'team_members', 'team_folders',
        'team_note_permissions', 'team_invitations'
    ];

    try {
        $stmt = $db->query("SHOW TABLES");
        $existingTables = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $existingTables[] = $row[0];
        }

        $missingTables = array_diff($requiredTables, $existingTables);
        if (!empty($missingTables)) {
            @error_log("数据库缺少以下表（请通过 install.php 重新安装）: " . implode(', ', $missingTables));
        }
    } catch (Exception $e) {
        @error_log("数据库表检查失败: " . $e->getMessage());
    }
}

/**
 * 初始化数据库连接（延迟初始化，按需调用）
 *
 * 此函数应在需要数据库连接的地方调用
 * 安装向导不需要调用此函数（install.php有自己的初始化逻辑）
 *
 * @return PDO|null 数据库连接，失败时返回null
 */
function initDatabase() {
    try {
        $db = Database::getInstance()->getConnection();
        ensureTablesExist($db);
        return $db;
    } catch (Exception $e) {
        @error_log("数据库初始化失败: " . $e->getMessage());
        return null;
    }
}
?>
