<?php
/**
 * 笔记版本控制类
 * 
 * 功能：
 * - 自动保存笔记历史版本
 * - 版本对比
 * - 版本回滚
 * - 版本管理
 */

require_once 'config.php';

class VersionControl {
    private $db;
    private $maxVersions = NOTE_MAX_VERSIONS; // 最大版本数量
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * 保存笔记版本
     * 
     * @param int $note_id 笔记ID
     * @param int $user_id 用户ID
     * @param array $noteData 笔记数据
     * @param string $changeSummary 变更摘要
     * @return array
     */
    public function saveVersion($note_id, $user_id, $noteData, $changeSummary = '') {
        try {
            // 获取当前最大版本号
            $stmt = $this->db->prepare(
                "SELECT COALESCE(MAX(version_number), 0) as max_version 
                 FROM note_versions 
                 WHERE note_id = ?"
            );
            $stmt->execute([$note_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $newVersion = $result['max_version'] + 1;
            
            // 开始事务
            $this->db->beginTransaction();
            
            // 保存新版本
            $stmt = $this->db->prepare(
                "INSERT INTO note_versions 
                 (note_id, user_id, version_number, title, content, content_type, change_description) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            
            $stmt->execute([
                $note_id,
                $user_id,
                $newVersion,
                $noteData['title'],
                $noteData['content'],
                $noteData['content_type'],
                sanitizeInput($changeSummary)
            ]);
            
            $versionId = $this->db->lastInsertId();
            
            // 清理旧版本（保留最新的maxVersions个版本）
            $this->cleanupOldVersions($note_id);
            
            $this->db->commit();
            
            logSecurityEvent('version_saved', [
                'note_id' => $note_id,
                'version' => $newVersion,
                'user_id' => $user_id
            ]);
            
            return [
                'success' => true,
                'version_id' => $versionId,
                'version_number' => $newVersion,
                'message' => '版本保存成功'
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("保存版本失败: " . $e->getMessage());
            return ['success' => false, 'message' => '保存版本失败'];
        }
    }
    
    /**
     * 获取笔记版本列表
     * 
     * @param int $note_id 笔记ID
     * @param int $user_id 用户ID（权限验证）
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public function getVersionList($note_id, $user_id, $limit = 20, $offset = 0) {
        try {
            // 验证笔记所有权
            $stmt = $this->db->prepare("SELECT id FROM notes WHERE id = ? AND user_id = ?");
            $stmt->execute([$note_id, $user_id]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'message' => '没有权限查看此笔记的版本'];
            }
            
            $stmt = $this->db->prepare(
                "SELECT v.id, v.version_number, v.title, v.change_description, 
                        v.created_at, u.username
                 FROM note_versions v
                 LEFT JOIN users u ON v.user_id = u.id
                 WHERE v.note_id = ?
                 ORDER BY v.version_number DESC
                 LIMIT ? OFFSET ?"
            );
            
            $stmt->execute([$note_id, $limit, $offset]);
            $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 获取总数
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM note_versions WHERE note_id = ?");
            $stmt->execute([$note_id]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            return [
                'success' => true,
                'versions' => $versions,
                'total' => $total,
                'message' => '获取版本列表成功'
            ];
            
        } catch (Exception $e) {
            error_log("获取版本列表失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取版本列表失败'];
        }
    }
    
    /**
     * 获取版本详情
     * 
     * @param int $version_id 版本ID
     * @param int $user_id 用户ID（权限验证）
     * @return array
     */
    public function getVersion($version_id, $user_id) {
        try {
            // 验证版本所属笔记的所有权
            $stmt = $this->db->prepare(
                "SELECT v.*, n.user_id as note_owner
                 FROM note_versions v
                 JOIN notes n ON v.note_id = n.id
                 WHERE v.id = ?"
            );
            $stmt->execute([$version_id]);
            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$version) {
                return ['success' => false, 'message' => '版本不存在'];
            }
            
            if ($version['note_owner'] != $user_id) {
                return ['success' => false, 'message' => '没有权限查看此版本'];
            }
            
            unset($version['note_owner']);
            
            return [
                'success' => true,
                'version' => $version,
                'message' => '获取版本成功'
            ];
            
        } catch (Exception $e) {
            error_log("获取版本失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取版本失败'];
        }
    }
    
    /**
     * 回滚到指定版本
     * 
     * @param int $note_id 笔记ID
     * @param int $version_id 版本ID
     * @param int $user_id 用户ID
     * @return array
     */
    public function rollbackToVersion($note_id, $version_id, $user_id) {
        try {
            // 获取版本内容
            $stmt = $this->db->prepare(
                "SELECT * FROM note_versions WHERE id = ? AND note_id = ?"
            );
            $stmt->execute([$version_id, $note_id]);
            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$version) {
                return ['success' => false, 'message' => '版本不存在'];
            }

            // 验证笔记所有权
            $ownerStmt = $this->db->prepare("SELECT id FROM notes WHERE id = ? AND user_id = ? AND deleted_at IS NULL");
            $ownerStmt->execute([$note_id, $user_id]);
            if (!$ownerStmt->fetch()) {
                return ['success' => false, 'message' => '无权操作此笔记或笔记不存在'];
            }

            // 开始事务
            $this->db->beginTransaction();
            
            // 更新笔记内容
            $stmt = $this->db->prepare(
                "UPDATE notes 
                 SET title = ?, content = ?, content_type = ?, updated_at = NOW() 
                 WHERE id = ?"
            );
            $stmt->execute([
                $version['title'],
                $version['content'],
                $version['content_type'],
                $note_id
            ]);
            
            // 获取当前最大版本号
            $stmt = $this->db->prepare(
                "SELECT COALESCE(MAX(version_number), 0) as max_version 
                 FROM note_versions 
                 WHERE note_id = ?"
            );
            $stmt->execute([$note_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $newVersion = $result['max_version'] + 1;
            
            // 保存回滚操作为新版本（在同一个事务中执行）
            $stmt = $this->db->prepare(
                "INSERT INTO note_versions 
                 (note_id, user_id, version_number, title, content, content_type, change_description) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $note_id,
                $user_id,
                $newVersion,
                $version['title'],
                $version['content'],
                $version['content_type'],
                "回滚到版本 #{$version['version_number']}"
            ]);
            
            $this->db->commit();
            
            logSecurityEvent('version_rollback', [
                'note_id' => $note_id,
                'version_id' => $version_id,
                'user_id' => $user_id
            ]);
            
            return [
                'success' => true,
                'message' => '回滚成功',
                'version_number' => $version['version_number']
            ];
            
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("版本回滚失败: " . $e->getMessage());
            return ['success' => false, 'message' => '版本回滚失败'];
        }
    }
    
    /**
     * 对比两个版本
     * 
     * @param int $version1_id 版本1 ID
     * @param int $version2_id 版本2 ID
     * @param int $user_id 用户ID（权限验证）
     * @return array
     */
    public function compareVersions($version1_id, $version2_id, $user_id) {
        try {
            // 获取两个版本并验证权限
            $stmt = $this->db->prepare(
                "SELECT v.*, n.user_id as note_owner
                 FROM note_versions v
                 JOIN notes n ON v.note_id = n.id
                 WHERE v.id IN (?, ?)"
            );
            $stmt->execute([$version1_id, $version2_id]);
            $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($versions) !== 2) {
                return ['success' => false, 'message' => '版本不存在'];
            }
            
            // 验证用户权限
            foreach ($versions as $v) {
                if ($v['note_owner'] != $user_id) {
                    return ['success' => false, 'message' => '没有权限对比此版本'];
                }
            }
            
            $v1 = null;
            $v2 = null;
            foreach ($versions as $v) {
                if ($v['id'] == $version1_id) $v1 = $v;
                elseif ($v['id'] == $version2_id) $v2 = $v;
            }
            
            if (!$v1 || !$v2) {
                return ['success' => false, 'message' => '版本不存在'];
            }
            
            // 计算差异
            $diff = $this->calculateDiff($v1['content'], $v2['content']);
            
            return [
                'success' => true,
                'version1' => [
                    'id' => $v1['id'],
                    'version_number' => $v1['version_number'],
                    'title' => $v1['title'],
                    'created_at' => $v1['created_at']
                ],
                'version2' => [
                    'id' => $v2['id'],
                    'version_number' => $v2['version_number'],
                    'title' => $v2['title'],
                    'created_at' => $v2['created_at']
                ],
                'diff' => $diff,
                'message' => '对比成功'
            ];
            
        } catch (Exception $e) {
            error_log("版本对比失败: " . $e->getMessage());
            return ['success' => false, 'message' => '版本对比失败'];
        }
    }
    
    /**
     * 计算文本差异（简化版）
     * 
     * @param string $text1 文本1
     * @param string $text2 文本2
     * @return array
     */
    private function calculateDiff($text1, $text2) {
        $lines1 = explode("\n", $text1);
        $lines2 = explode("\n", $text2);
        
        $diff = [];
        $maxLines = max(count($lines1), count($lines2));
        
        for ($i = 0; $i < $maxLines; $i++) {
            $line1 = isset($lines1[$i]) ? $lines1[$i] : '';
            $line2 = isset($lines2[$i]) ? $lines2[$i] : '';
            
            if ($line1 !== $line2) {
                $diff[] = [
                    'line' => $i + 1,
                    'old' => $line1,
                    'new' => $line2,
                    'type' => $line1 === '' ? 'added' : ($line2 === '' ? 'removed' : 'changed')
                ];
            }
        }
        
        return $diff;
    }
    
    /**
     * 删除指定版本
     * 
     * @param int $version_id 版本ID
     * @param int $user_id 用户ID
     * @return array
     */
    public function deleteVersion($version_id, $user_id) {
        try {
            // 获取版本信息
            $stmt = $this->db->prepare(
                "SELECT v.*, n.user_id as note_owner 
                 FROM note_versions v
                 JOIN notes n ON v.note_id = n.id
                 WHERE v.id = ?"
            );
            $stmt->execute([$version_id]);
            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$version) {
                return ['success' => false, 'message' => '版本不存在'];
            }
            
            // 验证权限
            if ($version['note_owner'] != $user_id) {
                return ['success' => false, 'message' => '没有权限删除此版本'];
            }
            
            // 不允许删除最新版本
            $stmt = $this->db->prepare(
                "SELECT MAX(version_number) as max_version 
                 FROM note_versions 
                 WHERE note_id = ?"
            );
            $stmt->execute([$version['note_id']]);
            $maxVersion = $stmt->fetch(PDO::FETCH_ASSOC)['max_version'];
            
            if ($version['version_number'] == $maxVersion) {
                return ['success' => false, 'message' => '不能删除最新版本'];
            }
            
            // 删除版本
            $stmt = $this->db->prepare("DELETE FROM note_versions WHERE id = ?");
            $stmt->execute([$version_id]);
            
            logSecurityEvent('version_deleted', [
                'version_id' => $version_id,
                'note_id' => $version['note_id'],
                'user_id' => $user_id
            ]);
            
            return ['success' => true, 'message' => '版本删除成功'];
            
        } catch (Exception $e) {
            error_log("删除版本失败: " . $e->getMessage());
            return ['success' => false, 'message' => '删除版本失败'];
        }
    }
    
    /**
     * 清理旧版本
     * 
     * @param int $note_id 笔记ID
     */
    private function cleanupOldVersions($note_id) {
        try {
            // 获取版本数量
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM note_versions WHERE note_id = ?"
            );
            $stmt->execute([$note_id]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            // 如果超过最大版本数，删除最旧的版本
            if ($count > $this->maxVersions) {
                $stmt = $this->db->prepare(
                    "DELETE FROM note_versions 
                     WHERE note_id = ? 
                     ORDER BY version_number ASC 
                     LIMIT ?"
                );
                $stmt->execute([$note_id, $count - $this->maxVersions]);
            }
            
        } catch (Exception $e) {
            error_log("清理旧版本失败: " . $e->getMessage());
        }
    }
    
    /**
     * 设置最大版本数量
     * 
     * @param int $max 最大版本数
     */
    public function setMaxVersions($max) {
        $this->maxVersions = max(10, min(100, $max)); // 限制在10-100之间
    }
    
    /**
     * 自动清理所有笔记的超出版本数限制的旧版本
     * 
     * @return array
     */
    public function autoCleanup() {
        try {
            $totalDeleted = 0;
            
            // 获取所有有版本记录的笔记
            $stmt = $this->db->prepare(
                "SELECT note_id, COUNT(*) as count 
                 FROM note_versions 
                 GROUP BY note_id 
                 HAVING count > ?"
            );
            $stmt->execute([$this->maxVersions]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($notes as $note) {
                $noteId = $note['note_id'];
                $count = $note['count'];
                $toDelete = $count - $this->maxVersions;
                
                if ($toDelete > 0) {
                    $stmt = $this->db->prepare(
                        "DELETE FROM note_versions 
                         WHERE note_id = ? 
                         ORDER BY version_number ASC 
                         LIMIT ?"
                    );
                    $stmt->execute([$noteId, $toDelete]);
                    $totalDeleted += $stmt->rowCount();
                }
            }
            
            return [
                'success' => true,
                'deleted_count' => $totalDeleted,
                'message' => "自动清理了 {$totalDeleted} 个旧版本"
            ];
            
        } catch (Exception $e) {
            error_log("版本自动清理失败: " . $e->getMessage());
            return ['success' => false, 'message' => '版本自动清理失败'];
        }
    }
}
?>