<?php
/**
 * 统一错误处理类
 * 
 * 功能：
 * - 统一错误日志记录
 * - 标准化错误响应格式
 * - 区分错误等级
 * - 支持调试模式和生产品模式
 */

class ErrorHandler {

    // 错误等级常量
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';

    // 日志目录
    private static $logDir = __DIR__ . '/logs';

    // 日志配置
    const MAX_LOG_SIZE = 10 * 1024 * 1024; // 10MB
    const MAX_LOG_FILES = 5; // 保留最多5个轮转文件

    // 是否为调试模式
    private static $debugMode = false;
    
    /**
     * 初始化错误处理器
     */
    public static function init($debugMode = false) {
        self::$debugMode = $debugMode;
        
        // 设置错误处理器
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        
        // 确保日志目录存在
        if (!file_exists(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
    }
    
    /**
     * 处理普通错误
     */
    public static function handleError($severity, $message, $file, $line) {
        // 忽略被@运算符抑制的错误
        if (!(error_reporting() & $severity)) {
            return;
        }
        
        $level = self::severityToLevel($severity);
        
        $error = [
            'type' => 'error',
            'level' => $level,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'time' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'trace' => self::getBacktrace()
        ];
        
        // 记录日志
        self::log($error);
        
        // 在调试模式下抛出异常
        if (self::$debugMode && $severity <= E_WARNING) {
            throw new ErrorException($message, 0, $severity, $file, $line);
        }
        
        return true;
    }
    
    /**
     * 处理未捕获异常
     */
    public static function handleException($exception) {
        $error = [
            'type' => 'exception',
            'level' => self::LEVEL_ERROR,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'time' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'trace' => $exception->getTraceAsString(),
            'trace_array' => $exception->getTrace()
        ];
        
        // 记录日志
        self::log($error);
        
        // 发送响应
        self::sendErrorResponse($error);
    }
    
    /**
     * 记录错误日志
     */
    public static function log($error) {
        $level = $error['level'];
        $logFile = self::$logDir . '/' . $level . '.log';

        // 执行日志轮转检查
        self::rotateLog($logFile);

        $logEntry = json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 使用 @ 抑制警告，防止日志写入失败时输出到响应内容中
        @file_put_contents($logFile, $logEntry . "\n", FILE_APPEND | LOCK_EX);

        // 严重错误同时记录到系统日志
        if ($level === self::LEVEL_CRITICAL || $level === self::LEVEL_ERROR) {
            @error_log("[" . strtoupper($level) . "] " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
        }
    }

    /**
     * 日志轮转（按大小）
     * 当日志文件超过MAX_LOG_SIZE时，轮转日志文件
     */
    private static function rotateLog($logFile) {
        if (!file_exists($logFile)) {
            return;
        }

        $fileSize = filesize($logFile);
        if ($fileSize < self::MAX_LOG_SIZE) {
            return;
        }

        // 轮转现有备份文件
        for ($i = self::MAX_LOG_FILES - 1; $i >= 1; $i--) {
            $oldFile = $logFile . '.' . $i;
            $newFile = $logFile . '.' . ($i + 1);
            if (file_exists($newFile)) {
                @unlink($newFile);
            }
            if (file_exists($oldFile)) {
                @rename($oldFile, $newFile);
            }
        }

        // 将当前日志文件轮转为 .1
        $backupFile = $logFile . '.1';
        if (file_exists($backupFile)) {
            @unlink($backupFile);
        }
        @rename($logFile, $backupFile);
    }
    
    /**
     * 发送错误响应
     */
    public static function sendErrorResponse($error) {
        header('Content-Type: application/json; charset=utf-8');
        
        // 在调试模式下返回详细信息
        if (self::$debugMode) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => '系统错误，请稍后重试',
                'error' => [
                    'message' => $error['message'],
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'trace' => $error['trace']
                ]
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // 生产模式只返回通用错误信息
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => '系统错误，请稍后重试'
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    
    /**
     * 记录业务逻辑错误（不抛出异常）
     */
    public static function logBusinessError($message, $context = []) {
        $error = [
            'type' => 'business_error',
            'level' => self::LEVEL_WARNING,
            'message' => $message,
            'context' => $context,
            'time' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'url' => $_SERVER['REQUEST_URI'] ?? ''
        ];
        
        self::log($error);
    }
    
    /**
     * 记录安全相关事件
     */
    public static function logSecurityEvent($event, $details = []) {
        $error = [
            'type' => 'security',
            'level' => self::LEVEL_WARNING,
            'event' => $event,
            'details' => $details,
            'time' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_id' => $_SESSION['user_id'] ?? 'guest',
            'url' => $_SERVER['REQUEST_URI'] ?? ''
        ];
        
        $logFile = self::$logDir . '/security.log';
        
        $logEntry = json_encode($error, JSON_UNESCAPED_UNICODE);
        @file_put_contents($logFile, $logEntry . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * 将错误等级转换为日志级别
     */
    private static function severityToLevel($severity) {
        switch ($severity) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                return self::LEVEL_ERROR;
            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
                return self::LEVEL_WARNING;
            case E_NOTICE:
            case E_USER_NOTICE:
                return self::LEVEL_INFO;
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return self::LEVEL_DEBUG;
            default:
                // E_STRICT 在 PHP 8.4+ 已移除，引用未定义常量会触发 deprecated 警告
                if (defined('E_STRICT') && $severity === E_STRICT) {
                    return self::LEVEL_DEBUG;
                }
                return self::LEVEL_INFO;
        }
    }
    
    /**
     * 获取调用栈
     */
    private static function getBacktrace() {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $result = [];
        
        foreach ($trace as $frame) {
            if (isset($frame['file']) && isset($frame['line'])) {
                $result[] = $frame['file'] . ':' . $frame['line'];
            }
        }
        
        return implode(' <- ', $result);
    }
    
    /**
     * 创建标准化的成功响应
     */
    public static function success($data = null, $message = '操作成功') {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => time()
        ];
    }
    
    /**
     * 创建标准化的错误响应
     */
    public static function error($message = '操作失败', $code = null, $details = null) {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => time()
        ];
        
        if ($code !== null) {
            $response['code'] = $code;
        }
        
        if (self::$debugMode && $details !== null) {
            $response['details'] = $details;
        }
        
        return $response;
    }
    
    /**
     * 验证并返回统一格式的响应
     */
    public static function validateResponse($result, $successCode = true) {
        if (is_array($result) && isset($result['success'])) {
            if ($result['success'] === $successCode) {
                return self::success($result['data'] ?? $result, $result['message'] ?? '操作成功');
            } else {
                return self::error($result['message'] ?? '操作失败', $result['code'] ?? null);
            }
        }
        
        return self::success($result);
    }
}

/**
 * 快速错误响应函数
 */
function sendError($message, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 快速成功响应函数
 */
function sendSuccess($message = '操作成功', $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
