<?php
// 全局异常处理由 config.php 中的 ErrorHandler::init() 统一管理
// 不再在此处重复注册，避免覆盖

header('Content-Type: application/json; charset=utf-8');

// 获取请求方法和路径（早期获取，用于安装检测）
$method = $_SERVER['REQUEST_METHOD'];
$request = isset($_GET['action']) ? $_GET['action'] : '';

// ========================================
// 早期安装状态检测与拦截（在任何 require / DB 连接之前）
// 未安装时仅放行白名单接口，避免触发 DB 连接失败污染响应
// ========================================
$systemInstalled = file_exists(__DIR__ . '/install.lock');

if ($request === 'check_install') {
    echo json_encode([
        'success' => true,
        'installed' => $systemInstalled,
        'message' => $systemInstalled ? '系统已安装' : '系统未安装，请先完成安装',
        'redirect' => $systemInstalled ? null : 'install.html'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$systemInstalled) {
    $installWhitelist = ['check_install', 'health', 'get_csrf_token'];
    if (!in_array($request, $installWhitelist, true)) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => '系统尚未安装，请先完成安装向导',
            'error_code' => 'SYSTEM_NOT_INSTALLED',
            'installed' => false,
            'redirect' => 'install.html'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

require_once 'config.php';
require_once 'Auth.php';
require_once 'Notes.php';
require_once 'AIService.php';
require_once 'BackupManager.php';
require_once 'VersionControl.php';
require_once 'ShareClass.php';
require_once 'RecycleBin.php';
require_once 'UserSettings.php';
require_once 'LoginLog.php';
require_once 'RateLimiter.php';
require_once 'NoteShares.php';
require_once 'Comments.php';
require_once 'Notifications.php';
require_once 'NoteTemplates.php';
require_once 'TeamSpaces.php';
require_once 'ApiKeys.php';
require_once 'TwoFactorAuth.php';
require_once 'routes/middleware.php';
require_once 'TemplateManager.php';
require_once 'Tags.php';

// 响应函数
function sendResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ========================================
// 未安装时白名单接口的早期处理（绕过中间件，避免 RateLimiter 触发 DB 连接失败）
// ========================================
if (!$systemInstalled) {
    if ($request === 'get_csrf_token') {
        sendResponse(['success' => true, 'token' => generateCSRFToken()]);
    }
    if ($request === 'health') {
        sendResponse([
            'status' => 'degraded',
            'timestamp' => time(),
            'version' => '2.0',
            'checks' => [
                'php' => ['status' => version_compare(PHP_VERSION, '8.0', '>=') ? 'ok' : 'warning', 'version' => PHP_VERSION],
                'database' => ['status' => 'error', 'message' => '系统未安装']
            ],
            'message' => '系统未安装'
        ]);
    }
}

// 执行中间件（已确保系统安装，DB 可用）
runMiddlewares($request);

// 健康检查接口（无需登录，用于监控）
if ($request === 'health') {
    $health = [
        'status' => 'ok',
        'timestamp' => time(),
        'version' => '2.0',
        'checks' => []
    ];

    // 检查PHP版本
    $health['checks']['php'] = [
        'status' => version_compare(PHP_VERSION, '8.0', '>=') ? 'ok' : 'warning',
        'version' => PHP_VERSION
    ];

    // 检查数据库连接
    try {
        $db = Database::getInstance()->getConnection();
        $db->query("SELECT 1");
        $health['checks']['database'] = ['status' => 'ok'];
    } catch (Exception $e) {
        $health['checks']['database'] = ['status' => 'error', 'message' => $e->getMessage()];
        $health['status'] = 'degraded';
    }

    // 检查关键目录
    $dirs = ['uploads', 'attachments', 'logs'];
    foreach ($dirs as $dir) {
        $path = __DIR__ . '/' . $dir;
        $health['checks']['dir_' . $dir] = [
            'status' => is_dir($path) && is_writable($path) ? 'ok' : 'error',
            'path' => $path
        ];
    }

    // 检查加密密钥配置
    $health['checks']['encryption_key'] = [
        'status' => defined('ENCRYPTION_KEY') && !empty(ENCRYPTION_KEY) ? 'ok' : 'warning',
        'configured' => defined('ENCRYPTION_KEY') && !empty(ENCRYPTION_KEY)
    ];

    $httpCode = $health['status'] === 'ok' ? 200 : 503;
    http_response_code($httpCode);
    sendResponse($health);
}

// 初始化数据库连接（延迟初始化，确保数据库存在且已迁移）
// 注意：check_install 接口不需要数据库，已在上游早期处理
initDatabase();

// CSRF验证函数（用于POST/PUT/DELETE请求）
function verifyCSRF() {
    // 获取CSRF Token（从Header或POST数据）
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    
    // 从JSON body中获取（如果是JSON请求）
    if (empty($token) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $body = json_decode(file_get_contents('php://input'), true);
        $token = $body['csrf_token'] ?? '';
    }
    
    if (!verifyCSRFToken($token)) {
        sendResponse(['success' => false, 'message' => 'CSRF验证失败，请刷新页面重试']);
    }
}

// 管理员权限验证（增强版）
function verifyAdmin() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        sendResponse(['success' => false, 'message' => '请先登录']);
    }
    
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        logSecurityEvent('unauthorized_admin_access', [
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'action' => $_GET['action'] ?? 'unknown'
        ]);
        sendResponse(['success' => false, 'message' => '需要管理员权限']);
    }
    
    // 记录IP变化但不强制销毁Session（NAT/代理环境下IP可能变化）
    if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
        logSecurityEvent('admin_ip_change', [
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'old_ip' => $_SESSION['ip_address'],
            'new_ip' => $_SERVER['REMOTE_ADDR']
        ]);
    }
}

