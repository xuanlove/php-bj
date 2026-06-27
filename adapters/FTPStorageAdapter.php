<?php
require_once __DIR__ . '/../StorageAdapter.php';

/**
 * FTP 存储适配器
 */
class FTPStorageAdapter implements StorageAdapter {
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $path;
    private $conn = null;

    public function __construct(array $config) {
        $this->host = $config['host'];
        $this->port = (int)($config['port'] ?? 21);
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->path = rtrim($config['path'] ?? '/', '/');
    }

    public static function getType(): string {
        return 'ftp';
    }

    public static function getName(): string {
        return 'FTP 服务器';
    }

    private function connect(): void {
        if ($this->conn !== null) return;

        $this->conn = ftp_connect($this->host, $this->port, 30);
        if (!$this->conn) {
            throw new Exception("无法连接到 FTP 服务器 {$this->host}:{$this->port}");
        }

        $login = ftp_login($this->conn, $this->username, $this->password);
        if (!$login) {
            ftp_close($this->conn);
            $this->conn = null;
            throw new Exception('FTP 登录失败');
        }

        ftp_pasv($this->conn, true);

        // 切换到目标目录
        if (!empty($this->path) && $this->path !== '/') {
            if (!@ftp_chdir($this->conn, $this->path)) {
                $this->ensureDir($this->path);
                ftp_chdir($this->conn, $this->path);
            }
        }
    }

    private function ensureDir(string $path): void {
        $dirs = explode('/', trim($path, '/'));
        $current = '';
        foreach ($dirs as $dir) {
            $current .= '/' . $dir;
            if (!@ftp_chdir($this->conn, $current)) {
                ftp_mkdir($this->conn, $dir);
                ftp_chdir($this->conn, $dir);
            }
        }
    }

    public function testConnection(): array {
        try {
            $this->connect();
            return ['success' => true, 'message' => 'FTP 连接测试成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            if ($this->conn) {
                ftp_close($this->conn);
                $this->conn = null;
            }
        }
    }

    public function upload(string $localFile, string $remoteName): array {
        try {
            $this->connect();
            $upload = ftp_put($this->conn, $remoteName, $localFile, FTP_BINARY);
            if (!$upload) {
                throw new Exception('FTP 文件上传失败');
            }
            return ['success' => true, 'message' => '上传成功'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            if ($this->conn) {
                ftp_close($this->conn);
                $this->conn = null;
            }
        }
    }

    public function listFiles(string $path = '/'): array {
        try {
            $this->connect();
            $targetPath = !empty($path) && $path !== '/' ? $path : $this->path;
            if ($targetPath !== '/' && !@ftp_chdir($this->conn, $targetPath)) {
                return [];
            }
            $files = ftp_nlist($this->conn, '.');
            if ($files === false) return [];

            $result = [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $name = basename($file);
                $size = ftp_size($this->conn, $name);
                $mtime = @ftp_mdtm($this->conn, $name);
                $result[] = [
                    'name' => $name,
                    'size' => $size > -1 ? $size : 0,
                    'time' => $mtime > 0 ? $mtime : 0
                ];
            }
            return $result;
        } catch (Exception $e) {
            return [];
        } finally {
            if ($this->conn) {
                ftp_close($this->conn);
                $this->conn = null;
            }
        }
    }

    public function delete(string $remoteName): array {
        try {
            $this->connect();
            $ok = ftp_delete($this->conn, $remoteName);
            return $ok
                ? ['success' => true, 'message' => '删除成功']
                : ['success' => false, 'message' => '删除失败'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            if ($this->conn) {
                ftp_close($this->conn);
                $this->conn = null;
            }
        }
    }
}
