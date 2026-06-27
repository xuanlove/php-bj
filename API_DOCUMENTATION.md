# PHP笔记系统 V2.0 API文档

**版本**: V2.0  
**更新日期**: 2026年6月24日  
**基础URL**: `/api.php?action=`

---

## 📋 目录

1. [认证API](#认证api)
2. [笔记API](#笔记api)
3. [文件夹API](#文件夹api)
4. [附件API](#附件api)
5. [AI功能API](#ai功能api)
6. [评论API](#评论api)
7. [通知API](#通知api)
8. [分享协作API](#分享协作api)
9. [备份API](#备份api)
10. [邀请码API](#邀请码api)
11. [版本控制API](#版本控制api)
12. [分享功能API](#分享功能api)
13. [回收站API](#回收站api)
14. [用户设置API](#用户设置api)
15. [登录日志API](#登录日志api)
16. [管理员API](#管理员api)
17. [标签管理API](#标签管理api)
18. [笔记模板API](#笔记模板api)
19. [团队空间API](#团队空间api)
20. [API密钥管理API](#api密钥管理api)
21. [两步验证API](#两步验证api)
22. [笔记导出API](#笔记导出api)
23. [用户资料API](#用户资料api)
24. [UI模板管理API](#ui模板管理api)
25. [其他端点](#其他端点)

---

## 认证API

### 用户注册

**接口**: `register`  
**方法**: POST  
**需要登录**: 否  
**需要CSRF**: 是

**请求参数**:
```json
{
  "username": "testuser",
  "email": "test@example.com",
  "password": "password123",
  "full_name": "测试用户",
  "invitation_code": "OPTIONAL_CODE",
  "captcha": "1234"
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "注册成功"
}
```

---

### 用户登录

**接口**: `login`  
**方法**: POST  
**需要登录**: 否  
**需要CSRF**: 是

**请求参数**:
```json
{
  "username": "testuser",
  "password": "password123",
  "captcha": "1234"
}
```

**响应示例**:
```json
{
  "success": true,
  "user": {
    "id": 1,
    "username": "testuser",
    "role": "user"
  },
  "message": "登录成功"
}
```

---

### 用户登出

**接口**: `logout`  
**方法**: POST  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "message": "退出成功"
}
```

---

### 获取当前用户

**接口**: `current_user`  
**方法**: GET  
**需要登录**: 否（未登录返回失败）

**响应示例**:
```json
{
  "success": true,
  "user": {
    "id": 1,
    "username": "testuser",
    "email": "test@example.com",
    "role": "user"
  }
}
```

---

## 笔记API

### 创建笔记

**接口**: `notes_create`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "title": "笔记标题",
  "content": "笔记内容",
  "content_type": "markdown",
  "folder_id": 1
}
```

---

### 更新笔记

**接口**: `notes_update`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "title": "新标题",
  "content": "新内容",
  "content_type": "markdown",
  "is_archived": 0,
  "is_favorite": 1
}
```

---

### 删除笔记

**接口**: `notes_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1
}
```

---

### 获取笔记详情

**接口**: `notes_get`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `note_id`: 笔记ID（必填）

---

### 获取笔记列表

**接口**: `notes_list`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `folder_id`: 文件夹ID（可选）
- `is_favorite`: 是否收藏（可选，1/0）
- `is_archived`: 是否归档（可选，1/0）
- `search`: 搜索关键词（可选）

**响应示例**:
```json
{
  "success": true,
  "notes": [
    {
      "id": 1,
      "title": "笔记标题",
      "content": "笔记内容...",
      "attachment_count": 2,
      "is_archived": false,
      "is_favorite": false,
      "updated_at": "2026-04-13 14:30:00"
    }
  ]
}
```

---

### 搜索笔记

**接口**: `notes_search`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `keyword`: 搜索关键词（必填）

**响应示例**:
```json
{
  "success": true,
  "notes": [...]
}
```

---

## 文件夹API

### 创建文件夹

**接口**: `folder_create`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "name": "工作笔记",
  "parent_id": null
}
```

---

### 获取文件夹列表

**接口**: `folder_list`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "folders": [
    {
      "id": 1,
      "name": "工作笔记",
      "parent_id": null,
      "note_count": 5
    }
  ]
}
```

---

### 更新文件夹

**接口**: `folder_update`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "folder_id": 1,
  "name": "新名称"
}
```

---

### 删除文件夹

**接口**: `folder_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "folder_id": 1
}
```

---

## 附件API

### 上传附件

**接口**: `attachment_upload`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**: FormData
- `file`: 文件（必填）
- `note_id`: 笔记ID（必填）

**响应示例**:
```json
{
  "success": true,
  "attachment_id": 1,
  "file_path": "/uploads/202604/test.pdf",
  "message": "上传成功"
}
```

---

### 获取附件列表

**接口**: `attachment_list`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `note_id`: 笔记ID（必填）

**响应示例**:
```json
{
  "success": true,
  "attachments": [
    {
      "id": 1,
      "file_name": "test.pdf",
      "file_path": "/uploads/202604/test.pdf",
      "file_size": 10240,
      "file_type": "application/pdf",
      "created_at": "2026-04-13 14:30:00"
    }
  ]
}
```

---

### 删除附件

**接口**: `attachment_delete` / `delete_attachment`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "attachment_id": 1
}
```

---

## AI功能API

### AI校准

**接口**: `ai_proofread`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "provider": "chatgpt"
}
```

---

### AI续写

**接口**: `ai_continue`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "provider": "chatgpt"
}
```

---

### AI摘要

**接口**: `ai_summarize`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "provider": "chatgpt"
}
```

---

### AI重写

**接口**: `ai_rewrite`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "provider": "chatgpt",
  "style": "professional"
}
```

---

### AI历史记录

**接口**: `ai_history`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `note_id`: 笔记ID（可选）
- `limit`: 数量限制（默认20）

---

## 评论API

### 添加评论

**接口**: `comment_add`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "content": "评论内容",
  "parent_id": null
}
```

---

### 获取评论列表

**接口**: `comment_list`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `note_id`: 笔记ID（必填）

---

### 更新评论

**接口**: `comment_update`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "comment_id": 1,
  "content": "更新后的评论内容"
}
```

---

### 删除评论

**接口**: `comment_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "comment_id": 1
}
```

---

### 评论数量

**接口**: `comment_count`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `note_id`: 笔记ID（必填）

---

## 通知API

### 获取通知列表

**接口**: `notifications_list`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `limit`: 每页数量（默认20）
- `offset`: 偏移量（默认0）

---

### 未读通知数量

**接口**: `notifications_unread_count`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "count": 5
}
```

---

### 标记通知已读

**接口**: `notifications_mark_read`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "notification_id": 1
}
```

---

### 标记全部已读

**接口**: `notifications_mark_all_read`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

---

### 删除通知

**接口**: `notifications_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "notification_id": 1
}
```

---

### 清空通知

**接口**: `notifications_clear`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

---

## 分享协作API

### 分享笔记给用户

**接口**: `note_share_to_user`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "username": "targetuser",
  "permission": "read"
}
```

---

### 获取收到的分享

**接口**: `note_share_list_received`  
**方法**: GET  
**需要登录**: 是

---

### 获取发出的分享

**接口**: `note_share_list_sent`  
**方法**: GET  
**需要登录**: 是

---

### 获取协作者列表

**接口**: `note_share_collaborators`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `note_id`: 笔记ID（必填）

---

### 更新协作权限

**接口**: `note_share_update_permission`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "share_id": 1,
  "permission": "write"
}
```

---

### 撤销分享

**接口**: `note_share_revoke`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "share_id": 1
}
```

---

## 备份API

### 获取备份配置列表

**接口**: `backup_configs`  
**方法**: GET  
**需要登录**: 是（管理员）

**响应示例**:
```json
{
  "success": true,
  "configs": [...]
}
```

---

### 创建备份配置

**接口**: `backup_create`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "backup_name": "每日备份",
  "storage_type": "ftp",
  "ftp_host": "ftp.example.com",
  "ftp_port": 21,
  "ftp_user": "user",
  "ftp_pass": "pass",
  "backup_frequency": "daily"
}
```

---

### 更新备份配置

**接口**: `backup_update`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "id": 1,
  "backup_name": "新名称",
  "ftp_host": "ftp.example.com"
}
```

---

### 删除备份配置

**接口**: `backup_delete`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "id": 1
}
```

---

### 执行备份

**接口**: `backup_perform`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
- `backup_id`: 备份配置ID（必填，通过GET参数传递）

---

### 获取备份日志

**接口**: `backup_logs`  
**方法**: GET  
**需要登录**: 是（管理员）

**请求参数**:
- `config_id`: 备份配置ID（可选，不传则返回所有日志）

---

### 测试存储连接

**接口**: `backup_test_connection`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "storage_type": "ftp",
  "ftp_host": "ftp.example.com",
  "ftp_port": 21,
  "ftp_user": "user",
  "ftp_pass": "pass"
}
```

---

### 获取支持的存储类型

**接口**: `backup_storage_types`  
**方法**: GET  
**需要登录**: 是（管理员）

**响应示例**:
```json
{
  "success": true,
  "types": ["local", "ftp", "s3", "webdav"]
}
```

---

## 邀请码API

### 获取邀请码列表

**接口**: `invite_list`  
**方法**: GET  
**需要登录**: 是（管理员）

**请求参数**:
- `status`: 状态筛选（可选）
- `page`: 页码（默认1）

---

### 生成邀请码

**接口**: `invite_generate` / `generate_invite`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "count": 5,
  "expires_days": 7
}
```

---

### 删除邀请码

**接口**: `invite_delete`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "id": 1
}
```

---

### 批量删除邀请码

**接口**: `invite_batch_delete`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "ids": [1, 2, 3]
}
```

**响应示例**:
```json
{
  "success": true,
  "count": 3,
  "message": "已删除 3 个邀请码"
}
```

---

## 版本控制API

### 保存版本

**接口**: `version_save`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "content": {
    "title": "笔记标题",
    "content": "笔记内容",
    "content_type": "markdown"
  },
  "change_description": "修改了标题"
}
```

**响应示例**:
```json
{
  "success": true,
  "version_id": 123,
  "message": "版本保存成功"
}
```

---

### 获取版本列表

**接口**: `version_list`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `note_id`: 笔记ID（必填）
- `limit`: 每页数量（默认20）
- `offset`: 偏移量（默认0）

**响应示例**:
```json
{
  "success": true,
  "versions": [
    {
      "id": 123,
      "note_id": 1,
      "version_number": 5,
      "change_description": "修改了标题",
      "created_at": "2026-04-13 14:30:00"
    }
  ],
  "total": 5,
  "message": "获取版本列表成功"
}
```

---

### 获取版本详情

**接口**: `version_get`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `version_id`: 版本ID（必填）

**响应示例**:
```json
{
  "success": true,
  "version": {
    "id": 123,
    "note_id": 1,
    "version_number": 5,
    "content": "{\"title\":\"笔记标题\",\"content\":\"笔记内容\"}",
    "change_description": "修改了标题",
    "created_at": "2026-04-13 14:30:00"
  },
  "message": "获取版本成功"
}
```

---

### 版本回滚

**接口**: `version_rollback`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "version_id": 123
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "版本回滚成功"
}
```

---

### 版本对比

**接口**: `version_compare`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `version1_id`: 版本1 ID（必填）
- `version2_id`: 版本2 ID（必填）

**响应示例**:
```json
{
  "success": true,
  "comparison": {
    "version1": {...},
    "version2": {...},
    "differences": "版本差异描述"
  },
  "message": "版本对比成功"
}
```

---

### 删除版本

**接口**: `version_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "version_id": 123
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "版本已删除"
}
```

---

## 分享功能API

### 创建分享

**接口**: `share_create`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "password": "123456",  // 可选，系统会自动进行password_hash加密存储
  "expires_in": 86400,   // 可选，秒数（24小时）
  "allow_download": true // 可选，默认true
}
```

**响应示例**:
```json
{
  "success": true,
  "share_token": "abc123def456",
  "share_url": "http://yoursite.com/share.php?token=abc123def456",
  "message": "分享创建成功"
}
```

---

### 获取分享列表

**接口**: `share_list`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `note_id`: 笔记ID（可选，不传则返回所有分享）
- `limit`: 每页数量（默认20）
- `offset`: 偏移量（默认0）

**响应示例**:
```json
{
  "success": true,
  "shares": [
    {
      "id": 1,
      "note_id": 1,
      "note_title": "笔记标题",
      "share_token": "abc123",
      "has_password": true,
      "expires_at": "2026-04-14 14:30:00",
      "view_count": 10,
      "created_at": "2026-04-13 14:30:00"
    }
  ],
  "message": "获取分享列表成功"
}
```

> **注意**: 密码字段以 `password_hash` 形式加密存储于数据库中，API返回时以 `has_password` 布尔值标识是否设置了密码，不再返回明文或掩码密码。

---

### 更新分享

**接口**: `share_update`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "share_id": 1,
  "password": "newpassword",
  "expires_in": 172800,
  "allow_download": false
}
```

> **注意**: 传入的 `password` 字段会自动通过 `password_hash()` 加密后以 `password_hash` 字段存储。

**响应示例**:
```json
{
  "success": true,
  "message": "分享更新成功"
}
```

---

### 删除分享

**接口**: `share_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "share_id": 1
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "分享已删除"
}
```

---

### 获取分享信息（公开）

**接口**: `share_get`  
**方法**: GET  
**需要登录**: 否

**请求参数**:
- `token`: 分享token（必填）

---

### 获取分享内容（公开）

**接口**: `share_content`  
**方法**: POST / GET  
**需要登录**: 否

**请求参数**:
```json
{
  "token": "abc123def456",
  "password": "123456"
}
```

---

## 回收站API

### 移至回收站

**接口**: `note_soft_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "笔记已移至回收站"
}
```

---

### 恢复笔记

**接口**: `note_restore`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "笔记已恢复"
}
```

---

### 永久删除

**接口**: `note_permanent_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "笔记已永久删除"
}
```

---

### 回收站列表

**接口**: `recycle_bin_list`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `limit`: 每页数量（默认20）
- `offset`: 偏移量（默认0）

**响应示例**:
```json
{
  "success": true,
  "notes": [
    {
      "id": 1,
      "title": "已删除的笔记",
      "deleted_at": "2026-04-13 14:30:00"
    }
  ],
  "total": 5,
  "message": "获取回收站列表成功"
}
```

---

### 清空回收站

**接口**: `recycle_bin_empty`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**响应示例**:
```json
{
  "success": true,
  "deleted_count": 5,
  "message": "回收站已清空"
}
```

---

### 回收站统计

**接口**: `recycle_bin_stats`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "stats": {
    "total_notes": 5,
    "oldest_deleted": "2026-04-10 10:00:00",
    "total_size": "1.2 MB"
  },
  "message": "获取统计成功"
}
```

---

## 用户设置API

### 获取设置

**接口**: `settings_get`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "settings": {
    "theme": "dark",
    "editor_font_size": 14,
    "editor_font_family": "Source Code Pro",
    "editor_theme": "default",
    "auto_save": true,
    "auto_save_interval": 30,
    "email_notifications": true,
    "language": "zh-CN",
    "timezone": "Asia/Shanghai"
  },
  "message": "获取设置成功"
}
```

---

### 更新设置

**接口**: `settings_update`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "theme": "light",
  "editor_font_size": 16,
  "auto_save": false
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "设置更新成功"
}
```

---

### 重置设置

**接口**: `settings_reset`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**响应示例**:
```json
{
  "success": true,
  "message": "设置已重置为默认值"
}
```

---

## 登录日志API

### 登录历史

**接口**: `login_history`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `limit`: 每页数量（默认20）
- `offset`: 偏移量（默认0）

**响应示例**:
```json
{
  "success": true,
  "logs": [
    {
      "id": 1,
      "login_status": "success",
      "ip_address": "192.168.1.1",
      "location": "北京, 中国",
      "user_agent": "Mozilla/5.0...",
      "created_at": "2026-04-13 14:30:00"
    }
  ],
  "total": 100,
  "message": "获取登录历史成功"
}
```

---

### 登录统计

**接口**: `login_stats`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "total_logins": 100,
  "last_login": {
    "created_at": "2026-04-13 14:30:00",
    "ip_address": "192.168.1.1",
    "location": "北京, 中国"
  },
  "failed_attempts_24h": 0,
  "message": "获取统计成功"
}
```

---

### 检查锁定状态

**接口**: `check_lockout`  
**方法**: POST  
**需要登录**: 否（未登录时仅查询当前IP锁定状态）

**请求参数**:
```json
{
  "username": "testuser"
}
```

**响应示例**:
```json
{
  "locked": false
}
```

或

```json
{
  "locked": true,
  "type": "username",
  "remaining_time": 600,
  "message": "账户已锁定，请 600 秒后重试"
}
```

---

## 🔐 CSRF保护

所有POST/PUT/DELETE请求都需要CSRF Token。

**获取Token**:
```javascript
// 从Session或Cookie获取
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

// 在请求Header中发送
fetch('/api.php?action=version_save', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  body: JSON.stringify(data)
});
```

**或在POST数据中发送**:
```javascript
const data = {
  ...yourData,
  csrf_token: csrfToken
};
```

---

## 📝 错误响应格式

所有错误响应遵循统一格式：

```json
{
  "success": false,
  "message": "错误描述",
  "error_code": "ERROR_CODE"  // 可选
}
```

**常见错误码**:
- `UNAUTHORIZED` - 未登录
- `FORBIDDEN` - 无权限
- `NOT_FOUND` - 资源不存在
- `VALIDATION_ERROR` - 输入验证失败
- `CSRF_ERROR` - CSRF验证失败
- `RATE_LIMIT_EXCEEDED` - 请求频率超限

---

## 管理员API

以下接口需要管理员权限（`role === 'admin'`）。

### 系统统计

**接口**: `admin_stats`  
**方法**: GET  
**需要登录**: 是（管理员）

**响应示例**:
```json
{
  "success": true,
  "data": {
    "total_users": 100,
    "total_notes": 500,
    "total_attachments": 200,
    "ai_interactions": 50
  }
}
```

---

### 获取所有笔记

**接口**: `admin_all_notes`  
**方法**: GET  
**需要登录**: 是（管理员）

**请求参数**:
- `limit`: 每页数量（默认20）
- `offset`: 偏移量（默认0）

---

### 更新任意笔记

**接口**: `admin_update_note`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "title": "新标题",
  "content": "新内容"
}
```

---

### 删除任意笔记

**接口**: `admin_delete_note`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1
}
```

---

### 获取所有用户

**接口**: `admin_all_users`  
**方法**: GET  
**需要登录**: 是（管理员）

---

### 切换用户状态

**接口**: `admin_toggle_user_status`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "user_id": 2,
  "status": "suspended"
}
```

> **注意**: `status` 可选值为 `active`、`suspended`、`disabled`。

---

### 删除用户

**接口**: `admin_delete_user`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "user_id": 2
}
```

