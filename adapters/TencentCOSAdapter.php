<?php
require_once __DIR__ . '/../StorageAdapter.php';

/**
 * 腾讯云 COS 存储适配器
 * 需要安装: composer require qcloud/cos-sdk-v5
 */
class TencentCOSAdapter implements StorageAdapter {
    private string $secretId;
    private string $secretKey;
    private string $bucket;
    private string $region;        // 例如: ap-beijing
    private string $prefix;       // 存储路径前缀
    private $cosClient = null;

    public function __construct(array $config) {
        $this->secretId = $config['secret_id'];
        $this->secretKey = $config['secret_key'];
        $this->bucket = $config['bucket'];
        $this->region = $config['region'];
        $this->prefix = rtrim($config['prefix'] ?? 'backup/', '/');
    }

    public static function getType(): string {
        return 'tencent_cos';
    }

    public static function getName(): string {
        return '腾讯云 COS';
    }

    private function getClient() {
        if ($this->cosClient !== null) return $this->cosClient;

        if (!class_exists('Qcloud\Cos\Client')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            } else {
                throw new Exception('腾讯云 COS SDK 未安装，请运行: composer require qcloud/cos-sdk-v5');
            }
        }

        $this->cosClient = new Qcloud\Cos\Client([
            'region' => $this->region,
            'credentials' => [
                'secretId' => $this->secretId,
                'secretKey' => $this->secretKey,
            ]
        ]);

        return $this->cosClient;
    }

    public function testConnection(): array {
        try {
            $this->getClient()->listObjects(['Bucket' => $this->bucket, 'MaxKeys' => 1]);
            return ['success' => true, 'message' => '腾讯云 COS 连接测试成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'COS 连接失败: ' . $e->getMessage()];
        }
    }

    public function upload(string $localFile, string $remoteName): array {
        try {
            $key = $this->prefix . $remoteName;
            $fp = fopen($localFile, 'r');
            try {
                $this->getClient()->putObject([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'Body' => $fp,
                ]);
            } finally {
                fclose($fp);
            }
            return ['success' => true, 'message' => '上传成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'COS 上传失败: ' . $e->getMessage()];
        }
    }

    public function listFiles(string $path = '/'): array {
        try {
            $prefix = $this->prefix;
            if (!empty($path) && $path !== '/') {
                $prefix = $this->prefix . ltrim($path, '/');
            }
            $result = $this->getClient()->listObjects([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix
            ]);

            $resultArr = [];
            foreach (($result['Contents'] ?? []) as $item) {
                $resultArr[] = [
                    'name' => basename($item['Key']),
                    'size' => $item['Size'] ?? 0,
                    'time' => isset($item['LastModified']) ? strtotime($item['LastModified']) : 0
                ];
            }
            return $resultArr;
        } catch (Exception $e) {
            return [];
        }
    }

    public function delete(string $remoteName): array {
        try {
            $key = $this->prefix . $remoteName;
            $this->getClient()->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key
            ]);
            return ['success' => true, 'message' => '删除成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'COS 删除失败: ' . $e->getMessage()];
        }
    }
}
