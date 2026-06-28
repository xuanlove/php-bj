<?php
/**
 * 登录日志类
 * 
 * 功能：
 * - 记录登录历史
 * - 异常登录检测
 * - IP地理位置解析
 * - 登录统计
 */

require_once 'config.php';

class LoginLog {
    private $db;
    private $maxLoginAttempts = MAX_LOGIN_ATTEMPTS; // 最大登录尝试次数
    private $lockoutDuration = LOGIN_LOCKOUT_DURATION; // 锁定时长（秒）
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * 记录登录日志
     * 
     * @param int|null $user_id 用户ID（登录失败时可能为null）
     * @param string $username 用户名
     * @param string $status 登录状态（success/failed）
     * @param string $failureReason 失败原因
     * @return bool
     */
    public function logLogin($user_id, $username, $status, $failureReason = null) {
        try {
            $ipAddress = $_SERVER['REMOTE_ADDR'];
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            $location = $this->getIPLocation($ipAddress);
            
            $stmt = $this->db->prepare(
                "INSERT INTO login_logs 
                 (user_id, username, ip_address, user_agent, login_status, failure_reason, location) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            
            $stmt->execute([
                $user_id,
                sanitizeInput($username),
                $ipAddress,
                $userAgent,
                $status,
                $failureReason,
                $location
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("记录登录日志失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查是否被锁定
     * 
     * @param string $username 用户名
     * @param string $ipAddress IP地址
     * @return array
     */
    public function isLockedOut($username, $ipAddress) {
        try {
            // 检查用户名锁定
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as attempts, MAX(created_at) as last_attempt
                 FROM login_logs 
                 WHERE username = ? 
                 AND login_status = 'failed'
                 AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $stmt->execute([$username, $this->lockoutDuration]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['attempts'] >= $this->maxLoginAttempts) {
                $remainingTime = max(0, $this->lockoutDuration - (time() - strtotime($result['last_attempt'])));
                return [
                    'locked' => true,
                    'type' => 'username',
                    'remaining_time' => $remainingTime,
                    'message' => "账户已锁定，请 {$remainingTime} 秒后重试"
                ];
            }
            
            // 检查IP锁定
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as attempts, MAX(created_at) as last_attempt
                 FROM login_logs 
                 WHERE ip_address = ? 
                 AND login_status = 'failed'
                 AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $stmt->execute([$ipAddress, $this->lockoutDuration]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['attempts'] >= $this->maxLoginAttempts * 2) {
                $remainingTime = max(0, $this->lockoutDuration - (time() - strtotime($result['last_attempt'])));
                return [
                    'locked' => true,
                    'type' => 'ip',
                    'remaining_time' => $remainingTime,
                    'message' => "IP已被锁定，请 {$remainingTime} 秒后重试"
                ];
            }
            
            return ['locked' => false];
            
        } catch (Exception $e) {
            error_log("检查锁定状态失败: " . $e->getMessage());
            return ['locked' => false];
        }
    }
    
    /**
     * 仅按IP检查锁定状态（用于未登录用户）
     * 
     * @param string $ipAddress IP地址
     * @return array
     */
    public function isLockedOutForIP($ipAddress) {
        try {
            // 检查IP锁定（更严格的限制，针对IP暴力破解）
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as attempts, MAX(created_at) as last_attempt
                 FROM login_logs 
                 WHERE ip_address = ? 
                 AND login_status = 'failed'
                 AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
            );
            $stmt->execute([$ipAddress, $this->lockoutDuration]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['attempts'] >= $this->maxLoginAttempts * 2) {
                $remainingTime = max(0, $this->lockoutDuration - (time() - strtotime($result['last_attempt'])));
                return [
                    'locked' => true,
                    'type' => 'ip',
                    'remaining_time' => $remainingTime,
                    'message' => "IP已被锁定，请 {$remainingTime} 秒后重试"
                ];
            }
            
            return ['locked' => false];
            
        } catch (Exception $e) {
            error_log("检查IP锁定状态失败: " . $e->getMessage());
            return ['locked' => false];
        }
    }
    
    /**
     * 获取登录历史
     * 
     * @param int $user_id 用户ID
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public function getLoginHistory($user_id, $limit = 20, $offset = 0) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM login_logs 
                 WHERE user_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute([$user_id, $limit, $offset]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 获取总数
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as total FROM login_logs WHERE user_id = ?"
            );
            $stmt->execute([$user_id]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            return [
                'success' => true,
                'logs' => $logs,
                'total' => $total,
                'message' => '获取登录历史成功'
            ];
            
        } catch (Exception $e) {
            error_log("获取登录历史失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取登录历史失败'];
        }
    }
    
    /**
     * 检测异常登录
     * 
     * @param int $user_id 用户ID
     * @param string $ipAddress IP地址
     * @return array
     */
    public function detectAnomaly($user_id, $ipAddress) {
        try {
            // 获取用户常用IP
            $stmt = $this->db->prepare(
                "SELECT ip_address, COUNT(*) as count
                 FROM login_logs 
                 WHERE user_id = ? AND login_status = 'success'
                 GROUP BY ip_address
                 ORDER BY count DESC
                 LIMIT 5"
            );
            $stmt->execute([$user_id]);
            $commonIPs = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            
            // 如果IP不在常用IP列表中
            if (!empty($commonIPs) && !in_array($ipAddress, $commonIPs)) {
                return [
                    'anomaly' => true,
                    'type' => 'new_ip',
                    'message' => '检测到新IP登录'
                ];
            }
            
            // 检查短时间内异地登录
            $stmt = $this->db->prepare(
                "SELECT location, created_at
                 FROM login_logs 
                 WHERE user_id = ? AND login_status = 'success'
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $stmt->execute([$user_id]);
            $lastLogin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastLogin) {
                $currentLocation = $this->getIPLocation($ipAddress);
                $lastLocation = $lastLogin['location'];
                $timeDiff = time() - strtotime($lastLogin['created_at']);
                
                // 如果位置不同且时间间隔小于1小时
                if ($currentLocation && $lastLocation && 
                    $currentLocation !== $lastLocation && $timeDiff < 3600) {
                    return [
                        'anomaly' => true,
                        'type' => 'impossible_travel',
                        'message' => '检测到异常登录位置'
                    ];
                }
            }
            
            return ['anomaly' => false];
            
        } catch (Exception $e) {
            error_log("检测异常登录失败: " . $e->getMessage());
            return ['anomaly' => false];
        }
    }
    
    /**
     * 清除用户的失败登录计数（登录成功后调用）
     * 
     * @param string $username 用户名
     * @param string $ipAddress IP地址
     * @return void
     */
    public function clearFailedAttempts($username, $ipAddress) {
        try {
            $cutoff = time() - $this->lockoutDuration;
            $cutoffDate = date('Y-m-d H:i:s', $cutoff);
            
            $stmt = $this->db->prepare(
                "DELETE FROM login_logs
                 WHERE (username = ? OR ip_address = ?)
                 AND login_status = 'failed'
                 AND created_at > ?"
            );
            $stmt->execute([$username, $ipAddress, $cutoffDate]);
        } catch (Exception $e) {
            error_log("清除失败登录计数失败: " . $e->getMessage());
        }
    }

    /**
     * 获取IP地理位置
     * 
     * @param string $ip IP地址
     * @return string
     */
    private function getIPLocation($ip) {
        try {
            $context = stream_context_create(['http' => ['timeout' => 3]]);
            $url = "http://ip-api.com/json/{$ip}?lang=zh-CN";
            $response = @file_get_contents($url, false, $context);
            
            if ($response) {
                $data = json_decode($response, true);
                if ($data['status'] === 'success') {
                    return $data['city'] . ', ' . $data['country'];
                }
            }
            
            return 'Unknown';
            
        } catch (Exception $e) {
            return 'Unknown';
        }
    }
    
    /**
     * 清理旧日志
     * 
     * @param int $days 保留天数
     * @return array
     */
    public function cleanupOldLogs($days = null) {
        $days = $days ?? LOGIN_LOG_RETENTION_DAYS; // 默认使用常量值
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM login_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $stmt->execute([$days]);
            
            $deletedCount = $stmt->rowCount();
            
            logSecurityEvent('logs_cleaned', [
                'days' => $days,
                'count' => $deletedCount
            ]);
            
            return [
                'success' => true,
                'deleted_count' => $deletedCount,
                'message' => "清理了 {$deletedCount} 条旧日志"
            ];
            
        } catch (Exception $e) {
            error_log("清理旧日志失败: " . $e->getMessage());
            return ['success' => false, 'message' => '清理旧日志失败'];
        }
    }
    
    /**
     * 获取登录统计
     * 
     * @param int $user_id 用户ID
     * @return array
     */
    public function getLoginStats($user_id) {
        try {
            // 总登录次数
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as total FROM login_logs 
                 WHERE user_id = ? AND login_status = 'success'"
            );
            $stmt->execute([$user_id]);
            $totalLogins = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // 最近登录
            $stmt = $this->db->prepare(
                "SELECT created_at, ip_address, location FROM login_logs 
                 WHERE user_id = ? AND login_status = 'success'
                 ORDER BY created_at DESC LIMIT 1"
            );
            $stmt->execute([$user_id]);
            $lastLogin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 失败登录次数（最近24小时）
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM login_logs 
                 WHERE user_id = ? AND login_status = 'failed'
                 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $stmt->execute([$user_id]);
            $failedAttempts = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            return [
                'success' => true,
                'total_logins' => $totalLogins,
                'last_login' => $lastLogin,
                'failed_attempts_24h' => $failedAttempts,
                'message' => '获取统计成功'
            ];
            
        } catch (Exception $e) {
            error_log("获取登录统计失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取统计失败'];
        }
    }
    
    /**
     * 设置最大登录尝试次数
     * 
     * @param int $max 最大次数
     */
    public function setMaxLoginAttempts($max) {
        $this->maxLoginAttempts = max(3, min(10, $max));
    }
    
    /**
     * 设置锁定时长
     * 
     * @param int $seconds 秒数
     */
    public function setLockoutDuration($seconds) {
        $this->lockoutDuration = max(300, min(3600, $seconds));
    }
}
?>