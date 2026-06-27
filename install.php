<?php
// 安装程序后端脚本
// 设置session配置（必须与config.php保持完全一致）
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
               || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
               || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && $_SERVER['HTTP_FRONT_END_HTTPS'] === 'on')
               || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443');
    ini_set('session.cookie_secure', $isHttps ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    
    session_start();
    
    // 设置session初始化标记（与config.php保持一致）
    if (!isset($_SESSION['initiated'])) {
        $_SESSION['initiated'] = true;
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
}

// ========================================
// 安全检查：检查是否已安装
// ========================================
if (file_exists(__DIR__ . '/install.lock')) {
    // 已安装，返回错误信息
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'message' => '系统已安装完成！如需重新安装，请先删除 install.lock 文件。',
        'installed' => true,
        'redirect' => 'index.html'
    ]));
}

// 验证请求来源，无action参数时重定向到安装页面
$action = $_GET['action'] ?? '';
if (empty($action)) {
    // 直接访问install.php，重定向到安装向导页面
    header('Location: install.html');
    exit;
}

header('Content-Type: application/json');

// 环境检测
function checkEnvironment() {
    $checks = [
        // ===== 必需环境 =====
        [
            'name' => 'PHP 版本 >= 8.0',
            'passed' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'required' => true,
            'hint' => 'PHP 版本过低，系统无法运行'
        ],
        [
            'name' => 'PDO 扩展',
            'passed' => extension_loaded('pdo'),
            'required' => true,
            'hint' => '数据库操作必需'
        ],
        [
            'name' => 'PDO_MySQL 扩展',
            'passed' => extension_loaded('pdo_mysql'),
            'required' => true,
            'hint' => 'MySQL 数据库连接必需'
        ],
        [
            'name' => 'mbstring 扩展',
            'passed' => extension_loaded('mbstring'),
            'required' => true,
            'hint' => '多字节字符串处理必需'
        ],
        [
            'name' => 'ZipArchive 扩展',
            'passed' => class_exists('ZipArchive'),
            'required' => true,
            'hint' => '备份功能必需'
        ],
        [
            'name' => 'uploads/ 目录可写',
            'passed' => checkDirectory('uploads'),
            'required' => true,
            'hint' => '文件上传目录必需可写'
        ],
        [
            'name' => 'attachments/ 目录可写',
            'passed' => checkDirectory('attachments'),
            'required' => true,
            'hint' => '附件存储目录必需可写'
        ],
        // ===== 备份功能依赖（可选，缺失仅影响对应存储方式） =====
        [
            'name' => 'FTP 扩展',
            'passed' => extension_loaded('ftp'),
            'required' => false,
            'hint' => '未安装，FTP 备份不可用。通常 PHP 默认包含此扩展'
        ],
        [
            'name' => 'cURL 扩展',
            'passed' => extension_loaded('curl'),
            'required' => false,
            'hint' => '未安装，云存储（OSS/COS/S3）备份不可用。安装方式：apt install php-curl'
        ],
        [
            'name' => 'OpenSSL 扩展',
            'passed' => extension_loaded('openssl'),
            'required' => false,
            'hint' => '未安装，云存储 HTTPS 连接和凭证加密不可用。安装方式：apt install php-openssl'
        ],
        [
            'name' => '阿里云 OSS SDK',
            'passed' => class_exists('OSS\OssClient'),
            'required' => false,
            'hint' => '未安装，阿里云 OSS 备份不可用。安装命令：composer require aliyuncs/oss-sdk-php'
        ],
        [
            'name' => '腾讯云 COS SDK',
            'passed' => class_exists('Qcloud\Cos\Client'),
            'required' => false,
            'hint' => '未安装，腾讯云 COS 备份不可用。安装命令：composer require qcloud/cos-sdk-v5'
        ],
        [
            'name' => 'AWS S3 SDK',
            'passed' => class_exists('Aws\S3\S3Client'),
            'required' => false,
            'hint' => '未安装，S3 兼容存储备份不可用。安装命令：composer require aws/aws-sdk-php'
        ],
        [
            'name' => 'PHP ssh2 扩展（SFTP）',
            'passed' => extension_loaded('ssh2'),
            'required' => false,
            'hint' => '未安装，SFTP 备份不可用。安装方式：apt install php-ssh2（Debian/Ubuntu）或 yum install php-ssh2（CentOS）'
        ],
    ];
    
    $all_passed = true;
    foreach ($checks as $check) {
        if (!$check['passed'] && $check['required']) {
            $all_passed = false;
            break;
        }
    }
    
    return [
        'success' => true,
        'checks' => $checks,
        'all_passed' => $all_passed
    ];
}

