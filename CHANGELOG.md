# 更新日志

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.1.0] - 2026-06-29

### Added

#### Markdown 代码高亮支持
- 新增前端 Markdown 渲染工具模块 `vue-project/src/utils/markdown.js`，集成 marked + highlight.js
- 笔记编辑器新增三态视图切换：编辑 / 分屏预览 / 纯预览，预览区实时渲染 Markdown 并高亮代码
- 代码块支持语言标识（javascript、python、php 等），自动语法高亮
- 行内代码同样支持高亮渲染
- 分享页（SharedNote.vue）从纯文本改为 Markdown 渲染，代码高亮生效
- 后端分享页（Share.php）引入 highlight.js CDN 并初始化高亮
- HTML 导出新增 `renderMarkdownForExport()` 方法，真正解析 Markdown（标题/代码块/列表/引用/粗斜体/链接等），并内嵌 highlight.js CDN 实现导出文件代码高亮

#### 安装检测机制
- 新增三层安装状态防御：前端启动检查（`main.js` bootstrap）、后端 API 早期拦截（`api.php`）、数据库连接放行（`conn.php`）
- 新增 `check_install` 接口，未安装时返回 503 + 跳转指引
- 未安装时仅放行白名单接口（check_install / health / get_csrf_token），避免 DB 连接失败污染响应
- 安装成功后生成 `install.lock`（权限 0444）防止重复安装与恶意重装

#### 安装向导增强
- `createAdmin` 增加用户名 / 邮箱 / 密码格式校验
- `.env` 密码值用引号包裹并转义，`chmod('.env', 0600)` 限制权限
- `conn.php` 增强 `.env` 解析：支持 `export` 前缀、引号包裹值、行内注释

### Changed

#### 安全性增强
- `Auth.php` 登录成功后调用 `session_regenerate_id(true)` 防 session 固定
- `Auth.php` `getCurrentUser()` 增加 `status = 'active'` 检查，被禁用用户强制登出
- `VersionControl.php` `rollbackToVersion` 增加笔记所有权验证
- `NoteShares.php` `updatePermission` 权限白名单 ['read','edit']
- `TeamSpaces.php` `addMember` 角色白名单、`shareNoteToTeam` 权限白名单 + 成员校验
- `Share.php` 密码验证 session 1 小时过期，修复运算符优先级
- `AIService.php` `callChatGPT`/`callClaude` 校验 api_url，移除冗余 response 字段
- `CaptchaClass.php` 验证失败后清除验证码，防止重放

#### 前端健壮性改进
- `api/index.js` 修复响应拦截器吞错误、CSRF token 重置、401 不在公开页跳转
- `router/index.js` SharedNote 路由加 `meta:{public:true}`，beforeEach 仅在 requiresAuth 时 fetchCurrentUser
- `NoteEditor.vue` watch 路由参数、onUnmounted 清定时器、click-outside 关下拉
- `Settings.vue` 2FA 状态错误处理、`.number` 修饰符
- `stores/notifications.js` markAllAsRead / clearAll 校验 success
- `RecycleBin.vue` 操作校验 success + 错误提示
- `admin/Users.vue` toggleStatus 改为 active↔suspended
- `Profile.vue` 2FA 按钮状态判断 + onMounted 兜底
- `Layout.vue` 搜索跳 Notes 路由、createNote 失败提示、toggleNotifications
- `Notes.vue` 搜索优先级、createNote 失败提示
- `Login.vue` 注册成功清空表单
- `SharedNote.vue` watch token
- `main.scss` 补充 `--text-muted`、`--tertiary-bg` CSS 变量

#### 其他改进
- `RateLimiter.php` 窗口过期重置、retry_after max(0,...)
- `adapters/S3StorageAdapter.php` 新增 `private bool $sslVerify`
- `adapters/AliyunOSSAdapter.php` + `TencentCOSAdapter.php` fopen 用 finally 关闭
- `LoginLog.php` json_decode 空值检查
- `Comments.php` 去掉 sanitizeInput 改用 trim(strip_tags()) 避免双重转义
- `ShareClass.php` expires_in 整数验证
- `TwoFactorAuth.php` otpauth URL 邮箱 rawurlencode
- 新增 `@fortawesome/fontawesome-free` 依赖

### Fixed

