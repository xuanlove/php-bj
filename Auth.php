<?php
require_once 'config.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    // 用户注册
    public function register($username, $email, $password, $full_name = '', $invitation_code = null, $captcha = null) {
        $captcha_enabled = $this->getSystemSetting('captcha_enabled');
        if ($captcha_enabled !== 'false') {
            if (empty($captcha)) {
                return ['success' => false, 'message' => '请输入验证码'];
            }
            
            require_once 'CaptchaClass.php';
            $captcha_verify = Captcha::verify($captcha);
            if (!$captcha_verify['success']) {
                return $captcha_verify;
            }
        }
        
        // 输入验证和过滤
        $username = sanitizeInput($username);
        $email = sanitizeInput($email);
        $full_name = sanitizeInput($full_name);
        
        // 验证用户名格式
        if (!validateUsername($username)) {
            return ['success' => false, 'message' => '用户名只能包含字母、数字和下划线，长度3-20位'];
        }
        
        // 验证邮箱格式
        if (!validateEmail($email)) {
            return ['success' => false, 'message' => '邮箱格式不正确'];
        }
        
        // 验证密码强度
        $passwordValidation = validatePassword($password);
        if (!$passwordValidation['valid']) {
            return ['success' => false, 'message' => $passwordValidation['message']];
        }
        
        // 检查注册模式
        $registration_mode = $this->getSystemSetting('registration_mode');
        
        if ($registration_mode === 'closed') {
            return ['success' => false, 'message' => '注册已关闭'];
        }
        
        if ($registration_mode === 'invite' && empty($invitation_code)) {
            return ['success' => false, 'message' => '需要邀请码才能注册'];
        }
        
        // 验证邀请码（仅验证，不立即标记为已使用）
        $invited_by = null;
        if (!empty($invitation_code)) {
            $code_check = $this->validateInvitationCode($invitation_code);
            if (!$code_check['valid']) {
                return ['success' => false, 'message' => $code_check['message']];
            }
            $invited_by = $code_check['created_by'];
        }
        
        // 检查用户名和邮箱是否已存在
        $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => '用户名或邮箱已存在'];
        }
        
        // 创建用户（使用事务确保邀请码状态和用户创建原子性）
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        try {
            // 开始事务 - 包含邀请码锁定验证与用户创建，防止竞态条件
            $this->db->beginTransaction();
            
            // 在事务内重新验证邀请码，防止并发使用同一邀请码
            if (!empty($invitation_code)) {
                $stmt = $this->db->prepare(
                    "SELECT id, created_by FROM invitation_codes 
                     WHERE code = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())
                     FOR UPDATE"
                );
                $stmt->execute([$invitation_code]);
                $codeRow = $stmt->fetch();
                if (!$codeRow) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => '邀请码无效或已过期'];
                }
                $invited_by = $codeRow['created_by'];
            }
            
            // 创建用户
            $stmt = $this->db->prepare("INSERT INTO users (username, email, password, full_name, invited_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$username, $email, $hashed_password, $full_name, $invited_by]);
            $user_id = $this->db->lastInsertId();
            
            // 如果使用了邀请码,标记为已使用（在事务内执行）
            if (!empty($invitation_code)) {
                $this->markInvitationCodeAsUsed($invitation_code, $user_id);
            }
            
            // 提交事务
            $this->db->commit();
            
            // 记录安全日志
            logSecurityEvent('user_registered', ['user_id' => $user_id, 'username' => $username]);
            
            return ['success' => true, 'message' => '注册成功', 'user_id' => $user_id];
        } catch (Exception $e) {
            // 回滚事务
            $this->db->rollBack();
            error_log("注册失败: " . $e->getMessage());
            return ['success' => false, 'message' => '注册失败，请联系管理员'];
        }
    }
    
    // 用户登录
    public function login($username, $password, $captcha = null) {
        $captcha_enabled = $this->getSystemSetting('captcha_enabled');
        if ($captcha_enabled !== 'false') {
            if (empty($captcha)) {
                return ['success' => false, 'message' => '请输入验证码'];
            }
            
            require_once 'CaptchaClass.php';
            $captcha_verify = Captcha::verify($captcha);
            if (!$captcha_verify['success']) {
                return $captcha_verify;
            }
        }
        
        $stmt = $this->db->prepare("SELECT id, username, email, full_name, avatar, role, status, last_login, created_at FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // 用户不存在也用统一错误消息，防止用户枚举
            return ['success' => false, 'message' => '用户名或密码错误'];
        }
        
        // 单独查询密码哈希，不返回给前端
        $pwdStmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
        $pwdStmt->execute([$user['id']]);
        $pwdRow = $pwdStmt->fetch();
        
        if ($pwdRow && password_verify($password, $pwdRow['password'])) {
            // Session固定防护：登录成功后重新生成Session ID（保留现有session数据）
            session_regenerate_id(true);

            // 更新最后登录时间
            $update = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update->execute([$user['id']]);

            // 设置会话
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time(); // 记录登录时间

            return ['success' => true, 'message' => '登录成功', 'user' => $user];
        }
        
        return ['success' => false, 'message' => '用户名或密码错误'];
    }
    
    // 用户登出
    public function logout() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return ['success' => true, 'message' => '已退出登录'];
    }
    
    // 检查是否已登录
    public function isLoggedIn() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    // 获取当前用户
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT id, username, email, full_name, avatar, role FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        // 用户已被禁用时强制登出
        if (!$user) {
            $this->logout();
        }
        return $user;
    }
    
    // 生成邀请码
    public function generateInvitationCode($user_id, $expires_days = 30) {
        $code = bin2hex(random_bytes(16));
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_days} days"));
        
        $stmt = $this->db->prepare("INSERT INTO invitation_codes (code, created_by, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$code, $user_id, $expires_at]);
        
        return $code;
    }
    
    // 批量生成邀请码
    public function generateInvitationCodes($user_id, $count = 5, $expires_days = 30) {
        $count = max(1, min(50, intval($count))); // 限制1-50个
        $codes = [];
        
        for ($i = 0; $i < $count; $i++) {
            $code = $this->generateInvitationCode($user_id, $expires_days);
            $codes[] = $code;
        }
        
        return $codes;
    }
    
    // 获取邀请码列表
    public function getInvitationCodes($status = null, $page = 1, $per_page = 20) {
        $offset = ($page - 1) * $per_page;
        
        $sql = "SELECT ic.*, 
                u1.username as created_by_name,
                u2.username as used_by_name
                FROM invitation_codes ic 
                LEFT JOIN users u1 ON ic.created_by = u1.id 
                LEFT JOIN users u2 ON ic.used_by = u2.id";
        
        $params = [];
        if ($status && in_array($status, ['active', 'used', 'expired'])) {
            $sql .= " WHERE ic.status = ?";
            $params[] = $status;
        }
        
        // 标记已过期但状态仍为active的邀请码
        $this->db->exec("UPDATE invitation_codes SET status = 'expired' WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW()");
        
        $sql .= " ORDER BY ic.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $per_page;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $codes = $stmt->fetchAll();
        
        // 获取总数
        $countSql = "SELECT COUNT(*) as total FROM invitation_codes";
        if ($status && in_array($status, ['active', 'used', 'expired'])) {
            $countSql .= " WHERE status = ?";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute([$status]);
        } else {
            $countStmt = $this->db->query($countSql);
        }
        $total = $countStmt->fetch()['total'];
        
        return [
            'codes' => $codes,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        ];
    }
    
    // 删除邀请码
    public function deleteInvitationCode($code_id) {
        $stmt = $this->db->prepare("DELETE FROM invitation_codes WHERE id = ?");
        $stmt->execute([$code_id]);
        return $stmt->rowCount() > 0;
    }
    
    // 批量删除邀请码
    public function batchDeleteInvitationCodes($ids) {
        if (empty($ids) || !is_array($ids)) {
            return 0;
        }
        // 过滤并限制数量
        $ids = array_unique(array_map('intval', $ids));
        $ids = array_filter($ids, function($id) { return $id > 0; });
        if (empty($ids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("DELETE FROM invitation_codes WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));
        return $stmt->rowCount();
    }
    
    // 验证邀请码
    private function validateInvitationCode($code) {
        $stmt = $this->db->prepare("SELECT * FROM invitation_codes WHERE code = ? AND status = 'active' AND (expires_at IS NULL OR expires_at > NOW())");
        $stmt->execute([$code]);
        $invitation = $stmt->fetch();
        
        if (!$invitation) {
            return ['valid' => false, 'message' => '邀请码无效或已过期'];
        }
        
        return ['valid' => true, 'created_by' => $invitation['created_by']];
    }
    
    // 标记邀请码为已使用
    private function markInvitationCodeAsUsed($code, $used_by) {
        $stmt = $this->db->prepare("UPDATE invitation_codes SET status = 'used', used_by = ?, used_at = NOW() WHERE code = ?");
        $stmt->execute([$used_by, $code]);
    }
    
    // 获取系统设置
    private function getSystemSetting($key) {
        $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : null;
    }
    
    // 更新用户信息
    public function updateProfile($user_id, $data) {
        // 白名单允许的字段
        $allowed_fields = ['full_name', 'email', 'avatar'];
        $updates = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            // 严格验证字段名是否在白名单中
            if (in_array($key, $allowed_fields)) {
                // 输入过滤
                $value = sanitizeInput($value);
                
                // 验证邮箱格式
                if ($key === 'email' && !validateEmail($value)) {
                    return ['success' => false, 'message' => '邮箱格式不正确'];
                }
                
                // 使用参数化查询（字段名已在白名单中验证）
                $updates[] = "`" . $key . "` = ?";
                $values[] = $value;
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'message' => '没有可更新的字段'];
        }
        
        $values[] = $user_id;
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        try {
            $stmt->execute($values);
            logSecurityEvent('profile_updated', ['user_id' => $user_id]);
            return ['success' => true, 'message' => '更新成功'];
        } catch (Exception $e) {
            error_log("更新失败: " . $e->getMessage());
            return ['success' => false, 'message' => '更新失败，请联系管理员'];
        }
    }
    
    // 修改密码
    public function changePassword($user_id, $old_password, $new_password) {
        $stmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!password_verify($old_password, $user['password'])) {
            return ['success' => false, 'message' => '原密码错误'];
        }
        
        // 验证新密码强度
        $passwordValidation = validatePassword($new_password);
        if (!$passwordValidation['valid']) {
            return ['success' => false, 'message' => $passwordValidation['message']];
        }
        
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->execute([$hashed_password, $user_id]);
        
        logSecurityEvent('password_changed', ['user_id' => $user_id]);
        
        return ['success' => true, 'message' => '密码修改成功'];
    }
}
?>