// 检查目录
function checkDirectory($dir) {
    $path = __DIR__ . '/' . $dir;
    if (!file_exists($path)) {
        @mkdir($path, 0755, true);
    }
    return is_dir($path) && is_writable($path);
}

// 测试数据库连接
function testDatabase() {
    $host = $_POST['db_host'] ?? 'localhost';
    $port = $_POST['db_port'] ?? '3306';
    $name = $_POST['db_name'] ?? '';
    $user = $_POST['db_user'] ?? '';
    $pass = $_POST['db_pass'] ?? '';
    
    if (empty($name) || empty($user)) {
        return ['success' => false, 'message' => '数据库名称和用户名不能为空'];
    }
    
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // 保存配置到会话
        $_SESSION['db_config'] = [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'pass' => $pass
        ];
        
        return ['success' => true, 'message' => '数据库连接成功'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// 安装数据库
function installDatabase() {
    if (!isset($_SESSION['db_config'])) {
        return ['success' => false, 'message' => '请先测试数据库连接'];
    }
    
    $config = $_SESSION['db_config'];
    
    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // 读取 SQL 文件
        $sql_file = __DIR__ . '/database.sql';
        if (!file_exists($sql_file)) {
            return ['success' => false, 'message' => '找不到 database.sql 文件'];
        }

        $sql = file_get_contents($sql_file);
        
        // 移除单行注释（保留INSERT语句中的注释）
        $sql = preg_replace('/^--.*$/m', '', $sql);
        // 移除多行注释
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        
        // 分割SQL语句
        $statements = [];
        $current = '';
        $in_string = false;
        $string_char = '';
        
        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            
            // 处理字符串
            if (($char === '"' || $char === "'") && ($i === 0 || $sql[$i-1] !== '\\')) {
                if (!$in_string) {
                    $in_string = true;
                    $string_char = $char;
                } elseif ($char === $string_char) {
                    $in_string = false;
                }
            }
            
            // 分割语句（只在字符串外部的分号处分割）
            if ($char === ';' && !$in_string) {
                $stmt = trim($current);
                if (!empty($stmt)) {
                    $statements[] = $stmt;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }
        
        // 添加最后一条语句（如果有）
        if (!empty(trim($current))) {
            $statements[] = trim($current);
        }
        
        // 执行每条语句
        $errors = [];
        foreach ($statements as $statement) {
            if (!empty($statement) && !preg_match('/^\s*$/', $statement)) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    $errorMsg = $e->getMessage();
                    // 忽略"已存在"类错误（表、列、索引、键已存在）
                    if (strpos($errorMsg, 'already exists') !== false ||
                        strpos($errorMsg, 'Duplicate') !== false ||
                        strpos($errorMsg, '1060') !== false ||  // Duplicate column name
                        strpos($errorMsg, '1061') !== false ||  // Duplicate key name
                        strpos($errorMsg, '1062') !== false ||  // Duplicate entry
                        strpos($errorMsg, '1050') !== false) {  // Table already exists
                        continue;
                    }
                    // 记录其他错误但继续执行
                    $errors[] = $errorMsg;
                }
            }
        }
        
        // 如果有严重错误，返回错误信息
        if (!empty($errors)) {
            // 过滤掉可以忽略的警告
            $realErrors = array_filter($errors, function($e) {
                return strpos($e, 'already exists') === false &&
                       strpos($e, 'Duplicate') === false;
            });
            if (!empty($realErrors)) {
                return ['success' => false, 'message' => '安装失败: ' . implode('; ', $realErrors)];
            }
        }
        
        $encryptionKey = bin2hex(random_bytes(32));

        $envContent = "# PHP笔记系统 环境配置（由安装程序自动生成）\n"
            . "# 生成时间: " . date('Y-m-d H:i:s') . "\n\n"
            . "DB_HOST=" . $config['host'] . "\n"
            . "DB_NAME=" . $config['name'] . "\n"
            . "DB_USER=" . $config['user'] . "\n"
            . "DB_PASS=" . $config['pass'] . "\n"
            . "DB_PORT=" . $config['port'] . "\n"
            . "DB_CHARSET=utf8mb4\n\n"
            . "ENCRYPTION_KEY=" . $encryptionKey . "\n\n"
            . "ENVIRONMENT=production\n\n"
            . "SITE_URL=http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME'] ?? '/') . "\n";

        // 备份原有 .env
        $envPath = __DIR__ . '/.env';
        if (file_exists($envPath)) {
            @copy($envPath, __DIR__ . '/.env.bak');
        }

        $writeResult = file_put_contents($envPath, $envContent);
        if ($writeResult === false) {
            return ['success' => false, 'message' => '无法写入 .env 配置文件，请检查目录权限'];
        }

        // 清除Session中的数据库密码（安全措施）
        unset($_SESSION['db_config']);

        return ['success' => true, 'message' => '数据库安装成功'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => '安装失败: ' . $e->getMessage()];
    }
}

