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

    public function __construct($params) {
        $this->buffer = '';
        $this->counter = 0;
        $this->qmd5 = $params['qmd5'] ?? time();
        $this->chars = [];
        $this->lines = [];
        $this->punctuation = ['，', '。', '；', '？', '！', '……'];
        $this->streamHasContent = false; // 在构造函数中初始化
    }

    public function set_dfa(&$dfa){
        $this->dfa = $dfa;
        if(!empty($this->dfa) && $this->dfa->is_available()){
            $this->check_sensitive = TRUE;
        }
    }

    public function callback($ch, $data) {
        $this->counter += 1;
        
        // 增强调试目录
        $debugDir = './log/debug/stream/';
        if (!file_exists($debugDir)) {
            mkdir($debugDir, 0755, true);
        }
        
        // 记录每次回调收到的原始数据
        file_put_contents($debugDir . 'raw_data_' . $this->qmd5 . '_' . $this->counter . '.log', 
            '数据长度: ' . strlen($data) . PHP_EOL . 
            '内容: ' . $data . PHP_EOL . 
            '--------------------' . PHP_EOL);
            
        file_put_contents('./log/data.'.$this->qmd5.'.log', $this->counter.'=='.$data.PHP_EOL.'--------------------'.PHP_EOL, FILE_APPEND);

        // 检查流数据是否为空或无效
        if (empty(trim($data))) {
            file_put_contents($debugDir . 'empty_data_' . $this->qmd5 . '_' . $this->counter . '.log', 
                '警告: 接收到空数据' . PHP_EOL);
            return strlen($data);
        }

        // 尝试解析整个数据为JSON（可能是错误消息）
        $result = json_decode($data, TRUE);
        if(is_array($result)){
            // 记录错误响应
            file_put_contents($debugDir . 'error_response_' . $this->qmd5 . '.json', 
                json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                
            // 提取错误信息，如果存在
            $errorMsg = 'OpenAI 请求错误';
            if (isset($result['error']['message'])) {
                $errorMsg .= ': ' . $result['error']['message'];
            } else {
                $errorMsg .= ': ' . json_encode($result, JSON_UNESCAPED_UNICODE);
            }
            
        	$this->end($errorMsg);
        	return strlen($data);
        }

        /*
            此处步骤仅针对 openai 接口而言
            每次触发回调函数时，里边会有多条data数据，需要分割
            如某次收到 $data 如下所示：
            data: {"id":"chatcmpl-6wimHHBt4hKFHEpFnNT2ryUeuRRJC","object":"chat.completion.chunk","created":1679453169,"model":"gpt-3.5-turbo-0301","choices":[{"delta":{"role":"assistant"},"index":0,"finish_reason":null}]}\n\ndata: {"id":"chatcmpl-6wimHHBt4hKFHEpFnNT2ryUeuRRJC","object":"chat.completion.chunk","created":1679453169,"model":"gpt-3.5-turbo-0301","choices":[{"delta":{"content":"以下"},"index":0,"finish_reason":null}]}\n\ndata: {"id":"chatcmpl-6wimHHBt4hKFHEpFnNT2ryUeuRRJC","object":"chat.completion.chunk","created":1679453169,"model":"gpt-3.5-turbo-0301","choices":[{"delta":{"content":"是"},"index":0,"finish_reason":null}]}\n\ndata: {"id":"chatcmpl-6wimHHBt4hKFHEpFnNT2ryUeuRRJC","object":"chat.completion.chunk","created":1679453169,"model":"gpt-3.5-turbo-0301","choices":[{"delta":{"content":"使用"},"index":0,"finish_reason":null}]}

            最后两条一般是这样的：
            data: {"id":"chatcmpl-6wimHHBt4hKFHEpFnNT2ryUeuRRJC","object":"chat.completion.chunk","created":1679453169,"model":"gpt-3.5-turbo-0301","choices":[{"delta":{},"index":0,"finish_reason":"stop"}]}\n\ndata: [DONE]

            根据以上 openai 的数据格式，分割步骤如下：
        */

        // 0、把上次缓冲区内数据拼接上本次的data
        $buffer = $this->data_buffer.$data;
        
        //拼接完之后，要把缓冲字符串清空
        $this->data_buffer = '';

        // 1、把所有的 'data: {' 替换为 '{' ，'data: [' 换成 '['
        $buffer = str_replace('data: {', '{', $buffer);
        $buffer = str_replace('data: [', '[', $buffer);

        // 2、把所有的 '}\n\n{' 替换维 '}[br]{' ， '}\n\n[' 替换为 '}[br]['
        $buffer = str_replace("}\n\n{", '}[br]{', $buffer);
        $buffer = str_replace("}\n\n[", '}[br][', $buffer);

        // 3、用 '[br]' 分割成多行数组
        $lines = explode('[br]', $buffer);

        // 记录处理后的数据
        file_put_contents($debugDir . 'processed_data_' . $this->qmd5 . '_' . $this->counter . '.json', 
            json_encode([
                'original_data_length' => strlen($data),
                'buffer_length' => strlen($buffer),
                'lines_count' => count($lines),
                'lines' => $lines
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // 4、循环处理每一行，对于最后一行需要判断是否是完整的json
        $line_c = count($lines);
        
        foreach($lines as $li=>$line){
            if(trim($line) == '[DONE]'){
                //数据传输结束
                $this->data_buffer = '';
                $this->counter = 0;
                $this->sensitive_check();
                if (!$this->streamHasContent) {
                    // 如果没有收到任何内容就结束了，可能是某种错误
                    file_put_contents($debugDir . 'no_content_' . $this->qmd5 . '.log', 
                        '警告: 接收到[DONE]但未收到任何有效内容' . PHP_EOL);
                    $this->end('警告: 未收到API返回的有效内容，请检查API配置和日志');
                } else {
                    $this->end();
                }
                $this->streamHasContent = false; // 重置状态，为下一次流（如果适用）做准备
                break;
            }
            $line_data = json_decode(trim($line), TRUE);
            if( !is_array($line_data) || !isset($line_data['choices']) || !isset($line_data['choices'][0]) ){
                if($li == ($line_c - 1)){
                    //如果是最后一行
                    $this->data_buffer = $line;
                    break;
                }
                //如果是中间行无法json解析，则写入错误日志中
                file_put_contents($debugDir . 'parse_error_' . $this->qmd5 . '_' . $this->counter . '_' . $li . '.log',
                    json_encode([
                        'line' => $line,
                        'error' => json_last_error_msg()
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                file_put_contents('./log/error.'.$this->qmd5.'.log', json_encode(['i'=>$this->counter, 'line'=>$line, 'li'=>$li], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL.PHP_EOL, FILE_APPEND);
                continue;
            }

            if( isset($line_data['choices'][0]['delta']) && isset($line_data['choices'][0]['delta']['content']) ){
            	$this->sensitive_check($line_data['choices'][0]['delta']['content']);
                $this->streamHasContent = true; // 使用成员变量
            }
        }

        return strlen($data);
    }

    private function sensitive_check($content = NULL){
        // 如果不检测敏感词，则直接返回给前端
        if(!$this->check_sensitive){
            $this->write($content);
            return;
        }
    	//每个 content 都检测是否包含换行或者停顿符号，如有，则成为一个新行
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
