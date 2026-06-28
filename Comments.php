<?php
/**
 * 笔记评论管理类
 *
 * 功能：
 * - 添加评论
 * - 回复评论
 * - 编辑自己的评论
 * - 删除评论（软删除）
 * - 获取笔记的评论列表
 */

require_once 'config.php';

class Comments {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 添加评论
     *
     * @param int $note_id 笔记ID
     * @param int $user_id 用户ID
     * @param string $content 评论内容
     * @param int|null $parent_id 父评论ID（回复）
     * @return array
     */
    public function addComment($note_id, $user_id, $content, $parent_id = null) {
        try {
            // 验证笔记存在且用户有访问权限
            if (!$this->hasNoteAccess($note_id, $user_id)) {
                return ['success' => false, 'message' => '笔记不存在或没有权限'];
            }

            // 验证评论内容
            $content = trim($content);
            if (empty($content)) {
                return ['success' => false, 'message' => '评论内容不能为空'];
            }

            if (mb_strlen($content) > 1000) {
                return ['success' => false, 'message' => '评论内容不能超过1000字'];
            }

            // 归一化 parent_id：0 / 空字符串 / null 均视为顶层评论（转成 NULL 以满足外键约束）
            if ($parent_id !== null && $parent_id !== '' && !is_numeric($parent_id)) {
                return ['success' => false, 'message' => '无效的父评论'];
            }
            $parent_id = ($parent_id === null || $parent_id === '' || intval($parent_id) === 0) ? null : intval($parent_id);

            // 如果是回复，验证父评论存在
            if ($parent_id !== null) {
                $stmt = $this->db->prepare("SELECT id FROM comments WHERE id = ? AND is_deleted = 0");
                $stmt->execute([$parent_id]);
                if (!$stmt->fetch()) {
                    return ['success' => false, 'message' => '父评论不存在'];
                }
            }

            $stmt = $this->db->prepare(
                "INSERT INTO comments (note_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)"
            );
            // 仅去除标签，不做 htmlspecialchars，避免与前端 {{ }} 双重转义；XSS 由前端插值转义防护
            $stmt->execute([$note_id, $user_id, $parent_id, trim(strip_tags($content))]);

            $comment_id = $this->db->lastInsertId();

            // 获取刚创建的评论详情
            $stmt = $this->db->prepare(
                "SELECT c.*, u.username, u.full_name, u.avatar
                 FROM comments c
                 JOIN users u ON c.user_id = u.id
                 WHERE c.id = ?"
            );
            $stmt->execute([$comment_id]);
            $comment = $stmt->fetch();

            // 通知笔记作者
            $this->notifyNoteOwner($note_id, $user_id, $content);

            return [
                'success' => true,
                'message' => '评论发布成功',
                'comment' => $comment
            ];

        } catch (Exception $e) {
            error_log("添加评论失败: " . $e->getMessage());
            return ['success' => false, 'message' => '评论发布失败'];
        }
    }

