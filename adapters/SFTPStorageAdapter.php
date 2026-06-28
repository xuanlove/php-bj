<?php
require_once __DIR__ . '/../StorageAdapter.php';

/**
 * SFTP 存储适配器 (需 PHP ssh2 扩展)
 */
class SFTPStorageAdapter implements StorageAdapter {
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $path;
    private $sftp = null;
    private $sshConn = null;

    public function __construct(array $config) {
        $this->host = $config['host'];
        $this->port = (int)($config['port'] ?? 22);
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->path = rtrim($config['path'] ?? '/', '/');
    }

    public static function getType(): string {
        return 'sftp';
    }

    public static function getName(): string {
        return 'SFTP 服务器';
    }

    private function connect() {
        if ($this->sftp !== null) return;

        if (!extension_loaded('ssh2')) {
            throw new Exception('SFTP 需要 PHP ssh2 扩展（php_ssh2.dll）');
        }

        $this->sshConn = @ssh2_connect($this->host, $this->port, ['timeout' => 30]);
        if (!$this->sshConn) {
            throw new Exception("无法连接到 SFTP 服务器 {$this->host}:{$this->port}");
        }

        if (!@ssh2_auth_password($this->sshConn, $this->username, $this->password)) {
            throw new Exception('SFTP 登录失败，请检查用户名和密码');
        }

        $this->sftp = @ssh2_sftp($this->sshConn);
        if (!$this->sftp) {
            throw new Exception('无法建立 SFTP 会话');
        }

        // 确保目标目录存在
        $this->ensureDir($this->path);
    }

    private function ensureDir(string $path): void {
        $dirs = explode('/', trim($path, '/'));
        $current = '';
        foreach ($dirs as $dir) {
            if (empty($dir)) continue;
            $current .= '/' . $dir;
            $stat = @ssh2_sftp_stat($this->sftp, $current);
            if (!$stat) {
                @ssh2_sftp_mkdir($this->sftp, $current, 0755);
            }
        }
    }

    public function testConnection(): array {
        try {
            $this->connect();
            return ['success' => true, 'message' => 'SFTP 连接测试成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            $this->sftp = null;
            $this->sshConn = null;
        }
    }

    public function upload(string $localFile, string $remoteName): array {
        if (str_contains($remoteName, '..') || str_starts_with($remoteName, '/')) {
            return ['success' => false, 'message' => '非法的远程文件名'];
        }
        try {
            $this->connect();
            $remotePath = rtrim($this->path, '/') . '/' . $remoteName;
            $ok = @ssh2_scp_send($this->sshConn, $localFile, $remotePath, 0644);
            if (!$ok) {
                throw new Exception('SFTP 文件上传失败');
            }
            return ['success' => true, 'message' => '上传成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            $this->sftp = null;
            $this->sshConn = null;
        }
    }

    public function listFiles(string $path = '/'): array {
        try {
            $this->connect();
            $targetPath = !empty($path) && $path !== '/' ? $path : $this->path;
            $handle = @opendir("ssh2.sftp://{$this->sftp}" . $targetPath);
            if (!$handle) return [];

            $result = [];
            while (($file = readdir($handle)) !== false) {
                if ($file === '.' || $file === '..') continue;
                $fullPath = rtrim($targetPath, '/') . '/' . $file;
                $stat = @ssh2_sftp_stat($this->sftp, $fullPath);
                $result[] = [
                    'name' => $file,
                    'size' => $stat ? ($stat['size'] ?? 0) : 0,
                    'time' => $stat ? ($stat['mtime'] ?? 0) : 0
                ];
            }
            closedir($handle);
            return $result;
        } catch (Exception $e) {
            return [];
        } finally {
            $this->sftp = null;
            $this->sshConn = null;
        }
    }

    public function delete(string $remoteName): array {
        if (str_contains($remoteName, '..') || str_starts_with($remoteName, '/')) {
            return ['success' => false, 'message' => '非法的远程文件名'];
        }
        try {
            $this->connect();
            $remotePath = rtrim($this->path, '/') . '/' . $remoteName;
            $ok = @ssh2_sftp_unlink($this->sftp, $remotePath);
            return $ok
                ? ['success' => true, 'message' => '删除成功']
                : ['success' => false, 'message' => '删除失败'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            $this->sftp = null;
            $this->sshConn = null;
        }
    }
}