> **注意**: 不能删除自己的账号，不能删除管理员账号。

---

### 获取系统设置

**接口**: `admin_get_settings`  
**方法**: GET  
**需要登录**: 是（管理员）

**响应示例**:
```json
{
  "success": true,
  "settings": {
    "site_name": "笔记系统",
    "registration_mode": "open",
    "chatgpt_api_key": "••••••••"
  }
}
```

> **注意**: API密钥等敏感字段返回时以掩码显示。

---

### 保存系统设置

**接口**: `admin_save_settings`  
**方法**: POST  
**需要登录**: 是（管理员）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "site_name": "新站点名称",
  "registration_mode": "invite",
  "chatgpt_api_key": "sk-xxx",
  "chatgpt_api_url": "https://api.openai.com/v1",
  "doubao_api_key": "xxx",
  "doubao_api_url": "https://api.doubao.com/v1",
  "claude_api_key": "xxx",
  "claude_api_url": "https://api.anthropic.com/v1",
  "custom_openai_name": "自定义AI",
  "custom_openai_api_key": "xxx",
  "custom_openai_api_url": "https://custom.api.com/v1",
  "custom_openai_model": "gpt-custom",
  "default_ai_provider": "chatgpt",
  "max_upload_size": "10485760",
  "default_note_limit": "1000",
  "default_template": "default"
}
```

> **注意**: API密钥等敏感字段会自动加密存储。

---

## 标签管理API

### 获取标签列表

**接口**: `tags_list`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "tags": [
    {
      "id": 1,
      "name": "工作",
      "color": "#4CAF50",
      "note_count": 5,
      "created_at": "2026-04-13 14:30:00"
    }
  ]
}
```