- 修复 `Notes.php` `createFolder` 传 `parent_id=0` 因外键约束失败的问题（0/空/null 统一转 null）
- 修复 `Comments.php` `addComment` 传 `parent_id=0` 报"父评论不存在"的问题（同上归一化处理）
- 修复 `api.php` `backup_perform` 仅从 `$_GET` 读取 backup_id 导致前端 POST JSON body 请求始终返回"备份配置不存在"的问题（兼容 POST body 与 GET 参数）
- 修复 `Notes.php` `renderMarkdownForExport` 代码块占位符使用 `\x00`（null 字节）被段落处理的 `trim()` 去除，导致 `str_replace` 无法还原、代码块在 HTML 导出中丢失的问题（改用 `\x02`/`\x03` 控制字符）
- 修复 `ErrorHandler.php` 直接引用 `E_STRICT` 常量在 PHP 8.4+ 触发 deprecated 警告污染 JSON 响应的问题（改用 `defined` 检查）
- 修复 `conn.php` `ENCRYPTION_KEY` 检查在未安装时拦截 `check_install` 接口的问题（未安装时跳过检查）
- 修复 `api.php` `sendResponse` 函数重复定义问题
- 修复 `install.php` `check_install` 接口被 DB 连接失败绕过的问题（检测提前到所有 require 之前）

---

## [2.0.0] - 2026-06-24

### Added

#### 标签管理
- 新增标签管理 API（9个接口）
- 支持创建、更新、删除、重命名标签
- 支持按标签筛选笔记
- 支持为笔记添加/移除标签

#### 笔记模板
- 新增笔记模板 API（8个接口）
- 预置5套模板（会议记录、日记、项目计划、学习笔记、周报）
- 支持创建自定义模板
- 支持使用模板创建笔记
- 支持模板分类和搜索

#### 团队空间
- 新增团队空间 API（10个接口）
- 支持创建/管理团队
- 支持邀请成员（邮箱邀请）
- 支持团队笔记协作
- 支持团队文件夹管理

#### API 密钥管理
- 新增 API 密钥管理 API（4个接口）
- 支持创建、删除、启用/禁用 API 密钥
- 支持权限控制
- 完整密钥仅显示一次，需妥善保存

#### 两步验证
- 新增两步验证 API（5个接口）
- RFC 6238 标准 TOTP 算法
- 支持 Google Authenticator 等主流 APP
- 生成 8 个备用码，验证后自动失效
- 支持启用/禁用切换

#### 笔记导出
- 新增笔记导出 API（2个接口）
- 支持多种格式：Markdown、HTML、JSON、TXT
- 支持批量导出

#### 用户资料
- 新增用户资料 API（2个接口）
- 支持更新个人资料（姓名、邮箱）
- 支持修改密码（需验证原密码）

#### UI 模板管理
- 新增 UI 模板管理 API（3个接口）
- 支持获取模板列表、当前模板
- 预置 ocean 和 sunset 两套新主题

#### 其他功能
- 新增 Health Check 端点 `?action=health`
- 分享功能密码改为 password_hash 加密存储
- 新增评论更新接口 `comment_update`
- 新增邀请码批量删除接口 `invite_batch_delete`
- 新增公开分享访问接口 `share_get`、`share_content`
- 备份 API 重构，统一存储类型

### Changed

#### 安全性增强
- 所有 POST/PUT/DELETE 请求强制 CSRF 保护
- 加密密钥未配置时阻止系统运行
- API 密钥在数据库中加密存储
- 敏感配置返回时自动掩码
- 登录日志增强，支持锁定检测

#### 性能优化
- 速率限制中间件统一管理
- Session 安全增强（IP/User-Agent 变化检测）
- 数据库连接优化（延迟初始化）

#### 代码改进
- 添加日志轮转功能（按文件大小）
- AI 服务解密逻辑优化（兼容新旧数据）
- 修复 API 路由重复 case 问题

### Fixed

- 修复加密密钥未强制配置导致的安全风险
- 修复 API 路由重复定义问题
- 修复 AI 服务解密逻辑问题
- 修复 .gitignore 配置（排除构建产物）

---

## [1.3.0] - 2026-04-13

### Added

- 版本控制 API（6个接口）：保存、列表、获取、回滚、对比、删除
- 分享功能 API（4个接口）：创建、更新、删除、列表
- 回收站 API（5个接口）：软删除、恢复、永久删除、列表、统计
- 用户设置 API（3个接口）：获取、更新、重置
- 登录日志 API（3个接口）：历史、统计、锁定检测
- AI 功能 API（5个接口）：校准、续写、摘要、改写、历史
- 附件管理 API：上传、列表、删除

### Changed

- 全面支持 Markdown 和富文本两种格式
- UI 主题系统重构
- 数据库表结构优化

---

## [1.2.0] - 2025-XX-XX

### Added

- 基础笔记管理功能
- 文件夹管理
- 标签系统
- 收藏夹功能
- 归档功能
- 评论系统
- 通知系统
- 公开分享链接
- 用户间协作共享

---

## [1.1.0] - 2025-XX-XX

### Added

- 用户注册与登录
- 两步验证（基础版）
- API 密钥管理
- 备份与恢复

---

## [1.0.0] - 2025-XX-XX

### Added

- 项目初始化
- 基础用户认证
- 基础笔记 CRUD
- 安装向导