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

	public function __construct($params) {
        $this->api_key = $params['api_key'] ?? '';
        $this->api_url = $params['api_url'] ?? 'https://api.openai.com/v1/chat/completions';
        $this->api_model = $params['api_model'] ?? 'gpt-3.5-turbo-0613';
        $this->log_dir = $params['log_dir'] ?? './log/api/';
        
        // 确保日志目录存在
        if (!file_exists($this->log_dir)) {
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
        
        // 记录API请求
        $this->logRequest([
            'question' => $this->question,
            'client_ip' => $this->client_ip,
            'model' => $this->api_model,
            'temperature' => $params['temperature'] ?? 0.8,
            'time' => date('Y-m-d H:i:s')
        ]);
        
        $this->streamHandler = new StreamHandler([
            'qmd5' => md5($this->question.''.time())
        ]);
        if($this->check_sensitive){
            $this->streamHandler->set_dfa($this->dfa);
        }


        if(empty($this->api_key)){
            $this->logError('API key is empty');
            $this->streamHandler->end('OpenAI 的 api key 还没填');
            return;
        }


        // 开启检测且提问包含敏感词
        if($this->check_sensitive && $this->dfa->containsSensitiveWords($this->question)){
            $this->logError('Question contains sensitive words');
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
        // 调试日志
        $debugDir = './log/debug/api/';
        if (!file_exists($debugDir)) {
            mkdir($debugDir, 0755, true);
        }
        
        // 记录发送到OpenAI的请求
        $requestDebug = [
            'time' => date('Y-m-d H:i:s'),
            'api_url' => $this->api_url,
            'api_model' => $this->api_model,
            'request_json' => json_decode($json, true),
            'headers' => $headers
        ];
        
        file_put_contents($debugDir . 'openai_request_' . date('Y-m-d_H-i-s') . '.json', 
            json_encode($requestDebug, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    	
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
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen($debugDir . 'curl_verbose_' . date('Y-m-d_H-i-s') . '.log', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);

    	curl_setopt($ch, CURLOPT_WRITEFUNCTION, [$this->streamHandler, 'callback']);

    	$response = curl_exec($ch);

    	if (curl_errno($ch)) {
            $error = 'CURL error: ' . curl_error($ch) . ' (Code: ' . curl_errno($ch) . ')';
            $this->logError($error);
            
            // 记录更多curl信息
            $curlInfo = curl_getinfo($ch);
            $errorDetails = [
                'error' => $error,
                'curl_info' => $curlInfo,
                'request_url' => $this->api_url,
                'request_model' => $this->api_model
            ];
            
            file_put_contents($debugDir . 'curl_error_' . date('Y-m-d_H-i-s') . '.json', 
                json_encode($errorDetails, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                
            // 通知用户连接错误
            $this->streamHandler->end('连接OpenAI API失败: ' . $error);
    	} else {
            // 检查HTTP状态码
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode != 200) {
                $statusError = "HTTP错误: 状态码 $httpCode";
                $this->logError($statusError);
                
                // 记录HTTP错误信息
                $curlInfo = curl_getinfo($ch);
                file_put_contents($debugDir . 'http_error_' . date('Y-m-d_H-i-s') . '.json', 
                    json_encode([
                        'error' => $statusError,
                        'curl_info' => $curlInfo
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    
                // 通知用户HTTP错误
                $this->streamHandler->end('API响应错误: ' . $statusError);
            }
        }

        fclose($verbose);
    	curl_close($ch);
    }
    
    /**
     * 记录API请求
     * 
     * @param array $requestData 请求数据
     */
    private function logRequest($requestData) {
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
        $logFile = $this->log_dir . 'errors.log';
        $logEntry = date('Y-m-d H:i:s') . ' - IP: ' . $this->client_ip . ' - Error: ' . $error . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
    
    /**
     * 更新请求统计
     */
    private function updateRequestStats() {
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