// 实例化服务
$auth = new Auth();
$notes = new Notes();
$ai = new AIService();
$backup = new BackupManager();
$versionControl = new VersionControl();
$share = new Share();
$noteShares = new NoteShares();
$comments = new Comments();
$notifications = new Notifications();
$templates = new NoteTemplates();
$tags = new Tags();
$teamSpaces = new TeamSpaces();
$apiKeys = new ApiKeys();
$twoFactor = new TwoFactorAuth();
$recycleBin = new RecycleBin();
$userSettings = new UserSettings();
$loginLog = new LoginLog();
$rateLimiter = new RateLimiter();

// 获取客户端IP
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// 获取当前用户ID
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

// 获取CSRF Token接口（无需登录）
if ($request === 'get_csrf_token') {
    // 安全头：防止缓存（CSRF token不应被缓存）
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $token = generateCSRFToken();

    sendResponse(['success' => true, 'token' => $token]);
}

// API速率限制已统一到 routes/middleware.php 的 rateLimitMiddleware() 中处理
// 不再在此处重复限流

// 路由处理
switch ($request) {
    // 认证相关
    // 获取注册模式（公开接口，无需登录）
    case 'get_registration_mode':
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'registration_mode'");
        $stmt->execute();
        $mode = $stmt->fetchColumn();
        sendResponse(['success' => true, 'mode' => $mode ?: 'invite']);
        break;
        
    case 'register':
        verifyCSRF();  // CSRF验证
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($auth->register(
            $data['username'],
            $data['email'],
            $data['password'],
            $data['full_name'] ?? '',
            $data['invitation_code'] ?? null,
            $data['captcha'] ?? null
        ));
        break;
        
    case 'login':
        verifyCSRF();  // CSRF验证
        $data = json_decode(file_get_contents('php://input'), true);
        
        // API速率限制：登录接口每分钟10次
        $loginCheck = $rateLimiter->checkLogin($clientIP, $data['username'] ?? null);
        if (!$loginCheck['allowed']) {
            $rateLimiter->sendRateLimitResponse($loginCheck);
        }
        
        if (empty($data) || !isset($data['username']) || !isset($data['password'])) {
            sendResponse(['success' => false, 'message' => '请输入用户名和密码']);
        }
        
        // 先检查是否被锁定
        $username = $data['username'] ?? '';
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $lockoutCheck = $loginLog->isLockedOut($username, $ipAddress);
        if (isset($lockoutCheck['locked']) && $lockoutCheck['locked']) {
            sendResponse(['success' => false, 'message' => $lockoutCheck['message']]);
        }
        
        $result = $auth->login($data['username'], $data['password'], $data['captcha'] ?? null);
        
        // 登录成功时重置速率限制和锁定计数
        if ($result['success']) {
            $rateLimiter->reset($clientIP, 'login');
            if (isset($data['username'])) {
                $rateLimiter->reset($data['username'], 'login_user');
            }
            $loginLog->clearFailedAttempts($data['username'], $ipAddress);
        }
        
        // 记录登录日志
        if ($result['success']) {
            $loginLog->logLogin($result['user']['id'] ?? null, $data['username'], 'success');
        } else {
            $loginLog->logLogin(null, $data['username'], 'failed', $result['message'] ?? '未知错误');
        }
        
        sendResponse($result);
        break;
        
    case 'logout':
        verifyCSRF();  // CSRF验证，防止强制登出攻击
        sendResponse($auth->logout());
        break;
        
    case 'current_user':
        if ($auth->isLoggedIn()) {
            sendResponse(['success' => true, 'user' => $auth->getCurrentUser()]);
        } else {
            sendResponse(['success' => false, 'message' => '未登录']);
        }
        break;
        
    case 'generate_invite':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $code = $auth->generateInvitationCode($user_id);
        sendResponse(['success' => true, 'code' => $code]);
        break;
    
    // 邀请码管理（管理员）
    case 'invite_list':
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        $status = $_GET['status'] ?? null;
        $page = intval($_GET['page'] ?? 1);
        $result = $auth->getInvitationCodes($status, $page);
        sendResponse(['success' => true, 'data' => $result]);
        break;
        
    case 'invite_generate':
        // 先读取 JSON 请求体（verifyCSRF 内部可能消费 php://input）
        $inviteData = json_decode(file_get_contents('php://input'), true);
        verifyCSRF();
        if (($_SESSION['role'] ?? '') !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        $count = intval($inviteData['count'] ?? 1);
        $expires_days = intval($inviteData['expires_days'] ?? 30);
        $count = max(1, min(50, $count));
        $expires_days = max(1, min(365, $expires_days));
        $codes = $auth->generateInvitationCodes($user_id, $count, $expires_days);
        sendResponse(['success' => true, 'codes' => $codes, 'count' => count($codes)]);
        break;
        
    case 'invite_delete':
        // 先读取 JSON 请求体（verifyCSRF 内部可能消费 php://input）
        $inviteData = json_decode(file_get_contents('php://input'), true);
        verifyCSRF();
        if (($_SESSION['role'] ?? '') !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        $code_id = intval($inviteData['id'] ?? 0);
        if ($code_id <= 0) {
            sendResponse(['success' => false, 'message' => '无效的邀请码ID']);
        }
        $result = $auth->deleteInvitationCode($code_id);
        sendResponse(['success' => $result, 'message' => $result ? '删除成功' : '邀请码不存在']);
        break;
        
    case 'invite_batch_delete':
        $inviteData = json_decode(file_get_contents('php://input'), true);
        verifyCSRF();
        if (($_SESSION['role'] ?? '') !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        $ids = $inviteData['ids'] ?? [];
        if (empty($ids) || !is_array($ids)) {
            sendResponse(['success' => false, 'message' => '请选择要删除的邀请码']);
        }
        $deleted = $auth->batchDeleteInvitationCodes($ids);
        sendResponse(['success' => $deleted > 0, 'count' => $deleted, 'message' => "已删除 {$deleted} 个邀请码"]);
        break;
    
    // 笔记相关
    case 'notes_create':
        verifyCSRF();  // CSRF验证
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($notes->create($user_id, $data));
        break;
        
    case 'notes_update':
        verifyCSRF();  // CSRF验证
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $note_id = $data['note_id'];
        unset($data['note_id']);
        sendResponse($notes->update($note_id, $user_id, $data));
        break;
        
    case 'notes_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $note_id = intval($data['note_id'] ?? 0);
        sendResponse($recycleBin->moveToRecycleBin($note_id, $user_id));
        break;
        
    case 'notes_get':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $note_id = $_GET['note_id'] ?? 0;
        sendResponse($notes->get($note_id, $user_id));
        break;
        
    case 'notes_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $filters = [
            'folder_id' => $_GET['folder_id'] ?? null,
            'is_favorite' => $_GET['is_favorite'] ?? null,
            'is_archived' => $_GET['is_archived'] ?? null,
            'search' => $_GET['search'] ?? null
        ];
        sendResponse($notes->getAll($user_id, $filters));
        break;
        
    case 'notes_search':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $keyword = $_GET['keyword'] ?? '';
        sendResponse(['success' => true, 'notes' => $notes->search($user_id, $keyword)]);
        break;
    
    // 标签管理
    case 'tags_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        sendResponse($tags->getList($user_id));
        break;
    
    case 'tags_rename':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $old_tag = $data['old_tag'] ?? '';
        $new_tag = $data['new_tag'] ?? '';
        sendResponse($tags->renameTag($user_id, $old_tag, $new_tag));
        break;
    
    case 'tags_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $tag_name = $data['tag'] ?? '';
        sendResponse($tags->deleteTagByName($user_id, $tag_name));
        break;

    case 'tags_create':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($tags->create($user_id, $data));
        break;

    case 'tags_update':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($tags->update($user_id, $data));
        break;

    case 'tags_add_to_note':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($tags->addTagToNote($user_id, $data['note_id'] ?? 0, $data['tag_id'] ?? 0));
        break;

    case 'tags_remove_from_note':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($tags->removeTagFromNote($user_id, $data['note_id'] ?? 0, $data['tag_id'] ?? 0));
        break;

    case 'tags_get_note_tags':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $note_id = intval($_GET['note_id'] ?? 0);
        sendResponse($tags->getNoteTags($user_id, $note_id));
        break;
    
    case 'notes_by_tag':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $tag_id = intval($_GET['tag_id'] ?? 0);
        $limit = intval($_GET['limit'] ?? 50);
        $offset = intval($_GET['offset'] ?? 0);
        sendResponse($tags->getNotesByTagId($user_id, $tag_id, $limit, $offset));
        break;
    
    // 笔记导出
    case 'notes_export':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $note_id = intval($_GET['note_id'] ?? 0);
        $format = $_GET['format'] ?? 'markdown';
        sendResponse($notes->exportNote($note_id, $user_id, $format));
        break;
    
    case 'notes_batch_export':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $note_ids = $data['note_ids'] ?? [];
        $format = $data['format'] ?? 'markdown';
        sendResponse($notes->exportNotes($user_id, $note_ids, $format));
        break;
    
    // 附件相关
    case 'attachment_upload':
        verifyCSRF();  // CSRF验证
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $note_id = $_POST['note_id'] ?? 0;
        if (isset($_FILES['file'])) {
            sendResponse($notes->uploadAttachment($note_id, $user_id, $_FILES['file']));
        }
        sendResponse(['success' => false, 'message' => '没有上传文件']);
        break;
        
    case 'attachment_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $note_id = $_GET['note_id'] ?? 0;
        sendResponse($notes->getAttachments($note_id, $user_id));
        break;
        
    case 'attachment_delete':
    case 'delete_attachment':
        verifyCSRF();  // CSRF验证
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $attachment_id = $data['attachment_id'] ?? $_GET['attachment_id'] ?? 0;
        sendResponse($notes->deleteAttachment($attachment_id, $user_id));
        break;
    
    // 文件夹相关
    case 'folder_create':
        verifyCSRF();  // CSRF验证
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($notes->createFolder($user_id, $data['name'], $data['parent_id'] ?? null));
        break;
        
    case 'folder_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        sendResponse($notes->getFolders($user_id));
        break;

    case 'folder_update':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $folder_id = intval($data['folder_id'] ?? 0);
        unset($data['folder_id']);
        sendResponse($notes->updateFolder($folder_id, $user_id, $data));
        break;

    case 'folder_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $folder_id = intval($_GET['folder_id'] ?? $_POST['folder_id'] ?? 0);
        sendResponse($notes->deleteFolder($folder_id, $user_id));
        break;
    
    // AI功能
    case 'ai_proofread':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($ai->proofread($data['note_id'], $user_id, $data['provider'] ?? 'chatgpt'));
        break;
        
    case 'ai_continue':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($ai->continueWriting($data['note_id'], $user_id, $data['provider'] ?? 'chatgpt'));
        break;
        
    case 'ai_summarize':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($ai->summarize($data['note_id'], $user_id, $data['provider'] ?? 'chatgpt'));
        break;
        
    case 'ai_rewrite':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($ai->rewrite($data['note_id'], $user_id, $data['provider'] ?? 'chatgpt', $data['style'] ?? 'professional'));
        break;
        
    case 'ai_history':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $note_id = $_GET['note_id'] ?? 0;
        sendResponse($ai->getInteractionHistory($note_id, $user_id));
        break;
    
    // ===== 备份管理相关 (仅管理员) =====

    // 获取备份配置列表
    case 'backup_configs':
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        sendResponse($backup->getConfigs());
        break;

    // 创建备份配置
    case 'backup_create':
        verifyCSRF();
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($backup->createConfig($data));
        break;

    // 更新备份配置
    case 'backup_update':
        verifyCSRF();
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($backup->updateConfig($data['id'] ?? 0, $data));
        break;

    // 删除备份配置
    case 'backup_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        $deleteData = json_decode(file_get_contents('php://input'), true);
        $id = intval($deleteData['id'] ?? $_GET['id'] ?? 0);
        sendResponse($backup->deleteConfig($id));
        break;

    // 执行备份
    case 'backup_perform':
        verifyCSRF();
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        $backup_id = intval($_GET['backup_id'] ?? 0);
        sendResponse($backup->performBackup($backup_id));
        break;

    // 获取备份日志
    case 'backup_logs':
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        $config_id = isset($_GET['config_id']) ? intval($_GET['config_id']) : null;
        sendResponse($backup->getLogs($config_id));
        break;

    // 测试存储连接
    case 'backup_test_connection':
        verifyCSRF();
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($backup->testConnection($data));
        break;

    // 获取支持的存储类型
    case 'backup_storage_types':
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        sendResponse(['success' => true, 'types' => StorageFactory::getSupportedTypes()]);
        break;
    
    // 管理员功能 - 统计数据
    case 'admin_stats':
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        
        $db = Database::getInstance()->getConnection();
        
        $stats = [
            'total_users' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'total_notes' => $db->query("SELECT COUNT(*) FROM notes")->fetchColumn(),
            'total_attachments' => $db->query("SELECT COUNT(*) FROM attachments")->fetchColumn(),
            'ai_interactions' => $db->query("SELECT COUNT(*) FROM ai_interactions")->fetchColumn()
        ];
        
        sendResponse(['success' => true, 'data' => $stats]);
        break;
    
    // 管理员功能 - 获取所有笔记
    case 'admin_all_notes':
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        
        $db = Database::getInstance()->getConnection();
        $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
        $offset = max(0, intval($_GET['offset'] ?? 0));
        
        $countStmt = $db->query(
            "SELECT COUNT(*) FROM notes n
             JOIN users u ON n.user_id = u.id
             WHERE n.deleted_at IS NULL"
        );
        $total = (int)$countStmt->fetchColumn();
        
        $stmt = $db->prepare(
            "SELECT n.*, u.username 
             FROM notes n 
             JOIN users u ON n.user_id = u.id 
             WHERE n.deleted_at IS NULL
             ORDER BY n.updated_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        
        sendResponse(['success' => true, 'notes' => $stmt->fetchAll(), 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
        break;
    
    // 管理员功能 - 更新任意笔记
    case 'admin_update_note':
        verifyCSRF();
        verifyAdmin();
        
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::getInstance()->getConnection();
        
        $note_id = intval($data['note_id'] ?? 0);
        
        // 验证笔记是否存在
        $stmt = $db->prepare("SELECT id FROM notes WHERE id = ?");
        $stmt->execute([$note_id]);
        if (!$stmt->fetch()) {
            sendResponse(['success' => false, 'message' => '笔记不存在']);
        }
        
        $title = sanitizeInput($data['title'] ?? '');
        $content = $data['content'] ?? '';
        // 内容使用htmlspecialchars防止XSS，但不strip_tags以保留格式
        $content = htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stmt = $db->prepare("UPDATE notes SET title = ?, content = ? WHERE id = ?");
        $stmt->execute([$title, $content, $note_id]);
        
        sendResponse(['success' => true, 'message' => '笔记已更新']);
        break;
    
    // 管理员功能 - 删除任意笔记
    case 'admin_delete_note':
        verifyCSRF();
        verifyAdmin();
        
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::getInstance()->getConnection();
        
        $note_id = intval($data['note_id'] ?? 0);
        
        // 验证笔记是否存在
        $stmt = $db->prepare("SELECT id FROM notes WHERE id = ?");
        $stmt->execute([$note_id]);
        if (!$stmt->fetch()) {
            sendResponse(['success' => false, 'message' => '笔记不存在']);
        }
        
        // 删除关联的附件文件（磁盘清理）
        $attStmt = $db->prepare("SELECT file_path FROM attachments WHERE note_id = ?");
        $attStmt->execute([$note_id]);
        $attachments = $attStmt->fetchAll();
        foreach ($attachments as $attachment) {
            if (!empty($attachment['file_path']) && file_exists($attachment['file_path'])) {
                @unlink($attachment['file_path']);
            }
        }
        
        // 删除笔记（级联删除附件记录、版本、分享等）
        $stmt = $db->prepare("DELETE FROM notes WHERE id = ?");
        $stmt->execute([$note_id]);
        
        logSecurityEvent('admin_deleted_note', [
            'admin_id' => $_SESSION['user_id'] ?? 'unknown',
            'note_id' => $note_id,
            'attachments_cleaned' => count($attachments)
        ]);
        
        sendResponse(['success' => true, 'message' => '笔记已删除']);
        break;
    
    // 管理员功能 - 获取所有用户
    case 'admin_all_users':
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT id, username, email, role, status, created_at FROM users ORDER BY created_at DESC");
        
        sendResponse(['success' => true, 'users' => $stmt->fetchAll()]);
        break;
    
    // 管理员功能 - 切换用户状态
    case 'admin_toggle_user_status':
        verifyCSRF();
        verifyAdmin();
        
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::getInstance()->getConnection();
        
        // 验证状态值（必须与数据库 users.status ENUM 一致）
        $allowedStatuses = ['active', 'suspended', 'disabled'];
        if (!in_array($data['status'], $allowedStatuses)) {
            sendResponse(['success' => false, 'message' => '无效的状态值']);
        }
        
        $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$data['status'], $data['user_id']]);
        
        sendResponse(['success' => true, 'message' => '用户状态已更新']);
        break;
    
    // 管理员功能 - 删除用户
    case 'admin_delete_user':
        verifyCSRF();
        verifyAdmin();
        
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::getInstance()->getConnection();
        
        // 防止删除自己
        if ($data['user_id'] == $_SESSION['user_id']) {
            sendResponse(['success' => false, 'message' => '不能删除自己的账号']);
        }
        
        $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
        $stmt->execute([$data['user_id']]);
        
        if ($stmt->rowCount() === 0) {
            sendResponse(['success' => false, 'message' => '用户不存在、已是管理员或删除失败']);
        }
        
        sendResponse(['success' => true, 'message' => '用户已删除']);
        break;
    
    // 公开接口 - 获取AI配置（仅返回默认提供商，不含敏感信息）
    case 'ai_config':
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'default_ai_provider'");
        $stmt->execute();
        $result = $stmt->fetch();
        sendResponse([
            'success' => true,
            'default_provider' => $result ? $result['setting_value'] : 'chatgpt'
        ]);
        break;
    
    // 管理员功能 - 获取系统设置
    case 'admin_get_settings':
        if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
            sendResponse(['success' => false, 'message' => '需要管理员权限']);
        }
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
        $settings = [];
        
        while ($row = $stmt->fetch()) {
            $key = $row['setting_key'];
            $value = $row['setting_value'];
            $encryptedKeys = ['chatgpt_api_key', 'doubao_api_key', 'claude_api_key', 'custom_openai_api_key'];
            if (in_array($key, $encryptedKeys) && !empty($value)) {
                $value = !empty($value) ? '••••••••' : '';
            }
            $settings[$key] = $value;
        }
        
        sendResponse(['success' => true, 'settings' => $settings]);
        break;
    
    // 管理员功能 - 保存系统设置
    case 'admin_save_settings':
        verifyCSRF();
        verifyAdmin();
        
        $data = json_decode(file_get_contents('php://input'), true);
        $db = Database::getInstance()->getConnection();
        
        // 白名单验证设置键名
        $allowedSettings = [
            'registration_mode', 'site_name', 'site_description',
            'max_upload_size', 'default_note_limit', 'default_ai_provider',
            'default_template',
            'chatgpt_api_key', 'chatgpt_api_url',
            'doubao_api_key', 'doubao_api_url',
            'claude_api_key', 'claude_api_url',
            'custom_openai_name', 'custom_openai_api_key',
            'custom_openai_api_url', 'custom_openai_model',
            'allowed_file_types'
        ];
        $encryptedKeys = ['chatgpt_api_key', 'doubao_api_key', 'claude_api_key', 'custom_openai_api_key'];
        $stmt = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedSettings)) {
                if (in_array($key, $encryptedKeys) && !empty($value)) {
                    $value = encryptData($value);
                }
                $stmt->execute([$value, $key]);
            }
        }
        
        logSecurityEvent('admin_settings_updated', [
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'keys' => implode(', ', array_keys($data))
        ]);
        
        sendResponse(['success' => true, 'message' => '设置已保存']);
        break;
    
    


    
    // ===== V2.0 新增接口 =====
    
    // 版本控制相关
    case 'version_save':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($versionControl->saveVersion(
            $data['note_id'],
            $user_id,
            $data['content'],
            $data['change_description'] ?? ''
        ));
        break;
        
    case 'version_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $note_id = $_GET['note_id'] ?? 0;
        $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
        $offset = max(0, intval($_GET['offset'] ?? 0));
        sendResponse($versionControl->getVersionList($note_id, $user_id, $limit, $offset));
        break;
        
    case 'version_get':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $version_id = $_GET['version_id'] ?? 0;
        sendResponse($versionControl->getVersion($version_id, $user_id));
        break;
        
    case 'version_rollback':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($versionControl->rollbackToVersion(
            $data['note_id'],
            $data['version_id'],
            $user_id
        ));
        break;
        
    case 'version_compare':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $version1_id = $_GET['version1_id'] ?? 0;
        $version2_id = $_GET['version2_id'] ?? 0;
        sendResponse($versionControl->compareVersions($version1_id, $version2_id, $user_id));
        break;
        
    case 'version_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($versionControl->deleteVersion($data['version_id'], $user_id));
        break;
    
    // 分享相关
    case 'share_create':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($share->createShare($data['note_id'], $user_id, [
            'password' => $data['password'] ?? null,
            'expires_in' => $data['expires_in'] ?? null,
            'allow_download' => $data['allow_download'] ?? true
        ]));
        break;
        
    case 'share_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $note_id = $_GET['note_id'] ?? null;
        $limit = intval($_GET['limit'] ?? 20);
        $offset = intval($_GET['offset'] ?? 0);
        sendResponse($share->getShareList($user_id, $note_id, $limit, $offset));
        break;
        
    case 'share_update':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($share->updateShare($data['share_id'], $user_id, $data));
        break;
        
    case 'share_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }

        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($share->deleteShare($data['share_id'], $user_id));
        break;

    // ===== 公开分享访问（无需登录）=====
    case 'share_get':
        $token = $_GET['token'] ?? '';
        if (empty($token)) {
            sendResponse(['success' => false, 'message' => '缺少分享token']);
        }
        sendResponse($share->getShareInfo($token));
        break;

    case 'share_content':
        $data = json_decode(file_get_contents('php://input'), true);
        $token = $data['token'] ?? $_GET['token'] ?? '';
        $password = $data['password'] ?? null;
        if (empty($token)) {
            sendResponse(['success' => false, 'message' => '缺少分享token']);
        }
        sendResponse($share->getSharedContent($token, $password));
        break;

    // ===== 笔记协作分享（用户间共享）=====
    case 'note_share_to_user':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($noteShares->shareToUser(
            $data['note_id'],
            $user_id,
            $data['username'],
            $data['permission'] ?? 'read'
        ));
        break;

    case 'note_share_list_received':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        sendResponse($noteShares->getSharedToMe($user_id));
        break;

    case 'note_share_list_sent':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        sendResponse($noteShares->getSharedByMe($user_id));
        break;

    case 'note_share_collaborators':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $note_id = intval($_GET['note_id'] ?? 0);
        sendResponse($noteShares->getNoteShares($note_id, $user_id));
        break;

    case 'note_share_update_permission':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($noteShares->updatePermission($data['share_id'], $user_id, $data['permission']));
        break;

    case 'note_share_revoke':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($noteShares->revokeShare($data['share_id'], $user_id));
        break;

    // ===== 评论系统 =====
    case 'comment_add':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($comments->addComment(
            $data['note_id'],
            $user_id,
            $data['content'],
            $data['parent_id'] ?? null
        ));
        break;

    case 'comment_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $note_id = intval($_GET['note_id'] ?? 0);
        sendResponse($comments->getComments($note_id, $user_id));
        break;

    case 'comment_update':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($comments->updateComment(
            $data['comment_id'],
            $user_id,
            $data['content']
        ));
        break;

    case 'comment_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($comments->deleteComment($data['comment_id'], $user_id));
        break;

    case 'comment_count':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $note_id = intval($_GET['note_id'] ?? 0);
        $count = $comments->getCommentCount($note_id);
        sendResponse(['success' => true, 'count' => $count]);
        break;

    // 回收站相关
    case 'note_soft_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($recycleBin->moveToRecycleBin($data['note_id'], $user_id));
        break;
        
    case 'note_restore':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($recycleBin->restoreFromRecycleBin($data['note_id'], $user_id));
        break;
        
    case 'note_permanent_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($recycleBin->permanentDelete($data['note_id'], $user_id));
        break;
        
    case 'recycle_bin_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
        $offset = max(0, intval($_GET['offset'] ?? 0));
        sendResponse($recycleBin->getRecycleBinList($user_id, $limit, $offset));
        break;
        
    case 'recycle_bin_empty':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        sendResponse($recycleBin->emptyRecycleBin($user_id));
        break;
        
    case 'recycle_bin_stats':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        sendResponse($recycleBin->getRecycleBinStats($user_id));
        break;
    
    // 用户设置相关
    case 'settings_get':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        sendResponse($userSettings->getSettings($user_id));
        break;
        
    case 'settings_update':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($userSettings->updateSettings($user_id, $data));
        break;
        
    case 'settings_reset':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        sendResponse($userSettings->resetToDefault($user_id));
        break;
    
    // UI模板管理相关（注意：与笔记模板 templates_* 端点区分）
    case 'ui_templates_list':
        $templateManager = new TemplateManager();
        sendResponse(['success' => true, 'templates' => $templateManager->getTemplates()]);
        break;
    
    case 'ui_template_css':
        $templateManager = new TemplateManager();
        $templateId = $_GET['t'] ?? 'default';
        
        $result = $templateManager->getTemplateCSS($templateId);
        if ($result['success']) {
            header('Content-Type: text/css; charset=utf-8');
            header('Cache-Control: public, max-age=86400');
            echo $result['css'];
            exit;
        }
        sendResponse($result);
        break;
    
    case 'ui_template_current':
        $templateManager = new TemplateManager();
        $template = 'default';
        if ($auth->isLoggedIn()) {
            $template = $templateManager->getUserTemplate($user_id);
        } else {
            $template = $templateManager->getDefaultTemplate();
        }
        sendResponse(['success' => true, 'template' => $template]);
        break;
    
    // 登录日志相关
    case 'login_history':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
        $offset = max(0, intval($_GET['offset'] ?? 0));
        sendResponse($loginLog->getLoginHistory($user_id, $limit, $offset));
        break;
        
    case 'login_stats':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        sendResponse($loginLog->getLoginStats($user_id));
        break;
    
    case 'user_stats':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        
        $db = Database::getInstance()->getConnection();
        
        // 获取笔记统计
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_notes,
                COUNT(CASE WHEN deleted_at IS NOT NULL THEN 1 END) as archived_notes,
                COUNT(CASE WHEN is_favorite = 1 AND deleted_at IS NULL THEN 1 END) as favorite_notes,
                COUNT(CASE WHEN (SELECT COUNT(*) FROM attachments WHERE note_id = notes.id) > 0 THEN 1 END) as notes_with_attachments,
                SUM(LENGTH(content)) as total_chars
            FROM notes 
            WHERE user_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$user_id]);
        $noteStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 获取文件夹数
        $stmt = $db->prepare("SELECT COUNT(*) as folder_count FROM folders WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $folderStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 获取本月创作统计
        $stmt = $db->prepare("
            SELECT COUNT(*) as monthly_notes, 
                   SUM(LENGTH(content)) as monthly_chars
            FROM notes 
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$user_id]);
        $monthlyStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 获取今日创作
        $stmt = $db->prepare("
            SELECT COUNT(*) as today_notes,
                   SUM(LENGTH(content)) as today_chars
            FROM notes 
            WHERE user_id = ? AND created_at >= CURDATE()
        ");
        $stmt->execute([$user_id]);
        $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        sendResponse([
            'success' => true,
            'stats' => [
                'total_notes' => (int)$noteStats['total_notes'],
                'archived_notes' => (int)$noteStats['archived_notes'],
                'favorite_notes' => (int)$noteStats['favorite_notes'],
                'notes_with_attachments' => (int)$noteStats['notes_with_attachments'],
                'total_chars' => (int)$noteStats['total_chars'],
                'folder_count' => (int)$folderStats['folder_count'],
                'monthly_notes' => (int)$monthlyStats['monthly_notes'],
                'monthly_chars' => (int)$monthlyStats['monthly_chars'],
                'today_notes' => (int)$todayStats['today_notes'],
                'today_chars' => (int)$todayStats['today_chars']
            ]
        ]);
        break;

    case 'check_lockout':
        // 此接口允许未登录用户检查自己的锁定状态（登录流程中使用）
        // 已登录用户只能查询自己的锁定状态
        $data = json_decode(file_get_contents('php://input'), true);
        
        if ($auth->isLoggedIn()) {
            // 已登录用户：只能查询自己的锁定状态
            $user = $auth->getCurrentUser();
            $username = $user['username'];
        } else {
            // 未登录用户：只能查询自己尝试登录的用户名（防止用户枚举）
            $username = $data['username'] ?? '';
            // 仅允许查询当前IP的锁定状态
            $ipAddress = $_SERVER['REMOTE_ADDR'];
            // 使用公开的isLockedOutForIP方法（不带用户名）
            sendResponse($loginLog->isLockedOutForIP($ipAddress));
            break;
        }
        
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        sendResponse($loginLog->isLockedOut($username, $ipAddress));
        break;

    // ===== 通知系统 =====
    case 'notifications_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
        $offset = max(0, intval($_GET['offset'] ?? 0));
        sendResponse($notifications->getNotifications($user_id, $limit, $offset));
        break;

    case 'notifications_unread_count':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $count = $notifications->getUnreadCount($user_id);
        sendResponse(['success' => true, 'count' => $count]);
        break;

    case 'notifications_mark_read':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $notification_id = intval($data['notification_id'] ?? 0);
        sendResponse($notifications->markAsRead($notification_id, $user_id));
        break;

    case 'notifications_mark_all_read':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        sendResponse($notifications->markAllAsRead($user_id));
        break;

    case 'notifications_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $notification_id = intval($data['notification_id'] ?? 0);
        sendResponse($notifications->deleteNotification($notification_id, $user_id));
        break;

    case 'notifications_clear':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        sendResponse($notifications->clearAll($user_id));
        break;

    // ===== 笔记模板 =====
    case 'templates_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $category = $_GET['category'] ?? null;
        sendResponse($templates->getList($user_id, $category));
        break;
    
    case 'templates_get':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $template_id = intval($_GET['template_id'] ?? 0);
        sendResponse($templates->get($template_id, $user_id));
        break;
    
    case 'templates_create':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($templates->create($user_id, $data));
        break;
    
    case 'templates_update':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $template_id = intval($data['template_id'] ?? 0);
        sendResponse($templates->update($template_id, $user_id, $data));
        break;
    
    case 'templates_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $template_id = intval($data['template_id'] ?? 0);
        sendResponse($templates->delete($template_id, $user_id));
        break;
    
    case 'templates_use':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $template_id = intval($_GET['template_id'] ?? 0);
        sendResponse($templates->createNote($template_id, $user_id));
        break;
    
    case 'templates_duplicate':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $template_id = intval($data['template_id'] ?? 0);
        sendResponse($templates->duplicate($template_id, $user_id));
        break;
    
    case 'templates_categories':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        sendResponse($templates->getCategories($user_id));
        break;

    // ===== 团队空间 =====
    case 'teams_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        sendResponse($teamSpaces->getMyTeams($user_id));
        break;
    
    case 'teams_create':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($teamSpaces->createTeam($user_id, $data));
        break;
    
    case 'teams_get':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $team_id = intval($_GET['team_id'] ?? 0);
        sendResponse($teamSpaces->getTeam($team_id, $user_id));
        break;
    
    case 'teams_update':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $team_id = intval($data['team_id'] ?? 0);
        sendResponse($teamSpaces->updateTeam($team_id, $user_id, $data));
        break;
    
    case 'teams_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $team_id = intval($data['team_id'] ?? 0);
        sendResponse($teamSpaces->deleteTeam($team_id, $user_id));
        break;
    
    case 'teams_invite':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $team_id = intval($data['team_id'] ?? 0);
        sendResponse($teamSpaces->inviteMember($team_id, $user_id, $data));
        break;
    
    case 'teams_add_member':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $team_id = intval($data['team_id'] ?? 0);
        sendResponse($teamSpaces->addMember($team_id, $user_id, $data));
        break;
    
    case 'teams_remove_member':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $team_id = intval($data['team_id'] ?? 0);
        $target_id = intval($data['user_id'] ?? 0);
        sendResponse($teamSpaces->removeMember($team_id, $user_id, $target_id));
        break;
    
    case 'teams_accept_invite':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $token = $data['token'] ?? '';
        sendResponse($teamSpaces->acceptInvite($user_id, $token));
        break;
    
    case 'teams_get_notes':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $team_id = intval($_GET['team_id'] ?? 0);
        $folder_id = isset($_GET['folder_id']) ? intval($_GET['folder_id']) : null;
        sendResponse($teamSpaces->getTeamNotes($team_id, $user_id, $folder_id));
        break;

    case 'teams_share_note':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $note_id = intval($data['note_id'] ?? 0);
        $team_id = intval($data['team_id'] ?? 0);
        $permission = $data['permission'] ?? 'read';
        sendResponse($teamSpaces->shareNoteToTeam($note_id, $team_id, $user_id, $permission));
        break;

    case 'teams_create_folder':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $team_id = intval($data['team_id'] ?? 0);
        sendResponse($teamSpaces->createFolder($team_id, $user_id, $data));
        break;

    case 'teams_get_folders':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $team_id = intval($_GET['team_id'] ?? 0);
        sendResponse($teamSpaces->getFolders($team_id, $user_id));
        break;

    case 'teams_update_member_role':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $team_id = intval($data['team_id'] ?? 0);
        $target_member_id = intval($data['user_id'] ?? 0);
        $new_role = $data['role'] ?? 'member';
        sendResponse($teamSpaces->updateMemberRole($team_id, $user_id, $target_member_id, $new_role));
        break;

    // ===== API密钥管理 =====
    case 'api_keys_list':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        sendResponse($apiKeys->getList($user_id));
        break;

    case 'api_keys_create':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($apiKeys->create($user_id, $data['name'], $data['permissions'] ?? []));
        break;

    case 'api_keys_delete':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($apiKeys->delete($data['key_id'], $user_id));
        break;

    case 'api_keys_toggle':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($apiKeys->toggleStatus($data['key_id'], $user_id));
        break;

    // ===== 两步验证 =====
    case '2fa_status':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        sendResponse($twoFactor->getStatus($user_id));
        break;

    case '2fa_generate':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        sendResponse($twoFactor->generateSecret($user_id));
        break;

    case '2fa_enable':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($twoFactor->enable($user_id, $data['secret'], $data['code']));
        break;

    case '2fa_disable':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($twoFactor->disable($user_id, $data['code']));
        break;

    case '2fa_verify':
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($twoFactor->verifyLogin($user_id, $data['code']));
        break;

    // ===== 用户资料和密码 =====
    case 'update_profile':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($auth->updateProfile($user_id, $data));
        break;

    case 'change_password':
        verifyCSRF();
        if (!$auth->isLoggedIn()) {
            sendResponse(['success' => false, 'message' => '需要登录']);
        }
        $data = json_decode(file_get_contents('php://input'), true);
        sendResponse($auth->changePassword(
            $user_id,
            $data['old_password'] ?? '',
            $data['new_password'] ?? ''
        ));
        break;

    default:
        sendResponse(['success' => false, 'message' => '未知的请求']);
}
?>
