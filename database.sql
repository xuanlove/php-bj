-- ============================================
-- PHP笔记系统 V2.0 完整数据库脚本
-- 用途：新安装时由 install.php 执行
-- 版本：V2.0
-- 日期：2026年6月24日
-- 说明：此文件为唯一权威建库脚本
--       仅限新装使用，不支持从旧版升级
--       旧版升级请先备份数据，再全新安装后导入数据
-- ============================================

-- 用户表
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    avatar TEXT,
    role ENUM('admin', 'user') DEFAULT 'user',
    status ENUM('active', 'suspended', 'disabled') DEFAULT 'active',
    invited_by INT,
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    two_factor_secret VARCHAR(255),
    backup_codes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 邀请码表
CREATE TABLE IF NOT EXISTS invitation_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    created_by INT NOT NULL,
    used_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    status ENUM('active', 'used', 'expired') DEFAULT 'active',
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 笔记表
CREATE TABLE IF NOT EXISTS notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT,
    content_type ENUM('markdown', 'richtext') DEFAULT 'markdown',
    is_favorite BOOLEAN DEFAULT FALSE,
    is_archived BOOLEAN DEFAULT FALSE,
    is_public BOOLEAN DEFAULT FALSE,
    password_hash VARCHAR(255),
    cover_url VARCHAR(500),
    reminder_at TIMESTAMP NULL,
    folder_id INT,
    tags TEXT,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FULLTEXT INDEX ft_content (title, content),
    INDEX idx_deleted (deleted_at),
    INDEX idx_user_updated (user_id, updated_at),
    INDEX idx_user_folder (user_id, folder_id),
    INDEX idx_user_favorite (user_id, is_favorite)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 文件夹表
CREATE TABLE IF NOT EXISTS folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    parent_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 附件表
CREATE TABLE IF NOT EXISTS attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL,
    mime_type VARCHAR(100),
    file_path VARCHAR(500) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI交互历史表
CREATE TABLE IF NOT EXISTS ai_interactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note_id INT NOT NULL,
    user_id INT NOT NULL,
    ai_provider ENUM('chatgpt', 'doubao', 'claude', 'custom_openai') NOT NULL,
    action_type ENUM('proofread', 'continue', 'summarize', 'rewrite') NOT NULL,
    original_content TEXT,
    ai_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 系统设置表
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type VARCHAR(50),
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 登录日志表
CREATE TABLE IF NOT EXISTS login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(50),
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    login_status ENUM('success', 'failed') NOT NULL,
    failure_reason VARCHAR(100),
    location VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_date (user_id, created_at),
    INDEX idx_status (login_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 笔记版本控制表
CREATE TABLE IF NOT EXISTS note_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note_id INT NOT NULL,
    user_id INT NOT NULL,
    version_number INT NOT NULL,
    title VARCHAR(255),
    content LONGTEXT,
    content_type ENUM('markdown', 'richtext'),
    change_description VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_version (note_id, version_number),
    INDEX idx_note_created (note_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 笔记分享表
CREATE TABLE IF NOT EXISTS shared_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note_id INT NOT NULL,
    user_id INT NOT NULL,
    share_token VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255),
    expires_at TIMESTAMP NULL,
    view_count INT DEFAULT 0,
    allow_download BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (share_token),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 搜索历史表（预留，将在后续版本实现搜索历史功能）
CREATE TABLE IF NOT EXISTS search_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    search_query VARCHAR(255) NOT NULL,
    search_type ENUM('simple', 'advanced') DEFAULT 'simple',
    filters JSON,
    result_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 用户设置表
CREATE TABLE IF NOT EXISTS user_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    theme ENUM('light', 'dark', 'auto') DEFAULT 'dark',
    editor_font_size INT DEFAULT 14,
    editor_font_family VARCHAR(50) DEFAULT 'Source Code Pro',
    editor_theme VARCHAR(50) DEFAULT 'default',
    editor_tab_size INT DEFAULT 4,
    accent_color VARCHAR(20) DEFAULT '#d4af37',
    auto_save BOOLEAN DEFAULT TRUE,
    auto_save_interval INT DEFAULT 30,
    email_notifications BOOLEAN DEFAULT TRUE,
    language VARCHAR(10) DEFAULT 'zh-CN',
    timezone VARCHAR(50) DEFAULT 'Asia/Shanghai',
    template VARCHAR(50) DEFAULT 'default',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 笔记模板表
CREATE TABLE IF NOT EXISTS note_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50),
    content LONGTEXT,
    content_type ENUM('markdown', 'richtext') DEFAULT 'markdown',
    is_public BOOLEAN DEFAULT FALSE,
    use_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_category (category),
    INDEX idx_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 笔记协作共享表
