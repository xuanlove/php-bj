<?php
require_once 'config.php';

class Notes {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    private function getVersionControl() {
        static $vc = null;
        if ($vc === null) {
            require_once 'VersionControl.php';
            $vc = new VersionControl();
        }
        return $vc;
    }
    
    // 创建笔记
    public function create($user_id, $data) {
        $title = sanitizeInput($data['title'] ?? 'Untitled');
        $content = $data['content'] ?? '';
        $content_type = $data['content_type'] ?? 'markdown';
        $folder_id = isset($data['folder_id']) ? intval($data['folder_id']) : null;
        $tags = isset($data['tags']) ? json_encode($data['tags']) : null;
        
        $stmt = $this->db->prepare(
            "INSERT INTO notes (user_id, title, content, content_type, folder_id, tags) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        
        try {
            $stmt->execute([$user_id, $title, $content, $content_type, $folder_id, $tags]);
            $note_id = $this->db->lastInsertId();
            
            // 保存初始版本
            $noteData = [
                'title' => $title,
                'content' => $content,
                'content_type' => $content_type
            ];
            $this->getVersionControl()->saveVersion($note_id, $user_id, $noteData, '创建笔记');
            
            return ['success' => true, 'note_id' => $note_id, 'message' => '笔记创建成功'];
        } catch (Exception $e) {
            error_log("创建笔记失败: " . $e->getMessage());
            return ['success' => false, 'message' => '创建失败，请稍后重试'];
        }
    }
    
    // 更新笔记
    public function update($note_id, $user_id, $data) {
        $access = $this->verifyAccess($note_id, $user_id);
        if (!$access['allowed']) {
            return ['success' => false, 'message' => '没有权限修改此笔记'];
        }
        if ($access['permission'] !== 'owner' && $access['permission'] !== 'edit' && $access['permission'] !== 'write' && $access['permission'] !== 'admin') {
            return ['success' => false, 'message' => '只有编辑权限才能修改此笔记'];
        }
        
        // 先获取当前内容，用于判断是否需要保存版本
        $currentStmt = $this->db->prepare("SELECT title, content, content_type FROM notes WHERE id = ?");
        $currentStmt->execute([$note_id]);
        $current = $currentStmt->fetch();
        
        // 白名单允许的字段
        $allowed_fields = ['title', 'content', 'content_type', 'folder_id', 'tags', 'is_favorite', 'is_archived', 'is_public', 'password_hash', 'cover_url', 'reminder_at'];
        $updates = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            // 严格验证字段名是否在白名单中
            if (in_array($key, $allowed_fields)) {
                // 输入过滤
                if ($key !== 'content') {  // 内容不过滤，保留原始格式
                    $value = sanitizeInput($value);
                }
                
                if ($key === 'tags' && is_array($value)) {
                    $value = json_encode($value);
                }
                
                // 使用参数化查询（字段名已在白名单中验证）
                $updates[] = "`" . $key . "` = ?";
                $values[] = $value;
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'message' => '没有可更新的字段'];
        }
        
        $values[] = $note_id;
        $sql = "UPDATE notes SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        try {
            $stmt->execute($values);
            
            // 仅当标题、内容或内容类型发生变化时才保存版本历史
            $contentChanged = false;
            if (isset($data['title']) && $data['title'] !== $current['title']) {
                $contentChanged = true;
            }
            if (isset($data['content']) && $data['content'] !== $current['content']) {
                $contentChanged = true;
            }
            if (isset($data['content_type']) && $data['content_type'] !== $current['content_type']) {
                $contentChanged = true;
            }
            
            if ($contentChanged) {
                $noteData = [
                    'title' => sanitizeInput($data['title'] ?? $current['title']),
                    'content' => $data['content'] ?? $current['content'],
                    'content_type' => $data['content_type'] ?? $current['content_type']
                ];
                $this->getVersionControl()->saveVersion($note_id, $user_id, $noteData, '自动保存');
            }
            
            return ['success' => true, 'message' => '更新成功'];
        } catch (Exception $e) {
            error_log("更新笔记失败: " . $e->getMessage());
            return ['success' => false, 'message' => '更新失败，请联系管理员'];
        }
    }
    
    // 删除笔记
    public function delete($note_id, $user_id) {
        if (!$this->verifyOwnership($note_id, $user_id)) {
            return ['success' => false, 'message' => '没有权限删除此笔记'];
        }
        
        // 获取并删除所有附件文件
        $stmt = $this->db->prepare("SELECT * FROM attachments WHERE note_id = ?");
        $stmt->execute([$note_id]);
        $attachments = $stmt->fetchAll();
        
        foreach ($attachments as $attachment) {
            if (file_exists($attachment['file_path'])) {
                @unlink($attachment['file_path']);
            }
        }
        
        // 删除笔记（数据库级联会删除附件记录）
        $stmt = $this->db->prepare("DELETE FROM notes WHERE id = ?");
        try {
            $stmt->execute([$note_id]);
            return ['success' => true, 'message' => '笔记及相关文件已删除'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '删除失败，请稍后重试'];
        }
    }
    
    // 获取单个笔记
    public function get($note_id, $user_id) {
        $access = $this->verifyAccess($note_id, $user_id);
        if (!$access['allowed']) {
            return ['success' => false, 'message' => '笔记不存在'];
        }

        $stmt = $this->db->prepare(
            "SELECT n.*, 
                    (SELECT COUNT(*) FROM attachments WHERE note_id = n.id) as attachment_count
             FROM notes n
             WHERE n.id = ? AND n.deleted_at IS NULL"
        );
        $stmt->execute([$note_id]);
        $note = $stmt->fetch();
        
        if ($note) {
            $note['tags'] = is_string($note['tags']) ? (json_decode($note['tags'], true) ?? []) : [];
            $note['user_permission'] = $access['permission'];
            
            // 获取该笔记的所有附件
            $attStmt = $this->db->prepare(
                "SELECT id, original_filename, filename, file_size, mime_type, uploaded_at, file_path 
                 FROM attachments WHERE note_id = ? ORDER BY uploaded_at DESC"
            );
            $attStmt->execute([$note_id]);
            $attachments = $attStmt->fetchAll();
            
            // 转换路径为相对路径
            foreach ($attachments as &$att) {
                $att['file_path'] = str_replace(
                    [rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, '\\'],
                    ['', '/'],
                    $att['file_path']
                );
            }
            $note['attachments'] = $attachments;
            
            return ['success' => true, 'note' => $note];
        }
        
        return ['success' => false, 'message' => '笔记不存在'];
    }
    
    // 获取用户所有笔记
    public function getAll($user_id, $filters = []) {
        $where = ["user_id = ?", "deleted_at IS NULL"];
        $params = [$user_id];
        
        // 应用过滤条件
        if (isset($filters['folder_id'])) {
            $where[] = "folder_id = ?";
            $params[] = $filters['folder_id'];
        }
        
        if (isset($filters['is_favorite'])) {
            $where[] = "is_favorite = ?";
            $params[] = $filters['is_favorite'];
        }
        
        if (isset($filters['is_archived'])) {
            $where[] = "is_archived = ?";
            $params[] = $filters['is_archived'];
        }
        
        if (isset($filters['search']) && !empty($filters['search'])) {
            $where[] = "(title LIKE ? OR content LIKE ?)";
            $search_term = '%' . $filters['search'] . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $sql = "SELECT n.*, 
                       (SELECT COUNT(*) FROM attachments WHERE note_id = n.id) as attachment_count
                FROM notes n
                WHERE " . implode(' AND ', $where) . "
                ORDER BY n.updated_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $notes = $stmt->fetchAll();
        
        // 解析tags
        foreach ($notes as &$note) {
            $note['tags'] = is_string($note['tags']) ? (json_decode($note['tags'], true) ?? []) : [];
        }
        
        // 批量获取所有笔记的附件信息
        if (!empty($notes)) {
            $noteIds = array_column($notes, 'id');
            $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
            $attStmt = $this->db->prepare(
                "SELECT id, note_id, original_filename, filename, file_size, mime_type, uploaded_at, file_path 
                 FROM attachments WHERE note_id IN ($placeholders) 
                 ORDER BY uploaded_at DESC"
            );
            $attStmt->execute($noteIds);
            $allAttachments = $attStmt->fetchAll();
            
            // 按 note_id 分组附件
            $grouped = [];
            foreach ($allAttachments as $att) {
                // 转换路径为相对路径
                $att['file_path'] = str_replace(
                    [rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, '\\'],
                    ['', '/'],
                    $att['file_path']
                );
                $grouped[$att['note_id']][] = $att;
            }
            
            foreach ($notes as &$note) {
                $note['attachments'] = $grouped[$note['id']] ?? [];
            }
        }
        
        return ['success' => true, 'notes' => $notes];
    }
    
    // 搜索笔记
    public function search($user_id, $keyword) {
        $search_term = '%' . $keyword . '%';

        $sql = "SELECT * FROM notes 
             WHERE deleted_at IS NULL AND (
                 (user_id = ?) OR 
                 (id IN (SELECT note_id FROM note_shares WHERE shared_with_id = ?))
             )
             AND (title LIKE ? OR content LIKE ?)
             ORDER BY updated_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$user_id, $user_id, $search_term, $search_term]);
        
        return $stmt->fetchAll();
    }
    
    // 验证笔记所有权
    private function verifyOwnership($note_id, $user_id) {
        $stmt = $this->db->prepare("SELECT id FROM notes WHERE id = ? AND user_id = ?");
        $stmt->execute([$note_id, $user_id]);
        return $stmt->fetch() !== false;
    }

    private function verifyAccess($note_id, $user_id) {
        if ($this->verifyOwnership($note_id, $user_id)) {
            return ['allowed' => true, 'permission' => 'owner'];
        }

        $stmt = $this->db->prepare(
            "SELECT permission FROM note_shares WHERE note_id = ? AND shared_with_id = ?"
        );
        $stmt->execute([$note_id, $user_id]);
        $share = $stmt->fetch();
        if ($share) {
            return ['allowed' => true, 'permission' => $share['permission']];
        }

        $stmt = $this->db->prepare(
            "SELECT tnp.permission FROM team_note_permissions tnp
             JOIN team_members tm ON tnp.team_id = tm.team_id
             WHERE tnp.note_id = ? AND tm.user_id = ?"
        );
        $stmt->execute([$note_id, $user_id]);
        $teamPerm = $stmt->fetch();
        if ($teamPerm) {
            return ['allowed' => true, 'permission' => $teamPerm['permission']];
        }

        return ['allowed' => false, 'permission' => null];
    }
    
    // 上传附件（增强安全性）
    public function uploadAttachment($note_id, $user_id, $file) {
        // 验证笔记所有权
        if (!$this->verifyOwnership($note_id, $user_id)) {
            return ['success' => false, 'message' => '没有权限上传附件'];
        }
        
        // 检查文件上传错误
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => '文件上传失败'];
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => '上传错误: ' . $this->getUploadErrorMessage($file['error'])];
        }
        
        // 检查文件大小
        if ($file['size'] > MAX_UPLOAD_SIZE) {
            return ['success' => false, 'message' => '文件大小超过限制（最大' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB）'];
        }
        
        // 获取文件信息
        $originalName = $file['name'];
        $fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // 使用 finfo 获取真实 MIME 类型，客户端提供的 $file['type'] 可被伪造
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($mimeType === false) {
            $mimeType = $file['type'];
        }

        // 验证文件扩展名
        if (!isAllowedFileType($originalName)) {
            return ['success' => false, 'message' => '不支持的文件类型'];
        }

        // 验证MIME类型（基于文件内容真实类型，防止伪造 Content-Type 绕过）
        if (!isAllowedMimeType($mimeType)) {
            return ['success' => false, 'message' => '不支持的文件类型'];
        }

        // 检查是否为可执行文件（安全风险）
        if (defined('DANGEROUS_EXTENSIONS') && in_array($fileExt, DANGEROUS_EXTENSIONS)) {
            return ['success' => false, 'message' => '禁止上传可执行文件'];
        }
        
        // 按日期创建目录（年/月）
        $dateDir = date('Y/m');
        $uploadDir = ATTACHMENT_DIR . $dateDir . '/';
        
        // 确保目标目录存在
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // 保留原文件名（清理不安全字符）
        $safeBasename = preg_replace('/[^a-zA-Z0-9_\-\p{L}\.]/u', '', pathinfo($originalName, PATHINFO_FILENAME));
        $safeBasename = trim($safeBasename, '-_');
        $safeBasename = $safeBasename ?: 'file';
        $filename = $safeBasename . '.' . $fileExt;
        
        // 如果文件已存在，添加序号
        $counter = 1;
        $finalFilename = $filename;
        while (file_exists($uploadDir . $finalFilename)) {
            $finalFilename = $safeBasename . '_' . $counter . '.' . $fileExt;
            $counter++;
        }
        
        $filepath = $uploadDir . $finalFilename;
        
        // 移动文件
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // 设置文件权限（禁止执行）
            chmod($filepath, 0644);
            
            // 保存到数据库（使用相对路径）
            $relativePath = 'attachments/' . $dateDir . '/' . $finalFilename;
            $stmt = $this->db->prepare(
                "INSERT INTO attachments (note_id, filename, original_filename, file_size, mime_type, file_path)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            
            try {
                $stmt->execute([
                    $note_id,
                    $finalFilename,
                    sanitizeInput($originalName),
                    $file['size'],
                    $mimeType,
                    $filepath  // 存储完整服务器路径
                ]);
                
                logSecurityEvent('file_uploaded', [
                    'user_id' => $user_id,
                    'note_id' => $note_id,
                    'filename' => $finalFilename,
                    'size' => $file['size']
                ]);
                return [
                    'success' => true,
                    'attachment_id' => $this->db->lastInsertId(),
                    'filename' => $finalFilename,
                    'original_filename' => $originalName,
                    'file_path' => $relativePath,  // 返回相对路径给前端
                    'url' => $relativePath,
                    'message' => '附件上传成功'
                ];
            } catch (Exception $e) {
                unlink($filepath);
                error_log("保存附件失败: " . $e->getMessage());
                return ['success' => false, 'message' => '保存失败，请联系管理员'];
            }
        }
        
        return ['success' => false, 'message' => '文件保存失败'];
    }
    
    // 获取上传错误消息
    private function getUploadErrorMessage($errorCode) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => '文件大小超过服务器限制',
            UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
            UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
            UPLOAD_ERR_NO_FILE => '没有文件被上传',
            UPLOAD_ERR_NO_TMP_DIR => '缺少临时文件夹',
            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            UPLOAD_ERR_EXTENSION => '文件上传被扩展停止'
        ];
        return $errors[$errorCode] ?? '未知错误';
    }
    
    // 获取笔记的所有附件
    public function getAttachments($note_id, $user_id) {
        // 验证笔记所有权
        if (!$this->verifyOwnership($note_id, $user_id)) {
            return ['success' => false, 'message' => '没有权限查看附件'];
        }
        
        $stmt = $this->db->prepare("SELECT * FROM attachments WHERE note_id = ? ORDER BY uploaded_at DESC");
        $stmt->execute([$note_id]);
        $attachments = $stmt->fetchAll();
        
        // 将绝对路径转换为程序主目录相对路径
        $basePath = rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($attachments as &$att) {
            if (!empty($att['file_path'])) {
                // 将绝对路径转为相对路径（相对于程序主目录）
                $att['file_path'] = str_replace($basePath, '', $att['file_path']);
                // 统一使用正斜杠，兼容Web路径
                $att['file_path'] = str_replace('\\', '/', $att['file_path']);
            }
        }
        
        return ['success' => true, 'attachments' => $attachments];
    }
    
    // 删除附件
    public function deleteAttachment($attachment_id, $user_id) {
        // 获取附件信息并验证权限
        $stmt = $this->db->prepare(
            "SELECT a.* FROM attachments a
             JOIN notes n ON a.note_id = n.id
             WHERE a.id = ? AND n.user_id = ?"
        );
        $stmt->execute([$attachment_id, $user_id]);
        $attachment = $stmt->fetch();
        
        if (!$attachment) {
            return ['success' => false, 'message' => '附件不存在或没有权限'];
        }
        
        // 删除文件（file_path存储的是服务器完整路径）
        $fullPath = $attachment['file_path'];
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        
        // 删除数据库记录
        $delete = $this->db->prepare("DELETE FROM attachments WHERE id = ?");
        $delete->execute([$attachment_id]);
        
        return ['success' => true, 'message' => '附件删除成功'];
    }
    
    // 创建文件夹
    public function createFolder($user_id, $name, $parent_id = null) {
        // parent_id 为 0 或空字符串时视为顶层文件夹，转成 NULL 以满足外键约束
        if ($parent_id !== null && $parent_id !== '' && !is_numeric($parent_id)) {
            return ['success' => false, 'message' => '无效的父文件夹'];
        }
        $parent_id = ($parent_id === null || $parent_id === '' || intval($parent_id) === 0) ? null : intval($parent_id);

        $stmt = $this->db->prepare("INSERT INTO folders (user_id, name, parent_id) VALUES (?, ?, ?)");
        try {
            $stmt->execute([$user_id, $name, $parent_id]);
            return ['success' => true, 'folder_id' => $this->db->lastInsertId()];
        } catch (Exception $e) {
            error_log("创建文件夹失败: " . $e->getMessage());
            return ['success' => false, 'message' => '创建文件夹失败，请稍后重试'];
        }
    }
    
    // 获取用户文件夹
    public function getFolders($user_id) {
        $stmt = $this->db->prepare(
            "SELECT f.*,
                    (SELECT COUNT(*) FROM notes WHERE folder_id = f.id) as note_count
             FROM folders f
             WHERE f.user_id = ?
             ORDER BY f.name"
        );
        $stmt->execute([$user_id]);
        return ['success' => true, 'folders' => $stmt->fetchAll()];
    }

    // 更新文件夹
    public function updateFolder($folder_id, $user_id, $data) {
        // 验证文件夹所有权
        $stmt = $this->db->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folder_id, $user_id]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => '文件夹不存在或没有权限'];
        }

        // 白名单允许的字段
        $allowed_fields = ['name', 'parent_id'];
        $updates = [];
        $values = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                $updates[] = "`" . $key . "` = ?";
                $values[] = $value;
            }
        }

        if (empty($updates)) {
            return ['success' => false, 'message' => '没有可更新的字段'];
        }

        $values[] = $folder_id;
        $sql = "UPDATE folders SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);

        try {
            $stmt->execute($values);
            return ['success' => true, 'message' => '文件夹更新成功'];
        } catch (Exception $e) {
            error_log("更新文件夹失败: " . $e->getMessage());
            return ['success' => false, 'message' => '更新失败，请联系管理员'];
        }
    }

    // 删除文件夹
    public function deleteFolder($folder_id, $user_id) {
        // 验证文件夹所有权
        $stmt = $this->db->prepare("SELECT id FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folder_id, $user_id]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => '文件夹不存在或没有权限'];
        }

        // 检查文件夹是否有笔记
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM notes WHERE folder_id = ?");
        $stmt->execute([$folder_id]);
        $count = $stmt->fetch()['count'];

        if ($count > 0) {
            return ['success' => false, 'message' => '文件夹中仍有 ' . $count . ' 篇笔记，请先移出'];
        }

        // 删除文件夹
        $stmt = $this->db->prepare("DELETE FROM folders WHERE id = ?");
        try {
            $stmt->execute([$folder_id]);
            return ['success' => true, 'message' => '文件夹已删除'];
        } catch (Exception $e) {
            error_log("删除文件夹失败: " . $e->getMessage());
            return ['success' => false, 'message' => '删除失败，请联系管理员'];
        }
    }

    // ===== 笔记导出 =====
    
    /**
     * 导出笔记为指定格式
     */
    public function exportNote($note_id, $user_id, $format = 'markdown') {
        $note = $this->get($note_id, $user_id);
        if (!$note['success']) {
            return $note;
        }
        
        $note = $note['note'];
        // get() 方法已解码 tags，如果仍是字符串则需要解码
        if (is_string($note['tags'])) {
            $note['tags'] = json_decode($note['tags'], true) ?? [];
        }
        
        switch ($format) {
            case 'markdown':
                return $this->exportAsMarkdown($note);
            case 'html':
                return $this->exportAsHtml($note);
            case 'json':
                return $this->exportAsJson($note);
            case 'txt':
                return $this->exportAsTxt($note);
            default:
                return ['success' => false, 'message' => '不支持的导出格式'];
        }
    }
    
    /**
     * 导出为 Markdown
     */
    private function exportAsMarkdown($note) {
        $content = "# " . $note['title'] . "\n\n";
        
        if (!empty($note['tags'])) {
            $content .= "**标签**: " . implode(', ', $note['tags']) . "\n\n";
        }
        
        $content .= "---\n\n";
        $content .= $note['content'];
        
        return [
            'success' => true,
            'content' => $content,
            'filename' => $this->sanitizeFilename($note['title']) . '.md',
            'mime_type' => 'text/markdown'
        ];
    }
    
    /**
     * 导出为 HTML
     */
    private function exportAsHtml($note) {
        $safeTitle = htmlspecialchars($note['title'], ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeTitle}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.9.0/build/styles/github-dark.min.css">
    <script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.9.0/build/highlight.min.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; line-height: 1.6; color: #333; }
        h1 { color: #333; border-bottom: 2px solid #d4af37; padding-bottom: 10px; }
        h2 { font-size: 1.5em; margin-top: 1.5em; }
        h3 { font-size: 1.25em; margin-top: 1.25em; }
        .meta { color: #666; font-size: 0.9em; margin-bottom: 20px; }
        .tags { margin: 10px 0; }
        .tag { display: inline-block; padding: 2px 8px; background: #f0f0f0; border-radius: 12px; font-size: 0.85em; margin-right: 4px; }
        pre { background: #0d1117; padding: 14px; border-radius: 8px; overflow-x: auto; }
        pre code { font-family: 'Source Code Pro', 'Consolas', monospace; font-size: 13px; background: transparent; padding: 0; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 4px; font-family: 'Source Code Pro', monospace; color: #d4af37; }
        blockquote { border-left: 4px solid #d4af37; margin: 10px 0; padding: 8px 16px; color: #666; background: #fafafa; border-radius: 0 6px 6px 0; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; }
        th { background: #f5f5f5; }
        a { color: #d4af37; }
        img { max-width: 100%; border-radius: 8px; }
        hr { border: none; border-top: 1px solid #ddd; margin: 16px 0; }
        ul, ol { padding-left: 24px; }
    </style>
</head>
<body>
    <h1>{$safeTitle}</h1>
    <div class="meta">
        创建于: {$note['created_at']} | 更新于: {$note['updated_at']}
    </div>
HTML;

        if (!empty($note['tags'])) {
            $tagsHtml = '<div class="tags">' . implode('', array_map(function($tag) {
                return '<span class="tag">' . htmlspecialchars($tag) . '</span>';
            }, $note['tags'])) . '</div>';
            $html .= $tagsHtml;
        }

        // 将 Markdown 转换为 HTML（带代码高亮 class）
        $content = $this->renderMarkdownForExport($note['content']);

        $html .= <<<HTML
    <div class="content">
        {$content}
    </div>
    <script>document.querySelectorAll('pre code').forEach(b => { try { hljs.highlightElement(b); } catch(e) {} });</script>
</body>
</html>
HTML;

        return [
            'success' => true,
            'content' => $html,
            'filename' => $this->sanitizeFilename($note['title']) . '.html',
            'mime_type' => 'text/html'
        ];
    }

    /**
     * 轻量 Markdown→HTML 转换（用于 HTML 导出）
     * 支持：标题、代码块（带语言 class）、行内代码、粗斜体、引用、列表、链接、图片、分隔线、表格
     * 不引入外部依赖，足够导出场景使用；前端编辑器预览使用 marked+highlight.js
     */
    private function renderMarkdownForExport($text) {
        if (empty($text)) return '';

        // 1. 先提取代码块占位，避免被其它规则破坏
        // 注意：占位符不能用 null 字节（\x00），因为 trim() 会把它去除导致还原失败
        $codeBlocks = [];
        $text = preg_replace_callback('/```(\w*)\n(.*?)```/s', function($m) use (&$codeBlocks) {
            $lang = trim($m[1]);
            $code = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
            $langClass = $lang ? " class=\"language-{$lang}\"" : '';
            // 使用罕见的占位符标记，避免与正文冲突
            $placeholder = "\x02CBLOCK" . count($codeBlocks) . "\x03";
            $codeBlocks[] = "<pre><code{$langClass}>{$code}</code></pre>";
            return $placeholder;
        }, $text);

        // 2. HTML 转义其余内容
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // 3. 标题 h1-h4
        $text = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);

        // 4. 分隔线
        $text = preg_replace('/^---+$/m', '<hr>', $text);

        // 5. 引用
        $text = preg_replace('/^&gt; (.+)$/m', '<blockquote>$1</blockquote>', $text);

        // 6. 粗体、斜体
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);

        // 7. 图片、链接
        $text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1">', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $text);

        // 8. 行内代码
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

        // 9. 无序列表
        $text = preg_replace_callback('/(?:^|\n)((?:[\-\*\+] .+(?:\n|$))+)/', function($m) {
            $items = preg_replace('/^[\-\*\+] (.+)$/m', '<li>$1</li>', $m[1]);
            return "\n<ul>\n" . $items . "\n</ul>";
        }, $text);

        // 10. 有序列表
        $text = preg_replace_callback('/(?:^|\n)((?:\d+\. .+(?:\n|$))+)/', function($m) {
            $items = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $m[1]);
            return "\n<ol>\n" . $items . "\n</ol>";
        }, $text);

        // 11. 段落与换行
        $text = preg_replace('/\n\n+/', "\n\n", $text);
        $blocks = preg_split('/\n\n+/', $text);
        $result = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') continue;
            // 跳过已是块级元素的
            if (preg_match('/^<(h[1-6]|ul|ol|blockquote|pre|hr|table)/i', $block)) {
                $result[] = $block;
            } else {
                $result[] = '<p>' . nl2br($block) . '</p>';
            }
        }
        $text = implode("\n\n", $result);

        // 12. 还原代码块
        foreach ($codeBlocks as $i => $cb) {
            $text = str_replace("\x02CBLOCK" . $i . "\x03", $cb, $text);
        }

        return $text;
    }
    
    /**
     * 导出为 JSON
     */
    private function exportAsJson($note) {
        $exportData = [
            'title' => $note['title'],
            'content' => $note['content'],
            'content_type' => $note['content_type'],
            'tags' => $note['tags'],
            'created_at' => $note['created_at'],
            'updated_at' => $note['updated_at']
        ];
        
        return [
            'success' => true,
            'content' => json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'filename' => $this->sanitizeFilename($note['title']) . '.json',
            'mime_type' => 'application/json'
        ];
    }
    
    /**
     * 导出为纯文本
     */
    private function exportAsTxt($note) {
        $content = $note['title'] . "\n";
        $content .= str_repeat('=', mb_strlen($note['title'])) . "\n\n";
        
        if (!empty($note['tags'])) {
            $content .= "标签: " . implode(', ', $note['tags']) . "\n\n";
        }
        
        $content .= $note['content'];
        
        return [
            'success' => true,
            'content' => $content,
            'filename' => $this->sanitizeFilename($note['title']) . '.txt',
            'mime_type' => 'text/plain'
        ];
    }
    
    /**
     * 批量导出笔记
     */
    public function exportNotes($user_id, $note_ids, $format = 'zip') {
        if (empty($note_ids)) {
            return ['success' => false, 'message' => '请选择要导出的笔记'];
        }
        
        $exports = [];
        foreach ($note_ids as $note_id) {
            $result = $this->exportNote($note_id, $user_id, $format === 'zip' ? 'markdown' : $format);
            if ($result['success']) {
                $exports[] = $result;
            }
        }
        
        if (empty($exports)) {
            return ['success' => false, 'message' => '没有可导出的笔记'];
        }
        
        // 如果只有一个笔记，直接返回
        if (count($exports) === 1) {
            return $exports[0];
        }
        
        // 多个笔记返回列表供前端处理
        return [
            'success' => true,
            'notes' => $exports,
            'count' => count($exports)
        ];
    }
    
    /**
     * 清理文件名
     */
    private function sanitizeFilename($filename) {
        // 使用 \p{L} 支持 Unicode 字母，兼容 PCRE2
        $filename = preg_replace('/[^\w\s\-\p{L}]/u', '', $filename);
        $filename = preg_replace('/[\s]+/', '_', $filename);
        return trim($filename, '_') ?: 'untitled';
    }
}
?>
