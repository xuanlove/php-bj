<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/adapters/StorageFactory.php';

class BackupManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 获取所有备份配置列表
     */
    public function getConfigs(): array {
        try {
            $stmt = $this->db->query(
                "SELECT * FROM backup_configs ORDER BY created_at DESC"
            );
            $configs = $stmt->fetchAll();

            // 解密存储凭证（只返回掩码后的密码）
            foreach ($configs as &$cfg) {
                $cfg['credentials'] = $this->decryptCredentials($cfg['credentials_json'] ?? '{}');
                // 掩码敏感字段
                $cfg['masked_credentials'] = $this->maskCredentials($cfg['credentials']);
                // 移除敏感字段，仅保留掩码后的凭证
                unset($cfg['credentials'], $cfg['credentials_json']);
            }
            unset($cfg);

            return ['success' => true, 'configs' => $configs];
        } catch (Exception $e) {
            error_log("获取备份配置失败: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 创建备份配置
     */
    public function createConfig(array $data): array {
        try {
            $name = sanitizeInput($data['backup_name'] ?? '');
            $storageType = sanitizeInput($data['storage_type'] ?? 'ftp');
            $frequency = sanitizeInput($data['backup_frequency'] ?? 'weekly');
            $retentionCount = (int)($data['retention_count'] ?? 5);
            $enabled = ($data['enabled'] ?? 1) ? 1 : 0;

            if (empty($name)) {
                return ['success' => false, 'message' => '配置名称不能为空'];
            }
            if (!in_array($storageType, array_keys(StorageFactory::getSupportedTypes()))) {
                return ['success' => false, 'message' => '不支持的存储类型'];
            }

            // 构建凭证数组
            $credentials = $this->buildCredentials($storageType, $data);
            $credentialsJson = encryptData(json_encode($credentials, JSON_UNESCAPED_UNICODE));

            $stmt = $this->db->prepare(
                "INSERT INTO backup_configs
                 (backup_name, storage_type, backup_frequency, retention_count, enabled, credentials_json, next_backup)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $name,
                $storageType,
                $frequency,
                $retentionCount,
                $enabled,
                $credentialsJson,
                $this->calculateNextBackup($frequency)
            ]);

            $id = $this->db->lastInsertId();
            logSecurityEvent('backup_config_created', ['backup_id' => $id, 'storage_type' => $storageType]);

            return [
                'success' => true,
                'message' => '备份配置已创建',
                'backup_id' => $id
            ];
        } catch (Exception $e) {
            error_log("创建备份配置失败: " . $e->getMessage());
            return ['success' => false, 'message' => '创建失败: ' . $e->getMessage()];
        }
    }

    /**
     * 更新备份配置
     */
    public function updateConfig(int $id, array $data): array {
        try {
            $updates = [];
            $values = [];

            $fields = [
                'backup_name' => 'sanitize',
                'storage_type' => 'sanitize',
                'backup_frequency' => 'sanitize',
                'retention_count' => 'int',
                'enabled' => 'bool',
            ];

            foreach ($fields as $field => $type) {
                if (isset($data[$field])) {
                    $val = $data[$field];
                    if ($type === 'sanitize') $val = sanitizeInput($val);
                    elseif ($type === 'int') $val = (int)$val;
                    elseif ($type === 'bool') $val = $val ? 1 : 0;
                    $updates[] = "`{$field}` = ?";
                    $values[] = $val;
                }
            }

            // 始终从数据库获取当前 storage_type 和 backup_frequency
            $stmt = $this->db->prepare("SELECT storage_type, backup_frequency FROM backup_configs WHERE id = ?");
            $stmt->execute([$id]);
            $currentConfig = $stmt->fetch();
            $currentStorageType = $currentConfig['storage_type'] ?? 'ftp';
            $currentFrequency = $currentConfig['backup_frequency'] ?? 'weekly';

            // 如果提供了新凭证则更新
            $storageType = $data['storage_type'] ?? $currentStorageType;
            if ($this->hasCredentialFields($storageType, $data)) {
                $credentials = $this->buildCredentials($storageType, $data);
                $credentialsJson = encryptData(json_encode($credentials, JSON_UNESCAPED_UNICODE));
                $updates[] = "`credentials_json` = ?";
                $values[] = $credentialsJson;
            }

            // 当 storage_type 或 backup_frequency 实际变化时才重新计算 next_backup
            if (($data['storage_type'] ?? null) !== null || ($data['backup_frequency'] ?? null) !== null) {
                $newFrequency = $data['backup_frequency'] ?? $currentFrequency;
                $updates[] = "`next_backup` = ?";
                $values[] = $this->calculateNextBackup($newFrequency);
            }

            if (empty($updates)) {
                return ['success' => false, 'message' => '没有可更新的字段'];
            }

            $values[] = $id;
            $sql = "UPDATE backup_configs SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);

            logSecurityEvent('backup_config_updated', ['backup_id' => $id]);

            return ['success' => true, 'message' => '配置已更新'];
        } catch (Exception $e) {
            error_log("更新备份配置失败: " . $e->getMessage());
            return ['success' => false, 'message' => '更新失败: ' . $e->getMessage()];
        }
    }

    /**
     * 删除备份配置
     */
    public function deleteConfig(int $id): array {
        try {
            $stmt = $this->db->prepare("DELETE FROM backup_configs WHERE id = ?");
            $stmt->execute([$id]);
            return ['success' => true, 'message' => '配置已删除'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '删除失败: ' . $e->getMessage()];
        }
    }

    /**
     * 执行备份
     */
    public function performBackup(int $configId): array {
        $config = $this->getConfigById($configId);
        if (!$config) {
            return ['success' => false, 'message' => '备份配置不存在'];
        }

        $logId = $this->createLog($configId, 'in_progress');
        $zipPath = null;
        $storage = null;

        try {
            // 1. 创建完整备份 ZIP
            $zipPath = $this->createBackupZip();
            $fileSize = filesize($zipPath);
            $fileName = basename($zipPath);

            // 2. 上传到存储
            $storage = StorageFactory::create(
                $config['storage_type'],
                $config['credentials']
            );
            $uploadResult = $storage->upload($zipPath, $fileName);

            if (!$uploadResult['success']) {
                throw new Exception('上传失败: ' . $uploadResult['message']);
            }

            // 3. 执行保留策略（删除旧备份）
            $deletedCount = $this->applyRetentionPolicy($config, $storage, $fileName);

            // 4. 更新日志
            $this->updateLog($logId, 'success', $fileName, $fileSize);

            // 5. 更新配置
            $this->updateConfigLastBackup($configId, $config['backup_frequency']);

            logSecurityEvent('backup_performed', [
                'config_id' => $configId,
                'file' => $fileName,
                'size' => $fileSize
            ]);

            return [
                'success' => true,
                'message' => "备份成功，已清理 {$deletedCount} 个旧备份",
                'filename' => $fileName,
                'size' => $fileSize,
                'deleted_old' => $deletedCount
            ];

        } catch (Exception $e) {
            $this->updateLog($logId, 'failed', '', 0, $e->getMessage());
            return ['success' => false, 'message' => '备份失败: ' . $e->getMessage()];
        } finally {
            // 无论成功或失败，都清理本地临时 ZIP 文件
            if ($zipPath !== null && file_exists($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * 测试存储连接
     */
    public function testConnection(array $data): array {
        $storageType = sanitizeInput($data['storage_type'] ?? 'ftp');
        $credentials = $this->buildCredentials($storageType, $data);
        return StorageFactory::testConnection($storageType, $credentials);
    }

    /**
     * 获取备份日志
     */
    public function getLogs(?int $configId = null, int $limit = 50): array {
        try {
            if ($configId) {
                $stmt = $this->db->prepare(
                    "SELECT bl.*, bc.backup_name, bc.storage_type
                     FROM backup_logs bl
                     JOIN backup_configs bc ON bl.config_id = bc.id
                     WHERE bl.config_id = ?
                     ORDER BY bl.started_at DESC LIMIT ?"
                );
                $stmt->execute([$configId, $limit]);
            } else {
                $stmt = $this->db->prepare(
                    "SELECT bl.*, bc.backup_name, bc.storage_type
                     FROM backup_logs bl
                     JOIN backup_configs bc ON bl.config_id = bc.id
                     ORDER BY bl.started_at DESC LIMIT ?"
                );
                $stmt->execute([$limit]);
            }
            return ['success' => true, 'logs' => $stmt->fetchAll()];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ========== 私有方法 ==========

    private function getConfigById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM backup_configs WHERE id = ?");
        $stmt->execute([$id]);
        $config = $stmt->fetch();
        if (!$config) return null;
        $config['credentials'] = $this->decryptCredentials($config['credentials_json'] ?? '{}');
        return $config;
    }

    private function createLog(int $configId, string $status): int {
        $stmt = $this->db->prepare(
            "INSERT INTO backup_logs (config_id, status) VALUES (?, ?)"
        );
        $stmt->execute([$configId, $status]);
        return (int)$this->db->lastInsertId();
    }

    private function updateLog(int $logId, string $status, string $file = '', int $size = 0, string $error = ''): void {
        $sql = "UPDATE backup_logs SET status = ?, backup_file = ?, file_size = ?";
        $params = [$status, $file, $size];
        if ($status === 'success') {
            $sql .= ", completed_at = NOW()";
        }
        if ($status === 'failed' && $error) {
            $sql .= ", error_message = ?";
            $params[] = $error;
        }
        $sql .= " WHERE id = ?";
        $params[] = $logId;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * 创建完整备份 ZIP（数据库 + 附件 + 上传文件）
     */
    private function createBackupZip(): string {
        $backupDir = sys_get_temp_dir() . '/backup_' . time();
        mkdir($backupDir, 0755, true);

        $zipPath = "{$backupDir}/backup_" . date('Y-m-d_H-i-s') . ".zip";
        $zip = new ZipArchive();
        try {
            if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                throw new Exception('无法创建 ZIP 文件');
            }

            // 1. 数据库
            $dbBackup = $this->backupDatabase();
            $zip->addFromString('database.sql', $dbBackup);

            // 2. 附件目录
            $this->addDirectoryToZip($zip, ATTACHMENT_DIR, 'attachments');

            // 3. 上传目录（含笔记图片等）
            $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/';
            if (is_dir($uploadDir)) {
                $this->addDirectoryToZip($zip, $uploadDir, 'uploads');
            }

            $zip->close();

            // 将ZIP文件移到临时目录外，避免清理时被删除
            $finalZipPath = sys_get_temp_dir() . '/' . basename($zipPath);
            rename($zipPath, $finalZipPath);

            return $finalZipPath;
        } finally {
            if (isset($zip) && $zip instanceof ZipArchive) {
                @$zip->close();
            }
            if (isset($backupDir) && is_dir($backupDir)) {
                $this->removeDirectory($backupDir);
            }
        }
    }

    private function backupDatabase(): string {
        $output = "-- MySQL Database Backup - " . date('Y-m-d H:i:s') . "\n\n";
        $tables = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
                continue;
            }
            $result = $this->db->query("SHOW CREATE TABLE `$table`");
            $row = $result->fetch(PDO::FETCH_NUM);
            $output .= "\n-- Table: {$table}\n";
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $output .= $row[1] . ";\n\n";

            $data = $this->db->query("SELECT * FROM `$table`");
            while ($row = $data->fetch(PDO::FETCH_NUM)) {
                $output .= "INSERT INTO `$table` VALUES(";
                for ($j = 0; $j < count($row); $j++) {
                    $row[$j] = isset($row[$j]) ? $this->db->quote($row[$j]) : 'NULL';
                    $output .= ($j < count($row) - 1) ? $row[$j] . ',' : $row[$j];
                }
                $output .= ");\n";
            }
        }

        return $output;
    }

    private function addDirectoryToZip(ZipArchive $zip, string $dir, string $zipPath): void {
        if (!is_dir($dir)) return;

        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $filePath = "{$dir}/{$file}";
            $zipFilePath = "{$zipPath}/{$file}";

            if (is_dir($filePath)) {
                $zip->addEmptyDir($zipFilePath);
                $this->addDirectoryToZip($zip, $filePath, $zipFilePath);
            } else {
                $zip->addFile($filePath, $zipFilePath);
            }
        }
    }

    private function removeDirectory(string $dir): void {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * 应用保留策略：删除超过保留份数的旧备份
     */
    private function applyRetentionPolicy(array $config, StorageAdapter $storage, string $currentFile): int {
        $retentionCount = (int)($config['retention_count'] ?? 5);
        if ($retentionCount <= 0) return 0;

        $files = $storage->listFiles('/');
        // 仅对 backup_ 开头的文件应用保留策略
        $files = array_filter($files, fn($f) => strpos($f['name'] ?? '', 'backup_') === 0);
        $files = array_values($files);
        if (count($files) <= $retentionCount) return 0;

        // 按时间倒序排列，排除刚上传的最新文件
        usort($files, fn($a, $b) => ($b['time'] ?? 0) - ($a['time'] ?? 0));

        $deleted = 0;
        for ($i = $retentionCount; $i < count($files); $i++) {
            // 只删除 backup_ 开头的文件
            if (strpos($files[$i]['name'], 'backup_') === 0 && $files[$i]['name'] !== $currentFile) {
                $result = $storage->delete($files[$i]['name']);
                if ($result['success']) $deleted++;
            }
        }

        return $deleted;
    }

    private function updateConfigLastBackup(int $id, string $frequency): void {
        $next = $this->calculateNextBackup($frequency);
        $stmt = $this->db->prepare(
            "UPDATE backup_configs SET last_backup = NOW(), next_backup = ? WHERE id = ?"
        );
        $stmt->execute([$next, $id]);
    }

    private function calculateNextBackup(string $frequency): string {
        switch ($frequency) {
            case 'daily': return date('Y-m-d H:i:s', strtotime('+1 day'));
            case 'weekly': return date('Y-m-d H:i:s', strtotime('+1 week'));
            case 'monthly': return date('Y-m-d H:i:s', strtotime('+1 month'));
            default: return date('Y-m-d H:i:s', strtotime('+1 week'));
        }
    }

    private function buildCredentials(string $type, array $data): array {
        switch ($type) {
            case 'ftp':
                return [
                    'ftp_host' => $data['ftp_host'] ?? '',
                    'ftp_port' => $data['ftp_port'] ?? 21,
                    'ftp_username' => $data['ftp_username'] ?? '',
                    'ftp_password' => $data['ftp_password'] ?? '',
                    'ftp_path' => $data['ftp_path'] ?? '/',
                ];
            case 'sftp':
                return [
                    'sftp_host' => $data['sftp_host'] ?? '',
                    'sftp_port' => $data['sftp_port'] ?? 22,
                    'sftp_username' => $data['sftp_username'] ?? '',
                    'sftp_password' => $data['sftp_password'] ?? '',
                    'sftp_path' => $data['sftp_path'] ?? '/',
                ];
            case 'aliyun_oss':
                return [
                    'oss_access_key_id' => $data['oss_access_key_id'] ?? '',
                    'oss_access_key_secret' => $data['oss_access_key_secret'] ?? '',
                    'oss_bucket' => $data['oss_bucket'] ?? '',
                    'oss_region' => $data['oss_region'] ?? '',
                    'oss_endpoint' => $data['oss_endpoint'] ?? '',
                    'oss_prefix' => $data['oss_prefix'] ?? 'backup/',
                ];
            case 'tencent_cos':
                return [
                    'cos_secret_id' => $data['cos_secret_id'] ?? '',
                    'cos_secret_key' => $data['cos_secret_key'] ?? '',
                    'cos_bucket' => $data['cos_bucket'] ?? '',
                    'cos_region' => $data['cos_region'] ?? '',
                    'cos_prefix' => $data['cos_prefix'] ?? 'backup/',
                ];
            case 's3':
                return [
                    's3_endpoint' => $data['s3_endpoint'] ?? '',
                    's3_access_key' => $data['s3_access_key'] ?? '',
                    's3_secret_key' => $data['s3_secret_key'] ?? '',
                    's3_bucket' => $data['s3_bucket'] ?? '',
                    's3_region' => $data['s3_region'] ?? 'us-east-1',
                    's3_prefix' => $data['s3_prefix'] ?? 'backup/',
                    's3_ssl_verify' => $data['s3_ssl_verify'] ?? true,
                ];
            default:
                return [];
        }
    }

    private function decryptCredentials(string $json): array {
        if (empty($json)) return [];
        try {
            $decrypted = decryptData($json);
            return json_decode($decrypted, true) ?? [];
        } catch (Exception $e) {
            return [];
        }
    }

    private function maskCredentials(array $credentials): array {
        $masked = [];
        foreach ($credentials as $key => $value) {
            if (strpos($key, 'password') !== false || strpos($key, 'secret') !== false || strpos($key, 'key') !== false) {
                $masked[$key] = mb_strlen($value) > 4 ? str_repeat('*', mb_strlen($value) - 4) . mb_substr($value, -4) : '****';
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }

    private function hasCredentialFields(string $type, array $data): bool {
        $credFields = [
            'ftp' => ['ftp_host', 'ftp_username', 'ftp_password'],
            'sftp' => ['sftp_host', 'sftp_username', 'sftp_password'],
            'aliyun_oss' => ['oss_access_key_id', 'oss_access_key_secret', 'oss_bucket'],
            'tencent_cos' => ['cos_secret_id', 'cos_secret_key', 'cos_bucket'],
            's3' => ['s3_access_key', 's3_secret_key', 's3_bucket'],
        ];
        $fields = $credFields[$type] ?? [];
        foreach ($fields as $field) {
            if (!empty($data[$field])) return true;
        }
        return false;
    }
}
