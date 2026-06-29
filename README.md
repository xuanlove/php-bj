# PHP笔记系统 V2.1

一个功能完善的 PHP + Vue3 全栈笔记管理系统，支持 Markdown 代码高亮、AI 辅助、团队协作、云备份等企业级功能。

## 功能特性

### 核心笔记功能
- 📝 **笔记管理** - 支持 Markdown / 富文本，创建、编辑、删除、归档
- 🎨 **Markdown 代码高亮** - 编辑器分屏预览（编辑 / 分屏 / 预览三态切换），代码块与行内代码语法高亮，分享页与 HTML 导出同样支持高亮渲染
- 📁 **文件夹管理** - 树形目录结构，支持多级嵌套
- 🏷️ **标签系统** - 多标签分类，灵活筛选
- ⭐ **收藏夹** - 收藏重要笔记
- 🗑️ **回收站** - 误删恢复，30 天自动清理
- 📎 **附件上传** - 笔记可附带任意类型文件

### AI 智能助手
- ✨ **AI 校准** - 自动修正语法、拼写、标点
- 📖 **AI 续写** - 根据内容智能续写
- 📝 **AI 摘要** - 自动生成内容摘要
- 🔄 **AI 重写** - 专业 / 休闲 / 学术 / 创意多种风格

### 协作与分享
- 🔗 **分享链接** - 支持密码保护、过期时间，分享页 Markdown 渲染 + 代码高亮
- 👥 **协作共享** - 精确到笔记级别的权限控制
- 💬 **评论系统** - 笔记评论与讨论，支持多级回复
- 👨‍👩‍👧‍👦 **团队空间** - 多人协作，权限管理

### 安全与管理
- 🔐 **两步验证** - TOTP 标准，支持备用码
- 🔑 **API 密钥** - 开放 API 接口
- 📊 **登录日志** - 完整登录记录与锁定检测
- 🛡️ **CSRF 保护** - 全站 Token 验证
- 🚫 **速率限制** - 防暴力破解
- 🔒 **安装锁保护** - `install.lock` 防止重复安装与恶意重装

### 版本控制
- 📜 **版本历史** - 完整版本记录
- ⏪ **版本回滚** - 一键恢复到任意版本
- 🔍 **版本对比** - 可视化差异对比

### 数据管理
- 💾 **云端备份** - 支持 FTP / SFTP / OSS / COS / S3，可配置定时备份与保留策略
- 📤 **笔记导出** - Markdown / HTML / JSON / TXT，HTML 导出内嵌代码高亮
- 📥 **批量导出** - 一次性导出多篇笔记
- 🎨 **UI 主题** - 多套主题模板

## 技术栈

### 后端
- PHP 8.0+（兼容 8.4+）
- MySQL 8.0+ 或 MariaDB 10.3+
- PDO 数据库抽象层
- Composer 依赖管理

### 前端
- Vue 3.4+
- Vue Router
- Pinia 状态管理
- Axios HTTP 客户端
- Vite 构建工具
- Marked + Highlight.js（Markdown 渲染与代码高亮）

## 环境要求

- PHP >= 8.0
- MySQL >= 8.0 或 MariaDB >= 10.3
- Composer
- Node.js >= 18（前端开发）

## 快速开始

### 1. 克隆项目

```bash
git clone <repository-url> php-notes
cd php-notes
```

### 2. 安装依赖

```bash
# PHP 依赖
composer install

# 前端依赖（可选，用于开发）
cd vue-project
npm install
```

### 3. 运行安装向导

直接访问站点根目录，系统会自动检测安装状态：未安装时自动跳转到 `install.html`，按向导完成环境检测 → 数据库配置 → 创建管理员 → 完成安装。

安装成功后会生成：
- `.env` - 环境配置（含数据库连接与加密密钥，权限 0600）
- `install.lock` - 安装锁文件（权限 0444，防止重复安装）

> 安装检测为三层防御：前端启动检查（`main.js`）、后端 API 早期拦截（`api.php`）、数据库连接放行（`conn.php`），确保未安装时不会暴露错误细节。

### 4. 构建前端（生产环境）

```bash
cd vue-project
npm run build
# 构建产物自动输出到 ../assets/
# 将 vue-project/dist/index.html 复制到项目根目录
cp vue-project/dist/index.html index.html
```

## 目录结构

