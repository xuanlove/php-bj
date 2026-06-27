<?php
/**
 * 存储适配器接口
 * 所有备份存储后端必须实现此接口
 */
interface StorageAdapter {
    /**
     * 测试连接是否可用
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection(): array;

    /**
     * 上传文件到存储
     * @param string $localFile 本地文件路径
     * @param string $remoteName 远程文件名
     * @return array ['success' => bool, 'message' => string]
     */
    public function upload(string $localFile, string $remoteName): array;

    /**
     * 列出远程存储中的备份文件
     * @param string $path 远程路径
     * @return array 文件列表 [['name' => string, 'size' => int, 'time' => int], ...]
     */
    public function listFiles(string $path = '/'): array;

    /**
     * 删除远程文件
     * @param string $remoteName 远程文件名
     * @return array ['success' => bool, 'message' => string]
     */
    public function delete(string $remoteName): array;

    /**
     * 获取适配器类型标识
     * @return string
     */
    public static function getType(): string;

    /**
     * 获取适配器显示名称
     * @return string
     */
    public static function getName(): string;
}
