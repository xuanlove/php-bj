<?php
/**
 * 定时任务 - 统一清理脚本
 * 
 * 功能：
 * - 清理回收站（超过30天的笔记）
 * - 清理登录日志（超过90天的记录）
 * - 清理版本历史（超过50个版本）
 * 
 * 使用方式：
 * php cron_cleanup.php              # 运行所有清理任务
 * php cron_cleanup.php recycle     # 仅清理回收站
 * php cron_cleanup.php logs         # 仅清理登录日志
 * php cron_cleanup.php versions     # 仅清理版本历史
 * 
 * 建议配置：
 * crontab -e
 * # 每天凌晨2点清理回收站
 * 0 2 * * * php /path/to/cron_cleanup.php recycle
 * # 每周日凌晨3点清理登录日志
 * 0 3 * * 0 php /path/to/cron_cleanup.php logs
 * # 每天凌晨4点清理版本历史
 * 0 4 * * * php /path/to/cron_cleanup.php versions
 * 
 * 或者一次性运行所有清理：
 * 0 2 * * * php /path/to/cron_cleanup.php
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 引入必需文件
require_once __DIR__ . '/config.php';

// 初始化数据库连接（CLI脚本需要数据库）
$db = initDatabase();
if ($db === null) {
    echo "错误：无法连接数据库\n";
    exit(1);
}

require_once __DIR__ . '/RecycleBin.php';
require_once __DIR__ . '/LoginLog.php';
require_once __DIR__ . '/VersionControl.php';

/**
 * 清理任务类
 */
class CleanupCron {
    
    private $results = [];
    
    /**
     * 清理回收站
     * 
     * @return array 清理结果
     */
    public function cleanupRecycleBin() {
        echo "\n╔════════════════════════════════════════════╗\n";
        echo "║           清理回收站                       ║\n";
        echo "╚════════════════════════════════════════════╝\n\n";
        
        echo "[" . date('Y-m-d H:i:s') . "] 开始清理回收站...\n";
        
        try {
            $recycleBin = new RecycleBin();
            $result = $recycleBin->autoCleanup();
            
            if ($result['success']) {
                echo "[" . date('Y-m-d H:i:s') . "] ✓ 清理完成: " . $result['message'] . "\n";
                echo "删除笔记数: " . ($result['deleted_count'] ?? 0) . "\n";
                
                $this->results['recycle_bin'] = [
                    'success' => true,
                    'deleted_count' => $result['deleted_count'] ?? 0,
                    'message' => $result['message']
                ];
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] ✗ 清理失败: " . $result['message'] . "\n";
                
                $this->results['recycle_bin'] = [
                    'success' => false,
                    'error' => $result['message']
                ];
            }
            
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] ✗ 错误: " . $e->getMessage() . "\n";
            error_log("回收站清理失败: " . $e->getMessage());
            
            $this->results['recycle_bin'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] 任务结束\n";
        
