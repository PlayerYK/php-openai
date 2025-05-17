<?php

// 设置时区为东八区
date_default_timezone_set('PRC');

// 添加全局请求信息记录（调试用）
$debug_dir = './log/debug/';
if (!file_exists($debug_dir)) {
    mkdir($debug_dir, 0755, true);
}

// 记录所有请求头和参数信息
$debug_log = [
    'time' => date('Y-m-d H:i:s'),
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'query_string' => $_SERVER['QUERY_STRING'] ?? '',
    'http_referer' => $_SERVER['HTTP_REFERER'] ?? '',
    'http_origin' => $_SERVER['HTTP_ORIGIN'] ?? '',
    'http_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'all_headers' => function_exists('getallheaders') ? getallheaders() : [],
    'get_params' => $_GET,
    'post_params' => $_POST
];

file_put_contents($debug_dir . 'request_' . date('Y-m-d_H-i-s') . '.log', 
    json_encode($debug_log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

/*
以下几行比较长的注释由 GPT4 生成
*/

// 这行代码用于关闭输出缓冲。关闭后，脚本的输出将立即发送到浏览器，而不是等待缓冲区填满或脚本执行完毕。
ini_set('output_buffering', 'off');

// 这行代码禁用了 zlib 压缩。通常情况下，启用 zlib 压缩可以减小发送到浏览器的数据量，但对于服务器发送事件来说，实时性更重要，因此需要禁用压缩。
ini_set('zlib.output_compression', false);

// 这行代码使用循环来清空所有当前激活的输出缓冲区。ob_end_flush() 函数会刷新并关闭最内层的输出缓冲区，@ 符号用于抑制可能出现的错误或警告。
while (@ob_end_flush()) {}

// 这行代码设置 HTTP 响应的 Content-Type 为 text/event-stream，这是服务器发送事件（SSE）的 MIME 类型。
header('Content-Type: text/event-stream');

// 这行代码设置 HTTP 响应的 Cache-Control 为 no-cache，告诉浏览器不要缓存此响应。
header('Cache-Control: no-cache');

// 这行代码设置 HTTP 响应的 Connection 为 keep-alive，保持长连接，以便服务器可以持续发送事件到客户端。
header('Connection: keep-alive');

// 这行代码设置 HTTP 响应的自定义头部 X-Accel-Buffering 为 no，用于禁用某些代理或 Web 服务器（如 Nginx）的缓冲。
// 这有助于确保服务器发送事件在传输过程中不会受到缓冲影响。
header('X-Accel-Buffering: no');


// 引入敏感词检测类，该类由 GPT4 生成
require './class/Class.DFA.php';

// 引入流处理类，该类由 GPT4 生成大部分代码
require './class/Class.StreamHandler.php';

// 引入调用 OpenAI 接口类，该类由 GPT4 生成大部分代码
require './class/Class.ChatGPT.php';

// 引入请求频率限制类
require './class/Class.RateLimiter.php';

// 引入API身份验证类
require './class/Class.ApiAuth.php';

// 从配置文件 config.ini 加载设置
if (!$settings = parse_ini_file('./config.ini', TRUE)){
    echo "Server Error: Unable to open config".PHP_EOL.PHP_EOL;
    flush();
    exit();
}

// Helper function to safely convert comma-separated string to array
// Handles empty strings and trims whitespace
if (!function_exists('parse_comma_separated_string')) {
    function parse_comma_separated_string($string) {
        if (empty($string) || !is_string($string)) {
            return [];
        }
        return array_map('trim', explode(',', $string));
    }
}

// 初始化API身份验证
$apiAuth = new ApiAuth([
    'valid_tokens' => parse_comma_separated_string($settings['security']['valid_tokens'] ?? ''),
    'valid_referers' => parse_comma_separated_string($settings['security']['valid_referers'] ?? ''),
    'valid_origins' => parse_comma_separated_string($settings['security']['valid_origins'] ?? ''),
    'log_dir' => $settings['security']['auth_log_dir'] ?? './log/auth/',
    'enable_token_auth' => $settings['security']['enable_token_auth'] ?? false,
    'enable_referer_auth' => $settings['security']['enable_referer_auth'] ?? false,
    'enable_origin_auth' => $settings['security']['enable_origin_auth'] ?? false,
    'enable_strict_mode' => $settings['security']['enable_strict_mode'] ?? false,
    'enable_logging' => $settings['security']['enable_logging'] ?? true
]);

// 设置CORS头
$corsHeaders = $apiAuth->getCorsHeaders();
foreach ($corsHeaders as $header => $value) {
    header("$header: $value");
}

// 验证请求
if (!$apiAuth->validateRequest()) {
    // 获取验证失败原因
    $debugDir = './log/debug/auth/';
    $failReason = '未知原因';
    
    // 尝试获取最新的auth日志
    $authFiles = glob($debugDir . 'auth_*.json');
    if (!empty($authFiles)) {
        // 按照文件修改时间排序，获取最新的
        usort($authFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $latestAuthLog = file_get_contents($authFiles[0]);
        if ($latestAuthLog) {
            $authData = json_decode($latestAuthLog, true);
            if ($authData) {
                $reasons = [];
                if ($authData['enable_referer_auth'] && empty($authData['referer'])) {
                    $reasons[] = 'Referer验证失败(空Referer)';
                }
                if ($authData['enable_origin_auth'] && empty($authData['origin'])) {
                    $reasons[] = 'Origin验证失败(空Origin)';
                }
                if (!empty($reasons)) {
                    $failReason = implode(', ', $reasons);
                }
            }
        }
    }
    
    echo "event: error".PHP_EOL;
    echo "data: ".json_encode(['error' => 'Unauthorized request', 'reason' => $failReason]).PHP_EOL.PHP_EOL;
    flush();
    exit();
}

// 初始化请求频率限制
if ($settings['security']['enable_rate_limit'] ?? false) {
    $rateLimiter = new RateLimiter([
        'max_requests' => $settings['security']['max_requests'] ?? 10,
        'time_window' => $settings['security']['time_window'] ?? 60,
        'log_dir' => $settings['security']['rate_limit_log_dir'] ?? './log/rate_limit/'
    ]);

    // 获取客户端IP
    $clientIp = '';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $clientIp = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $clientIp = $_SERVER['REMOTE_ADDR'];
    }

    // 检查请求频率限制
    if (!$rateLimiter->checkLimit($clientIp)) {
        echo "event: error".PHP_EOL;
        echo "data: ".json_encode(['error' => 'Rate limit exceeded', 'retry_after' => $settings['security']['time_window'] ?? 60]).PHP_EOL.PHP_EOL;
        flush();
        exit();
    }
} else {
    // 如果不启用频率限制，仍然获取客户端IP用于日志记录
    $clientIp = '';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
        $clientIp = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['REMOTE_ADDR'])) {
        $clientIp = $_SERVER['REMOTE_ADDR'];
    }
}

