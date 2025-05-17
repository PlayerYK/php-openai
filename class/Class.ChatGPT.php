<?php

class ChatGPT {

    private $api_url = '';
	private $api_key = '';
	private $api_model = '';
	private $streamHandler;
	private $question;
    private $dfa = NULL;
    private $check_sensitive = FALSE;
    private $log_dir = './log/api/';
    private $client_ip = '';
    private $enable_operational_logging = true; // Default, will be overridden by constructor
    private $enable_debug_logging = false; // Default, will be overridden by constructor
    private $debug_log_dir_base = './log/debug/'; // Base for debug logs, can be configured

	public function __construct($params) {
        $this->api_key = $params['api_key'] ?? '';
        $this->api_url = $params['api_url'] ?? 'https://api.openai.com/v1/chat/completions';
        $this->api_model = $params['api_model'] ?? 'gpt-3.5-turbo-0613';
        $this->log_dir = $params['log_dir'] ?? './log/api/'; // Operational log dir
        $this->enable_operational_logging = $params['enable_operational_logging'] ?? true;
        $this->enable_debug_logging = $params['enable_debug_logging'] ?? false;
        // Note: $this->debug_log_dir_base could also be passed from $settings['debug']['debug_log_dir'] if needed for consistency
        
        // 确保操作日志目录存在
        if ($this->enable_operational_logging && !file_exists($this->log_dir)) {
            mkdir($this->log_dir, 0755, true);
        }
    }

    public function set_dfa(&$dfa){
        $this->dfa = $dfa;
        if(!empty($this->dfa) && $this->dfa->is_available()){
            $this->check_sensitive = TRUE;
        }
    }

    public function qa($params){
        $this->question = $params['question'];
        $this->client_ip = $params['client_ip'] ?? '';
        
        // 记录API请求 (受 operational logging 开关控制)
        $this->logRequest([
            'question' => $this->question,
            'client_ip' => $this->client_ip,
            'model' => $this->api_model,
            'temperature' => $params['temperature'] ?? 0.8,
            'time' => date('Y-m-d H:i:s')
        ]);
        
        // 从 $params 获取 StreamHandler 的参数
        $streamHandlerParams = $params['stream_handler_params'] ?? [];
        // 确保 qmd5 仍然是动态生成的，即使其他参数从外部传入
        $streamHandlerParams['qmd5'] = md5($this->question.''.time());
        
        $this->streamHandler = new StreamHandler($streamHandlerParams);
        if($this->check_sensitive){
            $this->streamHandler->set_dfa($this->dfa);
        }


        if(empty($this->api_key)){
            $this->logError('API key is empty'); // 受 operational logging 开关控制
            $this->streamHandler->end('OpenAI 的 api key 还没填');
            return;
        }


        // 开启检测且提问包含敏感词
        if($this->check_sensitive && $this->dfa->containsSensitiveWords($this->question)){
            $this->logError('Question contains sensitive words'); // 受 operational logging 开关控制
            $this->streamHandler->end('您的问题不合适，AI暂时无法回答');
            return;
        }

    	$messages = [
    	    [
    	        'role' => 'system',
    	        'content' => $params['system'] ?? '',
    	    ],
    	    [
    	        'role' => 'user',
    	        'content' => $this->question
    	    ]
    	];

    	$json = json_encode([
    	    'model' => $this->api_model,
    	    'messages' => $messages,
    	    'temperature' => $params['temperature'] ?? 0.8,
    	    'stream' => true,
    	]);

    	$headers = array(
    	    "Content-Type: application/json",
    	    "Authorization: Bearer ".$this->api_key,
    	);

    	$this->openai($json, $headers);

    }

