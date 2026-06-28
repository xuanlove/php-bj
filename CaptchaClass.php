<?php
/**
 * 验证码生成类
 * Captcha Generator Class
 */

class Captcha {
    private $width = 120;
    private $height = 40;
    private $length = 4;
    private $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    
    /**
     * 生成验证码图片
     */
    public function generate() {
        // 清理任何意外的输出缓冲（防止图片输出前有多余内容）
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // 生成验证码字符串
        $code = '';
        for ($i = 0; $i < $this->length; $i++) {
            $code .= $this->chars[random_int(0, strlen($this->chars) - 1)];
        }
        
        // 存储到Session
        $_SESSION['captcha_code'] = strtoupper($code);
        $_SESSION['captcha_time'] = time();
        
        // 设置session初始化标记（与config.php保持一致）
        if (!isset($_SESSION['initiated'])) {
            $_SESSION['initiated'] = true;
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }
        
        // 检查GD库是否可用
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            // GD库不可用，使用SVG方式输出验证码
            $this->generateSVG($code);
            return;
        }
        
        try {
            // 创建图片
            $image = @imagecreatetruecolor($this->width, $this->height);
            if ($image === false) {
                // 图片创建失败，降级为SVG
                $this->generateSVG($code);
                return;
            }
            
            // 设置颜色
            $bgColor = imagecolorallocate($image, 15, 14, 14); // 深色背景
            $textColor = imagecolorallocate($image, 212, 175, 55); // 金色文字
            $lineColor = imagecolorallocate($image, 184, 115, 51); // 铜色线条
            
            if ($bgColor === false || $textColor === false || $lineColor === false) {
                imagedestroy($image);
                $this->generateSVG($code);
                return;
            }
            
            // 填充背景
            imagefill($image, 0, 0, $bgColor);
            
            // 添加干扰线
            for ($i = 0; $i < 5; $i++) {
                imageline(
                    $image,
                    random_int(0, $this->width),
                    random_int(0, $this->height),
                    random_int(0, $this->width),
                    random_int(0, $this->height),
                    $lineColor
                );
            }
            
            // 添加干扰点
            for ($i = 0; $i < 50; $i++) {
                imagesetpixel(
                    $image,
                    random_int(0, $this->width),
                    random_int(0, $this->height),
                    $lineColor
                );
            }
            
            // 绘制验证码文字（PHP内置字体大小1-5，使用5为最大）
            $x = 15;
            for ($i = 0; $i < strlen($code); $i++) {
                $y = random_int(25, 35);
                imagestring($image, 5, $x, $y - 20, $code[$i], $textColor);
                $x += 25;
            }
            
            // 输出图片
            header('Content-Type: image/png');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            imagepng($image);
            imagedestroy($image);
        } catch (Exception $e) {
            // 任何异常降级为SVG
            $this->generateSVG($code);
            return;
        }
    }
    
    /**
     * SVG降级方式生成验证码（当GD库不可用时）
     */
    private function generateSVG($code) {
        // 清理任何意外的输出缓冲
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: image/svg+xml');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $svgWidth = $this->width;
        $svgHeight = $this->height;
        
        // 生成干扰线SVG路径
        $lines = '';
        for ($i = 0; $i < 5; $i++) {
            $x1 = random_int(0, $svgWidth);
            $y1 = random_int(0, $svgHeight);
            $x2 = random_int(0, $svgWidth);
            $y2 = random_int(0, $svgHeight);
            $lines .= "<line x1=\"{$x1}\" y1=\"{$y1}\" x2=\"{$x2}\" y2=\"{$y2}\" stroke=\"#b87333\" stroke-width=\"1\"/>\n";
        }
        
        // 生成验证码文字SVG
        $texts = '';
        $x = 15;
        for ($i = 0; $i < strlen($code); $i++) {
            $y = random_int(22, 30);
            $rotation = random_int(-15, 15);
            $texts .= "<text x=\"{$x}\" y=\"{$y}\" fill=\"#d4af37\" font-family=\"monospace\" font-size=\"20\" font-weight=\"bold\" transform=\"rotate({$rotation}, {$x}, {$y})\">" . htmlspecialchars($code[$i]) . "</text>\n";
            $x += 25;
        }
        
        echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$svgWidth}" height="{$svgHeight}" viewBox="0 0 {$svgWidth} {$svgHeight}">
  <rect width="100%" height="100%" fill="#0f0e0e"/>
  {$lines}
  {$texts}
</svg>
SVG;
    }
    
    /**
     * 验证验证码
     * @param string $code 用户输入的验证码
     * @return array 验证结果
     */
    public static function verify($code) {
        // 检查验证码是否存在
        if (!isset($_SESSION['captcha_code']) || !isset($_SESSION['captcha_time'])) {
            return ['success' => false, 'message' => '验证码已过期，请刷新'];
        }
        
        // 检查验证码是否过期（5分钟）
        if (time() - $_SESSION['captcha_time'] > 300) {
            unset($_SESSION['captcha_code']);
            unset($_SESSION['captcha_time']);
            return ['success' => false, 'message' => '验证码已过期，请刷新'];
        }
        
        // 验证码比较（不区分大小写）
        $storedCode = strtoupper($_SESSION['captcha_code']);
        $inputCode = strtoupper(trim($code));
        
        if ($storedCode !== $inputCode) {
            // 验证失败也清除验证码，强制刷新，防止暴力枚举
            unset($_SESSION['captcha_code']);
            unset($_SESSION['captcha_time']);
            return ['success' => false, 'message' => '验证码错误，请刷新'];
        }
        
        // 验证成功后清除验证码
        unset($_SESSION['captcha_code']);
        unset($_SESSION['captcha_time']);
        
        return ['success' => true, 'message' => '验证码正确'];
    }
    
    /**
     * 刷新验证码
     */
    public static function refresh() {
        unset($_SESSION['captcha_code']);
        unset($_SESSION['captcha_time']);
    }
}