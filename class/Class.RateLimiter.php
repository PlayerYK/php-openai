<?php

class RateLimiter {
    private $logDir;
    private $maxRequests;
    private $timeWindow;

    private $masterLoggingEnabled;      // 全局日志总开关
    private $rateLimitLoggingEnabled; // 频率限制模块日志开关
    private $rateLimitingFeatureEnabled; // 新增: 频率限制功能本身的开关
    
    /**
     * 构造函数
     * 
     * @param array $params 包含以下键:
     *                      - log_dir: 日志目录路径 (来自 config.ini -> rate_limit_log_dir)
     *                      - max_requests: 最大请求数量(默认为10)
     *                      - time_window: 时间窗口秒数(默认为60秒)
     *                      - master_logging_enabled: 全局日志总开关 (来自 config.ini -> master_logging_enabled)
     *                      - enable_rate_limit_logging: 频率限制模块日志开关 (来自 config.ini -> enable_rate_limit_logging)
     *                      - rate_limiting_feature_enabled: 频率限制功能是否启用 (来自 config.ini -> enable_rate_limit)
     */
    public function __construct($params = []) {
        $this->logDir = $params['log_dir'] ?? './log/rate_limit/';
        $this->maxRequests = $params['max_requests'] ?? 10;
        $this->timeWindow = $params['time_window'] ?? 60; 
        
        $this->masterLoggingEnabled = $params['master_logging_enabled'] ?? false;
        $this->rateLimitLoggingEnabled = $params['enable_rate_limit_logging'] ?? false;
        $this->rateLimitingFeatureEnabled = $params['rate_limiting_feature_enabled'] ?? false; // 初始化新属性
        
        // 如果频率限制功能启用, 确保其数据/日志目录存在
        // 这个目录用于存储每个IP的请求历史文件和exceeded.log
        if ($this->rateLimitingFeatureEnabled && !empty($this->logDir) && !file_exists($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        } 
        // 也考虑一种情况：即使功能关闭，但如果日志开关是开的，也创建日志目录以备万一（尽管当前逻辑下不太可能写exceeded.log）
        // 但主要还是依赖功能开关来创建目录，因为数据文件是核心。
        // 如果仅为了exceeded.log，也可以在此处添加:
        // else if (($this->masterLoggingEnabled && $this->rateLimitLoggingEnabled) && !empty($this->logDir) && !file_exists($this->logDir)) {
        //     mkdir($this->logDir, 0755, true);
        // }
        // 当前，只要rateLimitingFeatureEnabled为true，目录就会被创建，这已足够。
    }
    
    /**
     * 检查IP是否超过请求频率限制
     * 
     * @param string $ipAddress 客户端IP地址
     * @return bool 如果未超过限制或功能未启用返回true，否则返回false
     */
    public function checkLimit($ipAddress) {
        // 如果频率限制功能本身未启用, 则不进行限制检查
        if (!$this->rateLimitingFeatureEnabled) {
            return true;
        }

        $ipFile = $this->getIpFile($ipAddress);
        $currentTime = time();
        $requests = $this->getRequestHistory($ipFile);
        
        // 清理过期请求记录
        $validRequests = [];
        foreach ($requests as $timestamp) {
            if ($currentTime - $timestamp < $this->timeWindow) {
                $validRequests[] = $timestamp;
            }
        }
        
        // 检查是否超过限制
        if (count($validRequests) >= $this->maxRequests) {
            // 仅当日志开关启用时, 才记录超出限制的事件
            if ($this->masterLoggingEnabled && $this->rateLimitLoggingEnabled) {
                $this->logLimitExceeded($ipAddress);
            }
            return false; // 超过限制
        }
        
        // 未超过限制, 添加新请求并保存其历史记录 (这是功能核心部分)
        $validRequests[] = $currentTime;
        $this->saveRequestHistory($ipFile, $validRequests); // 总是保存，因为功能是启用的
        
        return true; // 未超过限制
    }
    
    /**
     * 获取IP对应的文件路径
     * 
     * @param string $ipAddress IP地址
     * @return string 文件路径
     */
    private function getIpFile($ipAddress) {
        return rtrim($this->logDir, '/') . '/' . md5($ipAddress) . '.log';
    }
    
    /**
     * 获取请求历史记录
     * 
     * @param string $filePath 文件路径
     * @return array 请求时间戳数组
     */
    private function getRequestHistory($filePath) {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        if (empty($content)) {
            return [];
        }
        
        return json_decode($content, true) ?? [];
    }
    
    /**
     * 保存请求历史记录 (此方法是频率限制功能的核心组成部分)
     * 
     * @param string $filePath 文件路径
     * @param array $requests 请求时间戳数组
     */
    private function saveRequestHistory($filePath, $requests) {
        // 直接保存数据，不依赖于exceeded.log的日志开关
        file_put_contents($filePath, json_encode($requests));
    }
    
    /**
     * 记录超过限制的请求事件 (这是一个日志记录行为)
     * 
     * @param string $ipAddress 客户端IP地址
     */
    private function logLimitExceeded($ipAddress) {
        // 此日志记录行为受日志开关控制
        if (!($this->masterLoggingEnabled && $this->rateLimitLoggingEnabled)) return;
        
        $logFile = rtrim($this->logDir, '/') . '/exceeded.log';
        $logEntry = date('Y-m-d H:i:s') . ' - IP: ' . $ipAddress . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * 获取IP剩余请求次数
     * 
     * @param string $ipAddress 客户端IP地址
     * @return int 剩余请求次数
     */
    public function getRemainingRequests($ipAddress) {
        // 如果功能未启用，可以认为有无限次或者按最大值返回
        if (!$this->rateLimitingFeatureEnabled) {
            return $this->maxRequests; // 或者一个非常大的数
        }

        $ipFile = $this->getIpFile($ipAddress);
        $currentTime = time();
        $requests = $this->getRequestHistory($ipFile);
        
        $validCount = 0;
        foreach ($requests as $timestamp) {
            if ($currentTime - $timestamp < $this->timeWindow) {
                $validCount++;
            }
        }
        
        return max(0, $this->maxRequests - $validCount);
    }
    
    /**
     * 启用或禁用频率限制模块日志记录 (控制 exceeded.log 等事件日志)
     * 
     * @param bool $enable 是否启用
     */
    public function enableRateLimitLogging($enable) {
        $this->rateLimitLoggingEnabled = $enable;
    }

    /**
     * 设置全局日志总开关状态 (通常由应用层面统一管理)
     * 
     * @param bool $enable 是否启用
     */
    public function setMasterLogging($enable) {
        $this->masterLoggingEnabled = $enable;
    }

    /**
     * 启用或禁用频率限制功能本身
     * 
     * @param bool $enable 是否启用
     */
    public function enableRateLimitingFeature($enable) {
        $this->rateLimitingFeatureEnabled = $enable;
        // 如果功能启用，确保目录存在
        if ($this->rateLimitingFeatureEnabled && !empty($this->logDir) && !file_exists($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
    
    /**
     * 设置请求频率限制参数
     * 
     * @param int $maxRequests 最大请求数量
     * @param int $timeWindow 时间窗口秒数
     */
    public function setLimit($maxRequests, $timeWindow) {
        $this->maxRequests = (int)$maxRequests;
        $this->timeWindow = (int)$timeWindow;
    }
} 