```
php-notes/
├── api.php                 # API 入口（含安装检测拦截）
├── config.php              # 配置文件
├── conn.php                # 数据库连接（.env 解析）
├── ErrorHandler.php        # 错误处理
├── index.html              # 前端入口页面
├── install.html            # 安装向导页面
├── install.php             # 安装向导后端
├── install.lock            # 安装锁（安装后生成）
├── .env                    # 环境配置（安装后生成）
│
├── Auth.php                # 用户认证
├── Notes.php               # 笔记管理（含 Markdown 导出渲染）
├── AIService.php           # AI 服务
├── BackupManager.php       # 备份管理
├── ShareClass.php          # 分享功能（类）
├── Share.php               # 分享公开页面（含代码高亮）
├── Tags.php                # 标签管理
├── TeamSpaces.php          # 团队空间
├── Comments.php            # 评论系统
├── Notifications.php       # 通知系统
├── TwoFactorAuth.php       # 两步验证
├── VersionControl.php      # 版本控制
├── RecycleBin.php          # 回收站
├── UserSettings.php        # 用户设置
├── LoginLog.php            # 登录日志
├── RateLimiter.php         # 速率限制
├── ApiKeys.php             # API 密钥
├── NoteShares.php          # 协作共享
├── NoteTemplates.php       # 笔记模板
├── TemplateManager.php     # UI 主题管理
├── Captcha.php             # 验证码生成
├── CaptchaClass.php        # 验证码验证
│
├── adapters/               # 存储适配器
│   ├── StorageAdapter.php  # 适配器接口
│   ├── StorageFactory.php  # 适配器工厂
│   ├── FTPStorageAdapter.php
│   ├── SFTPStorageAdapter.php
│   ├── AliyunOSSAdapter.php
│   ├── TencentCOSAdapter.php
│   └── S3StorageAdapter.php
│
├── routes/
│   └── middleware.php      # API 中间件
│
├── templates/              # UI 主题模板
│
├── vue-project/            # 前端 Vue 项目
│   ├── src/
│   │   ├── views/         # 页面组件
│   │   ├── components/    # 公共组件
│   │   ├── api/           # API 封装
│   │   ├── stores/        # 状态管理
│   │   ├── utils/         # 工具模块
│   │   │   └── markdown.js # Markdown 渲染（marked + highlight.js）
│   │   └── router/        # 路由配置
│   └── package.json
│
├── logs/                   # 日志目录
├── uploads/                # 上传目录
├── attachments/            # 笔记附件目录
├── assets/                 # 前端构建产物
├── database.sql            # 数据库建表脚本
├── cron_cleanup.php        # 定时清理任务
└── README.md               # 本文件
```

## API 接口

系统提供 100+ 个 RESTful API 接口，详见 [API_DOCUMENTATION.md](./API_DOCUMENTATION.md)。

### 常用接口

| 模块 | 接口 | 说明 |
|------|------|------|
| 系统 | `/api.php?action=check_install` | 检测安装状态 |
| 认证 | `/api.php?action=login` | 用户登录 |
| 认证 | `/api.php?action=register` | 用户注册 |
| 笔记 | `/api.php?action=notes_list` | 获取笔记列表 |
| 笔记 | `/api.php?action=notes_create` | 创建笔记 |
| 笔记 | `/api.php?action=notes_export` | 导出笔记（含高亮 HTML） |
| 附件 | `/api.php?action=attachment_upload` | 上传附件 |
| AI | `/api.php?action=ai_proofread` | AI 校准 |
| 分享 | `/api.php?action=share_create` | 创建分享 |
| 备份 | `/api.php?action=backup_perform` | 执行备份 |

## 定时任务

建议配置以下定时任务：

```bash
# 每天凌晨 2 点执行清理任务
0 2 * * * php /path/to/cron_cleanup.php

# 或分时段执行
0 2 * * * php /path/to/cron_cleanup.php recycle    # 回收站
0 3 * * 0 php /path/to/cron_cleanup.php logs       # 登录日志
0 4 * * * php /path/to/cron_cleanup.php versions   # 版本历史
```

## 备份配置

支持多种存储后端：

| 类型 | 说明 | 配置项 |
|------|------|--------|
| FTP | 标准 FTP 服务器 | host, port, username, password, path |
| SFTP | SSH 文件传输 | host, port, username, password, path |
| Aliyun OSS | 阿里云对象存储 | access_key_id, access_key_secret, bucket, region |
| Tencent COS | 腾讯云对象存储 | secret_id, secret_key, bucket, region |
| S3 | AWS S3 兼容存储 | endpoint, access_key, secret_key, bucket, region |

## 安全配置

### 生产环境建议

1. **加密密钥**：安装向导会自动生成，也可手动生成
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
2. **配置 HTTPS**：启用 HTTPS 并配置 HSTS
3. **限制文件上传**：设置上传大小限制，限制可执行文件类型
4. **保护安装文件**：安装完成后 `install.lock` 会阻止再次安装，生产环境可进一步删除 `install.php` / `install.html`
5. **定期更新**：关注安全更新，定期备份数据

## 常见问题

### Q: 如何开启 AI 功能？
A: 在管理员后台 - 系统设置中配置 AI 提供商的 API Key。

### Q: 如何恢复误删的笔记？
A: 笔记删除后会进入回收站，30 天内可在回收站中恢复。

### Q: 如何使用团队协作功能？
A: 创建团队后，可邀请成员加入，并将笔记共享到团队空间。

### Q: 两步验证无法使用怎么办？
A: 可使用备用码登录，备用码在启用两步验证时生成。

### Q: Markdown 代码高亮如何使用？
A: 编辑器中点击工具栏眼睛图标切换到分屏 / 预览模式即可看到高亮渲染；代码块用 ` ```language ` 标识语言（如 javascript、python、php），行内代码用反引号包裹。

### Q: 访问站点显示未安装怎么办？
A: 系统检测到 `install.lock` 不存在时会自动跳转安装向导，按向导完成安装即可。

## 许可证

MIT License

## 更新日志

详见 [CHANGELOG.md](./CHANGELOG.md)
