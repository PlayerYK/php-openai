<?php

class ApiAuth {
    private $validTokens;
    private $validReferers;
    private $validOrigins;
    private $logDir;
    private $enableTokenAuth;
    private $enableRefererAuth;
    private $enableOriginAuth;
    private $enableStrictMode;

    private $masterLoggingEnabled; // 新增: 全局日志总开关
    private $authLoggingEnabled;   // 新增: 身份验证模块日志开关
    private $authDebugLoggingEnabled; // 修改: 身份验证调试日志开关

    private $debug_log_dir_base = './log/debug/';
    
    /**
     * 构造函数
     * 
     * @param array $params 包含以下键:
     *                      - valid_tokens: 有效的令牌数组
     *                      - valid_referers: 有效的HTTP引用来源数组
     *                      - valid_origins: 有效的来源域名数组
     *                      - log_dir: 身份验证日志目录 (来自 config.ini -> auth_log_dir)
     *                      - master_logging_enabled: 全局日志总开关 (来自 config.ini -> master_logging_enabled)
     *                      - enable_auth_logging: 身份验证模块日志开关 (来自 config.ini -> enable_auth_logging)
     *                      - enable_token_auth: 是否启用Token验证
     *                      - enable_referer_auth: 是否启用Referer验证
     *                      - enable_origin_auth: 是否启用Origin验证
     *                      - enable_strict_mode: 是否启用严格模式（验证失败时拒绝请求）
     *                      - enable_auth_debug_logging: 是否启用详细身份验证调试日志 (来自 config.ini -> enable_auth_debug_logging)
     */
    public function __construct($params = []) {
        $this->validTokens = $params['valid_tokens'] ?? [];
        $this->validReferers = $params['valid_referers'] ?? [];
        $this->validOrigins = $params['valid_origins'] ?? [];
        $this->logDir = $params['log_dir'] ?? './log/auth/'; // 对应 config.ini 的 auth_log_dir
        
        // 新的日志控制开关
        $this->masterLoggingEnabled = $params['master_logging_enabled'] ?? false;
        $this->authLoggingEnabled = $params['enable_auth_logging'] ?? false;
        $this->authDebugLoggingEnabled = $params['enable_auth_debug_logging'] ?? false;

        // 安全功能开关
        $this->enableTokenAuth = $params['enable_token_auth'] ?? false; 
        $this->enableRefererAuth = $params['enable_referer_auth'] ?? false;
        $this->enableOriginAuth = $params['enable_origin_auth'] ?? false;
        $this->enableStrictMode = $params['enable_strict_mode'] ?? false;
        
        // 确保日志目录存在 (常规身份验证日志)
        if ($this->masterLoggingEnabled && $this->authLoggingEnabled && !empty($this->logDir) && !file_exists($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        // 确保调试日志目录存在 (身份验证调试日志)
        // 注意: 调试日志的开启独立于 masterLoggingEnabled 和 authLoggingEnabled
        if ($this->authDebugLoggingEnabled && !empty($this->debug_log_dir_base)) {
            $debugDirAuth = $this->debug_log_dir_base . 'auth/';
            if (!file_exists($debugDirAuth)) {
                mkdir($debugDirAuth, 0755, true);
            }
        }
    }
    
    /**
     * 验证API请求
     * 
     * @return bool 如果验证通过返回true，否则返回false
     */
    public function validateRequest() {
        // 获取请求头信息
        $headers = $this->getAllHeaders();
        $token = $headers['X-Api-Token'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $origin = $headers['Origin'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ipAddress = $this->getClientIp();
        
        if ($this->authDebugLoggingEnabled) { // 使用新的调试日志开关
            // 增强调试日志
            $debugInfo = [
                'time' => date('Y-m-d H:i:s'),
                'headers' => $headers,
                'token' => $token,
                'referer' => $referer,
                'origin' => $origin,
                'user_agent' => $userAgent,
                'ip' => $ipAddress,
                'valid_referers' => $this->validReferers,
                'valid_origins' => $this->validOrigins,
                'enable_referer_auth' => $this->enableRefererAuth,
                'enable_origin_auth' => $this->enableOriginAuth,
                'enable_strict_mode' => $this->enableStrictMode
            ];
            
            // 调试日志目录
            $debugDirAuth = $this->debug_log_dir_base . 'auth/';
            if (!file_exists($debugDirAuth)) {
                mkdir($debugDirAuth, 0755, true);
            }
            
            file_put_contents($debugDirAuth . 'auth_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json', 
                json_encode($debugInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        
        // 记录请求信息 (常规身份验证日志)
        if ($this->masterLoggingEnabled && $this->authLoggingEnabled) {
            $this->logRequest([
                'token' => $token,
                'referer' => $referer,
                'origin' => $origin,
                'user_agent' => $userAgent,
                'ip' => $ipAddress,
                'time' => date('Y-m-d H:i:s')
            ]);
        }
        
        // 验证结果
        $isValid = true;
        
        // 如果启用了Token验证，则进行验证
        if ($this->enableTokenAuth && !empty($this->validTokens) && !in_array($token, $this->validTokens)) {
            if ($this->masterLoggingEnabled && $this->authLoggingEnabled) { // 使用新的日志判断条件
                $this->logFailure('Invalid token', $ipAddress);
            }
            $isValid = false;
        }
        
        // 如果启用了Referer验证，则进行验证
        if ($isValid && $this->enableRefererAuth && !empty($this->validReferers)) {
            $isValidReferer = false;
            foreach ($this->validReferers as $validReferer) {
                if (strpos($referer, $validReferer) === 0) {
                    $isValidReferer = true;
                    break;
                }
            }
            
            if (!$isValidReferer) {
                if ($this->masterLoggingEnabled && $this->authLoggingEnabled) { // 使用新的日志判断条件
                    $this->logFailure('Invalid referer: ' . $referer, $ipAddress);
                }
                $isValid = false;
            }
        }
        
        // 如果启用了Origin验证，则进行验证
        if ($isValid && $this->enableOriginAuth && !empty($this->validOrigins) && !in_array($origin, $this->validOrigins)) {
            if ($this->masterLoggingEnabled && $this->authLoggingEnabled) { // 使用新的日志判断条件
                $this->logFailure('Invalid origin: ' . $origin, $ipAddress);
            }
            $isValid = false;
        }
        
        // 如果验证失败但不是严格模式，记录日志但仍然返回true
        if (!$isValid && !$this->enableStrictMode) {
            if ($this->masterLoggingEnabled && $this->authLoggingEnabled) { // 使用新的日志判断条件
                $this->logWarning('Authentication failed but allowed in non-strict mode', $ipAddress);
            }
            return true;
        }
        
        return $isValid;
    }
    
    /**
     * 获取所有HTTP请求头
     * 
     * @return array 请求头数组
     */
    private function getAllHeaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$name] = $value;
            } else if ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }
    
    /**
     * 获取客户端IP地址
     * 
     * @return string 客户端IP地址
     */
    private function getClientIp() {
        $ipAddress = '';
        
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ipAddress = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ipAddress = $_SERVER['REMOTE_ADDR'];
        }
        
        // 处理多个IP地址的情况，取第一个
        if (strpos($ipAddress, ',') !== false) {
            $ipAddresses = explode(',', $ipAddress);
            $ipAddress = trim($ipAddresses[0]);
        }
        
        return $ipAddress;
    }
    
    /**
     * 记录请求信息
     * 
     * @param array $requestData 请求数据
     */
    private function logRequest($requestData) {
        if (!($this->masterLoggingEnabled && $this->authLoggingEnabled)) return; // 使用新的日志判断条件
        
        $logFile = $this->logDir . 'requests.log';
        $logEntry = json_encode($requestData) . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * 记录验证失败信息
     * 
     * @param string $reason 失败原因
     * @param string $ipAddress 客户端IP地址
     */
    private function logFailure($reason, $ipAddress) {
        if (!($this->masterLoggingEnabled && $this->authLoggingEnabled)) return; // 使用新的日志判断条件
        
        $logFile = $this->logDir . 'failures.log';
        $logEntry = date('Y-m-d H:i:s') . ' - IP: ' . $ipAddress . ' - Reason: ' . $reason . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * 记录警告信息
     * 
     * @param string $message 警告信息
     * @param string $ipAddress 客户端IP地址
     */
    private function logWarning($message, $ipAddress) {
        if (!($this->masterLoggingEnabled && $this->authLoggingEnabled)) return; // 使用新的日志判断条件
        
        $logFile = $this->logDir . 'warnings.log';
        $logEntry = date('Y-m-d H:i:s') . ' - IP: ' . $ipAddress . ' - Message: ' . $message . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * 生成CORS头
     * 
     * @return array CORS头数组
     */
    public function getCorsHeaders() {
        $headers = [];
        
        if ($this->enableOriginAuth && !empty($this->validOrigins)) {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            if (in_array($origin, $this->validOrigins)) {
                $headers['Access-Control-Allow-Origin'] = $origin;
                $headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
                $headers['Access-Control-Allow-Headers'] = 'Content-Type, X-Api-Token';
                $headers['Access-Control-Allow-Credentials'] = 'true';
            } elseif (!$this->enableStrictMode) {
                // 非严格模式下，即使Origin验证失败也设置CORS头
                $headers['Access-Control-Allow-Origin'] = '*';
                $headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
                $headers['Access-Control-Allow-Headers'] = 'Content-Type, X-Api-Token';
            }
        } else {
            // 未启用Origin验证，允许所有来源
            $headers['Access-Control-Allow-Origin'] = '*';
            $headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS';
            $headers['Access-Control-Allow-Headers'] = 'Content-Type, X-Api-Token';
        }
        
        return $headers;
    }
    
    /**
     * 设置有效令牌
     * 
     * @param array $tokens 有效令牌数组
     */
    public function setValidTokens($tokens) {
        $this->validTokens = $tokens;
    }
    
    /**
     * 设置有效引用来源
     * 
     * @param array $referers 有效引用来源数组
     */
    public function setValidReferers($referers) {
        $this->validReferers = $referers;
    }
    
    /**
     * 设置有效来源域名
     * 
     * @param array $origins 有效来源域名数组
     */
    public function setValidOrigins($origins) {
        $this->validOrigins = $origins;
    }
    
    /**
     * 启用或禁用Token验证
     * 
     * @param bool $enable 是否启用
     */
    public function enableTokenAuth($enable) {
        $this->enableTokenAuth = $enable;
    }
    
    /**
     * 启用或禁用Referer验证
     * 
     * @param bool $enable 是否启用
     */
    public function enableRefererAuth($enable) {
        $this->enableRefererAuth = $enable;
    }
    
    /**
     * 启用或禁用Origin验证
     * 
     * @param bool $enable 是否启用
     */
    public function enableOriginAuth($enable) {
        $this->enableOriginAuth = $enable;
    }
    
    /**
     * 启用或禁用严格模式
     * 
     * @param bool $enable 是否启用
     */
    public function enableStrictMode($enable) {
        $this->enableStrictMode = $enable;
    }
    
    /**
     * 启用或禁用身份验证模块日志记录 (此方法现在控制本模块的独立开关)
     * 
     * @param bool $enable 是否启用
     */
    public function enableAuthLogging($enable) {
        $this->authLoggingEnabled = $enable;
    }

    /**
     * 启用或禁用身份验证模块的详细调试日志
     * 
     * @param bool $enable 是否启用
     */
    public function enableAuthDebugLogging($enable) {
        $this->authDebugLoggingEnabled = $enable;
    }

    /**
     * 设置全局日志总开关状态 (通常由应用层面统一管理)
     * 
     * @param bool $enable 是否启用
     */
    public function setMasterLogging($enable) {
        $this->masterLoggingEnabled = $enable;
    }
} 