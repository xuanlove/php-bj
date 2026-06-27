<?php
/**
 * API中间件 - 统一处理限流、缓存、请求日志
 * T7: 添加更完整的API频率限制
 */

// 限流中间件
function rateLimitMiddleware($action) {
    global $rateLimiter;
    
    // 如果全局限流器未初始化，创建一个
    if (!isset($rateLimiter) || $rateLimiter === null) {
        if (class_exists('RateLimiter')) {
            $rateLimiter = new RateLimiter();
        } else {
            return true; // 限流器不可用，跳过
        }
    }
    
    // 需要限流的API列表
    $limitedActions = [
        // 搜索操作 - 限流严格
        'notes_search' => ['limit' => 10, 'window' => 60],
        'tags_list' => ['limit' => 20, 'window' => 60],
        
        // 写入操作 - 中等限流
        'notes_create' => ['limit' => 30, 'window' => 60],
        'notes_update' => ['limit' => 30, 'window' => 60],
        'notes_delete' => ['limit' => 20, 'window' => 60],
        'note_soft_delete' => ['limit' => 20, 'window' => 60],
        'note_restore' => ['limit' => 20, 'window' => 60],
        'note_permanent_delete' => ['limit' => 10, 'window' => 60],
        
        // 读取操作 - 宽松限流
        'notes_list' => ['limit' => 60, 'window' => 60],
        'notes_get' => ['limit' => 60, 'window' => 60],
        
        // AI操作 - 最严格
        'ai_proofread' => ['limit' => 5, 'window' => 60],
        'ai_continue' => ['limit' => 5, 'window' => 60],
        'ai_summarize' => ['limit' => 10, 'window' => 60],
        'ai_rewrite' => ['limit' => 5, 'window' => 60],
        
        // 上传操作 - 严格限制
        'attachment_upload' => ['limit' => 10, 'window' => 60],
        
        // 分享操作
        'share_create' => ['limit' => 10, 'window' => 60],
        'note_share_to_user' => ['limit' => 10, 'window' => 60],
        
        // 公开分享端点（防暴力枚举）
        'share_get' => ['limit' => 20, 'window' => 60],
        'share_content' => ['limit' => 20, 'window' => 60],
        
        // 登录操作 - 非常严格
        'login' => ['limit' => 10, 'window' => 60],
        'register' => ['limit' => 3, 'window' => 60],
    ];
    
    // 检查是否需要限流
    if (!isset($limitedActions[$action])) {
        return true;
    }
    
    $config = $limitedActions[$action];
    
    // 获取客户端标识
    $identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (isset($_SESSION['user_id'])) {
        $identifier = 'user_' . $_SESSION['user_id'];
    }
    
    // 检查限流
    $result = $rateLimiter->check($identifier, 'api', $config['limit'], $config['window']);
    
    // 设置响应头
    header('X-RateLimit-Limit: ' . $config['limit']);
    header('X-RateLimit-Remaining: ' . $result['remaining']);
    header('X-RateLimit-Reset: ' . $result['reset']);
    
    // 如果被限流，返回错误
    if (!$result['allowed']) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => '请求过于频繁，请稍后再试',
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'retry_after' => $result['retry_after'] ?? 60,
            'limit' => $config['limit'],
            'window' => $config['window']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    return true;
}

// 安全头中间件
function securityHeadersMiddleware() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// 执行中间件
function runMiddlewares($action) {
    securityHeadersMiddleware();
    rateLimitMiddleware($action);
}