    /**
     * 获取笔记的评论列表
     *
     * @param int $note_id 笔记ID
     * @param int $user_id 用户ID（用于权限验证）
     * @return array
     */
    public function getComments($note_id, $user_id) {
        try {
            // 验证笔记访问权限
            if (!$this->hasNoteAccess($note_id, $user_id)) {
                return ['success' => false, 'message' => '笔记不存在或没有权限'];
            }

            // 获取顶级评论及其回复
            $stmt = $this->db->prepare(
                "SELECT c.*, u.username, u.full_name, u.avatar
                 FROM comments c
                 JOIN users u ON c.user_id = u.id
                 WHERE c.note_id = ? AND c.is_deleted = 0 AND c.parent_id IS NULL
                 ORDER BY c.created_at DESC"
            );
            $stmt->execute([$note_id]);
            $topLevel = $stmt->fetchAll();

            // 获取所有回复
            $stmt = $this->db->prepare(
                "SELECT c.*, u.username, u.full_name, u.avatar
                 FROM comments c
                 JOIN users u ON c.user_id = u.id
                 WHERE c.note_id = ? AND c.is_deleted = 0 AND c.parent_id IS NOT NULL
                 ORDER BY c.created_at ASC"
            );
            $stmt->execute([$note_id]);
            $replies = $stmt->fetchAll();

            // 组织评论树
            $comments = [];
            foreach ($topLevel as $comment) {
                $comment['replies'] = [];
                $comments[$comment['id']] = $comment;
            }

            // 添加回复到父评论
            foreach ($replies as $reply) {
                if (isset($comments[$reply['parent_id']])) {
                    $comments[$reply['parent_id']]['replies'][] = $reply;
                }
            }

            return [
                'success' => true,
                'comments' => array_values($comments),
                'total' => count($topLevel)
            ];

        } catch (Exception $e) {
            error_log("获取评论失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取评论失败'];
        }
    }

    /**
     * 更新评论（仅能编辑自己的评论）
     *
     * @param int $comment_id 评论ID
     * @param int $user_id 用户ID
     * @param string $content 新内容
     * @return array
     */
    public function updateComment($comment_id, $user_id, $content) {
        try {
            // 验证评论所有权
            $stmt = $this->db->prepare("SELECT user_id FROM comments WHERE id = ? AND is_deleted = 0");
            $stmt->execute([$comment_id]);
            $comment = $stmt->fetch();

            if (!$comment) {
                return ['success' => false, 'message' => '评论不存在'];
            }

            if ($comment['user_id'] !== $user_id) {
                return ['success' => false, 'message' => '只能编辑自己的评论'];
            }

            // 验证内容
            $content = trim($content);
            if (empty($content)) {
                return ['success' => false, 'message' => '评论内容不能为空'];
            }

            $stmt = $this->db->prepare(
                "UPDATE comments SET content = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([trim(strip_tags($content)), $comment_id]);

            return ['success' => true, 'message' => '评论已更新'];

        } catch (Exception $e) {
            error_log("更新评论失败: " . $e->getMessage());
            return ['success' => false, 'message' => '评论更新失败'];
        }
    }

    /**
     * 删除评论（软删除）
     *
     * @param int $comment_id 评论ID
     * @param int $user_id 用户ID
     * @return array
     */
    public function deleteComment($comment_id, $user_id) {
        try {
            // 验证评论所有权
            $stmt = $this->db->prepare("SELECT user_id FROM comments WHERE id = ? AND is_deleted = 0");
            $stmt->execute([$comment_id]);
            $comment = $stmt->fetch();

            if (!$comment) {
                return ['success' => false, 'message' => '评论不存在'];
            }

            // 只有评论作者可以删除自己的评论
            if ($comment['user_id'] !== $user_id) {
                return ['success' => false, 'message' => '只能删除自己的评论'];
            }

            $stmt = $this->db->prepare(
                "UPDATE comments SET is_deleted = 1, updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$comment_id]);

            return ['success' => true, 'message' => '评论已删除'];

        } catch (Exception $e) {
            error_log("删除评论失败: " . $e->getMessage());
            return ['success' => false, 'message' => '评论删除失败'];
        }
    }

    /**
     * 获取评论数量
     *
     * @param int $note_id 笔记ID
     * @return int
     */
    public function getCommentCount($note_id) {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM comments WHERE note_id = ? AND is_deleted = 0"
            );
            $stmt->execute([$note_id]);
            return intval($stmt->fetchColumn());
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * 验证用户是否有笔记访问权限（所有者或协作者）
     */
    private function hasNoteAccess($note_id, $user_id) {
        // 检查是否是所有者
        $stmt = $this->db->prepare(
            "SELECT id FROM notes WHERE id = ? AND user_id = ? AND deleted_at IS NULL"
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
     * 通知笔记作者有新评论
     */
    private function notifyNoteOwner($note_id, $commenter_id, $content) {
        try {
            // 获取笔记作者
            $stmt = $this->db->prepare(
                "SELECT n.user_id, n.title, u.username as commenter
                 FROM notes n
                 JOIN users u ON u.id = ?
                 WHERE n.id = ?"
            );
            $stmt->execute([$commenter_id, $note_id]);
            $info = $stmt->fetch();

            if (!$info || $info['user_id'] === $commenter_id) {
                return; // 自己评论自己不需要通知
            }

            $preview = mb_substr($content, 0, 50);
            if (mb_strlen($content) > 50) {
                $preview .= '...';
            }

            $stmt = $this->db->prepare(
                "INSERT INTO notifications (user_id, type, title, content, link)
                 VALUES (?, 'comment', '收到新评论', CONCAT(?, ' 评论了你的笔记《', ?, '》'), CONCAT('index.html?note=', ?))"
            );
            $stmt->execute([
                $info['user_id'],
                $info['commenter'],
                $info['title'],
                $note_id
            ]);
        } catch (Exception $e) {
            error_log("创建评论通知失败: " . $e->getMessage());
        }
    }
}
?>