---

### 创建标签

**接口**: `tags_create`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "name": "工作",
  "color": "#4CAF50"
}
```

---

### 更新标签

**接口**: `tags_update`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "tag_id": 1,
  "name": "新名称",
  "color": "#2196F3"
}
```

---

### 重命名标签

**接口**: `tags_rename`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "old_tag": "旧标签名",
  "new_tag": "新标签名"
}
```

---

### 删除标签

**接口**: `tags_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "tag": "标签名"
}
```

---

### 为笔记添加标签

**接口**: `tags_add_to_note`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "tag_id": 1
}
```

---

### 从笔记移除标签

**接口**: `tags_remove_from_note`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "tag_id": 1
}
```

---

### 获取笔记的标签

**接口**: `tags_get_note_tags`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `note_id`: 笔记ID（必填）

---

### 按标签获取笔记

**接口**: `notes_by_tag`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `tag_id`: 标签ID（必填）
- `limit`: 每页数量（默认50）
- `offset`: 偏移量（默认0）

---

## 笔记模板API

### 获取模板列表

**接口**: `templates_list`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `category`: 分类筛选（可选）

**响应示例**:
```json
{
  "success": true,
  "templates": [
    {
      "id": 1,
      "name": "会议记录",
      "category": "工作",
      "created_at": "2026-04-13 14:30:00"
    }
  ]
}
```

---

### 获取模板详情

**接口**: `templates_get`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `template_id`: 模板ID（必填）

---

### 创建模板

**接口**: `templates_create`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "name": "会议记录",
  "category": "工作",
  "content": "# 会议记录\n\n## 主题\n\n## 参会人员\n\n## 决议"
}
```

