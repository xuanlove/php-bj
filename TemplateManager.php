<?php
/**
 * UI 模板管理器
 * 
 * 功能：
 * - 扫描 templates/ 目录获取所有可用模板
 * - 读取模板 config.json 获取元数据
 * - 提供服务端模板 CSS
 * - 管理默认模板设置
 */

require_once __DIR__ . '/conn.php';

class TemplateManager {
    private $templatesDir;
    
    public function __construct() {
        $this->templatesDir = __DIR__ . '/templates/';
    }
    
    /**
     * 获取所有可用模板列表
     * @return array
     */
    public function getTemplates(): array {
        $templates = [];
        
        if (!is_dir($this->templatesDir)) {
            return $templates;
        }
        
        $dirs = array_filter(glob($this->templatesDir . '*'), 'is_dir');
        
        foreach ($dirs as $dir) {
            $dirname = basename($dir);
            $configFile = $dir . '/config.json';
            $cssFile = $dir . '/theme.css';
            
            if (!file_exists($cssFile)) {
                continue; // 没有CSS文件的跳过
            }
            
            $config = $this->readConfig($configFile);
            
            $templates[] = [
                'id' => $dirname,
                'name' => $config['name'] ?? $dirname,
                'description' => $config['description'] ?? '',
                'author' => $config['author'] ?? 'Unknown',
                'version' => $config['version'] ?? '1.0',
                'colors' => $config['colors'] ?? [],
                'tags' => $config['tags'] ?? [],
                'has_css' => true,
            ];
        }
        
        return $templates;
    }
    
    /**
     * 读取模板配置
     */
    private function readConfig(string $configFile): array {
        if (!file_exists($configFile)) {
            return [];
        }
        
        $content = file_get_contents($configFile);
        $config = json_decode($content, true);
        return is_array($config) ? $config : [];
    }
    
    /**
     * 获取模板 CSS 内容
     * @param string $templateId
     * @return array
     */
    public function getTemplateCSS(string $templateId): array {
        // 防止路径遍历攻击
        if (preg_match('/[\.\/\\\\]/', $templateId)) {
            return ['success' => false, 'message' => '无效的模板ID'];
        }

        $cssFile = $this->templatesDir . $templateId . '/theme.css';
        
        if (!file_exists($cssFile)) {
            // 返回默认模板
            $cssFile = $this->templatesDir . 'default/theme.css';
            if (!file_exists($cssFile)) {
                return ['success' => false, 'message' => '模板CSS文件不存在'];
            }
        }
        
        $css = file_get_contents($cssFile);
        
        return [
            'success' => true,
            'template_id' => $templateId,
            'css' => $css,
            'content_type' => 'text/css'
        ];
    }
    
    /**
     * 验证模板是否存在
     */
    public function templateExists(string $templateId): bool {
        if (preg_match('/[\.\/\\\\]/', $templateId)) return false;
        return file_exists($this->templatesDir . $templateId . '/theme.css');
    }
    
    /**
     * 获取系统默认模板
     * @return string
     */
    public function getDefaultTemplate(): string {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'default_template'"
            );
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $template = $result['setting_value'] ?? 'default';
            
            // 确保默认模板存在
            if (!$this->templateExists($template)) {
                return 'default';
            }
            
            return $template;
        } catch (Exception $e) {
            error_log("获取默认模板失败: " . $e->getMessage());
            return 'default';
        }
    }
    
    /**
     * 获取用户当前模板（优先用户设置 → 系统默认）
     * @param int $userId
     * @return string
     */
    public function getUserTemplate(int $userId): string {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "SELECT template FROM user_settings WHERE user_id = ?"
            );
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && !empty($result['template'])) {
                $template = $result['template'];
                if ($this->templateExists($template)) {
                    return $template;
                }
            }
            
            // 回退到系统默认
            return $this->getDefaultTemplate();
            
        } catch (Exception $e) {
            error_log("获取用户模板失败: " . $e->getMessage());
            return $this->getDefaultTemplate();
        }
    }
}
