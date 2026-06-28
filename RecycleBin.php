<?php
/**
 * 回收站管理类
 * 
 * 功能：
 * - 软删除笔记
 * - 恢复笔记
 * - 永久删除
 * - 自动清理过期笔记
 */

require_once 'config.php';

class RecycleBin {
    private $db;
    private $retentionDays = NOTE_RETENTION_DAYS; // 回收站保留天数
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * 移动笔记到回收站
     * 
     * @param int $note_id 笔记ID
     * @param int $user_id 用户ID
     * @return array
     */
    public function moveToRecycleBin($note_id, $user_id) {
        try {
            // 验证笔记所有权
            $stmt = $this->db->prepare(
                "SELECT id, deleted_at FROM notes WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$note_id, $user_id]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$note) {
                return ['success' => false, 'message' => '笔记不存在或没有权限'];
            }
            
            if ($note['deleted_at']) {
                return ['success' => false, 'message' => '笔记已在回收站中'];
            }
            
            // 开始事务
            $this->db->beginTransaction();
            
            // 软删除笔记
            $stmt = $this->db->prepare(
                "UPDATE notes SET deleted_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$note_id]);
            
            // 同时软删除附件（如果需要）
            // 这里可以选择是否保留附件
            
            $this->db->commit();
            
            logSecurityEvent('note_deleted', [
                'note_id' => $note_id,
                'user_id' => $user_id,
                'action' => 'moved_to_recycle_bin'
            ]);
            
            return [
                'success' => true,
                'message' => '笔记已移至回收站',
                'retention_days' => $this->retentionDays
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("移动到回收站失败: " . $e->getMessage());
            return ['success' => false, 'message' => '移动到回收站失败'];
        }
    }
    
    /**
     * 从回收站恢复笔记
     * 
     * @param int $note_id 笔记ID
     * @param int $user_id 用户ID
     * @return array
     */
    public function restoreFromRecycleBin($note_id, $user_id) {
        try {
            // 验证笔记所有权
            $stmt = $this->db->prepare(
                "SELECT id, deleted_at FROM notes WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$note_id, $user_id]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$note) {
                return ['success' => false, 'message' => '笔记不存在或没有权限'];
            }
            
            if (!$note['deleted_at']) {
                return ['success' => false, 'message' => '笔记不在回收站中'];
            }
            
            // 恢复笔记
            $stmt = $this->db->prepare(
                "UPDATE notes SET deleted_at = NULL WHERE id = ?"
            );
            $stmt->execute([$note_id]);
            
            logSecurityEvent('note_restored', [
                'note_id' => $note_id,
                'user_id' => $user_id
            ]);
            
            return ['success' => true, 'message' => '笔记已恢复'];
            
        } catch (Exception $e) {
            error_log("恢复笔记失败: " . $e->getMessage());
            return ['success' => false, 'message' => '恢复笔记失败'];
        }
    }
    
    /**
     * 永久删除笔记
     *
     * @param int $note_id 笔记ID
     * @param int $user_id 用户ID
     * @param bool $skipOwnershipCheck 是否跳过所有权校验（系统操作如 autoCleanup 可传 true）
     * @return array
     */
    public function permanentDelete($note_id, $user_id, $skipOwnershipCheck = false) {
        try {
            // 验证笔记所有权（系统操作可跳过）
            if (!$skipOwnershipCheck) {
                $stmt = $this->db->prepare(
                    "SELECT id, deleted_at FROM notes WHERE id = ? AND user_id = ?"
                );
                $stmt->execute([$note_id, $user_id]);
                $note = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$note) {
                    return ['success' => false, 'message' => '笔记不存在或没有权限'];
                }
            }
            
            // 开始事务
            $this->db->beginTransaction();
            
            // 删除附件文件
            $stmt = $this->db->prepare(
                "SELECT file_path FROM attachments WHERE note_id = ?"
            );
            $stmt->execute([$note_id]);
            $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($attachments as $attachment) {
                if (file_exists($attachment['file_path'])) {
                    unlink($attachment['file_path']);
                }
            }
            
            // 删除数据库记录（级联删除会自动处理附件、版本等）
            $stmt = $this->db->prepare("DELETE FROM notes WHERE id = ?");
            $stmt->execute([$note_id]);
            
            $this->db->commit();
            
            logSecurityEvent('note_permanently_deleted', [
                'note_id' => $note_id,
                'user_id' => $user_id
            ]);
            
            return ['success' => true, 'message' => '笔记已永久删除'];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("永久删除笔记失败: " . $e->getMessage());
            return ['success' => false, 'message' => '永久删除笔记失败'];
        }
    }
    
    /**
     * 获取回收站列表
     * 
     * @param int $user_id 用户ID
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public function getRecycleBinList($user_id, $limit = 20, $offset = 0) {
        try {
            $stmt = $this->db->prepare(
                "SELECT n.id, n.title, n.content, n.content_type, 
                        n.deleted_at, n.created_at, n.updated_at,
                        DATEDIFF(NOW(), n.deleted_at) as days_in_trash,
                        f.name as folder_name
                 FROM notes n
                 LEFT JOIN folders f ON n.folder_id = f.id
                 WHERE n.user_id = ? AND n.deleted_at IS NOT NULL
                 ORDER BY n.deleted_at DESC
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute([$user_id, $limit, $offset]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 获取总数
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as total FROM notes WHERE user_id = ? AND deleted_at IS NOT NULL"
            );
            $stmt->execute([$user_id]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // 添加剩余天数信息
            foreach ($notes as &$note) {
                $note['days_remaining'] = max(0, $this->retentionDays - $note['days_in_trash']);
                $note['will_be_deleted'] = $note['days_remaining'] == 0;
            }
            
            return [
                'success' => true,
                'notes' => $notes,
                'total' => $total,
                'retention_days' => $this->retentionDays,
                'message' => '获取回收站列表成功'
            ];
            
        } catch (Exception $e) {
            error_log("获取回收站列表失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取回收站列表失败'];
        }
    }
    
    /**
     * 清空回收站
     * 
     * @param int $user_id 用户ID
     * @return array
     */
    public function emptyRecycleBin($user_id) {
        try {
            // 获取回收站中的笔记
            $stmt = $this->db->prepare(
                "SELECT id FROM notes WHERE user_id = ? AND deleted_at IS NOT NULL"
            );
            $stmt->execute([$user_id]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($notes)) {
                return ['success' => true, 'message' => '回收站已经是空的'];
            }
            
            $deletedCount = 0;
            foreach ($notes as $note) {
                // 永久删除每个笔记（每个笔记独立事务）
                $result = $this->permanentDelete($note['id'], $user_id);
                if ($result['success']) {
                    $deletedCount++;
                }
            }
            
            logSecurityEvent('recycle_bin_emptied', [
                'user_id' => $user_id,
                'count' => $deletedCount
            ]);
            
            return [
                'success' => true,
                'deleted_count' => $deletedCount,
                'message' => "已永久删除 {$deletedCount} 个笔记"
            ];
            
        } catch (Exception $e) {
            error_log("清空回收站失败: " . $e->getMessage());
            return ['success' => false, 'message' => '清空回收站失败'];
        }
    }
    
    /**
     * 自动清理过期笔记
     * 
     * @return array
     */
    public function autoCleanup() {
        try {
            // 查找过期的笔记
            $stmt = $this->db->prepare(
                "SELECT id, user_id FROM notes 
                 WHERE deleted_at IS NOT NULL 
                 AND DATEDIFF(NOW(), deleted_at) >= ?"
            );
            $stmt->execute([$this->retentionDays]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($notes)) {
                return ['success' => true, 'message' => '没有需要清理的笔记'];
            }
            
            $deletedCount = 0;
            foreach ($notes as $note) {
                // 永久删除（系统清理操作，跳过所有权校验）
                $result = $this->permanentDelete($note['id'], $note['user_id'], true);
                if ($result['success']) {
                    $deletedCount++;
                }
            }
            
            logSecurityEvent('auto_cleanup', [
                'count' => $deletedCount,
                'retention_days' => $this->retentionDays
            ]);
            
            return [
                'success' => true,
                'deleted_count' => $deletedCount,
                'message' => "自动清理了 {$deletedCount} 个过期笔记"
            ];
            
        } catch (Exception $e) {
            error_log("自动清理失败: " . $e->getMessage());
            return ['success' => false, 'message' => '自动清理失败'];
        }
    }
    
    /**
     * 设置保留天数
     * 
     * @param int $days 天数
     */
    public function setRetentionDays($days) {
        $this->retentionDays = max(1, min(90, $days)); // 限制在1-90天之间
    }
    
    /**
     * 获取回收站统计
     * 
     * @param int $user_id 用户ID
     * @return array
     */
    public function getRecycleBinStats($user_id) {
        try {
            // 总数
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as total FROM notes WHERE user_id = ? AND deleted_at IS NOT NULL"
            );
            $stmt->execute([$user_id]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // 即将删除的笔记（剩余天数<=3）
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM notes 
                 WHERE user_id = ? AND deleted_at IS NOT NULL 
                 AND DATEDIFF(NOW(), deleted_at) >= ?"
            );
            $stmt->execute([$user_id, $this->retentionDays - 3]);
            $expiringSoon = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            return [
                'success' => true,
                'total' => $total,
                'expiring_soon' => $expiringSoon,
                'retention_days' => $this->retentionDays,
                'message' => '获取统计成功'
            ];
            
        } catch (Exception $e) {
            error_log("获取回收站统计失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取统计失败'];
        }
    }
}
?>