---

### 更新模板

**接口**: `templates_update`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "template_id": 1,
  "name": "新模板名称",
  "category": "新分类",
  "content": "新模板内容"
}
```

---

### 删除模板

**接口**: `templates_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "template_id": 1
}
```

---

### 使用模板创建笔记

**接口**: `templates_use`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `template_id`: 模板ID（必填）

**响应示例**:
```json
{
  "success": true,
  "note_id": 10,
  "message": "笔记创建成功"
}
```

---

### 复制模板

**接口**: `templates_duplicate`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "template_id": 1
}
```

---

### 获取模板分类

**接口**: `templates_categories`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "categories": ["工作", "学习", "生活"]
}
```

---

## 团队空间API

### 获取我的团队列表

**接口**: `teams_list`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "teams": [
    {
      "id": 1,
      "name": "开发团队",
      "description": "项目开发",
      "role": "owner",
      "member_count": 5,
      "created_at": "2026-04-13 14:30:00"
    }
  ]
}
```

---

### 创建团队

**接口**: `teams_create`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "name": "开发团队",
  "description": "项目开发"
}
```

---

### 获取团队详情

**接口**: `teams_get`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `team_id`: 团队ID（必填）

---

### 更新团队

**接口**: `teams_update`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "team_id": 1,
  "name": "新团队名称",
  "description": "新描述"
}
```

