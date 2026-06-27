<?php
require_once __DIR__ . '/../StorageAdapter.php';

/**
 * S3 通用存储适配器
 * 支持 AWS S3、MinIO、DigitalOcean Spaces、七牛云等 S3 兼容存储
 * 需要安装: composer require aws/aws-sdk-php
 */
class S3StorageAdapter implements StorageAdapter {
    private string $endpoint;
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $region;
    private string $prefix;       // 存储路径前缀
    private string $sslVerify;     // 是否验证 SSL
    private $s3Client = null;

    public function __construct(array $config) {
        $this->endpoint = rtrim($config['endpoint'] ?? '', '/');
        $this->accessKey = $config['access_key'];
        $this->secretKey = $config['secret_key'];
        $this->bucket = $config['bucket'];
        $this->region = $config['region'] ?? 'us-east-1';
        $this->prefix = rtrim($config['prefix'] ?? 'backup/', '/');
        $this->sslVerify = $config['ssl_verify'] ?? true;
    }

    public static function getType(): string {
        return 's3';
    }

    public static function getName(): string {
        return 'S3 兼容存储';
    }

    private function getClient() {
        if ($this->s3Client !== null) return $this->s3Client;

        if (!class_exists('Aws\S3\S3Client')) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
            } else {
                throw new Exception('AWS SDK 未安装，请运行: composer require aws/aws-sdk-php');
            }
        }

        $config = [
            'version' => 'latest',
            'region' => $this->region,
            'credentials' => [
                'key' => $this->accessKey,
                'secret' => $this->secretKey,
            ],
        ];

        if (!empty($this->endpoint)) {
            $config['endpoint'] = $this->endpoint;
            // 非 AWS 的 S3 兼容存储（如 MinIO）需要路径风格访问
            if (strpos($this->endpoint, 'amazonaws.com') === false) {
                $config['use_path_style_endpoint'] = true;
            }
        }

        if (!$this->sslVerify) {
            $config['http'] = ['verify' => false];
        }

        $this->s3Client = new Aws\S3\S3Client($config);
        return $this->s3Client;
    }

    public function testConnection(): array {
        try {
            $this->getClient()->headBucket(['Bucket' => $this->bucket]);
            return ['success' => true, 'message' => 'S3 连接测试成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'S3 连接失败: ' . $e->getMessage()];
        }
    }

    public function upload(string $localFile, string $remoteName): array {
        try {
            $key = $this->prefix . $remoteName;
            $this->getClient()->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $localFile,
            ]);
            return ['success' => true, 'message' => '上传成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'S3 上传失败: ' . $e->getMessage()];
        }
    }

    public function listFiles(string $path = '/'): array {
        try {
            $prefix = $this->prefix;
            if (!empty($path) && $path !== '/') {
                $prefix = $this->prefix . ltrim($path, '/');
            }

            $result = $this->getClient()->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix
            ]);

            $files = [];
            foreach (($result['Contents'] ?? []) as $item) {
                $files[] = [
                    'name' => basename($item['Key']),
                    'size' => $item['Size'] ?? 0,
                    'time' => isset($item['LastModified']) ? strtotime($item['LastModified']) : 0
                ];
            }
            return $files;
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
            return ['success' => false, 'message' => 'S3 删除失败: ' . $e->getMessage()];
        }
    }
}