echo 'data: '.json_encode(['time'=>date('Y-m-d H:i:s'), 'content'=>'']).PHP_EOL.PHP_EOL;
flush();

// 从 get 中获取提问
$question = urldecode($_GET['q'] ?? '');
if(empty($question)) {
    echo "event: close".PHP_EOL;
    echo "data: Connection closed".PHP_EOL.PHP_EOL;
    flush();
    exit();
}

$question = str_ireplace('{[$add$]}', '+', $question);

// 初始化 ChatGPT 类
$chat = new ChatGPT([
    'api_key' => $settings['openai']['api_key'],
    'api_url' => $settings['openai']['api_url'],
    'api_model' => $settings['openai']['api_model'] ?? 'gpt-3.5-turbo-1106',
]);

// 启用敏感词检测
if ($settings['security']['enable_sensitive_words_filter'] ?? false) {
    // 特别注意，这里特意用乱码字符串文件名是为了防止他人下载敏感词文件，请你部署后也自己改一个别的乱码文件名
    $dfa = new DFA([
        'words_file' => './sensitive_words_sdfdsfvdfs5v56v5dfvdf.txt',
    ]);
    $chat->set_dfa($dfa);
}

$systemPrompt = "Prompt: You are a translation engine, you can only translate text and cannot interpret it, and do not explain. ";

// 开始提问
$chat->qa([
    'system' => "",
    'question' => "\n\n{$systemPrompt}\n\n{$question}\n\nYour translation:",
    'temperature' => 0,
    'client_ip' => $clientIp, // 传递客户端IP，用于请求记录
]);
