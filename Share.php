<?php
/**
 * 笔记分享访问入口
 * 
 * 功能：
 * - 提供公开的笔记分享链接
 * - 支持密码保护
 * - 支持有效期验证
 * - 记录访问统计
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ShareClass.php';

// 处理下载请求（必须在任何输出之前处理）
$token = $_GET['token'] ?? '';

if (!empty($token) && ((isset($_GET['download']) && $_GET['download'] == 1) || (isset($_POST['download']) && $_POST['download'] == 1))) {
    $share = new Share();
    $shareInfo = $share->getShareInfo($token);
    
    if (!$shareInfo['success']) {
        http_response_code(404);
        die('分享链接不存在');
    }
    
    $shareData = $shareInfo['share'];
    
    if ($shareData['expired']) {
        http_response_code(410);
        die('分享链接已过期');
    }
    
    // 如果需要密码，验证session中是否已验证（验证状态1小时内有效，避免公共电脑长期免密）
    if ($shareData['has_password']) {
        $verifiedTime = $_SESSION['share_token_verified_time'] ?? 0;
        $verifiedExpired = (time() - $verifiedTime) > 3600;
        if (!isset($_SESSION['share_token_verified']) || $_SESSION['share_token_verified'] !== $token || $verifiedExpired) {
            unset($_SESSION['share_token_verified'], $_SESSION['share_token_verified_time']);
            http_response_code(403);
            die('请先在页面中验证密码');
        }
    }
    
    $result = $share->getSharedContent($token);
    
    if ($result['success']) {
        // 安全处理文件名：移除路径分隔符和特殊字符，防止 CRLF 注入
        $filename = $shareData['title'];
        $filename = str_replace(["\r", "\n", '"', '\\', '/'], '', $filename);
        $filename = preg_replace('/[^\w\s\-\.\x{4e00}-\x{9fff}]/u', '', $filename);
        $filename = trim($filename) ?: 'note';
        $filename .= '.txt';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $result['content']['content'];
        exit;
    }
    
    http_response_code(500);
    die('下载失败');
}

// 获取分享token
if (empty($token)) {
    http_response_code(400);
    die('分享链接无效');
}

// 实例化分享类
$share = new Share();

// 获取分享信息
$shareInfo = $share->getShareInfo($token);

if (!$shareInfo['success']) {
    die(htmlspecialchars($shareInfo['message']));
}

$shareData = $shareInfo['share'];

// 检查是否需要密码
$needsPassword = $shareData['has_password'];
$passwordVerified = false;
$noteContent = null;

// 初始化错误变量
$error = null;

// 处理密码验证
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $password = $_POST['password'];
    $result = $share->getSharedContent($token, $password);
    
    if ($result['success']) {
        $passwordVerified = true;
        $noteContent = $result['content'];
        // 密码验证成功后，在session中记录验证状态及时间
        $_SESSION['share_token_verified'] = $token;
        $_SESSION['share_token_verified_time'] = time();
    } else {
        $error = $result['message'];
    }
} elseif (!$needsPassword) {
    // 不需要密码，直接获取内容
    $result = $share->getSharedContent($token);

    if ($result['success']) {
        $noteContent = $result['content'];
        $_SESSION['share_token_verified'] = $token;
        $_SESSION['share_token_verified_time'] = time();
    } else {
        die(htmlspecialchars($result['message']));
    }
}

/**
 * 简单的Markdown转HTML函数
 */
