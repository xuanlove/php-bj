<?php
require_once 'config.php';

class AIService {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    // 获取系统设置
    private function getSetting($key) {
        $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        if (!$result) return null;
        $value = $result['setting_value'];
        $encryptedKeys = ['chatgpt_api_key', 'doubao_api_key', 'claude_api_key', 'custom_openai_api_key'];
        if (in_array($key, $encryptedKeys) && !empty($value)) {
            // 检查是否已经是加密格式（以特定前缀或长度判断）
            $isEncrypted = strlen($value) > 32 && preg_match('/^[A-Za-z0-9+\/]+=*$/', $value);
            if ($isEncrypted) {
                try {
                    $decrypted = decryptData($value);
                    if ($decrypted !== false) return $decrypted;
                } catch (Exception $e) {
                    // 解密失败说明可能是旧版未加密的值，直接返回
                }
            }
            // 非加密格式直接返回明文（兼容旧数据）
        }
        return $value;
    }
    
    // ChatGPT API调用
    private function callChatGPT($prompt, $content) {
        $api_key = $this->getSetting('chatgpt_api_key');
        $api_url = $this->getSetting('chatgpt_api_url');
        
        if (empty($api_key)) {
            return ['success' => false, 'message' => 'ChatGPT API密钥未配置'];
        }
        
        $data = [
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $content]
            ],
            'temperature' => 0.7
        ];
        
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $result = json_decode($response, true);
            return [
                'success' => true,
                'content' => $result['choices'][0]['message']['content'] ?? ''
            ];
        }
        
        return ['success' => false, 'message' => 'ChatGPT API调用失败', 'response' => $response];
    }
    
    // 豆包API调用
    private function callDoubao($prompt, $content) {
        $api_key = $this->getSetting('doubao_api_key');
        $api_url = $this->getSetting('doubao_api_url');
        
        if (empty($api_key) || empty($api_url)) {
            return ['success' => false, 'message' => '豆包API未配置'];
        }
        
        $data = [
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $content]
            ]
        ];
        
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $result = json_decode($response, true);
            return [
                'success' => true,
                'content' => $result['choices'][0]['message']['content'] ?? ''
            ];
        }
        
        return ['success' => false, 'message' => '豆包API调用失败'];
    }
    
    // Claude API调用
    private function callClaude($prompt, $content) {
        $api_key = $this->getSetting('claude_api_key');
        $api_url = $this->getSetting('claude_api_url');
        
        if (empty($api_key)) {
            return ['success' => false, 'message' => 'Claude API密钥未配置'];
        }
        
        $data = [
            'model' => 'claude-3-sonnet-20240229',
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $prompt . "\n\n" . $content]
            ]
        ];
        
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $result = json_decode($response, true);
            return [
                'success' => true,
                'content' => $result['content'][0]['text'] ?? ''
            ];
        }
        
        return ['success' => false, 'message' => 'Claude API调用失败'];
    }
    
    // 自定义OpenAI格式接口调用
    private function callCustomOpenAI($prompt, $content) {
        $api_key = $this->getSetting('custom_openai_api_key');
        $api_url = $this->getSetting('custom_openai_api_url');
        $model = $this->getSetting('custom_openai_model');
        
        if (empty($api_url)) {
            return ['success' => false, 'message' => '自定义OpenAI接口URL未配置'];
        }
        
        if (empty($model)) {
            return ['success' => false, 'message' => '自定义OpenAI接口模型名称未配置'];
        }
        
        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $content]
            ],
            'temperature' => 0.7
        ];
        
        $headers = ['Content-Type: application/json'];
        if (!empty($api_key)) {
            $headers[] = 'Authorization: Bearer ' . $api_key;
        }
        
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $result = json_decode($response, true);
            return [
                'success' => true,
                'content' => $result['choices'][0]['message']['content'] ?? ''
            ];
        }
        
        return ['success' => false, 'message' => '自定义OpenAI接口调用失败', 'response' => $response];
    }
    
    // 校准笔记(修正语法、拼写等)
    public function proofread($note_id, $user_id, $provider = 'chatgpt') {
        // 获取笔记内容
        $stmt = $this->db->prepare("SELECT content FROM notes WHERE id = ? AND user_id = ?");
        $stmt->execute([$note_id, $user_id]);
        $note = $stmt->fetch();
        
        if (!$note) {
            return ['success' => false, 'message' => '笔记不存在'];
        }
        
        $prompt = "请帮我校准以下文本,修正语法错误、拼写错误和标点符号问题,保持原文的语气和风格,只返回修正后的内容:";
        
        // 调用相应的AI服务
        switch ($provider) {
            case 'chatgpt':
                $result = $this->callChatGPT($prompt, $note['content']);
                break;
            case 'doubao':
                $result = $this->callDoubao($prompt, $note['content']);
                break;
            case 'claude':
                $result = $this->callClaude($prompt, $note['content']);
                break;
            case 'custom_openai':
                $result = $this->callCustomOpenAI($prompt, $note['content']);
                break;
            default:
                return ['success' => false, 'message' => '不支持的AI提供商'];
        }
        
        if ($result['success']) {
            // 记录AI交互
            $this->logAIInteraction($note_id, $user_id, $provider, 'proofread', $note['content'], $result['content']);
        }
        
        return $result;
    }
    
    // 续写笔记
    public function continueWriting($note_id, $user_id, $provider = 'chatgpt') {
        $stmt = $this->db->prepare("SELECT title, content FROM notes WHERE id = ? AND user_id = ?");
        $stmt->execute([$note_id, $user_id]);
        $note = $stmt->fetch();
        
        if (!$note) {
            return ['success' => false, 'message' => '笔记不存在'];
        }
        
        $prompt = "根据以下笔记的标题和内容,请继续撰写后续内容,保持相同的风格和主题。标题:" . $note['title'] . "\n\n当前内容:";
        
        switch ($provider) {
            case 'chatgpt':
                $result = $this->callChatGPT($prompt, $note['content']);
                break;
            case 'doubao':
                $result = $this->callDoubao($prompt, $note['content']);
                break;
            case 'claude':
                $result = $this->callClaude($prompt, $note['content']);
                break;
            case 'custom_openai':
                $result = $this->callCustomOpenAI($prompt, $note['content']);
                break;
            default:
                return ['success' => false, 'message' => '不支持的AI提供商'];
        }
        
        if ($result['success']) {
            $this->logAIInteraction($note_id, $user_id, $provider, 'continue', $note['content'], $result['content']);
        }
        
        return $result;
    }
    
    // 总结笔记
    public function summarize($note_id, $user_id, $provider = 'chatgpt') {
        $stmt = $this->db->prepare("SELECT content FROM notes WHERE id = ? AND user_id = ?");
        $stmt->execute([$note_id, $user_id]);
        $note = $stmt->fetch();
        
        if (!$note) {
            return ['success' => false, 'message' => '笔记不存在'];
        }
        
        $prompt = "请为以下内容生成一个简洁的摘要,突出重点信息:";
        
        switch ($provider) {
            case 'chatgpt':
                $result = $this->callChatGPT($prompt, $note['content']);
                break;
            case 'doubao':
                $result = $this->callDoubao($prompt, $note['content']);
                break;
            case 'claude':
                $result = $this->callClaude($prompt, $note['content']);
                break;
            case 'custom_openai':
                $result = $this->callCustomOpenAI($prompt, $note['content']);
                break;
            default:
                return ['success' => false, 'message' => '不支持的AI提供商'];
        }
        
        if ($result['success']) {
            $this->logAIInteraction($note_id, $user_id, $provider, 'summarize', $note['content'], $result['content']);
        }
        
        return $result;
    }
    
    // 改写笔记
    public function rewrite($note_id, $user_id, $provider = 'chatgpt', $style = 'professional') {
        $stmt = $this->db->prepare("SELECT content FROM notes WHERE id = ? AND user_id = ?");
        $stmt->execute([$note_id, $user_id]);
        $note = $stmt->fetch();
        
        if (!$note) {
            return ['success' => false, 'message' => '笔记不存在'];
        }
        
        $style_prompts = [
            'professional' => '请用专业、正式的语言重写',
            'casual' => '请用轻松、随意的语言重写',
            'academic' => '请用学术、严谨的语言重写',
            'creative' => '请用创意、生动的语言重写'
        ];
        
        $prompt = ($style_prompts[$style] ?? $style_prompts['professional']) . "以下内容,保持核心信息不变:";
        
        switch ($provider) {
            case 'chatgpt':
                $result = $this->callChatGPT($prompt, $note['content']);
                break;
            case 'doubao':
                $result = $this->callDoubao($prompt, $note['content']);
                break;
            case 'claude':
                $result = $this->callClaude($prompt, $note['content']);
                break;
            case 'custom_openai':
                $result = $this->callCustomOpenAI($prompt, $note['content']);
                break;
            default:
                return ['success' => false, 'message' => '不支持的AI提供商'];
        }
        
        if ($result['success']) {
            $this->logAIInteraction($note_id, $user_id, $provider, 'rewrite', $note['content'], $result['content']);
        }
        
        return $result;
    }
    
    // 记录AI交互历史
    private function logAIInteraction($note_id, $user_id, $provider, $action_type, $original, $response) {
        $stmt = $this->db->prepare(
            "INSERT INTO ai_interactions (note_id, user_id, ai_provider, action_type, original_content, ai_response) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$note_id, $user_id, $provider, $action_type, $original, $response]);
    }
    
    // 获取AI交互历史
    public function getInteractionHistory($note_id, $user_id) {
        $stmt = $this->db->prepare(
            "SELECT * FROM ai_interactions 
             WHERE note_id = ? AND user_id = ? 
             ORDER BY created_at DESC"
        );
        $stmt->execute([$note_id, $user_id]);
        return ['success' => true, 'history' => $stmt->fetchAll()];
    }
}
?>
