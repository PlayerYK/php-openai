# PHP OpenAI GPT Stream API

由 [@qiayue](https://github.com/qiayue/) 开源的纯 PHP 实现 OpenAI GPT 流式 API 服务。该项目允许您搭建一个后端服务，通过 API 调用实现与 OpenAI GPT模型的流式交互。

**核心功能：**

*   纯 PHP 实现，无需复杂依赖。
*   支持 OpenAI GPT 流式响应。
*   通过简单的 HTTP GET 请求即可调用。
*   内置安全机制，包括 API Token 认证、Referer/Origin 验证和请求频率限制。
*   灵活的配置选项。

## 目录结构

```
/
├─ class/
│  ├─ Class.ApiAuth.php        # API 认证处理
│  ├─ Class.ChatGPT.php        # OpenAI API 交互核心
│  ├─ Class.DFA.php            # 敏感词过滤 (DFA算法)
│  ├─ Class.RateLimiter.php    # 请求频率限制
│  ├─ Class.StreamHandler.php  # 处理流式响应
├─ log/                        # 日志文件目录 (需手动创建或由程序自动创建)
├─ chat.php                    # API 入口文件
├─ config.ini                  # 运行配置文件 (拷贝自 config.sample.ini 并修改)
├─ config.sample.ini           # 配置文件示例
├─ sensitive_words_sdfdsfvdfs5v56v5dfvdf.txt # 敏感词文件示例
├─ deployment-guide.md         # 详细的部署与配置指南
├─ extension-integration-guide.md # 客户端 (如Chrome扩展) 集成示例指南
├─ security_overview.md        # API 安全机制概览
├─ LICENSE                     # 项目许可证
├─ README.md                   # 本文档
```

## 快速开始

1.  **获取源码：** 克隆或下载本项目。
2.  **配置 `config.ini`：**
    *   复制 `config.sample.ini` 并重命名为 `config.ini`。
    *   打开 `config.ini`，**必须填写** `[openai]` 部分的配置：
        ```ini
        [openai]
        api_key = sk-YOUR_OPENAI_API_KEY
        ; api_url = https://api.openai.com/v1/chat/completions ; 可选，如果使用代理或自定义端点
        ; api_model = gpt-3.5-turbo ; 可选，默认为 gpt-3.5-turbo-1106
        ```
    *   根据需要调整其他配置项，特别是 `[security]` 部分的设置。详细配置说明请参考 `deployment-guide.md`。
3.  **确保日志目录可写：** 如果启用了日志功能，请确保 `./log/` 目录存在且 PHP 进程有权写入。
4.  **部署到服务器：** 将项目文件上传到支持 PHP 的 Web 服务器。

## API 使用方法

通过向 `chat.php` 发送 HTTP GET 请求来调用 API。

**请求参数：**

*   `text` (必需): 需要发送给 OpenAI 模型的原始文本内容。请确保此参数经过 URL 编码。
*   `target_lang` (可选): 主要用于翻译场景，指定目标语言。`chat.php` 内部会根据此参数和 `text` 参数构建 Prompt。如果未提供，`chat.php` 中的示例 Prompt 默认为 "Simplified Chinese"。您可以根据自己的需求修改 `chat.php` 中的 Prompt 构建逻辑。此参数也需要 URL 编码。

**请求示例：**

假设 `chat.php` 部署在 `https://yourdomain.com/api/`下：

```
https://yourdomain.com/api/chat.php?text=What%20Are%20Custom%20GPTs%3F&target_lang=Simplified%20Chinese
```

**响应格式：**

API 以 `text/event-stream` (Server-Sent Events) 的形式流式返回数据。每个事件包含一个 JSON 对象，通常结构如下：

```json
{"time":"YYYY-MM-DD HH:MM:SS", "content":"模型的回复片段"}
```

当对话结束或发生错误时，可能会有特定的事件或数据格式，具体请参考 `Class.StreamHandler.php` 中的 `end()` 方法和 `chat.php` 中的错误处理。

## 安全特性

本项目内置了多项安全机制以保护您的 API 服务：

*   **API Token 认证：** 客户端需在请求头中提供预设的 API Token (`X-Api-Token`)。
*   **Referer 和 Origin 验证：** 限制允许调用 API 的来源页面和域名。
*   **请求频率限制：** 基于 IP 地址限制单位时间内的请求次数。
*   **敏感词过滤：** 可配置的 DFA 算法敏感词检测和替换。
*   **日志记录：** 记录 API 调用、认证失败、频率限制超限等信息，便于审计和排查问题。

所有安全相关的配置均在 `config.ini` 的 `[security]` 部分。详细说明请参阅 `security_overview.md` 和 `deployment-guide.md`。

## 高级指南

*   **详细部署与配置：** 请参阅 `deployment-guide.md`，其中包含 `config.ini` 各项配置的详细说明、分阶段安全部署策略、问题排查等。
*   **客户端集成示例：** 如果您计划为浏览器扩展或其他客户端应用集成此 API，请参阅 `extension-integration-guide.md`，其中提供了 Chrome 扩展集成的详细步骤。

## 注意事项

*   **敏感词文件：** `sensitive_words_sdfdsfvdfs5v56v5dfvdf.txt` 是一个示例文件名，建议您在部署后使用自定义的、不易被猜到的文件名，并在 `config.ini` 中正确配置路径，以增强安全性。
*   **错误处理：** API 会在发生错误时返回特定格式的事件。客户端应妥善处理这些错误情况。
*   **日志安全：** 确保日志目录的访问权限配置得当，防止敏感信息泄露。

## License

[BSD 2-Clause](LICENSE)