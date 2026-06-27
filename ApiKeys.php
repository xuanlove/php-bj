<?php
/**
 * API密钥管理类
 *
 * 安全修复：密钥以SHA256哈希存储，API调用时验证哈希
 * 密钥创建时明文返回一次，后续仅存哈希
 */

require_once 'config.php';

class ApiKeys {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 创建API密钥
     * 修复：明文仅返回一次，数据库存储SHA256哈希
     */
    public function create($user_id, $key_name, $permissions = []) {
        try {
            $api_key = bin2hex(random_bytes(32));

            // 存储哈希而非明文
            $key_hash = hash('sha256', $api_key);

            $stmt = $this->db->prepare(
                "INSERT INTO api_keys (user_id, key_name, api_key, permissions) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([
                $user_id,
                sanitizeInput($key_name),
                $key_hash,
                json_encode($permissions ?: [])
            ]);

            return [
                'success' => true,
                'api_key' => $api_key,
                'id' => $this->db->lastInsertId()
            ];
        } catch (Exception $e) {
            error_log("创建API密钥失败: " . $e->getMessage());
            return ['success' => false, 'message' => '创建失败'];
        }
    }

    /**
     * 获取用户的API密钥列表（不返回哈希值）
     */
    public function getList($user_id) {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, key_name, key_name as display_name, last_used, usage_count, expires_at, status, created_at
                 FROM api_keys WHERE user_id = ? ORDER BY created_at DESC"
            );
            $stmt->execute([$user_id]);
            $keys = $stmt->fetchAll();

            return ['success' => true, 'keys' => $keys];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '获取失败'];
        }
    }

    /**
     * 删除密钥
     */
    public function delete($key_id, $user_id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM api_keys WHERE id = ? AND user_id = ?");
            $stmt->execute([$key_id, $user_id]);
            $count = $stmt->rowCount();
            return [
                'success' => $count > 0,
                'message' => $count > 0 ? '已删除' : '密钥不存在'
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '删除失败'];
        }
    }

    /**
     * 切换状态
     */
    public function toggleStatus($key_id, $user_id) {
        try {
            $stmt = $this->db->prepare(
                "UPDATE api_keys SET status = IF(status='active','revoked','active') WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$key_id, $user_id]);
            return ['success' => $stmt->rowCount() > 0, 'message' => '已更新'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '更新失败'];
        }
    }

    /**
     * 验证API密钥（Bearer Token验证）
     * 用于中间件或过滤器
     */
    public function verifyKey($api_key) {
        if (empty($api_key)) {
            return ['valid' => false, 'message' => '密钥为空'];
        }

        $key_hash = hash('sha256', $api_key);

        try {
            $stmt = $this->db->prepare(
                "SELECT id, user_id, permissions, status, expires_at FROM api_keys WHERE api_key = ?"
            );
            $stmt->execute([$key_hash]);
            $key = $stmt->fetch();

            if (!$key) {
                return ['valid' => false, 'message' => '密钥无效'];
            }

            if ($key['status'] !== 'active') {
                return ['valid' => false, 'message' => '密钥已被禁用'];
            }

            // 检查是否过期
            if (!empty($key['expires_at']) && strtotime($key['expires_at']) < time()) {
                return ['valid' => false, 'message' => '密钥已过期'];
            }

            // 更新使用统计
            $update = $this->db->prepare(
                "UPDATE api_keys SET last_used = NOW(), usage_count = usage_count + 1 WHERE id = ?"
            );
            $update->execute([$key['id']]);

            return [
                'valid' => true,
                'user_id' => $key['user_id'],
                'permissions' => json_decode($key['permissions'] ?: '{}', true)
            ];
        } catch (Exception $e) {
            error_log("API密钥验证失败: " . $e->getMessage());
            return ['valid' => false, 'message' => '验证失败'];
        }
    }
}
?>
