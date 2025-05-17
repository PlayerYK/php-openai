# API 安全机制概览

为防止API被恶意刷取和滥用，我们已在代码中实现了以下安全措施：

## 1. 频率限制 (Rate Limiting)

-   **实现文件**: `class/Class.RateLimiter.php`
-   **机制**: 基于IP地址进行请求频率限制。
-   **默认设置**: 每个IP地址每分钟最多允许30次请求。

## 2. 身份验证 (Authentication)

-   **实现文件**: `class/Class.ApiAuth.php`
-   **支持方式**:
    -   API Token 验证
    -   HTTP Referer 验证
    -   Origin 验证

## 3. 请求日志 (Request Logging)

-   **实现文件**: `class/Class.ChatGPT.php`
-   **日志内容**:
    -   每日请求统计
    -   IP地址访问统计
    -   错误日志

## 4. 配置 (Configuration)

-   **配置文件**: `config.ini` (在 `[security]` 部分)
-   **参考文件**: `config.sample.ini`

## 5. 前端集成 (Frontend Integration)

-   **相关文件**: `static/js/chat.js`
-   **功能**: 添加了对API Token的支持，以便客户端（如Chrome扩展）能够获取并使用认证Token。

通过以上措施，可以有效提升API的安全性，防止恶意调用和资源滥用。 