---

### 删除团队

**接口**: `teams_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "team_id": 1
}
```

---

### 邀请成员

**接口**: `teams_invite`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "team_id": 1,
  "email": "user@example.com",
  "role": "member"
}
```

---

### 添加成员

**接口**: `teams_add_member`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "team_id": 1,
  "user_id": 2,
  "role": "member"
}
```

---

### 移除成员

**接口**: `teams_remove_member`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "team_id": 1,
  "user_id": 2
}
```

---

### 接受邀请

**接口**: `teams_accept_invite`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "token": "invite_token_here"
}
```

---

### 获取团队笔记

**接口**: `teams_get_notes`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `team_id`: 团队ID（必填）
- `folder_id`: 文件夹ID（可选）

---

### 共享笔记到团队

**接口**: `teams_share_note`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "note_id": 1,
  "team_id": 1,
  "permission": "read"
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "已共享到团队"
}
```

---

### 创建团队文件夹

**接口**: `teams_create_folder`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "team_id": 1,
  "name": "项目文档",
  "parent_id": null
}
```

---

### 获取团队文件夹

**接口**: `teams_get_folders`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `team_id`: 团队ID（必填）

---

### 更新成员角色

**接口**: `teams_update_member_role`  
**方法**: POST  
**需要登录**: 是（仅团队所有者）  
**需要CSRF**: 是

