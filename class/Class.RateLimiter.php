<?php

class RateLimiter {
    private $logDir;
    private $maxRequests;
    private $timeWindow;
    private $enableLogging;
    
    /**
     * 构造函数
     * 
     * @param array $params 包含以下键:
     *                      - log_dir: 日志目录路径
     *                      - max_requests: 最大请求数量(默认为10)
     *                      - time_window: 时间窗口秒数(默认为60秒)
     *                      - enable_logging: 是否启用日志记录(默认为true)
     */
    public function __construct($params = []) {
        $this->logDir = $params['log_dir'] ?? './log/rate_limit/';
        $this->maxRequests = $params['max_requests'] ?? 10;
        $this->timeWindow = $params['time_window'] ?? 60; // 默认1分钟内最多10次请求
        $this->enableLogging = $params['enable_logging'] ?? true;
        
        // 确保日志目录存在
        if ($this->enableLogging && !file_exists($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
    
    /**
     * 检查IP是否超过请求频率限制
     * 
     * @param string $ipAddress 客户端IP地址
     * @return bool 如果未超过限制返回true，否则返回false
     */
    public function checkLimit($ipAddress) {
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
            if ($this->enableLogging) {
                $this->logLimitExceeded($ipAddress);
            }
            return false;
        }
        
        // 添加新请求并保存
        $validRequests[] = $currentTime;
        $this->saveRequestHistory($ipFile, $validRequests);
        
        return true;
    }
    
    /**
     * 获取IP对应的文件路径
     * 
     * @param string $ipAddress IP地址
     * @return string 文件路径
     */
    private function getIpFile($ipAddress) {
        return $this->logDir . md5($ipAddress) . '.log';
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
     * 保存请求历史记录
     * 
     * @param string $filePath 文件路径
     * @param array $requests 请求时间戳数组
     */
    private function saveRequestHistory($filePath, $requests) {
        if ($this->enableLogging) {
            file_put_contents($filePath, json_encode($requests));
        }
    }
    
    /**
     * 记录超过限制的请求
     * 
     * @param string $ipAddress 客户端IP地址
     */
    private function logLimitExceeded($ipAddress) {
        if (!$this->enableLogging) return;
        
        $logFile = $this->logDir . 'exceeded.log';
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
     * 启用或禁用日志记录
     * 
     * @param bool $enable 是否启用
     */
    public function enableLogging($enable) {
        $this->enableLogging = $enable;
    }
    
    /**
     * 设置请求频率限制
     * 
     * @param int $maxRequests 最大请求数量
     * @param int $timeWindow 时间窗口秒数
     */
    public function setLimit($maxRequests, $timeWindow) {
        $this->maxRequests = $maxRequests;
        $this->timeWindow = $timeWindow;
    }
} 