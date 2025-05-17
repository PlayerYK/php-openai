<?php

class StreamHandler {

    private $data_buffer;//缓存，有可能一条data被切分成两部分了，无法解析json，所以需要把上一半缓存起来
    private $counter;//数据接收计数器
    private $qmd5;//问题md5
    private $chars;//字符数组，开启敏感词检测时用于缓存待检测字符
    private $punctuation;//停顿符号
    private $dfa = NULL;
    private $check_sensitive = FALSE;
    private $streamHasContent = false; // 新增的成员变量
    private $enable_debug_logging = false;
    private $enable_stream_data_logging = false;
    private $debug_log_dir_stream = './log/debug/stream/'; // Default, can be overridden

    public function __construct($params) {
        $this->data_buffer = '';
        $this->counter = 0;
        $this->qmd5 = $params['qmd5'] ?? md5(time() . uniqid());
        $this->chars = [];
        $this->punctuation = ['，', '。', '；', '？', '！', '……'];
        $this->streamHasContent = false;

        $this->enable_debug_logging = $params['enable_debug_logging'] ?? false;
        $this->enable_stream_data_logging = $params['enable_stream_data_logging'] ?? false;
        $base_debug_dir = rtrim($params['debug_log_dir'] ?? './log/debug', '/');
        $this->debug_log_dir_stream = $base_debug_dir . '/stream/';
    }

    public function set_dfa(&$dfa){
        $this->dfa = $dfa;
        if(!empty($this->dfa) && $this->dfa->is_available()){
            $this->check_sensitive = TRUE;
        }
    }

