<?php
/**
 * 笔记协作者共享管理类
 *
 * 功能：
 * - 笔记协作共享（用户与用户之间）
 * - 读写权限管理
 * - 协作列表查看
 */

require_once 'config.php';

class NoteShares {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 分享笔记给其他用户
     *
     * @param int $note_id 笔记ID
     * @param int $owner_id 所有者ID
     * @param string $shared_with_username 被分享者的用户名
     * @param string $permission 权限（read/edit）
     * @return array
     */
    public function shareToUser($note_id, $owner_id, $shared_with_username, $permission = 'read') {
        if (!in_array($permission, ['read', 'edit'])) {
            return ['success' => false, 'message' => '无效的权限类型'];
        }
        try {
            // 验证笔记所有权
            $stmt = $this->db->prepare("SELECT id, title FROM notes WHERE id = ? AND user_id = ?");
            $stmt->execute([$note_id, $owner_id]);
            $note = $stmt->fetch();
            if (!$note) {
                return ['success' => false, 'message' => '笔记不存在或没有权限分享'];
            }

            // 验证被分享用户存在
            $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? AND status = 'active'");
            $stmt->execute([$shared_with_username]);
            $sharedUser = $stmt->fetch();
            if (!$sharedUser) {
                return ['success' => false, 'message' => '用户不存在或已被禁用'];
            }

            // 不能分享给自己
            if ($sharedUser['id'] == $owner_id) {
                return ['success' => false, 'message' => '不能分享给自己'];
            }

            $shared_with_id = $sharedUser['id'];

            // 检查是否已经分享过
            $stmt = $this->db->prepare(
                "SELECT id FROM note_shares WHERE note_id = ? AND owner_id = ? AND shared_with_id = ?"
            );
            $stmt->execute([$note_id, $owner_id, $shared_with_id]);
            $existing = $stmt->fetch();

            if ($existing) {
                // 更新权限
                $stmt = $this->db->prepare(
                    "UPDATE note_shares SET permission = ? WHERE id = ?"
                );
                $stmt->execute([$permission, $existing['id']]);
                return ['success' => true, 'message' => '已更新分享权限'];
            }

            // 创建新的分享
            $stmt = $this->db->prepare(
                "INSERT INTO note_shares (note_id, owner_id, shared_with_id, permission) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$note_id, $owner_id, $shared_with_id, $permission]);

            // 查询所有者用户名
            $ownerStmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
            $ownerStmt->execute([$owner_id]);
            $ownerName = $ownerStmt->fetchColumn() ?: '未知用户';

            // 创建通知
            $this->createNotification(
                $shared_with_id,
                'share',
                '收到新笔记分享',
                "用户 {$ownerName} 分享了笔记《{$note['title']}》给你",
                "index.html?note={$note_id}"
            );

            logSecurityEvent('note_shared_to_user', [
                'note_id' => $note_id,
                'owner_id' => $owner_id,
                'shared_with_id' => $shared_with_id,
                'permission' => $permission
            ]);

            return ['success' => true, 'message' => '分享成功'];

        } catch (Exception $e) {
            error_log("分享笔记失败: " . $e->getMessage());
            return ['success' => false, 'message' => '分享失败，请稍后重试'];
        }
    }

    /**
     * 获取我收到的分享列表
     *
     * @param int $user_id 用户ID
     * @return array
     */
    public function getSharedToMe($user_id) {
        try {
            $stmt = $this->db->prepare(
                "SELECT ns.*, n.title, n.content, n.content_type, n.created_at as note_created, n.updated_at as note_updated,
                        u.username as owner_name
                 FROM note_shares ns
                 JOIN notes n ON ns.note_id = n.id
                 JOIN users u ON ns.owner_id = u.id
                 WHERE ns.shared_with_id = ? AND n.deleted_at IS NULL
                 ORDER BY ns.created_at DESC"
            );
            $stmt->execute([$user_id]);
            $shares = $stmt->fetchAll();

            return ['success' => true, 'shares' => $shares];

        } catch (Exception $e) {
            error_log("获取分享列表失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取分享列表失败'];
        }
    }

    /**
     * 获取我分享出去的列表
     *
     * @param int $user_id 用户ID
     * @return array
     */
    public function getSharedByMe($user_id) {
        try {
            $stmt = $this->db->prepare(
                "SELECT ns.*, n.title, n.content, n.content_type, n.created_at as note_created, n.updated_at as note_updated,
                        u.username as shared_with_name
                 FROM note_shares ns
                 JOIN notes n ON ns.note_id = n.id
                 JOIN users u ON ns.shared_with_id = u.id
                 WHERE ns.owner_id = ? AND n.deleted_at IS NULL
                 ORDER BY ns.created_at DESC"
            );
            $stmt->execute([$user_id]);
            $shares = $stmt->fetchAll();

            return ['success' => true, 'shares' => $shares];

        } catch (Exception $e) {
            error_log("获取分享列表失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取分享列表失败'];
        }
    }

    /**
     * 获取笔记的协作分享信息
     *
     * @param int $note_id 笔记ID
     * @param int $user_id 用户ID（所有者或被分享者）
     * @return array
     */
    public function getNoteShares($note_id, $user_id) {
        try {
            // 验证权限：所有者或被分享者
            $stmt = $this->db->prepare(
                "SELECT n.id FROM notes n
                 LEFT JOIN note_shares ns ON n.id = ns.note_id AND ns.shared_with_id = ?
                 WHERE n.id = ? AND (n.user_id = ? OR ns.id IS NOT NULL)"
            );
            $stmt->execute([$user_id, $note_id, $user_id]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'message' => '笔记不存在或没有权限'];
            }

            // 获取所有协作者
            $stmt = $this->db->prepare(
                "SELECT ns.*, u.username, u.email
                 FROM note_shares ns
                 JOIN users u ON ns.shared_with_id = u.id
                 WHERE ns.note_id = ?"
            );
            $stmt->execute([$note_id]);
            $shares = $stmt->fetchAll();

            return ['success' => true, 'shares' => $shares];

        } catch (Exception $e) {
            error_log("获取协作信息失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取协作信息失败'];
        }
    }

    /**
     * 更新协作分享权限
     *
     * @param int $share_id 分享ID
     * @param int $owner_id 所有者ID（用于验证）
     * @param string $permission 新权限
     * @return array
     */
    public function updatePermission($share_id, $owner_id, $permission) {
        if (!in_array($permission, ['read', 'edit'])) {
            return ['success' => false, 'message' => '无效的权限类型'];
        }
        try {
            $stmt = $this->db->prepare(
                "UPDATE note_shares SET permission = ? WHERE id = ? AND owner_id = ?"
            );
            $stmt->execute([$permission, $share_id, $owner_id]);

            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'message' => '分享不存在或没有权限'];
            }

            return ['success' => true, 'message' => '权限已更新'];

        } catch (Exception $e) {
            error_log("更新权限失败: " . $e->getMessage());
            return ['success' => false, 'message' => '更新失败'];
        }
    }

    /**
     * 取消协作分享
     *
     * @param int $share_id 分享ID
     * @param int $owner_id 所有者ID（用于验证）
     * @return array
     */
    public function revokeShare($share_id, $owner_id) {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM note_shares WHERE id = ? AND owner_id = ?"
            );
            $stmt->execute([$share_id, $owner_id]);

            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'message' => '分享不存在或没有权限'];
            }

            return ['success' => true, 'message' => '已取消分享'];

        } catch (Exception $e) {
            error_log("取消分享失败: " . $e->getMessage());
            return ['success' => false, 'message' => '取消分享失败'];
        }
    }

    /**
     * 检查用户是否有笔记访问权限
     *
     * @param int $note_id 笔记ID
     * @param int $user_id 用户ID
     * @return bool
     */
    public function hasAccess($note_id, $user_id) {
        // 检查是否是所有者
        $stmt = $this->db->prepare(
            "SELECT id FROM notes WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$note_id, $user_id]);
        if ($stmt->fetch()) {
            return true;
        }

        // 检查是否是协作者
        $stmt = $this->db->prepare(
            "SELECT id FROM note_shares WHERE note_id = ? AND shared_with_id = ?"
        );
        $stmt->execute([$note_id, $user_id]);
        if ($stmt->fetch()) {
            return true;
        }

        return false;
    }

    /**
     * 获取用户在笔记中的权限
     *
     * @param int $note_id 笔记ID
     * @param int $user_id 用户ID
     * @return string|null owner/read/edit
     */
    public function getPermission($note_id, $user_id) {
        // 检查是否是所有者
        $stmt = $this->db->prepare(
            "SELECT id FROM notes WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$note_id, $user_id]);
        if ($stmt->fetch()) {
            return 'owner';
        }

        // 检查是否是协作者
        $stmt = $this->db->prepare(
            "SELECT permission FROM note_shares WHERE note_id = ? AND shared_with_id = ?"
        );
        $stmt->execute([$note_id, $user_id]);
        $result = $stmt->fetch();
        if ($result) {
            return $result['permission'];
        }

        return null;
    }

    /**
     * 创建通知
     */
    private function createNotification($user_id, $type, $title, $content, $link = null) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO notifications (user_id, type, title, content, link) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$user_id, $type, $title, $content, $link]);
        } catch (Exception $e) {
            error_log("创建通知失败: " . $e->getMessage());
        }
    }
}
?>