// 创建管理员账户
function createAdmin() {
    if (!file_exists(__DIR__ . '/config.php')) {
        return ['success' => false, 'message' => '请先完成数据库安装'];
    }

    // 验证验证码（必须在require config.php之前，因为config.php会重建session）
    $captcha = $_POST['captcha'] ?? '';
    if (empty($captcha)) {
        return ['success' => false, 'message' => '请输入验证码'];
    }

    require_once __DIR__ . '/CaptchaClass.php';
    $captcha_verify = Captcha::verify($captcha);
    if (!$captcha_verify['success']) {
        return $captcha_verify;
    }

    require_once __DIR__ . '/config.php';

    $username = trim($_POST['admin_username'] ?? '');
    $email = trim($_POST['admin_email'] ?? '');
    $password = $_POST['admin_password'] ?? '';
    $fullname = trim($_POST['admin_fullname'] ?? '');
    $registration_mode = $_POST['registration_mode'] ?? 'invite';

    if (empty($username) || empty($email) || empty($password)) {
        return ['success' => false, 'message' => '用户名、邮箱和密码不能为空'];
    }

    // 验证用户名格式
    if (!validateUsername($username)) {
        return ['success' => false, 'message' => '用户名只能包含字母、数字和下划线，长度3-20位'];
    }

    // 验证邮箱格式
    if (!validateEmail($email)) {
        return ['success' => false, 'message' => '邮箱格式不正确'];
    }

    // 验证密码强度
    $passwordValidation = validatePassword($password);
    if (!$passwordValidation['valid']) {
        return ['success' => false, 'message' => $passwordValidation['message']];
    }

    try {
        $db = Database::getInstance()->getConnection();

        // 检查用户名或邮箱是否已存在
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => '用户名或邮箱已存在'];
        }

        // 删除默认管理员（使用参数化查询防止SQL注入）
        $stmt = $db->prepare("DELETE FROM users WHERE username = ?");
        $stmt->execute(['admin']);

        // 创建新管理员
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare(
            "INSERT INTO users (username, email, password, full_name, role, status)
             VALUES (?, ?, ?, ?, 'admin', 'active')"
        );
        $stmt->execute([$username, $email, $hashed_password, $fullname]);

        // 更新注册模式
        $stmt = $db->prepare(
            "UPDATE system_settings SET setting_value = ? WHERE setting_key = 'registration_mode'"
        );
        $stmt->execute([$registration_mode]);

        // ========================================
        // 创建安装锁定文件（重要安全措施）
        // ========================================
        $lock_content = [
            'installed_at' => date('Y-m-d H:i:s'),
            'admin_username' => $username,
            'admin_email' => $email,
            'database_name' => $db->query("SELECT DATABASE()")->fetchColumn(),
            'php_version' => PHP_VERSION,
            'installation_id' => bin2hex(random_bytes(16))
        ];

        $lockPath = __DIR__ . '/install.lock';
        $writeResult = file_put_contents($lockPath, json_encode($lock_content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($writeResult === false) {
            return ['success' => false, 'message' => '管理员账户已创建，但无法写入 install.lock 文件，请手动创建该文件'];
        }
        @chmod($lockPath, 0444); // 设置为只读

        return ['success' => true, 'message' => '管理员账户创建成功'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// 路由处理
switch ($action) {
    case 'check_status':
        // 检查安装状态（此检查在脚本开头已经执行，如果到这里说明未安装）
        echo json_encode([
            'success' => true, 
            'installed' => false,
            'message' => '系统未安装'
        ]);
        break;
        
    case 'check_environment':
        echo json_encode(checkEnvironment());
        break;
        
    case 'test_database':
        echo json_encode(testDatabase());
        break;
        
    case 'install_database':
        echo json_encode(installDatabase());
        break;
        
    case 'create_admin':
        echo json_encode(createAdmin());
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => '未知操作']);
}
