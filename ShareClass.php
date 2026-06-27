<?php
/**
 * 笔记分享管理类
 * 
 * 功能：
 * - 创建分享链接
 * - 获取分享信息
 * - 获取分享内容
 * - 分享列表管理
 * - 更新/删除分享
 * - 访问统计
 */

require_once 'config.php';

class Share {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * 创建分享链接
     * 
     * @param int $note_id 笔记ID
     * @param int $user_id 用户ID
     * @param array $options 分享选项（password, expires_in, allow_download）
     * @return array
     */
    public function createShare($note_id, $user_id, $options = []) {
        try {
            // 验证笔记所有权
            $stmt = $this->db->prepare("SELECT id, title, content, content_type FROM notes WHERE id = ? AND user_id = ?");
            $stmt->execute([$note_id, $user_id]);
            $note = $stmt->fetch();
            
            if (!$note) {
                return ['success' => false, 'message' => '笔记不存在或没有权限'];
            }
            
            // 检查是否已有有效分享
            $stmt = $this->db->prepare(
                "SELECT id, share_token FROM shared_notes 
                 WHERE note_id = ? AND user_id = ? 
                 AND (expires_at IS NULL OR expires_at > NOW())
                 LIMIT 1"
            );
            $stmt->execute([$note_id, $user_id]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                return [
                    'success' => true, 
                    'share_id' => $existing['id'],
                    'share_token' => $existing['share_token'],
                    'message' => '该笔记已有有效分享链接'
                ];
            }
            
            // 生成分享token
            $token = bin2hex(random_bytes(32));
            
            // 处理密码
            $password = null;
            if (!empty($options['password'])) {
                $password = password_hash($options['password'], PASSWORD_DEFAULT);
            }
            
            // 处理过期时间
            $expires_at = null;
            if (!empty($options['expires_in'])) {
                $expires_at = date('Y-m-d H:i:s', strtotime("+{$options['expires_in']} seconds"));
            }
            
            $allow_download = isset($options['allow_download']) ? (bool)$options['allow_download'] : true;
            
            $stmt = $this->db->prepare(
                "INSERT INTO shared_notes (note_id, user_id, share_token, password_hash, expires_at, allow_download) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$note_id, $user_id, $token, $password, $expires_at, (int)$allow_download]);
            
            $share_id = $this->db->lastInsertId();
            
            logSecurityEvent('share_created', [
                'share_id' => $share_id,
                'note_id' => $note_id,
                'user_id' => $user_id
            ]);
            
            return [
                'success' => true,
                'share_id' => $share_id,
                'share_token' => $token,
                'expires_at' => $expires_at,
                'message' => '分享链接创建成功'
            ];
            
        } catch (Exception $e) {
            error_log("创建分享失败: " . $e->getMessage());
            return ['success' => false, 'message' => '创建分享失败，请稍后重试'];
        }
    }
    
    /**
     * 获取分享信息（用于分享页面展示）
     * 
     * @param string $token 分享token
     * @return array
     */
    public function getShareInfo($token) {
        try {
            $stmt = $this->db->prepare(
                "SELECT sn.*, n.title, n.content_type, u.username 
                 FROM shared_notes sn
                 JOIN notes n ON sn.note_id = n.id
                 JOIN users u ON sn.user_id = u.id
                 WHERE sn.share_token = ?"
            );
            $stmt->execute([$token]);
            $share = $stmt->fetch();
            
            if (!$share) {
                return ['success' => false, 'message' => '分享链接不存在'];
            }
            
            // 检查是否过期
            $expired = false;
            if ($share['expires_at'] !== null && strtotime($share['expires_at']) < time()) {
                $expired = true;
            }
            
            // 更新访问计数
            $this->db->prepare("UPDATE shared_notes SET view_count = view_count + 1 WHERE id = ?")
                      ->execute([$share['id']]);
            
            return [
                'success' => true,
                'share' => [
                    'id' => $share['id'],
                    'note_id' => $share['note_id'],
                    'title' => $share['title'],
                    'content_type' => $share['content_type'],
                    'username' => $share['username'],
                    'has_password' => !empty($share['password_hash']),
                    'expires_at' => $share['expires_at'],
                    'expired' => $expired,
                    'view_count' => $share['view_count'] + 1,
                    'allow_download' => (bool)$share['allow_download'],
                    'created_at' => $share['created_at']
                ]
            ];
            
        } catch (Exception $e) {
            error_log("获取分享信息失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取分享信息失败'];
        }
    }
    
    /**
     * 获取分享内容（密码验证后）
     * 
     * @param string $token 分享token
     * @param string|null $password 密码（可选）
     * @return array
     */
    public function getSharedContent($token, $password = null) {
        try {
            $stmt = $this->db->prepare(
                "SELECT sn.*, n.title, n.content, n.content_type 
                 FROM shared_notes sn
                 JOIN notes n ON sn.note_id = n.id
                 WHERE sn.share_token = ?"
            );
            $stmt->execute([$token]);
            $share = $stmt->fetch();
            
            if (!$share) {
                return ['success' => false, 'message' => '分享链接不存在'];
            }
            
            // 检查是否过期
            if ($share['expires_at'] !== null && strtotime($share['expires_at']) < time()) {
                return ['success' => false, 'message' => '分享链接已过期'];
            }
            
            // 验证密码
            if (!empty($share['password_hash'])) {
                if ($password === null) {
                    return ['success' => false, 'message' => '需要密码', 'requires_password' => true];
                }
                if (!password_verify($password, $share['password_hash'])) {
                    return ['success' => false, 'message' => '密码错误'];
                }
            }
            
            return [
                'success' => true,
                'content' => [
                    'title' => $share['title'],
                    'content' => $share['content'],
                    'content_type' => $share['content_type']
                ]
            ];
            
        } catch (Exception $e) {
            error_log("获取分享内容失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取分享内容失败'];
        }
    }
    
    /**
     * 获取用户的分享列表
     * 
     * @param int $user_id 用户ID
     * @param int|null $note_id 笔记ID（可选过滤）
     * @param int $limit 每页数量
     * @param int $offset 偏移量
     * @return array
     */
    public function getShareList($user_id, $note_id = null, $limit = 20, $offset = 0) {
        try {
            $sql = "SELECT sn.*, n.title 
                    FROM shared_notes sn
                    JOIN notes n ON sn.note_id = n.id
                    WHERE sn.user_id = ?";
            $params = [$user_id];
            
            if ($note_id !== null) {
                $sql .= " AND sn.note_id = ?";
                $params[] = $note_id;
            }
            
            $sql .= " ORDER BY sn.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $shares = $stmt->fetchAll();
            
            // 不返回密码哈希
            foreach ($shares as &$share) {
                $share['has_password'] = !empty($share['password_hash']);
                $share['is_expired'] = $share['expires_at'] !== null && strtotime($share['expires_at']) < time();
                unset($share['password_hash']);
            }
            
            return ['success' => true, 'shares' => $shares];
            
        } catch (Exception $e) {
            error_log("获取分享列表失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取分享列表失败'];
        }
    }
    
    /**
     * 更新分享设置
     * 
     * @param int $share_id 分享ID
     * @param int $user_id 用户ID
     * @param array $data 更新数据
     * @return array
     */
    public function updateShare($share_id, $user_id, $data) {
        try {
            // 验证所有权
            $stmt = $this->db->prepare("SELECT id FROM shared_notes WHERE id = ? AND user_id = ?");
            $stmt->execute([$share_id, $user_id]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'message' => '分享不存在或没有权限'];
            }
            
            $allowedFields = ['password_hash', 'expires_at', 'allow_download'];
            $updates = [];
            $values = [];
            
            foreach ($data as $key => $value) {
                if (!in_array($key, $allowedFields) && $key !== 'password') {
                    continue;
                }
                
                if ($key === 'password') {
                    $key = 'password_hash';
                    if (!empty($value)) {
                        $value = password_hash($value, PASSWORD_DEFAULT);
                    } else {
                        $value = null;
                    }
                } elseif ($key === 'password_hash') {
                    if (!empty($value)) {
                        $value = password_hash($value, PASSWORD_DEFAULT);
                    } else {
                        $value = null;
                    }
                }
                
                if ($key === 'allow_download') {
                    $value = (int)(bool)$value;
                }
                
                $updates[] = "`$key` = ?";
                $values[] = $value;
            }
            
            if (empty($updates)) {
                return ['success' => false, 'message' => '没有要更新的字段'];
            }
            
            $values[] = $share_id;
            $sql = "UPDATE shared_notes SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);
            
            logSecurityEvent('share_updated', [
                'share_id' => $share_id,
                'user_id' => $user_id
            ]);
            
            return ['success' => true, 'message' => '分享设置更新成功'];
            
        } catch (Exception $e) {
            error_log("更新分享失败: " . $e->getMessage());
            return ['success' => false, 'message' => '更新分享失败'];
        }
    }
    
    /**
     * 删除分享
     * 
     * @param int $share_id 分享ID
     * @param int $user_id 用户ID
     * @return array
     */
    public function deleteShare($share_id, $user_id) {
        try {
            // 验证所有权
            $stmt = $this->db->prepare("SELECT id FROM shared_notes WHERE id = ? AND user_id = ?");
            $stmt->execute([$share_id, $user_id]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'message' => '分享不存在或没有权限'];
            }
            
            $stmt = $this->db->prepare("DELETE FROM shared_notes WHERE id = ?");
            $stmt->execute([$share_id]);
            
            logSecurityEvent('share_deleted', [
                'share_id' => $share_id,
                'user_id' => $user_id
            ]);
            
            return ['success' => true, 'message' => '分享已删除'];
            
        } catch (Exception $e) {
            error_log("删除分享失败: " . $e->getMessage());
            return ['success' => false, 'message' => '删除分享失败'];
        }
    }
}
?>