CREATE TABLE IF NOT EXISTS note_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note_id INT NOT NULL,
    owner_id INT NOT NULL,
    shared_with_id INT NOT NULL,
    permission ENUM('read', 'edit') DEFAULT 'read',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_share (note_id, shared_with_id),
    INDEX idx_owner (owner_id),
    INDEX idx_shared_with (shared_with_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 评论表
CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note_id INT NOT NULL,
    user_id INT NOT NULL,
    parent_id INT,
    content TEXT NOT NULL,
    is_deleted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE,
    INDEX idx_note (note_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 通知表
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('system', 'share', 'comment', 'ai_complete', 'security') NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT,
    link VARCHAR(500),
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API密钥表
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    permissions JSON,
    last_used TIMESTAMP NULL,
    usage_count INT DEFAULT 0,
    expires_at TIMESTAMP NULL,
    status ENUM('active', 'revoked') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_key (api_key),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 系统日志表
CREATE TABLE IF NOT EXISTS system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level ENUM('info', 'warning', 'error', 'critical') NOT NULL,
    category VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    context JSON,
    user_id INT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_level_date (level, created_at),
    INDEX idx_category (category),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 速率限制表
CREATE TABLE IF NOT EXISTS rate_limits (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'ip',
    request_count INT NOT NULL DEFAULT 1,
    window_start INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_type (identifier, type),
    INDEX idx_window_start (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 备份配置表
CREATE TABLE IF NOT EXISTS backup_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_name VARCHAR(100) NOT NULL,
    storage_type VARCHAR(20) NOT NULL DEFAULT 'ftp',
    backup_frequency ENUM('daily', 'weekly', 'monthly') DEFAULT 'weekly',
    retention_count INT NOT NULL DEFAULT 5,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    credentials_json TEXT,
    last_backup TIMESTAMP NULL,
    next_backup TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enabled_next (enabled, next_backup)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 备份日志表
CREATE TABLE IF NOT EXISTS backup_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_id INT NOT NULL,
    backup_file VARCHAR(255),
    file_size BIGINT,
    status ENUM('success', 'failed', 'in_progress') DEFAULT 'in_progress',
    error_message TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_config_started (config_id, started_at),
    FOREIGN KEY (config_id) REFERENCES backup_configs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 标签表
CREATE TABLE IF NOT EXISTS note_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(20) DEFAULT '#d4af37',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_tag (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 标签关联表
CREATE TABLE IF NOT EXISTS note_tag_mapping (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note_id INT NOT NULL,
    tag_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES note_tags(id) ON DELETE CASCADE,
    UNIQUE KEY unique_note_tag (note_id, tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 团队空间表
CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    owner_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 团队成员表
CREATE TABLE IF NOT EXISTS team_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner', 'admin', 'member') DEFAULT 'member',
    invited_by INT,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_team_user (team_id, user_id),
    INDEX idx_team (team_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 团队文件夹表
CREATE TABLE IF NOT EXISTS team_folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    parent_id INT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_team (team_id),
    INDEX idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 团队笔记权限表
CREATE TABLE IF NOT EXISTS team_note_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    note_id INT NOT NULL,
    team_id INT NOT NULL,
    permission ENUM('read', 'write', 'admin') DEFAULT 'read',
    granted_by INT NOT NULL,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_note_team (note_id, team_id),
    INDEX idx_note (note_id),
    INDEX idx_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 团队邀请表
CREATE TABLE IF NOT EXISTS team_invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    email VARCHAR(100),
    invited_by INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    role ENUM('admin', 'member') DEFAULT 'member',
    status ENUM('pending', 'accepted', 'expired') DEFAULT 'pending',
    accepted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 默认数据
-- ============================================

-- 系统设置
INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
('registration_mode', 'invite', 'string', '注册模式: open, invite, closed'),
('site_name', '智能笔记系统', 'string', '网站名称'),
('max_upload_size', '10485760', 'integer', '最大上传文件大小(字节)'),
('allowed_file_types', 'pdf,doc,docx,txt,zip,rar,jpg,png,gif', 'string', '允许的文件类型'),
('chatgpt_api_key', '', 'string', 'ChatGPT API密钥'),
('chatgpt_api_url', 'https://api.openai.com/v1/chat/completions', 'string', 'ChatGPT API地址'),
('doubao_api_key', '', 'string', '豆包API密钥'),
('doubao_api_url', '', 'string', '豆包API地址'),
('claude_api_key', '', 'string', 'Claude API密钥'),
('claude_api_url', 'https://api.anthropic.com/v1/messages', 'string', 'Claude API地址'),
('custom_openai_name', '', 'string', '自定义AI提供商名称'),
('custom_openai_api_key', '', 'string', '自定义AI接口密钥'),
('custom_openai_api_url', '', 'string', '自定义AI接口地址'),
('custom_openai_model', '', 'string', '自定义AI模型名称'),
('version', '2.0', 'string', '系统版本'),
('enable_version_control', 'true', 'boolean', '启用笔记版本控制'),
('max_version_count', '50', 'integer', '最大版本数量'),
('enable_share', 'true', 'boolean', '启用笔记分享功能'),
('enable_template', 'true', 'boolean', '启用笔记模板'),
('enable_recycle_bin', 'true', 'boolean', '启用回收站'),
('recycle_bin_retention_days', '30', 'integer', '回收站保留天数'),
('enable_two_factor', 'true', 'boolean', '启用两步验证'),
('enable_api_keys', 'true', 'boolean', '启用API密钥管理'),
('max_login_attempts', '5', 'integer', '最大登录尝试次数'),
('login_lockout_duration', '900', 'integer', '登录锁定时长（秒）'),
('default_template', 'default', 'string', '系统默认UI模板'),
('captcha_enabled', 'true', 'boolean', '是否启用验证码')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

-- 默认笔记模板
INSERT INTO note_templates (user_id, name, description, category, content, is_public) VALUES
(NULL, '会议记录模板', '用于记录会议内容和决议', '工作', '# 会议记录\n\n**会议主题**: \n**会议时间**: \n**参会人员**: \n\n## 会议议程\n\n1. \n2. \n3. \n\n## 会议内容\n\n### 议题一\n\n**讨论要点**:\n- \n\n**决议**:\n- \n\n### 议题二\n\n**讨论要点**:\n- \n\n**决议**:\n- \n\n## 待办事项\n\n- [ ]  \n- [ ]  \n\n## 下次会议\n\n**时间**: \n**议题**: ', TRUE),
(NULL, '日记模板', '用于记录日常生活', '个人', '# {{date}} 日记\n\n## 今日心情\n\n（描述今天的心情）\n\n## 今日事件\n\n### 上午\n\n- \n\n### 下午\n\n- \n\n### 晚上\n\n- \n\n## 感悟\n\n（记录今天的感想和思考）\n\n## 明日计划\n\n- [ ] \n- [ ] ', TRUE),
(NULL, '项目计划模板', '用于项目规划', '工作', '# 项目计划\n\n## 项目概述\n\n**项目名称**: \n**负责人**: \n**开始时间**: \n**结束时间**: \n\n## 项目目标\n\n1. \n2. \n3. \n\n## 项目范围\n\n### 包含内容\n\n- \n\n### 不包含内容\n\n- \n\n## 项目里程碑\n\n| 里程碑 | 时间 | 交付物 | 负责人 |\n|--------|------|--------|--------|\n| 需求确认 | | | |\n| 设计完成 | | | |\n| 开发完成 | | | |\n| 测试完成 | | | |\n| 上线 | | | |\n\n## 风险管理\n\n| 风险 | 等级 | 应对措施 |\n|------|------|----------|\n| | | |\n\n## 资源需求\n\n- **人力资源**: \n- **技术资源**: \n- **预算**: ', TRUE),
(NULL, '学习笔记模板', '用于记录学习内容', '学习', '# {{topic}} 学习笔记\n\n## 学习目标\n\n- \n- \n\n## 核心概念\n\n### 概念一\n\n**定义**: \n\n**要点**:\n- \n\n### 概念二\n\n**定义**: \n\n**要点**:\n- \n\n## 重点内容\n\n### 章节1\n\n**笔记**:\n\n**疑问**:\n\n### 章节2\n\n**笔记**:\n\n**疑问**:\n\n## 实践练习\n\n### 练习1\n\n**题目**: \n\n**解答**:\n\n```\n\n```\n\n### 练习2\n\n**题目**: \n\n**解答**:\n\n```\n\n```\n\n## 总结\n\n（总结学习内容）\n\n## 下一步计划\n\n- [ ] \n- [ ] ', TRUE),
(NULL, '周报模板', '用于撰写周报', '工作', '# 本周工作总结\n\n**时间范围**: {{start_date}} ~ {{end_date}}\n\n## 本周完成工作\n\n### 项目一\n\n- 完成内容：\n- 进度：\n- 备注：\n\n### 项目二\n\n- 完成内容：\n- 进度：\n- 备注：\n\n## 遇到的问题\n\n### 问题一\n\n**描述**: \n\n**解决方案**: \n\n### 问题二\n\n**描述**: \n\n**解决方案**: \n\n## 下周工作计划\n\n### 优先级高\n\n- [ ] \n- [ ] \n\n### 优先级中\n\n- [ ] \n- [ ] \n\n### 优先级低\n\n- [ ] \n\n## 需要的支持\n\n- \n\n## 其他说明\n\n', TRUE)
ON DUPLICATE KEY UPDATE note_templates.name=VALUES(note_templates.name);
