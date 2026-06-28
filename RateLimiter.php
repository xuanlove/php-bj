<?php
/**
 * API速率限制类
 * 
 * 功能：
 * - 基于IP的请求频率限制
 * - 基于用户的请求频率限制（登录后）
 * - 防止暴力破解和滥用
 */

require_once 'config.php';

class RateLimiter {
    private $db;
    
    // 默认限制配置
    const DEFAULT_LIMIT = API_RATE_LIMIT_DEFAULT;           // 默认每分钟60次请求
    const DEFAULT_WINDOW = API_RATE_LIMIT_WINDOW;            // 时间窗口60秒
    const LOGIN_LIMIT = API_RATE_LIMIT_LOGIN;              // 登录接口每分钟10次
    const LOGIN_WINDOW = API_RATE_LIMIT_WINDOW;             // 登录限制时间窗口
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * 检查请求是否超过限制
     * 
     * @param string $identifier 标识符（IP或user_id）
     * @param string $type 类型（ip/user/login）
     * @param int $limit 请求次数限制
     * @param int $window 时间窗口（秒）
     * @return array ['allowed' => bool, 'remaining' => int, 'reset' => int]
     */
    public function check($identifier, $type = 'ip', $limit = null, $window = null) {
        // 根据类型设置默认限制
        if ($limit === null) {
            $limit = ($type === 'login') ? self::LOGIN_LIMIT : self::DEFAULT_LIMIT;
        }
        if ($window === null) {
            $window = ($type === 'login') ? self::LOGIN_WINDOW : self::DEFAULT_WINDOW;
        }
        
        try {
            $table = 'rate_limits';
            
            // 确保表存在
            $this->ensureTableExists();
            
            // 清理过期记录
            $this->cleanup($type, $identifier, $window);
            
            // 查询当前请求次数
            $stmt = $this->db->prepare(
                "SELECT request_count, window_start 
                 FROM {$table} 
                 WHERE identifier = ? AND type = ? AND window_start = (
                     SELECT MAX(window_start) FROM {$table} WHERE identifier = ? AND type = ?
                 )"
            );
            $stmt->execute([$identifier, $type, $identifier, $type]);
            $record = $stmt->fetch();
            
            $now = time();
            $windowStart = $now - $window;
            
            if ($record) {
                $requestsInWindow = $record['request_count'];
                $resetTime = $record['window_start'] + $window;

                // 窗口已过期：重置计数，创建新窗口，避免用户被持续限流
                if ($now >= $resetTime) {
                    $this->createRecord($identifier, $type, $now);
                    return [
                        'allowed' => true,
                        'remaining' => $limit - 1,
                        'reset' => $now + $window
                    ];
                }

                if ($requestsInWindow >= $limit) {
                    return [
                        'allowed' => false,
                        'remaining' => 0,
                        'reset' => $resetTime,
                        'retry_after' => max(0, $resetTime - $now)
                    ];
                }

                // 更新计数
                $this->increment($identifier, $type);

                return [
                    'allowed' => true,
                    'remaining' => $limit - $requestsInWindow - 1,
                    'reset' => $resetTime
                ];
            } else {
                // 新记录
                $this->createRecord($identifier, $type, $now);
                
                return [
                    'allowed' => true,
                    'remaining' => $limit - 1,
                    'reset' => $now + $window
                ];
            }
            
        } catch (Exception $e) {
            error_log("RateLimiter check failed: " . $e->getMessage());
            // 发生错误时允许请求，但记录日志
            return [
                'allowed' => true,
                'remaining' => $limit,
                'reset' => time() + $window,
                'error' => true
            ];
        }
    }
    
    /**
     * 记录一次请求
     */
    public function hit($identifier, $type = 'ip') {
        try {
            $this->ensureTableExists();
            $this->increment($identifier, $type);
        } catch (Exception $e) {
            error_log("RateLimiter hit failed: " . $e->getMessage());
        }
    }
    
    /**
     * 创建记录
     */
    private function createRecord($identifier, $type, $windowStart) {
        $stmt = $this->db->prepare(
            "INSERT INTO rate_limits (identifier, type, request_count, window_start, created_at) 
             VALUES (?, ?, 1, ?, NOW())"
        );
        $stmt->execute([$identifier, $type, $windowStart]);
    }
    
    /**
     * 增加请求计数
     */
    private function increment($identifier, $type) {
        $stmt = $this->db->prepare(
            "UPDATE rate_limits 
             SET request_count = request_count + 1 
             WHERE identifier = ? AND type = ? AND window_start = (
                 SELECT MAX(window_start) FROM (SELECT window_start FROM rate_limits WHERE identifier = ? AND type = ?) AS t
             )"
        );
        $stmt->execute([$identifier, $type, $identifier, $type]);
    }
    
    /**
     * 清理过期记录
     */
    private function cleanup($type, $identifier, $window) {
        $cutoff = time() - $window * 2; // 保留2个时间窗口的记录
        
        $stmt = $this->db->prepare(
            "DELETE FROM rate_limits WHERE type = ? AND identifier = ? AND window_start < ?"
        );
        $stmt->execute([$type, $identifier, $cutoff]);
    }
    
    /**
     * 确保表存在
     */
    private function ensureTableExists() {
        static $checked = false;
        if ($checked) return;
        
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'rate_limits'");
            if ($stmt->rowCount() > 0) {
                $checked = true;
                return;
            }
        } catch (Exception $e) {
        }
        
        @error_log("rate_limits 表不存在（请通过 install.php 重新安装）");
        $checked = true;
    }
    
    /**
     * 检查登录限制
     */
    public function checkLogin($ip, $username = null) {
        // IP级别限制
        $ipResult = $this->check($ip, 'login');
        if (!$ipResult['allowed']) {
            return $ipResult;
        }
        
        // 用户名级别限制（如果提供）
        if ($username !== null) {
            $userResult = $this->check($username, 'login_user');
            if (!$userResult['allowed']) {
                return $userResult;
            }
        }
        
        return $ipResult;
    }
    
    /**
     * 获取限制信息头
     */
    public function getHeaders($result) {
        return [
            'X-RateLimit-Limit' => $result['limit'] ?? self::DEFAULT_LIMIT,
            'X-RateLimit-Remaining' => $result['remaining'],
            'X-RateLimit-Reset' => $result['reset']
        ];
    }
    
    /**
     * 发送速率限制响应
     */
    public function sendRateLimitResponse($result) {
        $retryAfter = $result['retry_after'] ?? 60;
        
        header('Content-Type: application/json; charset=utf-8');
        header('X-RateLimit-Limit: ' . ($result['limit'] ?? self::DEFAULT_LIMIT));
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: ' . $result['reset']);
        header('Retry-After: ' . $retryAfter);
        
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => '请求过于频繁，请稍后再试',
            'retry_after' => $retryAfter
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * 重置限制计数
     */
    public function reset($identifier, $type = 'ip') {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM rate_limits WHERE identifier = ? AND type = ?"
            );
            $stmt->execute([$identifier, $type]);
            return true;
        } catch (Exception $e) {
            error_log("RateLimiter reset failed: " . $e->getMessage());
            return false;
        }
    }
}
?>
