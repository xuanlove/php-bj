<?php
require_once __DIR__ . '/../StorageAdapter.php';

/**
 * 阿里云 OSS 存储适配器
 * 需要安装: composer require aliyuncs/oss-sdk-php
 */
class AliyunOSSAdapter implements StorageAdapter {
    private string $accessKeyId;
    private string $accessKeySecret;
    private string $bucket;
    private string $region;        // 例如: oss-cn-hangzhou
    private string $endpoint;       // 可选，自定义 endpoint
    private string $prefix;        // 存储路径前缀
    private $ossClient = null;

    public function __construct(array $config) {
        $this->accessKeyId = $config['access_key_id'];
        $this->accessKeySecret = $config['access_key_secret'];
        $this->bucket = $config['bucket'];
        $this->region = $config['region'] ?? '';
        $this->endpoint = $config['endpoint'] ?? '';
        $this->prefix = rtrim($config['prefix'] ?? 'backup/', '/');
    }

    public static function getType(): string {
        return 'aliyun_oss';
    }

    public static function getName(): string {
        return '阿里云 OSS';
    }

    private function getClient() {
        if ($this->ossClient !== null) return $this->ossClient;

        if (!class_exists('OSS\OssClient')) {
            // 尝试通过 composer 自动加载
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            } else {
                throw new Exception('阿里云 OSS SDK 未安装，请运行: composer require aliyuncs/oss-sdk-php');
            }
        }

        $endpoint = $this->endpoint;
        if (empty($endpoint) && !empty($this->region)) {
            // 根据用户配置的 Region 自动生成 Endpoint
            $endpoint = "https://{$this->region}.aliyuncs.com";
        }
        if (empty($endpoint)) {
            // 兼容：既没设置 endpoint 也没设置 region 的情况
            $endpoint = "https://oss-cn-hangzhou.aliyuncs.com";
        }

        $this->ossClient = new OSS\OssClient(
            $this->accessKeyId,
            $this->accessKeySecret,
            $endpoint
        );

        return $this->ossClient;
    }

    public function testConnection(): array {
        try {
            $client = $this->getClient();
            $client->getBucketInfo($this->bucket);
            return ['success' => true, 'message' => '阿里云 OSS 连接测试成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'OSS 连接失败: ' . $e->getMessage()];
        }
    }

    public function upload(string $localFile, string $remoteName): array {
        try {
            $client = $this->getClient();
            $objectName = $this->prefix . $remoteName;
            $client->putObject($this->bucket, $objectName, fopen($localFile, 'r'));
            return ['success' => true, 'message' => '上传成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'OSS 上传失败: ' . $e->getMessage()];
        }
    }

    public function listFiles(string $path = '/'): array {
        try {
            $client = $this->getClient();
            $prefix = $this->prefix;
            if (!empty($path) && $path !== '/') {
                $prefix = $this->prefix . ltrim($path, '/');
            }
            $objectListing = $client->listObjects($this->bucket, ['prefix' => $prefix]);

            $result = [];
            $objectList = $objectListing->getObjectList();
            foreach ($objectList as $obj) {
                $result[] = [
                    'name' => basename($obj->getKey()),
                    'size' => $obj->getSize(),
                    'time' => strtotime($obj->getLastModified())
                ];
            }
            return $result;
        } catch (Exception $e) {
            return [];
        }
    }

    public function delete(string $remoteName): array {
        try {
            $client = $this->getClient();
            $objectName = $this->prefix . $remoteName;
            $client->deleteObject($this->bucket, $objectName);
            return ['success' => true, 'message' => '删除成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'OSS 删除失败: ' . $e->getMessage()];
        }
    }
}