function renderMarkdown($text) {
    // 先转义HTML特殊字符防止XSS
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    
    // 然后渲染Markdown语法（在已转义的文本上操作）
    // 标题
    $text = preg_replace('/^### (.*+)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.*+)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^# (.*+)$/m', '<h1>$1</h1>', $text);
    
    // 粗体和斜体
    $text = preg_replace('/\*\*\*(.*?)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $text);
    
    // 代码块（需要还原HTML实体以正确显示）
    $text = preg_replace_callback('/```(\w*)\n(.*?)```/s', function($m) {
        $lang = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        $code = $m[2]; // $m[2]已经是htmlspecialchars处理后的内容
        return '<pre><code class="language-' . $lang . '">' . $code . '</code></pre>';
    }, $text);
    $text = preg_replace('/`(.*?)`/s', '<code>$1</code>', $text);
    
    // 链接（安全处理href）
    $text = preg_replace_callback('/\[([^\]]*)\]\(([^\)]+)\)/', function($m) {
        $text = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        $href = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
        $scheme = parse_url($m[2], PHP_URL_SCHEME);
        if ($scheme && !in_array(strtolower($scheme), ['http', 'https', 'mailto'])) {
            return $text;
        }
        return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
    }, $text);
    
    // 列表
    $text = preg_replace('/^\- (.*+)$/m', '<li>$1</li>', $text);
    $text = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $text);
    
    // 段落和换行
    $text = preg_replace('/\n\n/', '</p><p>', $text);
    $text = nl2br($text);
    
    return $text;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($shareData['title']); ?> - 笔记分享</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            max-width: 900px;
            width: 100%;
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .header .meta {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .content {
            padding: 40px;
        }
        
        .password-form {
            max-width: 400px;
            margin: 0 auto;
            text-align: center;
        }
        
        .password-form h2 {
            margin-bottom: 20px;
            color: #333;
        }
        
        .password-form input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            margin-bottom: 20px;
            transition: border-color 0.3s;
        }
        
        .password-form input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .password-form button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .password-form button:hover {
            transform: translateY(-2px);
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .note-content {
            line-height: 1.8;
            color: #333;
        }
        
        .note-content h1, .note-content h2, .note-content h3 {
            margin-top: 24px;
            margin-bottom: 16px;
        }
        
        .note-content p {
            margin-bottom: 16px;
        }
        
        .note-content code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
        
        .note-content pre {
            background: #f5f5f5;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            margin-bottom: 16px;
        }
        
        .note-content img {
            max-width: 100%;
            border-radius: 8px;
            margin: 16px 0;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            background: #f9f9f9;
            border-top: 1px solid #e0e0e0;
            font-size: 14px;
            color: #666;
        }
        
        .download-btn {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: background 0.3s;
        }
        
        .download-btn:hover {
            background: #764ba2;
        }
        
        .expired {
            text-align: center;
            padding: 60px 20px;
        }
        
        .expired h2 {
            color: #c33;
            margin-bottom: 20px;
        }
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.9.0/build/styles/github-dark.min.css">
    <script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11.9.0/build/highlight.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><?php echo htmlspecialchars($shareData['title']); ?></h1>
            <div class="meta">
                分享者: <?php echo htmlspecialchars($shareData['username']); ?> | 
                分享时间: <?php echo date('Y-m-d H:i', strtotime($shareData['created_at'])); ?>
                <?php if ($shareData['view_count'] > 0): ?>
                | 查看次数: <?php echo $shareData['view_count']; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($shareData['expired']): ?>
            <div class="expired">
                <h2>⏰ 分享链接已过期</h2>
                <p>此分享链接已超过有效期，请联系分享者重新分享。</p>
            </div>
        <?php elseif ($needsPassword && !$passwordVerified): ?>
            <div class="content">
                <div class="password-form">
                    <h2>此笔记需要密码访问</h2>
                    <?php if (isset($error)): ?>
                        <div class="error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="password" name="password" placeholder="请输入访问密码" required>
                        <button type="submit">访问笔记</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="content">
                <div class="note-content">
                    <?php 
                    if ($noteContent && $noteContent['content_type'] === 'markdown') {
                        echo renderMarkdown($noteContent['content']);
                    } elseif ($noteContent) {
                        echo nl2br(htmlspecialchars($noteContent['content'], ENT_QUOTES, 'UTF-8'));
                    }
                    ?>
                </div>
                
                <?php if ($shareData['allow_download'] && $noteContent): ?>
                    <div style="text-align: center;">
                        <form method="POST" action="?token=<?php echo htmlspecialchars($token); ?>&download=1" style="display:inline;">
                            <button type="submit" class="download-btn">📥 下载笔记</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div class="footer">
            Powered by PHP笔记系统
        </div>
    </div>
    <script>document.querySelectorAll('pre code').forEach(function(b){try{hljs.highlightElement(b)}catch(e){}});</script>
</body>
</html>