**请求参数**:
```json
{
  "team_id": 1,
  "user_id": 2,
  "role": "admin"
}
```

> **注意**: `role` 可选值为 `admin`、`member`。不能修改 owner 角色。

## API密钥管理API

### 获取API密钥列表

**接口**: `api_keys_list`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "keys": [
    {
      "id": 1,
      "name": "我的API密钥",
      "key_prefix": "nk_abc...",
      "permissions": ["read", "write"],
      "status": "active",
      "last_used_at": "2026-04-13 14:30:00",
      "created_at": "2026-04-10 10:00:00"
    }
  ]
}
```

---

### 创建API密钥

**接口**: `api_keys_create`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "name": "我的API密钥",
  "permissions": ["read", "write"]
}
```

**响应示例**:
```json
{
  "success": true,
  "key": "nk_abc123def456...",
  "message": "API密钥创建成功"
}
```

> **注意**: 完整的API密钥仅在创建时返回一次，请妥善保存。

---

### 删除API密钥

**接口**: `api_keys_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "key_id": 1
}
```

---

### 切换API密钥状态

**接口**: `api_keys_toggle`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "key_id": 1
}
```

**响应示例**:
```json
{
  "success": true,
  "status": "disabled",
  "message": "API密钥已禁用"
}
```

---

## 两步验证API

### 获取两步验证状态

**接口**: `2fa_status`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "enabled": false,
  "message": "获取两步验证状态成功"
}
```