        return $this->results['recycle_bin'];
    }
    
    /**
     * 清理登录日志
     * 
     * @param int $daysToKeep 保留天数（默认90天）
     * @return array 清理结果
     */
    public function cleanupLoginLogs($daysToKeep = 90) {
        echo "\n╔════════════════════════════════════════════╗\n";
        echo "║           清理登录日志                     ║\n";
        echo "╚════════════════════════════════════════════╝\n\n";
        
        echo "[" . date('Y-m-d H:i:s') . "] 开始清理登录日志...\n";
        echo "保留天数: {$daysToKeep}\n";
        
        try {
            $loginLog = new LoginLog();
            $result = $loginLog->cleanupOldLogs($daysToKeep);
            
            if ($result['success']) {
                echo "[" . date('Y-m-d H:i:s') . "] ✓ 清理完成: " . $result['message'] . "\n";
                echo "删除日志数: " . ($result['deleted_count'] ?? 0) . "\n";
                
                $this->results['login_logs'] = [
                    'success' => true,
                    'deleted_count' => $result['deleted_count'] ?? 0,
                    'message' => $result['message']
                ];
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] ✗ 清理失败: " . $result['message'] . "\n";
                
                $this->results['login_logs'] = [
                    'success' => false,
                    'error' => $result['message']
                ];
            }
            
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] ✗ 错误: " . $e->getMessage() . "\n";
            error_log("登录日志清理失败: " . $e->getMessage());
            
            $this->results['login_logs'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] 任务结束\n";
        
        return $this->results['login_logs'];
    }
    
    /**
     * 清理版本历史
     * 
     * @return array 清理结果
     */
    public function cleanupVersions() {
        echo "\n╔════════════════════════════════════════════╗\n";
        echo "║           清理版本历史                     ║\n";
        echo "╚════════════════════════════════════════════╝\n\n";
        
        echo "[" . date('Y-m-d H:i:s') . "] 开始清理版本历史...\n";
        
        try {
            $versionControl = new VersionControl();
            $result = $versionControl->autoCleanup();
            
            if ($result['success']) {
                echo "[" . date('Y-m-d H:i:s') . "] ✓ 清理完成: " . $result['message'] . "\n";
                echo "删除版本数: " . ($result['deleted_count'] ?? 0) . "\n";
                
                $this->results['versions'] = [
                    'success' => true,
                    'deleted_count' => $result['deleted_count'] ?? 0,
                    'message' => $result['message']
                ];
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] ✗ 清理失败: " . $result['message'] . "\n";
                
                $this->results['versions'] = [
                    'success' => false,
                    'error' => $result['message']
                ];
            }
            
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] ✗ 错误: " . $e->getMessage() . "\n";
            error_log("版本历史清理失败: " . $e->getMessage());
            
            $this->results['versions'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] 任务结束\n";
        
        return $this->results['versions'];
    }
    
    /**
     * 运行所有清理任务
     * 
     * @return array 所有清理结果
     */
    public function runAll() {
        echo "\n";
        echo "╔════════════════════════════════════════════╗\n";
        echo "║     PHP笔记系统 V2.0 - 定时清理任务        ║\n";
        echo "║     执行时间: " . date('Y-m-d H:i:s') . "             ║\n";
        echo "╚════════════════════════════════════════════╝\n";
        
        // 清理回收站
        $this->cleanupRecycleBin();
        
        // 清理登录日志
        $this->cleanupLoginLogs();
        
        // 清理版本历史
        $this->cleanupVersions();
        
        // 输出汇总
        $this->printSummary();
        
        return $this->results;
    }
    
    /**
     * 打印清理汇总
     */
    private function printSummary() {
        echo "\n";
        echo "╔════════════════════════════════════════════╗\n";
        echo "║              清理结果汇总                  ║\n";
        echo "╚════════════════════════════════════════════╝\n\n";
        
        $totalDeleted = 0;
        $successCount = 0;
        $failCount = 0;
        
        // 回收站
        if (isset($this->results['recycle_bin'])) {
            $recycle = $this->results['recycle_bin'];
            if ($recycle['success']) {
                echo "✓ 回收站清理成功\n";
                echo "  删除笔记数: " . ($recycle['deleted_count'] ?? 0) . "\n";
                $totalDeleted += $recycle['deleted_count'] ?? 0;
                $successCount++;
            } else {
                echo "✗ 回收站清理失败\n";
                echo "  错误: " . ($recycle['error'] ?? '未知错误') . "\n";
                $failCount++;
            }
        }
        
        // 登录日志
        if (isset($this->results['login_logs'])) {
            $logs = $this->results['login_logs'];
            if ($logs['success']) {
                echo "✓ 登录日志清理成功\n";
                echo "  删除日志数: " . ($logs['deleted_count'] ?? 0) . "\n";
                $totalDeleted += $logs['deleted_count'] ?? 0;
                $successCount++;
            } else {
                echo "✗ 登录日志清理失败\n";
                echo "  错误: " . ($logs['error'] ?? '未知错误') . "\n";
                $failCount++;
            }
        }
        
        // 版本历史
        if (isset($this->results['versions'])) {
            $versions = $this->results['versions'];
            if ($versions['success']) {
                echo "✓ 版本历史清理成功\n";
                echo "  删除版本数: " . ($versions['deleted_count'] ?? 0) . "\n";
                $totalDeleted += $versions['deleted_count'] ?? 0;
                $successCount++;
            } else {
                echo "✗ 版本历史清理失败\n";
                echo "  错误: " . ($versions['error'] ?? '未知错误') . "\n";
                $failCount++;
            }
        }
        
        echo "\n";
        echo "总计删除记录: {$totalDeleted}\n";
        echo "成功任务数: {$successCount}\n";
        echo "失败任务数: {$failCount}\n";
        
        if ($failCount === 0) {
            echo "\n✅ 所有清理任务执行成功！\n";
        } else {
            echo "\n⚠️ 部分清理任务执行失败，请检查日志。\n";
        }
        
        echo "\n执行时间: " . date('Y-m-d H:i:s') . "\n";
    }
    
    /**
     * 获取清理结果
     * 
     * @return array 清理结果
     */
    public function getResults() {
        return $this->results;
    }
}

// 主程序
try {
    $cron = new CleanupCron();
    
    // 获取命令行参数
    $task = isset($argv[1]) ? strtolower($argv[1]) : 'all';
    
    // 根据参数执行不同的清理任务
    switch ($task) {
        case 'recycle':
        case 'recycle_bin':
        case 'recyclebin':
            $cron->cleanupRecycleBin();
            break;
            
        case 'logs':
        case 'login_logs':
        case 'loginlogs':
            $cron->cleanupLoginLogs();
            break;
            
        case 'versions':
        case 'version':
        case 'version_history':
            $cron->cleanupVersions();
            break;
            
        case 'all':
        default:
            $cron->runAll();
            break;
    }
    
} catch (Exception $e) {
    echo "\n❌ 系统错误: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
?>