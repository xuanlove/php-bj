<?php
require_once __DIR__ . '/../StorageAdapter.php';
require_once __DIR__ . '/FTPStorageAdapter.php';
require_once __DIR__ . '/SFTPStorageAdapter.php';
require_once __DIR__ . '/AliyunOSSAdapter.php';
require_once __DIR__ . '/TencentCOSAdapter.php';
require_once __DIR__ . '/S3StorageAdapter.php';

/**
 * 存储适配器工厂
 * 根据配置类型创建对应的存储适配器实例
 */
class StorageFactory {

    /**
     * 获取所有支持的存储类型
     * @return array [type => name]
     */
    public static function getSupportedTypes(): array {
        return [
            'ftp' => FTPStorageAdapter::getName(),
            'sftp' => SFTPStorageAdapter::getName(),
            'aliyun_oss' => AliyunOSSAdapter::getName(),
            'tencent_cos' => TencentCOSAdapter::getName(),
            's3' => S3StorageAdapter::getName(),
        ];
    }

    /**
     * 创建存储适配器实例
     * @param string $type 存储类型
     * @param array $credentials 凭证配置
     * @return StorageAdapter
     * @throws Exception
     */
    public static function create(string $type, array $credentials): StorageAdapter {
        switch ($type) {
            case 'ftp':
                return new FTPStorageAdapter([
                    'host' => $credentials['ftp_host'] ?? '',
                    'port' => $credentials['ftp_port'] ?? 21,
                    'username' => $credentials['ftp_username'] ?? '',
                    'password' => $credentials['ftp_password'] ?? '',
                    'path' => $credentials['ftp_path'] ?? '/',
                ]);

            case 'sftp':
                return new SFTPStorageAdapter([
                    'host' => $credentials['sftp_host'] ?? '',
                    'port' => $credentials['sftp_port'] ?? 22,
                    'username' => $credentials['sftp_username'] ?? '',
                    'password' => $credentials['sftp_password'] ?? '',
                    'path' => $credentials['sftp_path'] ?? '/',
                ]);

            case 'aliyun_oss':
                return new AliyunOSSAdapter([
                    'access_key_id' => $credentials['oss_access_key_id'] ?? '',
                    'access_key_secret' => $credentials['oss_access_key_secret'] ?? '',
                    'bucket' => $credentials['oss_bucket'] ?? '',
                    'region' => $credentials['oss_region'] ?? '',
                    'endpoint' => $credentials['oss_endpoint'] ?? '',
                    'prefix' => $credentials['oss_prefix'] ?? 'backup/',
                ]);

            case 'tencent_cos':
                return new TencentCOSAdapter([
                    'secret_id' => $credentials['cos_secret_id'] ?? '',
                    'secret_key' => $credentials['cos_secret_key'] ?? '',
                    'bucket' => $credentials['cos_bucket'] ?? '',
                    'region' => $credentials['cos_region'] ?? '',
                    'prefix' => $credentials['cos_prefix'] ?? 'backup/',
                ]);

            case 's3':
                return new S3StorageAdapter([
                    'endpoint' => $credentials['s3_endpoint'] ?? '',
                    'access_key' => $credentials['s3_access_key'] ?? '',
                    'secret_key' => $credentials['s3_secret_key'] ?? '',
                    'bucket' => $credentials['s3_bucket'] ?? '',
                    'region' => $credentials['s3_region'] ?? 'us-east-1',
                    'prefix' => $credentials['s3_prefix'] ?? 'backup/',
                    'ssl_verify' => !empty($credentials['s3_ssl_verify']),
                ]);

            default:
                throw new Exception("不支持的存储类型: {$type}");
        }
    }

    /**
     * 测试指定类型的连接
     * @param string $type 存储类型
     * @param array $credentials 凭证配置
     * @return array ['success' => bool, 'message' => string]
     */
    public static function testConnection(string $type, array $credentials): array {
        try {
            $adapter = self::create($type, $credentials);
            return $adapter->testConnection();
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
