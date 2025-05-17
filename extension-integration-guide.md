# Chrome扩展程序修改说明

为了与新增加的后端安全防护系统对接，您需要对扩展程序进行以下修改：

## 1. 添加API Token存储和管理

在扩展的`manifest.json`中添加存储权限：

```json
{
  "permissions": [
    "storage"
  ]
}
```

## 2. 在扩展中添加设置页面，用于配置API Token

创建`options.html`文件：

```html
<!DOCTYPE html>
<html>
<head>
  <title>API设置</title>
  <style>
    body { padding: 20px; font-family: Arial, sans-serif; }
    .form-group { margin-bottom: 15px; }
    label { display: block; margin-bottom: 5px; }
    input[type="text"] { width: 300px; padding: 5px; }
    button { padding: 8px 15px; background: #4285f4; color: white; border: none; cursor: pointer; }
    .success { color: green; margin-top: 10px; display: none; }
  </style>
</head>
<body>
  <h1>API设置</h1>
  <div class="form-group">
    <label for="apiToken">API Token:</label>
    <input type="text" id="apiToken" placeholder="输入您的API Token">
  </div>
  <button id="save">保存</button>
  <div id="status" class="success">设置已保存</div>

  <script src="options.js"></script>
</body>
</html>
```

创建`options.js`文件：

```javascript
document.addEventListener('DOMContentLoaded', function() {
  // 加载已保存的值
  chrome.storage.sync.get(['apiToken'], function(items) {
    if (items.apiToken) {
      document.getElementById('apiToken').value = items.apiToken;
    }
  });

  // 保存设置
  document.getElementById('save').addEventListener('click', function() {
    var apiToken = document.getElementById('apiToken').value;
    
    chrome.storage.sync.set({
      apiToken: apiToken
    }, function() {
      // 显示保存成功提示
      var status = document.getElementById('status');
      status.style.display = 'block';
      setTimeout(function() {
        status.style.display = 'none';
      }, 3000);
    });
  });
});
```

在`manifest.json`中注册选项页面：

```json
{
  "options_ui": {
    "page": "options.html",
    "open_in_tab": true
  }
}
```

## 3. 修改扩展程序的API请求代码

为所有通向后端的API请求添加Token验证。以下是修改后的请求示例：

```javascript
// 发送请求前获取Token
function sendRequestToBackend(data) {
  chrome.storage.sync.get(['apiToken'], function(result) {
    const apiToken = result.apiToken || '';
    
    // 使用fetch API发送请求
    fetch('https://your-backend-domain.com/chat.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Api-Token': apiToken
      },
      body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
      // 处理响应
      handleResponse(data);
    })
    .catch(error => {
      // 处理错误
      handleError(error);
    });
  });
}
```

## 4. 修改EventSource请求（如果使用）

如果扩展使用EventSource进行服务器发送事件(SSE)通信，需要修改如下：

```javascript
function setupEventSource(queryParam) {
  chrome.storage.sync.get(['apiToken'], function(result) {
    const apiToken = result.apiToken || '';
    
    // 需要创建一个自定义的EventSource类以支持添加自定义头
    class CustomEventSource extends EventSource {
      constructor(url, options) {
        super(url, options);
        
        // 使用XMLHttpRequest进行请求拦截和修改
        const originalXhrOpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function() {
          originalXhrOpen.apply(this, arguments);
          this.setRequestHeader('X-Api-Token', apiToken);
        };
      }
    }
    
    const eventSource = new CustomEventSource(`https://your-backend-domain.com/chat.php?q=${queryParam}`);
    
    eventSource.addEventListener("message", (event) => {
      // 处理消息
    });
    
    eventSource.addEventListener("error", (event) => {
      // 处理错误
      handleEventSourceError(event);
    });
  });
}

// 处理EventSource错误
function handleEventSourceError(event) {
  try {
    if (event.data) {
      const errorData = JSON.parse(event.data);
      if (errorData.error) {
        // 显示错误信息给用户
        showErrorToUser(errorData.error);
        
        // 如果是频率限制错误，显示等待时间
        if (errorData.retry_after) {
          const retrySeconds = parseInt(errorData.retry_after);
          const retryMinutes = Math.ceil(retrySeconds / 60);
          showRetryMessage(retryMinutes);
        }
      }
    }
  } catch (e) {
    console.error("处理API错误失败:", e);
  }
}
```

## 5. 更新manifest.json的content_security_policy

添加您的后端域名到内容安全策略：

```json
{
  "content_security_policy": "script-src 'self'; connect-src 'self' https://your-backend-domain.com;"
}
```

## 6. 注意事项

1. **服务器配置**：确保在后端`config.ini`文件中正确设置了：
   - 在`valid_tokens`中添加您的API Token
   - 在`valid_origins`中添加`chrome-extension://您的扩展ID`
   - 在`valid_referers`中添加`chrome-extension://您的扩展ID`

2. **错误处理**：为用户提供友好的错误提示，特别是在遇到请求频率限制时

3. **扩展ID**：您可以在Chrome扩展管理页面找到扩展ID，或者在已加载的扩展中通过`chrome.runtime.id`获取

4. **测试**：修改完成后，请彻底测试所有功能，确保Token验证正常工作

5. **安全性**：避免将API Token硬编码在代码中，始终使用Chrome存储API管理它

通过以上修改，您的Chrome扩展程序将能够安全地与新的后端安全系统进行对接，防止API被恶意刷取。 