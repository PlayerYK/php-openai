# PHP-OpenAI 安全升级部署指南

本文档提供了安全升级系统的部署指南，包括配置说明和平滑过渡策略，以确保在不影响现有扩展程序的情况下完成升级。

## 配置文件说明

安全相关配置都在 `config.ini` 文件的 `[security]` 部分，配置项按照功能分组：

### 全局日志设置

```ini
; 是否启用请求日志记录
enable_logging=true
; API调用日志目录
api_log_dir=./log/api/
```

### 敏感词过滤设置

```ini
; 是否启用敏感词过滤
enable_sensitive_words_filter=true
; 敏感词文件路径（建议使用随机文件名增加安全性）
sensitive_words_file=./sensitive_words_sdfdsfvdfs5v56v5dfvdf.txt
```

### API认证设置

```ini
; 是否启用Token验证
enable_token_auth=false
; 可以添加多个有效Token，使用英文逗号分隔
valid_tokens=token1,token2,token3

; 是否启用Referer验证
enable_referer_auth=false
; 可以添加多个有效引用来源，使用英文逗号分隔
valid_referers=chrome-extension://youextensionid,http://localhost

; 是否启用Origin验证
enable_origin_auth=false
; 可以添加多个有效来源域名，使用英文逗号分隔
valid_origins=chrome-extension://youextensionid,http://localhost
; 身份验证日志目录
auth_log_dir=./log/auth/
```

### 频率限制设置

```ini
; 是否启用频率限制
enable_rate_limit=false
; 时间窗口内最大请求数量
max_requests=30
; 时间窗口秒数
time_window=60
; 频率限制日志目录
rate_limit_log_dir=./log/rate_limit/
```

### 验证模式设置

```ini
; 是否启用严格模式，验证失败时拒绝请求
; 设为false时将记录但允许验证失败的请求（适合过渡期使用）
enable_strict_mode=false
```

## 平滑过渡部署策略

为了确保升级过程不影响现有用户，建议采用以下分阶段部署策略：

### 阶段一：监控模式（1-2周）

1. 部署新代码，但保持所有验证开关为关闭状态，仅启用日志记录：

```ini
enable_token_auth=false
enable_referer_auth=false
enable_origin_auth=false
enable_strict_mode=false
enable_logging=true
enable_rate_limit=false
```

2. 在此阶段，系统将记录所有请求但不会阻止任何请求
3. 分析日志，了解现有使用模式和可能的滥用情况
4. 确定哪些验证方法适合您的系统

### 阶段二：宽松模式（2-4周）

1. 启用必要的验证方法，但保持在非严格模式：

```ini
enable_token_auth=true    # 如果您需要Token验证
enable_referer_auth=true  # 如果您需要Referer验证
enable_origin_auth=true   # 如果您需要Origin验证
enable_strict_mode=false  # 保持非严格模式
enable_rate_limit=true    # 启用频率限制
```

2. 在此阶段，系统将记录验证失败的请求，但仍然允许它们通过
3. 通知扩展用户需要更新其应用程序以适应新的安全要求
4. 继续监控日志，确保合法用户不会被错误拒绝

### 阶段三：严格模式（最终阶段）

1. 启用严格模式，拒绝未授权的请求：

```ini
enable_strict_mode=true   # 启用严格模式
```

2. 此时，所有未通过验证的请求都将被拒绝
3. 确保所有合法用户都已更新他们的扩展程序

## 配置和部署步骤

1. **备份现有系统**
   ```bash
   cp -r /path/to/php-openai /path/to/backup
   ```

2. **上传新文件**
   - 上传所有新的和修改过的文件
   - 确保保留现有的 `config.ini` 文件

3. **更新配置文件**
   - 基于 `config.sample.ini` 添加新的安全配置项到您的 `config.ini`
   - 根据上面的阶段策略设置适当的选项

4. **创建日志目录**
   ```bash
   mkdir -p ./log/api ./log/auth ./log/rate_limit
   chmod 755 ./log/api ./log/auth ./log/rate_limit
   ```

5. **验证权限**
   - 确保Web服务器有权读取所有文件和写入日志目录

6. **测试系统**
   - 使用不同的验证方法测试系统
   - 验证日志是否正确记录

## 问题排查

### 验证问题

如果扩展程序无法通过验证：

1. 检查日志文件 `./log/auth/failures.log` 查看失败原因
2. 确认扩展程序是否正确传递Token
3. 验证 `valid_tokens`, `valid_referers`, `valid_origins` 配置是否正确

### 频率限制问题

如果用户被频率限制阻止：

1. 检查 `./log/rate_limit/exceeded.log` 文件
2. 调整 `max_requests` 和 `time_window` 设置
3. 考虑为特定用户组提供更高的限制或专用的Token

## 安全最佳实践

1. **定期轮换Token**
   - 每隔一段时间更改Token
   - 通知用户更新其Token

2. **监控日志**
   - 定期检查日志文件
   - 寻找可疑的访问模式

3. **隔离环境**
   - 对生产环境和测试环境使用不同的Token
   - 在不同的环境中使用不同的安全设置

4. **备份策略**
   - 定期备份配置和日志
   - 制定故障恢复计划

通过遵循这些指南，您可以平稳地升级系统安全性，同时确保现有用户不会受到影响。 