    public function callback($ch, $data) {
        $this->counter += 1;
        
        if ($this->enable_debug_logging) {
            if (!file_exists($this->debug_log_dir_stream)) {
                mkdir($this->debug_log_dir_stream, 0755, true);
            }
            
            file_put_contents($this->debug_log_dir_stream . 'raw_data_' . $this->qmd5 . '_' . $this->counter . '_' . uniqid() . '.log', 
                '数据长度: ' . strlen($data) . PHP_EOL . 
                '内容: ' . $data . PHP_EOL . 
                '--------------------' . PHP_EOL);
        }
            
        if ($this->enable_stream_data_logging) {
            if (!file_exists('./log/')) {
                mkdir('./log/', 0755, true);
            }
            file_put_contents('./log/data.'.$this->qmd5.'.log', $this->counter.'=='.$data.PHP_EOL.'--------------------'.PHP_EOL, FILE_APPEND);
        }

        if (empty(trim($data))) {
            if ($this->enable_debug_logging) {
                 if (!file_exists($this->debug_log_dir_stream)) mkdir($this->debug_log_dir_stream, 0755, true);
                file_put_contents($this->debug_log_dir_stream . 'empty_data_' . $this->qmd5 . '_' . $this->counter . '_' . uniqid() . '.log', 
                    '警告: 接收到空数据' . PHP_EOL);
            }
            return strlen($data);
        }

        $result = json_decode($data, TRUE);
        if(is_array($result)){
            if ($this->enable_debug_logging) {
                if (!file_exists($this->debug_log_dir_stream)) mkdir($this->debug_log_dir_stream, 0755, true);
                file_put_contents($this->debug_log_dir_stream . 'error_response_' . $this->qmd5 . '_' . uniqid() . '.json', 
                    json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
                
            $errorMsg = 'OpenAI 请求错误';
            if (isset($result['error']['message'])) {
                $errorMsg .= ': ' . $result['error']['message'];
            } else {
                $errorMsg .= ': ' . json_encode($result, JSON_UNESCAPED_UNICODE);
            }
            
        	$this->end($errorMsg);
        	return strlen($data);
        }

        $buffer = $this->data_buffer.$data;
        
        $this->data_buffer = '';

        $buffer = str_replace('data: {', '{', $buffer);
        $buffer = str_replace('data: [', '[', $buffer);

        $buffer = str_replace("}\n\n{", '}[br]{', $buffer);
        $buffer = str_replace("}\n\n[", '}[br][', $buffer);

        $lines = explode('[br]', $buffer);

        if ($this->enable_debug_logging) {
            if (!file_exists($this->debug_log_dir_stream)) mkdir($this->debug_log_dir_stream, 0755, true);
            file_put_contents($this->debug_log_dir_stream . 'processed_data_' . $this->qmd5 . '_' . $this->counter . '_' . uniqid() . '.json', 
                json_encode([
                    'original_data_length' => strlen($data),
                    'buffer_length' => strlen($buffer),
                    'lines_count' => count($lines),
                    'lines' => $lines
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        $line_c = count($lines);
        
        foreach($lines as $li=>$line){
            if(trim($line) == '[DONE]'){
                $this->data_buffer = '';
                $this->counter = 0;
                $this->sensitive_check();
                if (!$this->streamHasContent) {
                    if ($this->enable_debug_logging) {
                        if (!file_exists($this->debug_log_dir_stream)) mkdir($this->debug_log_dir_stream, 0755, true);
                        file_put_contents($this->debug_log_dir_stream . 'no_content_' . $this->qmd5 . '_' . uniqid() . '.log', 
                            '警告: 接收到[DONE]但未收到任何有效内容' . PHP_EOL);
                    }
                    $this->end('警告: 未收到API返回的有效内容，请检查API配置和日志');
                } else {
                    $this->end();
                }
                $this->streamHasContent = false;
                break;
            }
            $line_data = json_decode(trim($line), TRUE);
            if( !is_array($line_data) || !isset($line_data['choices']) || !isset($line_data['choices'][0]) ){
                if($li == ($line_c - 1)){
                    $this->data_buffer = $line;
                    break;
                }
                if ($this->enable_debug_logging) {
                     if (!file_exists($this->debug_log_dir_stream)) mkdir($this->debug_log_dir_stream, 0755, true);
                    file_put_contents($this->debug_log_dir_stream . 'parse_error_' . $this->qmd5 . '_' . $this->counter . '_' . $li . '_' . uniqid() . '.log',
                        json_encode([
                            'line' => $line,
                            'error' => json_last_error_msg()
                        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
                if ($this->enable_stream_data_logging) {
                    if (!file_exists('./log/')) {
                        mkdir('./log/', 0755, true);
                    }
                    file_put_contents('./log/error.'.$this->qmd5.'.log', json_encode(['i'=>$this->counter, 'line'=>$line, 'li'=>$li], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL.PHP_EOL, FILE_APPEND);
                }
                continue;
            }

            if( isset($line_data['choices'][0]['delta']) && isset($line_data['choices'][0]['delta']['content']) ){
            	$this->sensitive_check($line_data['choices'][0]['delta']['content']);
                $this->streamHasContent = true;
            }
        }

        return strlen($data);
    }

    private function sensitive_check($content = NULL){
        if(!$this->check_sensitive){
            $this->write($content);
            return;
        }
    	if(!$this->has_pause($content)){
            $this->chars[] = $content;
            return;
        }
        $this->chars[] = $content;
        $content = implode('', $this->chars);
        if($this->dfa->containsSensitiveWords($content)){
            $content = $this->dfa->replaceWords($content);
            $this->write($content);
        }else{
            foreach($this->chars as $char){
                $this->write($char);
            }
        }
        $this->chars = [];
    }

    private function has_pause($content){
        if($content == NULL){
            return TRUE;
        }
        $has_p = false;
        if(is_numeric(strripos(json_encode($content), '\n'))){
            $has_p = true;
        }else{
            foreach($this->punctuation as $p){
                if( is_numeric(strripos($content, $p)) ){
                    $has_p = true;
                    break;
                }
            }
        }
        return $has_p;
    }

    private function write($content = NULL, $flush=TRUE){
        if($content != NULL){
            echo 'data: '.json_encode(['time'=>date('Y-m-d H:i:s'), 'content'=>$content], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL.PHP_EOL;
        }        

        if($flush){
            flush();
        }
    }

    public function end($content = NULL){
        if(!empty($content)){
            $this->write($content, FALSE);
        }

    	echo 'retry: 86400000'.PHP_EOL;
    	echo 'event: close'.PHP_EOL;
    	echo 'data: Connection closed'.PHP_EOL.PHP_EOL;
    	flush();

    }
}