---

### 生成两步验证密钥

**接口**: `2fa_generate`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "secret": "JBSWY3DPEHPK3PXP",
  "qr_code_url": "otpauth://totp/...",
  "message": "密钥生成成功"
}
```

---

### 启用两步验证

**接口**: `2fa_enable`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "secret": "JBSWY3DPEHPK3PXP",
  "code": "123456"
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "两步验证已启用"
}
```

---

### 禁用两步验证

**接口**: `2fa_disable`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "code": "123456"
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "两步验证已禁用"
}
```

---

### 验证两步验证码

**接口**: `2fa_verify`  
**方法**: POST  
**需要登录**: 是

**请求参数**:
```json
{
  "code": "123456"
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "验证成功"
}
```

---

## 笔记导出API

### 导出单个笔记

**接口**: `notes_export`  
**方法**: GET  
**需要登录**: 是

**请求参数**:
- `note_id`: 笔记ID（必填）
- `format`: 导出格式（可选，默认`markdown`，支持`markdown`、`html`、`pdf`等）

**响应示例**:
```json
{
  "success": true,
  "note_id": 1,
  "format": "markdown",
  "content": "导出的笔记内容...",
  "message": "导出成功"
}
```

---

### 批量导出笔记

**接口**: `notes_batch_export`  
**方法**: POST  
**需要登录**: 是

**请求参数**:
```json
{
  "note_ids": [1, 2, 3],
  "format": "markdown"
}
```

**响应示例**:
```json
{
  "success": true,
  "format": "markdown",
  "notes": [
    {
      "note_id": 1,
      "title": "笔记1",
      "content": "内容1"
    },
    {
      "note_id": 2,
      "title": "笔记2",
      "content": "内容2"
    }
  ],
  "message": "批量导出成功"
}
```

---

## 用户资料API

### 更新用户资料

**接口**: `update_profile`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "full_name": "新姓名",
  "email": "newemail@example.com"
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "资料更新成功"
}
```

---

### 修改密码

