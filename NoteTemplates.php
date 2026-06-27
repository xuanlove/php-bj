<?php
/**
 * 笔记模板管理类
 * 
 * 功能：
 * - 创建模板
 * - 获取模板列表
 * - 使用模板创建笔记
 * - 编辑/删除模板
 */

require_once 'config.php';

class NoteTemplates {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * 创建模板
     */
    public function create($user_id, $data) {
        $name = sanitizeInput($data['name'] ?? '');
        $description = sanitizeInput($data['description'] ?? '');
        $category = sanitizeInput($data['category'] ?? '');
        $content = $data['content'] ?? '';
        $content_type = $data['content_type'] ?? 'markdown';
        $is_public = isset($data['is_public']) ? ($data['is_public'] ? 1 : 0) : 0;
        
        if (empty($name)) {
            return ['success' => false, 'message' => '模板名称不能为空'];
        }
        
        $stmt = $this->db->prepare(
            "INSERT INTO note_templates (user_id, name, description, category, content, content_type, is_public)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        
        try {
            $stmt->execute([$user_id, $name, $description, $category, $content, $content_type, $is_public]);
            return [
                'success' => true,
                'template_id' => $this->db->lastInsertId(),
                'message' => '模板创建成功'
            ];
        } catch (Exception $e) {
            error_log("创建模板失败: " . $e->getMessage());
            return ['success' => false, 'message' => '创建失败，请稍后重试'];
        }
    }
    
    /**
     * 获取模板列表
     */
    public function getList($user_id, $category = null) {
        $sql = "SELECT * FROM note_templates 
                WHERE (user_id = ? OR is_public = 1) AND user_id IS NOT NULL
                ORDER BY use_count DESC, updated_at DESC";
        $params = [$user_id];
        
        if ($category) {
            $sql = "SELECT * FROM note_templates 
                    WHERE (user_id = ? OR is_public = 1) AND user_id IS NOT NULL AND category = ?
                    ORDER BY use_count DESC, updated_at DESC";
            $params[] = $category;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return ['success' => true, 'templates' => $stmt->fetchAll()];
    }
    
    /**
     * 获取单个模板
     */
    public function get($template_id, $user_id) {
        $stmt = $this->db->prepare(
            "SELECT * FROM note_templates WHERE id = ? AND (user_id = ? OR is_public = 1)"
        );
        $stmt->execute([$template_id, $user_id]);
        $template = $stmt->fetch();
        
        if (!$template) {
            return ['success' => false, 'message' => '模板不存在或无权访问'];
        }
        
        return ['success' => true, 'template' => $template];
    }
    
    /**
     * 更新模板
     */
    public function update($template_id, $user_id, $data) {
        // 验证所有权
        $check = $this->get($template_id, $user_id);
        if (!$check['success']) {
            return $check;
        }
        
        // 只允许模板所有者编辑
        $template = $check['template'];
        if ($template['user_id'] != $user_id) {
            return ['success' => false, 'message' => '只有模板创建者才能编辑'];
        }
        
        $allowed_fields = ['name', 'description', 'category', 'content', 'content_type', 'is_public'];
        $updates = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                if ($key !== 'content') {
                    $value = sanitizeInput($value);
                }
                $updates[] = "`" . $key . "` = ?";
                $values[] = $value;
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'message' => '没有可更新的字段'];
        }
        
        $values[] = $template_id;
        $sql = "UPDATE note_templates SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        try {
            $stmt->execute($values);
            return ['success' => true, 'message' => '模板更新成功'];
        } catch (Exception $e) {
            error_log("更新模板失败: " . $e->getMessage());
            return ['success' => false, 'message' => '更新失败'];
        }
    }
    
    /**
     * 删除模板
     */
    public function delete($template_id, $user_id) {
        // 验证所有权
        $stmt = $this->db->prepare(
            "SELECT id, user_id FROM note_templates WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$template_id, $user_id]);
        $template = $stmt->fetch();
        
        if (!$template) {
            return ['success' => false, 'message' => '模板不存在或无权删除'];
        }
        
        $delete = $this->db->prepare("DELETE FROM note_templates WHERE id = ?");
        try {
            $delete->execute([$template_id]);
            return ['success' => true, 'message' => '模板已删除'];
        } catch (Exception $e) {
            error_log("删除模板失败: " . $e->getMessage());
            return ['success' => false, 'message' => '删除失败'];
        }
    }
    
    /**
     * 使用模板创建笔记
     */
    public function createNote($template_id, $user_id) {
        $template = $this->get($template_id, $user_id);
        if (!$template['success']) {
            return $template;
        }
        
        $tpl = $template['template'];
        
        // 增加使用计数
        $update_count = $this->db->prepare(
            "UPDATE note_templates SET use_count = use_count + 1 WHERE id = ?"
        );
        $update_count->execute([$template_id]);
        
        return [
            'success' => true,
            'note_data' => [
                'title' => $tpl['name'],
                'content' => $tpl['content'],
                'content_type' => $tpl['content_type']
            ],
            'message' => '模板应用成功'
        ];
    }
    
    /**
     * 获取模板分类列表
     */
    public function getCategories($user_id) {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT category FROM note_templates 
             WHERE (user_id = ? OR is_public = 1) AND user_id IS NOT NULL AND category != ''
             ORDER BY category"
        );
        $stmt->execute([$user_id]);
        
        $categories = [];
        while ($row = $stmt->fetch()) {
            if (!empty($row['category'])) {
                $categories[] = $row['category'];
            }
        }
        
        return ['success' => true, 'categories' => $categories];
    }
    
    /**
     * 复制模板
     */
    public function duplicate($template_id, $user_id) {
        $template = $this->get($template_id, $user_id);
        if (!$template['success']) {
            return $template;
        }
        
        $tpl = $template['template'];
        
        return $this->create($user_id, [
            'name' => $tpl['name'] . ' (副本)',
            'description' => $tpl['description'],
            'category' => $tpl['category'],
            'content' => $tpl['content'],
            'content_type' => $tpl['content_type'],
            'is_public' => 0
        ]);
    }
}
?>
