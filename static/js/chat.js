const messagesContainer = document.getElementById('messages');
const input = document.getElementById('input');
const sendButton = document.getElementById('send');
var qaIdx = 0,answers={},answerContent='',answerWords=[];
var codeStart=false,lastWord='',lastLastWord='';
var typingTimer=null,typing=false,typingIdx=0,contentIdx=0,contentEnd=false;
// API Token - 从Chrome扩展存储中读取
var apiToken = '';

// 初始化时获取API Token
document.addEventListener('DOMContentLoaded', function() {
    // 如果是Chrome扩展环境，从Storage获取Token
    if (typeof chrome !== 'undefined' && chrome.storage) {
        chrome.storage.sync.get(['apiToken'], function(result) {
            if (result.apiToken) {
                apiToken = result.apiToken;
            }
        });
    }
});

//markdown解析，代码高亮设置
marked.setOptions({
    highlight: function (code, language) {
        const validLanguage = hljs.getLanguage(language) ? language : 'javascript';
        return hljs.highlight(code, { language: validLanguage }).value;
    },
});


//在输入时和获取焦点后自动调整输入框高度
input.addEventListener('input', adjustInputHeight);
input.addEventListener('focus', adjustInputHeight);

// 自动调整输入框高度
function adjustInputHeight() {
    input.style.height = 'auto'; // 将高度重置为 auto
    input.style.height = (input.scrollHeight+2) + 'px';
}

function sendMessage() {
    const inputValue = input.value;
    if (!inputValue) {
        return;
    }

    const question = document.createElement('div');
    question.setAttribute('class', 'message question');
    question.setAttribute('id', 'question-'+qaIdx);
    question.innerHTML = marked.parse(inputValue);
    messagesContainer.appendChild(question);

    const answer = document.createElement('div');
    answer.setAttribute('class', 'message answer');
    answer.setAttribute('id', 'answer-'+qaIdx);
    answer.innerHTML = marked.parse('AI思考中……');
    messagesContainer.appendChild(answer);

    answers[qaIdx] = document.getElementById('answer-'+qaIdx);

    input.value = '';
    input.disabled = true;
    sendButton.disabled = true;
    adjustInputHeight();

    typingTimer = setInterval(typingWords, 50);

    getAnswer(inputValue);
}

function getAnswer(inputValue){
  inputValue = encodeURIComponent(inputValue.replace(/\+/g, "{[$add$]}"));
  const url = "./chat.php?q=" + inputValue;
  const eventSource = new EventSource(url);

  // 如果有API Token，添加到请求头
  if (apiToken) {
    const originalOpen = EventSource.prototype.open;
    EventSource.prototype.open = function () {
      this.setRequestHeader("X-Api-Token", apiToken);
      originalOpen.apply(this, arguments);
    };
  }

  eventSource.addEventListener("open", (event) => {
    console.log("连接已建立", JSON.stringify(event));
  });

  eventSource.addEventListener("message", (event) => {
    //console.log("接收数据：", event);
    try {
      var result = JSON.parse(event.data);
      if (result.time && result.content) {
        answerWords.push(result.content);
        contentIdx += 1;
      }
    } catch (error) {
      console.log(error);
    }
  });

  eventSource.addEventListener("error", (event) => {
    console.error("发生错误：", JSON.stringify(event));
    handleApiError(event);
  });

  eventSource.addEventListener("close", (event) => {
    console.log("连接已关闭", JSON.stringify(event.data));
    eventSource.close();
    contentEnd = true;
    console.log(new Date().getTime(), "answer end");
  });
}

// 处理API错误
function handleApiError(event) {
    try {
        if (event.data) {
            const errorData = JSON.parse(event.data);
            if (errorData.error) {
                // 显示错误信息
                if (answers[qaIdx]) {
                    answers[qaIdx].innerHTML = marked.parse('错误: ' + errorData.error);
                }
                
                // 如果是频率限制错误，显示等待时间
                if (errorData.retry_after) {
                    const retrySeconds = parseInt(errorData.retry_after);
                    const retryMinutes = Math.ceil(retrySeconds / 60);
                    if (answers[qaIdx]) {
                        answers[qaIdx].innerHTML += marked.parse(`\n\n请等待 ${retryMinutes} 分钟后再试。`);
                    }
                }
            }
        }
    } catch (e) {
        console.error("处理API错误失败:", e);
    }
    
    // 重置输入状态
    clearInterval(typingTimer);
    input.disabled = false;
    sendButton.disabled = false;
}

function typingWords(){
    if(contentEnd && contentIdx==typingIdx){
        clearInterval(typingTimer);
        answerContent = '';
        answerWords = [];
        answers = [];
        qaIdx += 1;
        typingIdx = 0;
        contentIdx = 0;
        contentEnd = false;
        lastWord = '';
        lastLastWord = '';
        input.disabled = false;
        sendButton.disabled = false;
        console.log((new Date().getTime()), 'typing end');
        return;
    }
    if(contentIdx<=typingIdx){
        return;
    }
    if(typing){
        return;
    }
    typing = true;

    if(!answers[qaIdx]){
        answers[qaIdx] = document.getElementById('answer-'+qaIdx);
    }

    const content = answerWords[typingIdx];
    if(content.indexOf('`') != -1){
        if(content.indexOf('```') != -1){
            codeStart = !codeStart;
        }else if(content.indexOf('``') != -1 && (lastWord + content).indexOf('```') != -1){
            codeStart = !codeStart;
        }else if(content.indexOf('`') != -1 && (lastLastWord + lastWord + content).indexOf('```') != -1){
            codeStart = !codeStart;
        }
    }

    lastLastWord = lastWord;
    lastWord = content;

    answerContent += content;
    answers[qaIdx].innerHTML = marked.parse(answerContent+(codeStart?'\n\n```':''));

    typingIdx += 1;
    typing = false;
}
