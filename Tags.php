<?php
/**
 * 标签管理类 - P01
 * 提供标签的创建、编辑、删除、查询功能
 */

require_once __DIR__ . '/config.php';

class Tags {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    // 获取用户所有标签
    public function getList($user_id, $data = []) {
        // 首先确保note_tags表存在
        $this->ensureTableExists();

        // 字段名白名单，防止 SQL 注入
        $allowedSortFields = ['name', 'note_count', 'created_at'];
        $sortBy = in_array($data['sort_by'] ?? 'created_at', $allowedSortFields) ? ($data['sort_by'] ?? 'created_at') : 'created_at';
        $sortOrder = strtoupper($data['sort_order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $stmt = $this->db->prepare("
            SELECT
                t.id,
                t.name,
                t.color,
                t.created_at,
                COUNT(n.id) as note_count
            FROM note_tags t
            LEFT JOIN note_tag_mapping nt ON t.id = nt.tag_id
            LEFT JOIN notes n ON nt.note_id = n.id AND n.user_id = ?
            WHERE t.user_id = ?
            GROUP BY t.id
            ORDER BY `$sortBy` $sortOrder
        ");
        $stmt->execute([$user_id, $user_id]);
        $tags = $stmt->fetchAll();

        return [
            'success' => true,
            'tags' => $tags
        ];
    }
    
    // 创建标签
    public function create($user_id, $data) {
        $this->ensureTableExists();
        
        $name = sanitizeInput($data['name'] ?? '');
        $color = sanitizeInput($data['color'] ?? '#d4af37');
        
        if (empty($name)) {
            return ['success' => false, 'message' => '标签名称不能为空'];
        }
        
        // 检查标签是否已存在
        $checkStmt = $this->db->prepare("SELECT id FROM note_tags WHERE user_id = ? AND name = ?");
        $checkStmt->execute([$user_id, $name]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => '标签已存在'];
        }
        
        try {
            $stmt = $this->db->prepare("INSERT INTO note_tags (user_id, name, color) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $name, $color]);
            
            logSecurityEvent('tag_created', ['user_id' => $user_id, 'tag_name' => $name]);
            
            return [
                'success' => true,
                'message' => '标签创建成功',
                'tag_id' => $this->db->lastInsertId()
            ];
        } catch (Exception $e) {
            error_log("创建标签失败: " . $e->getMessage());
            return ['success' => false, 'message' => '创建失败'];
        }
    }
    
    // 更新标签
    public function update($user_id, $data) {
        $tag_id = intval($data['tag_id'] ?? 0);
        $name = sanitizeInput($data['name'] ?? '');
        $color = sanitizeInput($data['color'] ?? '#d4af37');
        
        if (empty($tag_id) || empty($name)) {
            return ['success' => false, 'message' => '参数不完整'];
        }
        
        // 检查标签所有权
        $checkStmt = $this->db->prepare("SELECT id FROM note_tags WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$tag_id, $user_id]);
        if (!$checkStmt->fetch()) {
            return ['success' => false, 'message' => '标签不存在'];
        }
        
        try {
            $stmt = $this->db->prepare("UPDATE note_tags SET name = ?, color = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$name, $color, $tag_id, $user_id]);
            
            return ['success' => true, 'message' => '标签更新成功'];
        } catch (Exception $e) {
            error_log("更新标签失败: " . $e->getMessage());
            return ['success' => false, 'message' => '更新失败'];
        }
    }
    
    // 删除标签
    public function delete($user_id, $tag_id) {
        $tag_id = intval($tag_id);
        
        if (empty($tag_id)) {
            return ['success' => false, 'message' => '标签ID不能为空'];
        }
        
        // 检查标签所有权
        $checkStmt = $this->db->prepare("SELECT id FROM note_tags WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$tag_id, $user_id]);
        if (!$checkStmt->fetch()) {
            return ['success' => false, 'message' => '标签不存在'];
        }
        
        $this->db->beginTransaction();
        try {
            // 先删除标签关联
            $deleteMapping = $this->db->prepare("DELETE FROM note_tag_mapping WHERE tag_id = ?");
            $deleteMapping->execute([$tag_id]);

            // 再删除标签
            $stmt = $this->db->prepare("DELETE FROM note_tags WHERE id = ? AND user_id = ?");
            $stmt->execute([$tag_id, $user_id]);

            $this->db->commit();

            logSecurityEvent('tag_deleted', ['user_id' => $user_id, 'tag_id' => $tag_id]);

            return ['success' => true, 'message' => '标签删除成功'];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("删除标签失败: " . $e->getMessage());
            return ['success' => false, 'message' => '删除失败: ' . $e->getMessage()];
        }
    }
    
    // 为笔记添加标签
    public function addTagToNote($user_id, $note_id, $tag_id) {
        // 验证笔记所有权
        $noteStmt = $this->db->prepare("SELECT id FROM notes WHERE id = ? AND user_id = ?");
        $noteStmt->execute([$note_id, $user_id]);
        if (!$noteStmt->fetch()) {
            return ['success' => false, 'message' => '笔记不存在'];
        }
        
        // 验证标签所有权
        $tagStmt = $this->db->prepare("SELECT id FROM note_tags WHERE id = ? AND user_id = ?");
        $tagStmt->execute([$tag_id, $user_id]);
        if (!$tagStmt->fetch()) {
            return ['success' => false, 'message' => '标签不存在'];
        }
        
        try {
            // 检查是否已关联
            $checkStmt = $this->db->prepare("SELECT id FROM note_tag_mapping WHERE note_id = ? AND tag_id = ?");
            $checkStmt->execute([$note_id, $tag_id]);
            if ($checkStmt->fetch()) {
                return ['success' => true, 'message' => '标签已关联'];
            }
            
            $stmt = $this->db->prepare("INSERT INTO note_tag_mapping (note_id, tag_id) VALUES (?, ?)");
            $stmt->execute([$note_id, $tag_id]);
            
            return ['success' => true, 'message' => '标签添加成功'];
        } catch (Exception $e) {
            error_log("添加标签失败: " . $e->getMessage());
            return ['success' => false, 'message' => '添加失败'];
        }
    }
    
    // 移除笔记的标签
    public function removeTagFromNote($user_id, $note_id, $tag_id) {
        // 验证笔记所有权
        $noteStmt = $this->db->prepare("SELECT id FROM notes WHERE id = ? AND user_id = ?");
        $noteStmt->execute([$note_id, $user_id]);
        if (!$noteStmt->fetch()) {
            return ['success' => false, 'message' => '笔记不存在'];
        }
        
        try {
            $stmt = $this->db->prepare("DELETE FROM note_tag_mapping WHERE note_id = ? AND tag_id = ?");
            $stmt->execute([$note_id, $tag_id]);
            
            return ['success' => true, 'message' => '标签移除成功'];
        } catch (Exception $e) {
            error_log("移除标签失败: " . $e->getMessage());
            return ['success' => false, 'message' => '移除失败'];
        }
    }
    
    // 获取笔记的所有标签
    public function getNoteTags($user_id, $note_id) {
        // 验证笔记所有权
        $noteStmt = $this->db->prepare("SELECT id FROM notes WHERE id = ? AND user_id = ?");
        $noteStmt->execute([$note_id, $user_id]);
        if (!$noteStmt->fetch()) {
            return ['success' => false, 'message' => '笔记不存在'];
        }
        
        $stmt = $this->db->prepare("
            SELECT t.id, t.name, t.color
            FROM note_tags t
            JOIN note_tag_mapping nt ON t.id = nt.tag_id
            WHERE nt.note_id = ? AND t.user_id = ?
        ");
        $stmt->execute([$note_id, $user_id]);
        $tags = $stmt->fetchAll();
        
        return [
            'success' => true,
            'tags' => $tags
        ];
    }

    // 重命名标签（更新标签名称，所有关联笔记自动生效）
    public function renameTag($user_id, $old_name, $new_name) {
        $old_name = trim($old_name);
        $new_name = trim($new_name);

        if (empty($old_name) || empty($new_name)) {
            return ['success' => false, 'message' => '标签名称不能为空'];
        }

        if ($old_name === $new_name) {
            return ['success' => false, 'message' => '新标签名称与原名称相同'];
        }

        $this->ensureTableExists();

        $stmt = $this->db->prepare("SELECT id FROM note_tags WHERE user_id = ? AND name = ?");
        $stmt->execute([$user_id, $old_name]);
        $tag = $stmt->fetch();

        if (!$tag) {
            return ['success' => false, 'message' => '标签不存在'];
        }

        $checkStmt = $this->db->prepare("SELECT id FROM note_tags WHERE user_id = ? AND name = ?");
        $checkStmt->execute([$user_id, $new_name]);
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => '新标签名称已存在'];
        }

        try {
            $stmt = $this->db->prepare("UPDATE note_tags SET name = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$new_name, $tag['id'], $user_id]);

            return ['success' => true, 'message' => '标签重命名成功'];
        } catch (Exception $e) {
            error_log("重命名标签失败: " . $e->getMessage());
            return ['success' => false, 'message' => '重命名失败'];
        }
    }

    // 删除标签（同时删除所有关联）
    public function deleteTagByName($user_id, $name) {
        $name = trim($name);
        if (empty($name)) {
            return ['success' => false, 'message' => '标签名称不能为空'];
        }

        $this->ensureTableExists();

        $stmt = $this->db->prepare("SELECT id FROM note_tags WHERE user_id = ? AND name = ?");
        $stmt->execute([$user_id, $name]);
        $tag = $stmt->fetch();

        if (!$tag) {
            return ['success' => false, 'message' => '标签不存在'];
        }

        return $this->delete($user_id, $tag['id']);
    }

    // 按标签ID获取关联笔记列表
    public function getNotesByTagId($user_id, $tag_id, $limit = 50, $offset = 0) {
        $this->ensureTableExists();

        $stmt = $this->db->prepare("
            SELECT n.*, nt_map.tag_id
            FROM notes n
            JOIN note_tag_mapping nt_map ON n.id = nt_map.note_id
            JOIN note_tags nt ON nt_map.tag_id = nt.id
            WHERE nt.user_id = ? AND nt.id = ? AND n.deleted_at IS NULL
            ORDER BY n.updated_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user_id, $tag_id, $limit, $offset]);
        $notes = $stmt->fetchAll();

        return ['success' => true, 'notes' => $notes];
    }
    
    // 确保表存在
    private function ensureTableExists() {
        static $checked = false;
        if ($checked) return;
        
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'note_tags'");
            if ($stmt->rowCount() > 0) {
                $checked = true;
                return;
            }
        } catch (Exception $e) {
        }
        
        @error_log("note_tags/note_tag_mapping 表不存在（请通过 install.php 重新安装）");
        $checked = true;
    }
}
?>
