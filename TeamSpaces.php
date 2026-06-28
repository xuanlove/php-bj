<?php
/**
 * 团队空间管理类
 * 
 * 功能：
 * - 创建/管理团队
 * - 团队成员管理
 * - 团队文件夹管理
 * - 笔记共享权限管理
 * - 团队邀请
 */

require_once 'config.php';

class TeamSpaces {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    // ===== 团队管理 =====
    
    /**
     * 创建团队
     */
    public function createTeam($user_id, $data) {
        $name = sanitizeInput($data['name'] ?? '');
        $description = sanitizeInput($data['description'] ?? '');
        
        if (empty($name)) {
            return ['success' => false, 'message' => '团队名称不能为空'];
        }
        
        $this->db->beginTransaction();
        try {
            // 创建团队
            $stmt = $this->db->prepare(
                "INSERT INTO teams (name, description, owner_id) VALUES (?, ?, ?)"
            );
            $stmt->execute([$name, $description, $user_id]);
            $team_id = $this->db->lastInsertId();
            
            // 创建者自动成为成员
            $stmt = $this->db->prepare(
                "INSERT INTO team_members (team_id, user_id, role) VALUES (?, ?, 'owner')"
            );
            $stmt->execute([$team_id, $user_id]);
            
            // 创建根文件夹
            $stmt = $this->db->prepare(
                "INSERT INTO team_folders (team_id, name, created_by) VALUES (?, '根目录', ?)"
            );
            $stmt->execute([$team_id, $user_id]);
            
            $this->db->commit();
            return [
                'success' => true,
                'team_id' => $team_id,
                'message' => '团队创建成功'
            ];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log("创建团队失败: " . $e->getMessage());
            return ['success' => false, 'message' => '创建失败，请稍后重试'];
        }
    }
    
    /**
     * 获取用户的团队列表
     */
    public function getMyTeams($user_id) {
        $stmt = $this->db->prepare(
            "SELECT t.*, tm.role as my_role,
                    (SELECT COUNT(*) FROM team_members WHERE team_id = t.id) as member_count
             FROM teams t
             JOIN team_members tm ON t.id = tm.team_id
             WHERE tm.user_id = ?
             ORDER BY t.updated_at DESC"
        );
        $stmt->execute([$user_id]);
        
        return ['success' => true, 'teams' => $stmt->fetchAll()];
    }
    
    /**
     * 获取团队详情
     */
    public function getTeam($team_id, $user_id) {
        // 验证成员身份
        if (!$this->isMember($team_id, $user_id)) {
            return ['success' => false, 'message' => '无权访问此团队'];
        }
        
        $stmt = $this->db->prepare(
            "SELECT t.*, tm.role as my_role
             FROM teams t
             JOIN team_members tm ON t.id = tm.team_id
             WHERE t.id = ? AND tm.user_id = ?"
        );
        $stmt->execute([$team_id, $user_id]);
        $team = $stmt->fetch();
        
        if (!$team) {
            return ['success' => false, 'message' => '团队不存在'];
        }
        
        // 获取成员列表
        $stmt = $this->db->prepare(
            "SELECT tm.*, u.username, u.email, u.full_name
             FROM team_members tm
             JOIN users u ON tm.user_id = u.id
             WHERE tm.team_id = ?
             ORDER BY tm.role DESC, tm.joined_at ASC"
        );
        $stmt->execute([$team_id]);
        $team['members'] = $stmt->fetchAll();
        
        // 获取文件夹
        $stmt = $this->db->prepare(
            "SELECT * FROM team_folders WHERE team_id = ? ORDER BY name"
        );
        $stmt->execute([$team_id]);
        $team['folders'] = $stmt->fetchAll();
        
        return ['success' => true, 'team' => $team];
    }
    
    /**
     * 更新团队信息
     */
    public function updateTeam($team_id, $user_id, $data) {
        // 需要 owner 或 admin 权限
        if (!$this->isAdmin($team_id, $user_id)) {
            return ['success' => false, 'message' => '需要管理员权限'];
        }
        
        $name = sanitizeInput($data['name'] ?? '');
        $description = sanitizeInput($data['description'] ?? '');
        
        if (empty($name)) {
            return ['success' => false, 'message' => '团队名称不能为空'];
        }
        
        $stmt = $this->db->prepare(
            "UPDATE teams SET name = ?, description = ? WHERE id = ?"
        );
        $stmt->execute([$name, $description, $team_id]);
        
        return ['success' => true, 'message' => '团队信息已更新'];
    }
    
