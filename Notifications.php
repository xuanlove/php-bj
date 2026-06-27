<?php
/**
 * 通知管理类
 *
 * 功能：
 * - 获取用户通知列表
 * - 标记通知为已读
 * - 标记所有通知为已读
 * - 删除通知
 * - 获取未读通知数量
 */

require_once 'config.php';

class Notifications {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 获取通知列表
     *
     * @param int $user_id 用户ID
     * @param int $limit 数量限制
     * @param int $offset 偏移量
     * @return array
     */
    public function getNotifications($user_id, $limit = 20, $offset = 0) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM notifications
                 WHERE user_id = ?
                 ORDER BY created_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute([$user_id, $limit, $offset]);
            $notifications = $stmt->fetchAll();

            // 获取总数
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ?"
            );
            $stmt->execute([$user_id]);
            $total = intval($stmt->fetchColumn());

            // 获取未读数
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
            );
            $stmt->execute([$user_id]);
            $unread = intval($stmt->fetchColumn());

            return [
                'success' => true,
                'notifications' => $notifications,
                'total' => $total,
                'unread' => $unread
            ];

        } catch (Exception $e) {
            error_log("获取通知失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取通知失败'];
        }
    }

    /**
     * 获取未读通知数量
     *
     * @param int $user_id 用户ID
     * @return int
     */
    public function getUnreadCount($user_id) {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
            );
            $stmt->execute([$user_id]);
            return intval($stmt->fetchColumn());
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * 标记通知为已读
     *
     * @param int $notification_id 通知ID
     * @param int $user_id 用户ID（用于验证）
     * @return array
     */
    public function markAsRead($notification_id, $user_id) {
        try {
            $stmt = $this->db->prepare(
                "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$notification_id, $user_id]);

            return ['success' => true, 'message' => '已标记为已读'];

        } catch (Exception $e) {
            error_log("标记已读失败: " . $e->getMessage());
            return ['success' => false, 'message' => '操作失败'];
        }
    }

    /**
     * 标记所有通知为已读
     *
     * @param int $user_id 用户ID
     * @return array
     */
    public function markAllAsRead($user_id) {
        try {
            $stmt = $this->db->prepare(
                "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0"
            );
            $stmt->execute([$user_id]);

            $count = $stmt->rowCount();
            return [
                'success' => true,
                'message' => "已标记 {$count} 条通知为已读",
                'count' => $count
            ];

        } catch (Exception $e) {
            error_log("标记已读失败: " . $e->getMessage());
            return ['success' => false, 'message' => '操作失败'];
        }
    }

    /**
     * 删除单条通知
     *
     * @param int $notification_id 通知ID
     * @param int $user_id 用户ID（用于验证）
     * @return array
     */
    public function deleteNotification($notification_id, $user_id) {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM notifications WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$notification_id, $user_id]);

            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'message' => '通知不存在'];
            }

            return ['success' => true, 'message' => '通知已删除'];

        } catch (Exception $e) {
            error_log("删除通知失败: " . $e->getMessage());
            return ['success' => false, 'message' => '删除失败'];
        }
    }

    /**
     * 清空所有通知
     *
     * @param int $user_id 用户ID
     * @return array
     */
    public function clearAll($user_id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->execute([$user_id]);

            return ['success' => true, 'message' => '已清空所有通知'];

        } catch (Exception $e) {
            error_log("清空通知失败: " . $e->getMessage());
            return ['success' => false, 'message' => '清空失败'];
        }
    }

    /**
     * 创建通知（内部方法，由其他模块调用）
     *
     * @param int $user_id 接收用户ID
     * @param string $type 类型（system/share/comment/ai_complete/security）
     * @param string $title 标题
     * @param string $content 内容
     * @param string|null $link 跳转链接
     * @return bool
     */
    public function create($user_id, $type, $title, $content, $link = null) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO notifications (user_id, type, title, content, link) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$user_id, $type, $title, $content, $link]);
            return true;
        } catch (Exception $e) {
            error_log("创建通知失败: " . $e->getMessage());
            return false;
        }
    }
}
?>