**接口**: `change_password`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "old_password": "旧密码",
  "new_password": "新密码"
}
```

**响应示例**:
```json
{
  "success": true,
  "message": "密码修改成功"
}
```

---

## UI模板管理API

> **注意**: UI模板与笔记模板（`templates_*`）不同，UI模板用于控制界面外观样式。

### 获取UI模板列表

**接口**: `ui_templates_list`  
**方法**: GET  
**需要登录**: 否

**响应示例**:
```json
{
  "success": true,
  "templates": [
    {
      "id": "default",
      "name": "默认主题",
      "description": "系统默认主题"
    },
    {
      "id": "dark",
      "name": "暗色主题",
      "description": "深色背景主题"
    }
  ]
}
```

---

### 获取UI模板CSS

**接口**: `ui_template_css`  
**方法**: GET  
**需要登录**: 否

**请求参数**:
- `t`: 模板ID（可选，默认`default`）

**响应**: 返回 `text/css` 格式的CSS内容，带24小时缓存头。

---

### 获取当前UI模板

**接口**: `ui_template_current`  
**方法**: GET  
**需要登录**: 否（已登录返回用户设置的模板，未登录返回系统默认模板）

**响应示例**:
```json
{
  "success": true,
  "template": "dark"
}
```

---

## 其他端点

### 获取CSRF Token

**接口**: `get_csrf_token`  
**方法**: GET  
**需要登录**: 否

**响应示例**:
```json
{
  "success": true,
  "token": "a1b2c3d4e5f6..."
}
```

> **注意**: 所有POST/PUT/DELETE请求都需要携带CSRF Token，通过 `X-CSRF-Token` Header或 `csrf_token` POST字段传递。

---

### 更新文件夹

**接口**: `folder_update`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "folder_id": 1,
  "name": "新文件夹名称",
  "parent_id": null
}
```

---

### 删除文件夹

**接口**: `folder_delete`  
**方法**: POST  
**需要登录**: 是  
**需要CSRF**: 是

**请求参数**:
```json
{
  "folder_id": 1
}
```

> **注意**: 文件夹中有笔记时无法删除，需先移出所有笔记。

---

### 生成邀请码（单个）

**接口**: `generate_invite`  
**方法**: POST  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "code": "a1b2c3d4e5f6..."
}
```

---

### 用户统计

**接口**: `user_stats`  
**方法**: GET  
**需要登录**: 是

**响应示例**:
```json
{
  "success": true,
  "stats": {
    "total_notes": 50,
    "archived_notes": 5,
    "favorite_notes": 10,
    "notes_with_attachments": 8,
    "total_chars": 125000,
    "folder_count": 6,
    "monthly_notes": 12,
    "monthly_chars": 30000,
    "today_notes": 2,
    "today_chars": 5000
  }
}
```

---

### 获取AI配置

**接口**: `ai_config`  
**方法**: GET  
**需要登录**: 否

**响应示例**:
```json
{
  "success": true,
  "default_provider": "chatgpt"
}
```

> **注意**: 此接口为公开接口，仅返回默认AI提供商名称，不含敏感信息。

---

### 检查安装状态

**接口**: `check_install`  
**方法**: GET  
**需要登录**: 否

**响应示例**:
```json
{
  "success": true,
  "installed": true,
  "message": "系统已安装"
}
```

或

```json
{
  "success": true,
  "installed": false,
  "message": "系统未安装，请先完成安装"
}
```

---

### 获取注册模式

**接口**: `get_registration_mode`  
**方法**: GET  
**需要登录**: 否

**响应示例**:
```json
{
  "success": true,
  "mode": "invite"
}
```

> **注意**: `mode` 可选值为 `open`（开放注册）、`invite`（邀请码注册）。

---

## 📅 更新日志

### V2.0 (2026-04-13)
- ✅ 新增版本控制API（6个接口）
- ✅ 新增分享功能API（4个接口）
- ✅ 新增回收站API（5个接口）
- ✅ 新增用户设置API（3个接口）
- ✅ 新增登录日志API（3个接口）
- ✅ 所有接口添加CSRF保护
- ✅ 所有接口添加权限验证

### V2.1 (2026-06-24)
- ✅ 新增标签管理API（9个接口）
- ✅ 新增笔记模板API（8个接口）
- ✅ 新增团队空间API（10个接口）
- ✅ 新增API密钥管理API（4个接口）
- ✅ 新增两步验证API（5个接口）
- ✅ 新增笔记导出API（2个接口）
- ✅ 新增用户资料API（2个接口）
- ✅ 新增UI模板管理API（3个接口）
- ✅ 新增其他端点（user_stats、ai_config、check_install、get_registration_mode）
- ✅ 备份API重构（统一存储类型，新增backup_create/update/delete/test_connection/storage_types）
- ✅ 分享功能密码字段改为password_hash加密存储
- ✅ 新增评论更新接口（comment_update）
- ✅ 新增邀请码批量删除接口（invite_batch_delete）
- ✅ 新增公开分享访问接口（share_get、share_content）

---

**文档维护**: 开发团队  
**最后更新**: 2026年6月24日
