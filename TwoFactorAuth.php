<?php
/**
 * 两步验证管理类
 *
 * 修复：实现RFC 6238 标准TOTP算法
 * - 使用HMAC-SHA1（不是SHA-256）
 * - 6位数字
 * - 30秒时间步长
 * - 正确的Base32密钥编码
 */

require_once 'config.php';

class TwoFactorAuth {
    private $db;

    // TOTP 参数（RFC 6238）
    private $digits = 6;
    private $timeStep = 30;
    private $timeDrift = 1; // 允许前后1个时间窗口

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * 生成两步验证设置信息
     */
    public function generateSecret($user_id) {
        $secret = $this->generateBase32Secret(20); // 160bit = 20字节 → 32字符Base32
        $account = $this->getUserEmail($user_id);
        $issuer = 'NoteVault';
        $otpauth = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits={$this->digits}&period={$this->timeStep}";

        return [
            'success' => true,
            'secret' => $secret,
            'otpauth_url' => $otpauth,
            'qr_code_url' => "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($otpauth)
        ];
    }

    /**
     * 启用两步验证
     */
    public function enable($user_id, $secret, $code) {
        if (!$this->verifyCode($secret, $code)) {
            return ['success' => false, 'message' => '验证码不正确'];
        }

        $backup_codes = $this->generateBackupCodes();

        try {
            $stmt = $this->db->prepare(
                "UPDATE users SET two_factor_enabled = 1, two_factor_secret = ?, backup_codes = ? WHERE id = ?"
            );
            $stmt->execute([$secret, json_encode($backup_codes), $user_id]);

            return [
                'success' => true,
                'message' => '两步验证已启用',
                'backup_codes' => $backup_codes
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '启用失败'];
        }
    }

    /**
     * 禁用两步验证
     */
    public function disable($user_id, $code) {
        $stmt = $this->db->prepare("SELECT two_factor_secret FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user || !$this->verifyCode($user['two_factor_secret'], $code)) {
            return ['success' => false, 'message' => '验证码不正确'];
        }

        try {
            $stmt = $this->db->prepare(
                "UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL, backup_codes = NULL WHERE id = ?"
            );
            $stmt->execute([$user_id]);
            return ['success' => true, 'message' => '两步验证已禁用'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '操作失败'];
        }
    }

    /**
     * 获取状态
     */
    public function getStatus($user_id) {
        $stmt = $this->db->prepare("SELECT two_factor_enabled, backup_codes FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        return [
            'success' => true,
            'enabled' => (bool)$user['two_factor_enabled'],
            'has_backup_codes' => !empty($user['backup_codes'])
        ];
    }

    /**
     * 验证登录
     */
    public function verifyLogin($user_id, $code) {
        $stmt = $this->db->prepare("SELECT two_factor_secret, backup_codes FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user || empty($user['two_factor_secret'])) {
            return ['success' => false, 'message' => '未启用两步验证'];
        }

        // 验证TOTP
        if ($this->verifyCode($user['two_factor_secret'], $code)) {
            return ['success' => true];
        }

        // 备用码验证
        $backup_codes = json_decode($user['backup_codes'] ?: '[]', true);
        $code_hash = hash('sha256', $code);
        foreach ($backup_codes as $i => $c) {
            if (hash_equals($c, $code_hash)) {
                unset($backup_codes[$i]);
                $stmt = $this->db->prepare("UPDATE users SET backup_codes = ? WHERE id = ?");
                $stmt->execute([json_encode(array_values($backup_codes)), $user_id]);
                return ['success' => true, 'used_backup' => true];
            }
        }

        return ['success' => false, 'message' => '验证码不正确'];
    }

    /**
     * 生成Base32密钥（RFC 4648）
     * 20字节(160bit)随机数 → 32字符Base32编码
     */
    private function generateBase32Secret($bytes = 20) {
        $base32_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        $random = random_bytes($bytes);
        $buffer = 0;
        $bits_left = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $buffer = ($buffer << 8) | ord($random[$i]);
            $bits_left += 8;
            while ($bits_left >= 5) {
                $secret .= $base32_chars[($buffer >> ($bits_left - 5)) & 31];
                $bits_left -= 5;
            }
        }
        if ($bits_left > 0) {
            $secret .= $base32_chars[($buffer << (5 - $bits_left)) & 31];
        }
        return $secret;
    }

    /**
     * 生成备用码（8个）
     */
    private function generateBackupCodes($count = 8) {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
            $codes[] = hash('sha256', $code);
        }
        return $codes;
    }

    private function getUserEmail($user_id) {
        $stmt = $this->db->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        return $user['email'] ?? 'user';
    }

    /**
     * RFC 6238 标准TOTP验证
     * 检查当前时间步及前后各1个时间步（共3个窗口）
     */
    private function verifyCode($secret, $code) {
        $code = trim($code);
        if (!ctype_digit($code) || strlen($code) != $this->digits) {
            return false;
        }

        $currentStep = intdiv(time(), $this->timeStep);
        for ($i = -$this->timeDrift; $i <= $this->timeDrift; $i++) {
            $expected = $this->computeTOTP($secret, $currentStep + $i);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 计算给定时间步的TOTP值
     * RFC 6238 / RFC 4226 标准 HOTP/TOTP 算法
     *
     * @param string $secret Base32编码的密钥
     * @param int    $time_step 时间步计数器 (Unix时间戳 / 30)
     */
    private function computeTOTP($secret, $time_step) {
        // Base32解码密钥
        $secret_bin = $this->base32Decode($secret);

        // 时间计数器必须是 8 字节(64位) 大端无符号整数
        // pack('N*', 0, $time_step) → 前4字节为0，后4字节为时间步
        $time_bin = pack('N*', 0, $time_step);

        // HMAC-SHA1 计算
        $hash = hash_hmac('sha1', $time_bin, $secret_bin, true);

        // 动态截取（Dynamic Truncation）
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        // 取模得到6位数字
        $otp = $binary % 1000000;
        return str_pad((string)$otp, $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Base32解码（RFC 4648）
     */
    private function base32Decode($base32) {
        $base32 = strtoupper($base32);
        $buffer = 0;
        $bits_left = 0;
        $output = '';

        for ($i = 0; $i < strlen($base32); $i++) {
            $char = $base32[$i];
            if ($char === '=') break;
            $buffer = ($buffer << 5) | $this->base32CharValue($char);
            $bits_left += 5;

            if ($bits_left >= 8) {
                $output .= chr(($buffer >> ($bits_left - 8)) & 0xFF);
                $bits_left -= 8;
            }
        }

        return $output;
    }

    private function base32CharValue($char) {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $pos = strpos($map, $char);
        return $pos !== false ? $pos : 0;
    }
}
?>