    private function openai($json, $headers){
        $debugDirApi = $this->debug_log_dir_base . 'api/'; // Specific debug path for API related logs
        $verboseFile = null;

        if ($this->enable_debug_logging) {
            if (!file_exists($debugDirApi)) {
                mkdir($debugDirApi, 0755, true);
            }
            
            // 记录发送到OpenAI的请求
            $requestDebug = [
                'time' => date('Y-m-d H:i:s'),
                'api_url' => $this->api_url,
                'api_model' => $this->api_model,
                'request_json' => json_decode($json, true),
                'headers' => $headers
            ];
            
            file_put_contents($debugDirApi . 'openai_request_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json', 
                json_encode($requestDebug, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    	
        $ch = curl_init();

    	curl_setopt($ch, CURLOPT_URL, $this->api_url);
    	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    	curl_setopt($ch, CURLOPT_HEADER, false);
    	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    	curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    	curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // 设置超时，避免请求挂起
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 连接超时10秒
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 总超时60秒

        // 增加更多curl调试选项
        if ($this->enable_debug_logging) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            // Ensure directory exists before opening file for writing
            if (!file_exists($debugDirApi)) {
                mkdir($debugDirApi, 0755, true);
            }
            $verboseFile = fopen($debugDirApi . 'curl_verbose_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.log', 'w+');
            if ($verboseFile) {
                curl_setopt($ch, CURLOPT_STDERR, $verboseFile);
            } else {
                // Optionally log an error if the verbose log file cannot be opened
                $this->logError('Failed to open cURL verbose log file.');
            }
        }

    	curl_setopt($ch, CURLOPT_WRITEFUNCTION, [$this->streamHandler, 'callback']);

    	$response = curl_exec($ch);

    	if (curl_errno($ch)) {
            $error = 'CURL error: ' . curl_error($ch) . ' (Code: ' . curl_errno($ch) . ')';
            $this->logError($error); // 受 operational logging 开关控制
            
            if ($this->enable_debug_logging) {
                 // 记录更多curl信息
                $curlInfo = curl_getinfo($ch);
                $errorDetails = [
                    'error' => $error,
                    'curl_info' => $curlInfo,
                    'request_url' => $this->api_url,
                    'request_model' => $this->api_model
                ];
                
                file_put_contents($debugDirApi . 'curl_error_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json', 
                    json_encode($errorDetails, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
                            
            // 通知用户连接错误
            $this->streamHandler->end('连接OpenAI API失败: ' . $error);
    	} else {
            // 检查HTTP状态码
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode != 200) {
                $statusError = "HTTP错误: 状态码 $httpCode";
                $this->logError($statusError); // 受 operational logging 开关控制
                
                if ($this->enable_debug_logging) {
                    // 记录HTTP错误信息
                    $curlInfo = curl_getinfo($ch);
                    file_put_contents($debugDirApi . 'http_error_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.json', 
                        json_encode([
                            'error' => $statusError,
                            'curl_info' => $curlInfo
                        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
                                    
                // 通知用户HTTP错误
                $this->streamHandler->end('API响应错误: ' . $statusError);
            }
        }

        if ($verboseFile) {
            fclose($verboseFile);
        }
    	curl_close($ch);
    }
    
    /**
     * 记录API请求
     * 
     * @param array $requestData 请求数据
     */
    private function logRequest($requestData) {
        if (!$this->enable_operational_logging) return;

        // 确保操作日志目录存在
        if (!file_exists($this->log_dir)) {
            mkdir($this->log_dir, 0755, true); // Attempt to create if missing
        }
        
        $logFile = $this->log_dir . 'requests_' . date('Y-m-d') . '.log';
        $logEntry = json_encode($requestData, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        // 记录请求统计
        $this->updateRequestStats();
    }
    
    /**
     * 记录错误信息
     * 
     * @param string $error 错误信息
     */
    private function logError($error) {
        if (!$this->enable_operational_logging) return;

        // 确保操作日志目录存在
        if (!file_exists($this->log_dir)) {
            mkdir($this->log_dir, 0755, true); // Attempt to create if missing
        }

        $logFile = $this->log_dir . 'errors.log';
        $logEntry = date('Y-m-d H:i:s') . ' - IP: ' . $this->client_ip . ' - Error: ' . $error . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * 更新请求统计
     */
    private function updateRequestStats() {
        if (!$this->enable_operational_logging) return;

        // 确保操作日志目录存在
        if (!file_exists($this->log_dir)) {
            mkdir($this->log_dir, 0755, true); // Attempt to create if missing
        }

        $statsFile = $this->log_dir . 'stats.json';
        $today = date('Y-m-d');
        
        // 读取现有统计数据
        $stats = [];
        if (file_exists($statsFile)) {
            $statsContent = file_get_contents($statsFile);
            if (!empty($statsContent)) {
                $stats = json_decode($statsContent, true) ?? [];
            }
        }
        
        // 更新今日统计
        if (!isset($stats[$today])) {
            $stats[$today] = [
                'total' => 0,
                'ip_count' => []
            ];
        }
        
        $stats[$today]['total']++;
        
        // 更新IP统计
        if (!empty($this->client_ip)) {
            if (!isset($stats[$today]['ip_count'][$this->client_ip])) {
                $stats[$today]['ip_count'][$this->client_ip] = 0;
            }
            $stats[$today]['ip_count'][$this->client_ip]++;
        }
        
        // 保存统计数据
        file_put_contents($statsFile, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

