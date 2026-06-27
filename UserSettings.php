<?php
/**
 * 用户设置管理类
 * 
 * 功能：
 * - 用户偏好设置
 * - 主题设置
 * - 编辑器设置
 * - 通知设置
 */

require_once 'config.php';

class UserSettings {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * 获取用户设置
     * 
     * @param int $user_id 用户ID
     * @return array
     */
    public function getSettings($user_id) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM user_settings WHERE user_id = ?"
            );
            $stmt->execute([$user_id]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$settings) {
                // 创建默认设置
                $this->createDefaultSettings($user_id);
                return $this->getSettings($user_id);
            }
            
            return [
                'success' => true,
                'settings' => $settings,
                'message' => '获取设置成功'
            ];
            
        } catch (Exception $e) {
            error_log("获取用户设置失败: " . $e->getMessage());
            return ['success' => false, 'message' => '获取设置失败'];
        }
    }
    
    /**
     * 更新用户设置
     * 
     * @param int $user_id 用户ID
     * @param array $settings 设置数据
     * @return array
     */
    public function updateSettings($user_id, $settings) {
        try {
            // 允许更新的字段
            $allowedFields = [
                'theme', 'accent_color', 'editor_font_size', 'editor_font_family', 
                'editor_theme', 'editor_tab_size', 'auto_save', 
                'auto_save_interval', 'email_notifications', 
                'language', 'timezone', 'template'
            ];
            
            $updates = [];
            $values = [];
            
            foreach ($settings as $key => $value) {
                if (in_array($key, $allowedFields)) {
                    $updates[] = "`$key` = ?";
                    $values[] = $value;
                }
            }
            
            if (empty($updates)) {
                return ['success' => false, 'message' => '没有要更新的设置'];
            }
            
            $values[] = $user_id;
            
            $sql = "UPDATE user_settings SET " . implode(', ', $updates) . " WHERE user_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);
            
            logSecurityEvent('settings_updated', [
                'user_id' => $user_id,
                'fields' => implode(', ', array_keys($settings))
            ]);
            
            return ['success' => true, 'message' => '设置更新成功'];
            
        } catch (Exception $e) {
            error_log("更新用户设置失败: " . $e->getMessage());
            return ['success' => false, 'message' => '更新设置失败'];
        }
    }
    
    /**
     * 创建默认设置
     * 
     * @param int $user_id 用户ID
     */
    private function createDefaultSettings($user_id) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO user_settings 
                 (user_id, theme, editor_font_size, editor_font_family, 
                  editor_theme, editor_tab_size, accent_color, auto_save, auto_save_interval, email_notifications, 
                  language, timezone, template) 
                  VALUES (?, 'dark', 14, 'Source Code Pro', 'default', 
                          4, '#d4af37', TRUE, 30, TRUE, 'zh-CN', 'Asia/Shanghai', 'default')"
            );
            $stmt->execute([$user_id]);
            
        } catch (Exception $e) {
            error_log("创建默认设置失败: " . $e->getMessage());
        }
    }
    
    /**
     * 重置设置为默认值
     * 
     * @param int $user_id 用户ID
     * @return array
     */
    public function resetToDefault($user_id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM user_settings WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            $this->createDefaultSettings($user_id);
            
            logSecurityEvent('settings_reset', ['user_id' => $user_id]);
            
            return ['success' => true, 'message' => '设置已重置为默认值'];
            
        } catch (Exception $e) {
            error_log("重置设置失败: " . $e->getMessage());
            return ['success' => false, 'message' => '重置设置失败'];
        }
    }
    
    /**
     * 获取单个设置项
     * 
     * @param int $user_id 用户ID
     * @param string $key 设置键名
     * @return mixed
     */
    public function getSetting($user_id, $key) {
        // 白名单验证，防止SQL注入
        $allowedFields = [
            'theme', 'accent_color', 'editor_font_size', 'editor_font_family', 
            'editor_theme', 'editor_tab_size', 'auto_save', 'auto_save_interval',
            'email_notifications', 'language', 'timezone', 'template'
        ];
        
        if (!in_array($key, $allowedFields)) {
            return null;
        }
        
        try {
            $stmt = $this->db->prepare(
                "SELECT `$key` FROM user_settings WHERE user_id = ?"
            );
            $stmt->execute([$user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result[$key] : null;
            
        } catch (Exception $e) {
            error_log("获取设置项失败: " . $e->getMessage());
            return null;
        }
    }
}
?>