    /**
     * 删除团队（仅 owner）
     */
    public function deleteTeam($team_id, $user_id) {
        // 需要 owner 权限
        $stmt = $this->db->prepare(
            "SELECT owner_id FROM teams WHERE id = ?"
        );
        $stmt->execute([$team_id]);
        $team = $stmt->fetch();
        
        if (!$team) {
            return ['success' => false, 'message' => '团队不存在'];
        }
        
        if ($team['owner_id'] != $user_id) {
            return ['success' => false, 'message' => '仅团队所有者可以删除团队'];
        }
        
        $stmt = $this->db->prepare("DELETE FROM teams WHERE id = ?");
        $stmt->execute([$team_id]);
        
        return ['success' => true, 'message' => '团队已删除'];
    }
    
    // ===== 成员管理 =====
    
    /**
     * 邀请成员
     */
    public function inviteMember($team_id, $user_id, $data) {
        // 需要 admin 权限
        if (!$this->isAdmin($team_id, $user_id)) {
            return ['success' => false, 'message' => '需要管理员权限'];
        }
        
        $email = sanitizeInput($data['email'] ?? '');
        $role = $data['role'] ?? 'member';
        
        if (!in_array($role, ['admin', 'member'])) {
            $role = 'member';
        }
        
        // 检查邮箱是否已注册
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $invited_user = $stmt->fetch();
        
        if ($invited_user) {
            // 直接添加成员
            return $this->addMember($team_id, $user_id, [
                'user_id' => $invited_user['id'],
                'role' => $role
            ]);
        } else {
            // 发送邀请链接
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
            
            $stmt = $this->db->prepare(
                "INSERT INTO team_invitations (team_id, email, token, role, invited_by, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$team_id, $email, $token, $role, $user_id, $expires_at]);
            
            return [
                'success' => true,
                'invite_token' => $token,
                'message' => '邀请链接已生成'
            ];
        }
    }
    
    /**
     * 添加成员（直接）
     */
    public function addMember($team_id, $invited_by, $data) {
        if (!$this->isAdmin($team_id, $invited_by)) {
            return ['success' => false, 'message' => '需要管理员权限'];
        }

        $target_user_id = intval($data['user_id'] ?? 0);
        $role = $data['role'] ?? 'member';

        if (!in_array($role, ['admin', 'member'])) {
            return ['success' => false, 'message' => '无效的角色'];
        }

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO team_members (team_id, user_id, role, invited_by) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$team_id, $target_user_id, $role, $invited_by]);
            return ['success' => true, 'message' => '成员已添加'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '该用户已在团队中'];
        }
    }
    
    /**
     * 移除成员
     */
    public function removeMember($team_id, $user_id, $target_member_id) {
        // 需要 admin 权限或移除自己
        if ($user_id != $target_member_id && !$this->isAdmin($team_id, $user_id)) {
            return ['success' => false, 'message' => '需要管理员权限'];
        }
        
        // 不能移除 owner
        $stmt = $this->db->prepare(
            "SELECT role FROM team_members WHERE team_id = ? AND user_id = ?"
        );
        $stmt->execute([$team_id, $target_member_id]);
        $member = $stmt->fetch();
        
        if ($member && $member['role'] === 'owner') {
            return ['success' => false, 'message' => '不能移除团队所有者'];
        }
        
        $stmt = $this->db->prepare(
            "DELETE FROM team_members WHERE team_id = ? AND user_id = ?"
        );
        $stmt->execute([$team_id, $target_member_id]);
        
        return ['success' => true, 'message' => '成员已移除'];
    }
    
    /**
     * 更新成员角色
     */
    public function updateMemberRole($team_id, $user_id, $target_member_id, $new_role) {
        // 需要 owner 权限
        if (!$this->isOwner($team_id, $user_id)) {
            return ['success' => false, 'message' => '仅所有者可以修改角色'];
        }
        
        if (!in_array($new_role, ['admin', 'member'])) {
            return ['success' => false, 'message' => '无效的角色'];
        }
        
        $stmt = $this->db->prepare(
            "UPDATE team_members SET role = ? WHERE team_id = ? AND user_id = ? AND role != 'owner'"
        );
        $stmt->execute([$new_role, $team_id, $target_member_id]);
        
        return ['success' => true, 'message' => '角色已更新'];
    }
    
    /**
     * 接受邀请
     */
    public function acceptInvite($user_id, $token) {
        $stmt = $this->db->prepare(
            "SELECT * FROM team_invitations WHERE token = ? AND accepted_at IS NULL AND expires_at > NOW()"
        );
        $stmt->execute([$token]);
        $invite = $stmt->fetch();
        
        if (!$invite) {
            return ['success' => false, 'message' => '邀请已过期或不存在'];
        }
        
        $this->db->beginTransaction();
        try {
            // 添加成员
            $stmt = $this->db->prepare(
                "INSERT INTO team_members (team_id, user_id, role, invited_by) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$invite['team_id'], $user_id, $invite['role'], $invite['invited_by']]);
            
            // 标记邀请已接受
            $stmt = $this->db->prepare(
                "UPDATE team_invitations SET accepted_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$invite['id']]);
            
            $this->db->commit();
            return ['success' => true, 'message' => '已加入团队'];
        } catch (Exception $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            return ['success' => false, 'message' => '加入失败'];
        }
    }
    
    // ===== 文件夹管理 =====
    
    /**
     * 创建团队文件夹
     */
    public function createFolder($team_id, $user_id, $data) {
        if (!$this->isMember($team_id, $user_id)) {
            return ['success' => false, 'message' => '无权访问'];
        }
        
        $name = sanitizeInput($data['name'] ?? '');
        $parent_id = isset($data['parent_id']) ? intval($data['parent_id']) : null;
        
        if (empty($name)) {
            return ['success' => false, 'message' => '文件夹名称不能为空'];
        }
        
        $stmt = $this->db->prepare(
            "INSERT INTO team_folders (team_id, name, parent_id, created_by) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$team_id, $name, $parent_id, $user_id]);
        
        return [
            'success' => true,
            'folder_id' => $this->db->lastInsertId(),
            'message' => '文件夹已创建'
        ];
    }
    
    /**
     * 获取团队文件夹
     */
    public function getFolders($team_id, $user_id) {
        if (!$this->isMember($team_id, $user_id)) {
            return ['success' => false, 'message' => '无权访问'];
        }
        
        $stmt = $this->db->prepare(
            "SELECT tf.*, u.username as created_by_name
             FROM team_folders tf
             LEFT JOIN users u ON tf.created_by = u.id
             WHERE tf.team_id = ?
             ORDER BY tf.name"
        );
        $stmt->execute([$team_id]);
        
        return ['success' => true, 'folders' => $stmt->fetchAll()];
    }
    
    // ===== 笔记权限管理 =====
    
    /**
     * 共享笔记到团队
     */
    public function shareNoteToTeam($note_id, $team_id, $user_id, $permission = 'read') {
        // 笔记所有者才能共享
        $stmt = $this->db->prepare("SELECT user_id FROM notes WHERE id = ?");
        $stmt->execute([$note_id]);
        $note = $stmt->fetch();
        
        if (!$note || $note['user_id'] != $user_id) {
            return ['success' => false, 'message' => '只有笔记所有者可以共享'];
        }
        
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO team_note_permissions (note_id, team_id, permission, granted_by)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$note_id, $team_id, $permission, $user_id]);
            return ['success' => true, 'message' => '已共享到团队'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '笔记已在团队中'];
        }
    }
    
    /**
     * 获取团队可访问的笔记
     */
    public function getTeamNotes($team_id, $user_id, $folder_id = null) {
        if (!$this->isMember($team_id, $user_id)) {
            return ['success' => false, 'message' => '无权访问'];
        }
        
        $sql = "SELECT n.*, tnp.permission, u.username as owner_name
                FROM notes n
                JOIN team_note_permissions tnp ON n.id = tnp.note_id
                JOIN users u ON n.user_id = u.id
                WHERE tnp.team_id = ? AND n.deleted_at IS NULL";
        $params = [$team_id];
        
        if ($folder_id) {
            $sql .= " AND n.folder_id = ?";
            $params[] = $folder_id;
        }
        
        $sql .= " ORDER BY n.updated_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return ['success' => true, 'notes' => $stmt->fetchAll()];
    }
    
    // ===== 权限检查辅助方法 =====
    
    private function isMember($team_id, $user_id) {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?"
        );
        $stmt->execute([$team_id, $user_id]);
        return $stmt->fetch() !== false;
    }
    
    private function isAdmin($team_id, $user_id) {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND role IN ('owner', 'admin')"
        );
        $stmt->execute([$team_id, $user_id]);
        return $stmt->fetch() !== false;
    }
    
    private function isOwner($team_id, $user_id) {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND role = 'owner'"
        );
        $stmt->execute([$team_id, $user_id]);
        return $stmt->fetch() !== false;
    }
}
?>
