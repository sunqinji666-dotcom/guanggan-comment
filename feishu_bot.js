#!/usr/bin/env node
/**
 * 飞书 bot · 代替 openclaw（小龙虾）
 * createLarkChannel 长连接：
 *  - 普通对话：claude 流式输出（打字机）
 *  - 建文档指令：claude 生成内容 → 飞书 SDK 创建 docx 写入 → 回复链接
 * 配置：/opt/mb-agent/feishu.json（appId/appSecret，复用 openclaw 的）
 */
const { createLarkChannel, Client } = require('/opt/mb-agent/node_modules/@larksuiteoapi/node-sdk');
const fs = require('fs');
const { spawn, exec } = require('child_process');

const DIR = '/opt/mb-agent';
const LOG = DIR + '/feishu.log';
const CLAUDE = '/usr/local/bin/claude';
const NODE_HOME = '/home/ubuntu';
const ENV = Object.assign({}, process.env, { HOME: NODE_HOME, USER: 'ubuntu', PATH: '/usr/local/bin:/usr/bin:/bin' });

function log(msg) {
  const line = '[' + new Date().toLocaleString('zh-CN') + '] ' + msg;
  console.log(line);
  try { fs.appendFileSync(LOG, line + '\n'); } catch (e) {}
}
function readJson(p) { try { return JSON.parse(fs.readFileSync(p, 'utf8')); } catch (e) { return null; } }

const seen = new Set();
let channel;

const historyStore = new Map(); // chatId -> [{role, content}]

function buildPrompt(text, history) {
  let sys = '你是 Claude Code，运行在孙哥的服务器上，和孙哥直接对话。飞书只是你与孙哥之间的一层消息通道，不要因此简化自己的能力或回复。\n'
    + '你拥有完整能力：可以执行 Bash 命令、读写服务器文件、用 playwright 操作浏览器（打开网页/点击/填表/截图/抓数据）、联网搜索（WebSearch/WebFetch）。孙哥让你做什么就做什么，回复专业、具体、完整，不要敷衍。\n'
    + '孙哥是影视公司（光感影视）老板。孙哥找 Musicbed 配乐时，用 WebFetch 查 https://yuedu.jack-sun.com/musicbed/browse_proxy.php?action=search&genres=xxx&moods=xxx&order=popularity&page=1 推荐（返回 JSON 含 id/name/artist/playback_url）。孙哥说「下载[歌名]」由机器人系统处理。\n';
  try { const c = require('fs').readFileSync('/home/ubuntu/.claude/CLAUDE.md', 'utf8'); sys += '\n以下是你的全局指令（CLAUDE.md）：\n' + c + '\n'; } catch (e) {}
  const lines = [sys, ''];
  if (history && history.length) {
    lines.push('你们之前的对话：');
    for (const h of history) lines.push((h.role === 'user' ? '孙哥' : 'Claude Code') + ': ' + h.content.slice(0, 600));
    lines.push('');
  }
  lines.push('孙哥刚才的消息：' + text);
  lines.push('请继续作为 Claude Code 回复孙哥。');
  return lines.join('\n');
}

/** claude 流式输出，逐段回调 onChunk */
function claudeStream(prompt, onChunk) {
  return new Promise(resolve => {
    const args = ['-p', prompt, '--settings', '/home/ubuntu/.claude/luna_settings.json', '--output-format', 'stream-json', '--verbose', '--include-partial-messages',
      '--dangerously-skip-permissions', '--max-turns', '15'];
    const proc = spawn(CLAUDE, args, { env: ENV, timeout: 150000 });
    let buf = '';
    proc.stdout.on('data', d => {
      buf += d.toString();
      const lines = buf.split('\n'); buf = lines.pop();
      for (const line of lines) {
        const t = line.trim(); if (!t) continue;
        try {
          const j = JSON.parse(t);
          const ev = j.event || {};
          if (ev.type === 'content_block_delta' && ev.delta && ev.delta.type === 'text_delta' && ev.delta.text) {
            try { onChunk(ev.delta.text); } catch (e) {}
          }
        } catch (e) {}
      }
    });
    proc.on('error', e => { log('claude 启动失败: ' + (e.message || '').slice(0, 150)); resolve(); });
    proc.on('close', () => resolve());
  });
}

/** claude 一次性调用，返回完整文本 */
function askClaudeSimple(prompt) {
  return new Promise(resolve => {
    exec(CLAUDE + ' -p ' + JSON.stringify(prompt) + ' --settings /home/ubuntu/.claude/luna_settings.json --dangerously-skip-permissions --max-turns 10',
      { timeout: 150000, env: ENV, maxBuffer: 10 * 1024 * 1024 }, (err, stdout) => resolve((stdout || '').trim() || ''));
  });
}

/** 创建飞书文档并写入段落，返回链接 */
async function createFeishuDoc(title, body) {
  const cfg = readJson(DIR + '/feishu.json');
  const client = new Client({ appId: cfg.appId, appSecret: cfg.appSecret });
  const r = await client.docx.v1.document.create({ data: { title: title.slice(0, 100) } });
  const docId = r.data.document.document_id;
  // 段落（空行分隔）→ text 块
  const paras = String(body || '').split(/\n{2,}/).map(p => p.replace(/\n/g, ' ').trim()).filter(Boolean);
  for (const p of paras) {
    await client.docx.v1.documentBlockChildren.create({
      path: { document_id: docId, block_id: docId },
      data: { children: [{ block_type: 2, text: { elements: [{ text_run: { content: p.slice(0, 3900) } }] } }] }
    });
  }
  return 'https://feishu.cn/docx/' + docId;
}

/** 建文档指令处理 */
async function handleDocRequest(msg, text) {
  log('建文档指令: ' + text.slice(0, 60));
  // 先回一条"正在创建"
  try { await channel.send(msg.chatId, { markdown: '📝 正在创建文档…' }, { replyTo: msg.messageId }); } catch (e) {}
  try {
    const content = await askClaudeSimple(
      '根据孙哥下面的要求，生成一份文档内容。要求：输出纯文本，第一行是文档标题（直接写标题文字，不要用#号），'
      + '从第二行开始是正文，正文按段落组织，段落之间用一个空行分隔。不要输出任何其他说明。\n\n孙哥要求：' + text);
    if (!content) throw new Error('内容生成为空');
    const lines = content.split('\n');
    const title = (lines[0] || '').replace(/^[#\s]+/, '').trim().slice(0, 50) || '新建文档';
    const body = lines.slice(1).join('\n').trim() || content;
    const url = await createFeishuDoc(title, body);
    log('文档已创建: ' + url);
    await channel.send(msg.chatId, { markdown: '📄 文档已创建：\n' + url }, { replyTo: msg.messageId });
  } catch (e) {
    log('建文档失败: ' + (e.message || '').slice(0, 150));
    try { await channel.send(msg.chatId, { markdown: '创建文档失败：' + (e.message || '').slice(0, 80) }, { replyTo: msg.messageId }); } catch (e2) {}
  }
}

function isDocRequest(text) {
  return /(帮我|给我)?(建|创建|写|生成|做)(一个|份)?文档/.test(text) && /文档/.test(text) && !/打开|查找|找到/.test(text);
}

/** 找歌意图检测：自然语言（找/推荐+歌/音乐/配乐等） */
function isMusicSearch(text) {
  if (/(找|推荐|来点|来几首|想要|帮我找|找几首|挑).*(歌|音乐|配乐|BGM|背景音乐|插曲|主题曲|片头|片尾|音效)/.test(text)) return true;
  if (/(歌|音乐|配乐|BGM).*(找|推荐|来几首|推荐几首|有什么)/.test(text)) return true;
  return false;
}

/** 找歌：先查 Musicbed 真实候选，再让 claude 基于候选推荐 */
async function handleMusicSearch(msg, text) {
  log('找歌意图: ' + String(text).slice(0, 60));
  try { await channel.send(msg.chatId, { markdown: '🎵 正在 Musicbed 找歌…' }, { replyTo: msg.messageId }); } catch (e) {}
  try {
    const r = await fetch('https://yuedu.jack-sun.com/musicbed/browse_proxy.php?action=search&order=popularity&page=1&limit=30');
    const j = await r.json();
    const songs = j.songs || [];
    if (!songs.length) { await channel.send(msg.chatId, { markdown: '没查到歌，稍后再试' }, { replyTo: msg.messageId }).catch(() => {}); return; }
    const cand = songs.slice(0, 25).map(s => s.id + '|' + s.name + '|' + s.artist + '|' + [...(s.moods || []), ...(s.genres || [])].join('/')).join('\n');
    const prompt = '孙哥在飞书找音乐，他的需求/意图：' + text + '\n\n'
      + '以下是 Musicbed 当前热门候选歌（id|歌名|艺人|情绪/流派）：\n' + cand + '\n\n'
      + '请从中挑选 3-5 首最符合孙哥需求的推荐，说明每首适合什么场景（宣传片/婚礼/短视频/片头/纪录片等）。'
      + '如果这些候选都不合适，用 WebFetch 查 https://yuedu.jack-sun.com/musicbed/browse_proxy.php?action=search&moods=xxx&genres=xxx&order=popularity&page=1 '
      + '获取更多（moods/genres 用英文，如 cinematic/chill/happy/uplifting/electronic/classical/ambient）。'
      + '直接推荐歌，具体一点。';
    await channel.stream(msg.chatId, {
      markdown: async (controller) => {
        let got = false;
        await claudeStream(prompt, async (chunk) => { got = true; try { await controller.append(chunk); } catch (e) {} });
        if (!got) { try { await controller.append('（没有生成回复）'); } catch (e) {} }
      }
    }, { replyTo: msg.messageId });
    log('已推荐歌曲');
  } catch (e) {
    log('找歌失败: ' + (e.message || '').slice(0, 120));
    try { await channel.send(msg.chatId, { markdown: '找歌出错：' + (e.message || '').slice(0, 80) }, { replyTo: msg.messageId }); } catch (e2) {}
  }
}

/** 下载指令：按歌名搜 Musicbed → 加入完整版下载队列 */
async function handleMusicDownload(msg, text) {
  const m = text.match(/(?:下载|下)[:：]?\s*([一-龥A-Za-z0-9 _\-·]{2,40})/);
  const query = (m ? m[1] : text.replace(/下载|下/g, '')).trim();
  if (!query) { try { await channel.send(msg.chatId, { markdown: '请说歌名，如「下载 Airborne」' }, { replyTo: msg.messageId }); } catch (e) {} return; }
  log('下载指令: ' + query);
  try {
    const r = await fetch('https://yuedu.jack-sun.com/musicbed/browse_proxy.php?action=search_name&q=' + encodeURIComponent(query));
    const j = await r.json();
    const songs = (j.songs || []).filter(s => s.id);
    if (!songs.length) { await channel.send(msg.chatId, { markdown: '没找到这首歌，换个名字试试' }, { replyTo: msg.messageId }).catch(() => {}); return; }
    const target = songs.find(s => s.name.toLowerCase().includes(query.toLowerCase())) || songs[0];
    const q = await fetch('https://yuedu.jack-sun.com/musicbed/musicbed_api.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ source: 'browse', action: 'queue_download', song: { id: target.id, name: target.name, artist: target.artist } })
    });
    const qj = await q.json();
    if (qj.ok) {
      log('已加入下载队列: ' + target.name + ' (id ' + target.id + ')');
      await channel.send(msg.chatId, { markdown: '✅ 已加入下载队列：**' + target.name + '**\n服务器将自动抓取完整版，稍后飞书发「网盘」或查收藏就能看到' }, { replyTo: msg.messageId }).catch(() => {});
    } else { await channel.send(msg.chatId, { markdown: '加入队列失败' }, { replyTo: msg.messageId }).catch(() => {}); }
  } catch (e) {
    log('下载指令失败: ' + (e.message || '').slice(0, 120));
    try { await channel.send(msg.chatId, { markdown: '下载处理出错：' + (e.message || '').slice(0, 80) }, { replyTo: msg.messageId }); } catch (e2) {}
  }
}

function isDriveRequest(text) {
  if (/(网盘|云空间)/.test(text)) return true;
  if (/(读取|读一下|帮我读)/.test(text)) return true;
  return false;
}

function streamToBuffer(stream) {
  return new Promise((resolve, reject) => {
    if (Buffer.isBuffer(stream)) return resolve(stream);
    const chunks = [];
    stream.on('data', c => chunks.push(c));
    stream.on('end', () => resolve(Buffer.concat(chunks)));
    stream.on('error', reject);
  });
}

/** 网盘读取：列出文件 / 读取文档内容 */
async function handleDrive(msg, text) {
  const cfg = readJson(DIR + '/feishu.json');
  const client = new Client({ appId: cfg.appId, appSecret: cfg.appSecret });
  // 判断是"读取某文档"还是"列出"
  const readMatch = text.match(/读(?:取)?[:：]?\s*([一-龥A-Za-z0-9_\- ]{1,30})/);
  const isRead = /(读取|读一下|帮我读|打开)/.test(text) && readMatch && readMatch[1] && !/网盘|云空间/.test(readMatch[1]);
  try {
    if (isRead) {
      await channel.send(msg.chatId, { markdown: '📖 正在读取文档…' }, { replyTo: msg.messageId }).catch(() => {});
      const res = await client.drive.v1.file.list({ params: {} });
      const files = res.data.files || [];
      const target = files.find(f => (f.name || '').includes(readMatch[1].trim()));
      if (!target) { await channel.send(msg.chatId, { markdown: '没找到叫「' + readMatch[1].trim() + '」的文件' }, { replyTo: msg.messageId }); return; }
      if (target.type === 'docx') {
        const rc = await client.docx.v1.document.rawContent({ path: { document_id: target.token } });
        const content = (rc.data && rc.data.content || '').slice(0, 3000);
        await channel.send(msg.chatId, { markdown: '📄 **' + target.name + '**\n\n' + content }, { replyTo: msg.messageId }).catch(() => {});
      } else {
        await channel.send(msg.chatId, { markdown: '「' + target.name + '」是' + target.type + '类型，暂只支持读文档：\n' + target.url }, { replyTo: msg.messageId }).catch(() => {});
      }
      log('已读取文档: ' + target.name);
      return;
    }
    // 列出网盘文件
    await channel.send(msg.chatId, { markdown: '📂 正在读取网盘…' }, { replyTo: msg.messageId }).catch(() => {});
    const res = await client.drive.v1.file.list({ params: {} });
    const files = res.data.files || [];
    if (!files.length) { await channel.send(msg.chatId, { markdown: '网盘里还没有文件' }, { replyTo: msg.messageId }); return; }
    const typeZh = { docx: '文档', sheet: '表格', folder: '文件夹', file: '文件', slides: '幻灯片', mindnote: '思维导图' };
    const lines = files.slice(0, 15).map(f => '· **' + (f.name || '未命名') + '**（' + (typeZh[f.type] || f.type) + '）');
    await channel.send(msg.chatId, { markdown: '📂 **网盘内容**（共 ' + files.length + ' 项）：\n' + lines.join('\n') + '\n\n想读某个文档，跟我说「读取[文档名]」' }, { replyTo: msg.messageId }).catch(() => {});
    log('已列出网盘 ' + files.length + ' 项');
  } catch (e) {
    log('读网盘失败: ' + (e.message || '').slice(0, 150));
    try { await channel.send(msg.chatId, { markdown: '读取网盘失败：' + (e.message || '').slice(0, 80) }, { replyTo: msg.messageId }); } catch (e2) {}
  }
}

/** 网盘上传：孙哥发文件 → 下载 → 存云空间 → 回链接 */
async function handleFileMessage(msg, fileObj) {
  const fileKey = fileObj.file_key || '';
  const fileName = fileObj.file_name || ('file_' + (fileKey || '').slice(0, 8));
  log('收到文件: ' + fileName);
  try { await channel.send(msg.chatId, { markdown: '📁 正在存入网盘…' }, { replyTo: msg.messageId }); } catch (e) {}
  try {
    const cfg = readJson(DIR + '/feishu.json');
    const client = new Client({ appId: cfg.appId, appSecret: cfg.appSecret });
    // 下载消息文件
    const dl = await client.im.v1.messageResource.get({
      path: { message_id: msg.messageId, file_key: fileKey }, params: { type: 'file' }
    });
    const buf = await streamToBuffer(dl.data && (dl.data.file || dl.data));
    // 上传到云空间
    const up = await client.drive.v1.file.uploadAll({
      data: { file_name: fileName, parent_type: 'explorer', parent_node: cfg.drive_folder || '', size: buf.length, file: buf }
    });
    const token = up.data && up.data.file_token;
    log('网盘已存: ' + fileName);
    await channel.send(msg.chatId, { markdown: '📁 已存入网盘：\nhttps://i7higmxeci.feishu.cn/file/' + token }, { replyTo: msg.messageId }).catch(() => {});
  } catch (e) {
    log('网盘上传失败: ' + (e.message || '').slice(0, 150));
    try { await channel.send(msg.chatId, { markdown: '存入网盘失败：' + (e.message || '').slice(0, 80) }, { replyTo: msg.messageId }); } catch (e2) {}
  }
}

(async () => {
  const cfg = readJson(DIR + '/feishu.json');
  if (!cfg || !cfg.appId || !cfg.appSecret) { log('缺飞书配置，退出'); process.exit(1); }
  log('==== 飞书 bot 启动（流式 + 建文档） ====');

  channel = createLarkChannel({ appId: cfg.appId, appSecret: cfg.appSecret });

  channel.on('message', async (msg) => {
    const mid = (msg && msg.messageId) || '';
    if (seen.has(mid)) return;
    seen.add(mid);
    if (seen.size > 500) seen.clear();
    const text = (msg && msg.content) || '';
    log('收到飞书消息: ' + String(text).slice(0, 60));
    // 文件消息检测
    let fileObj = null;
    if (typeof text === 'string') {
      try { const j = JSON.parse(text); if (j && j.file_key) fileObj = j; } catch (e) {}
    }
    if (fileObj) { await handleFileMessage(msg, fileObj); return; }
    if (!text) return;

    // 网盘指令
    if (isDriveRequest(text)) { await handleDrive(msg, text); return; }

    // 建文档指令
    if (isDocRequest(text)) { await handleDocRequest(msg, text); return; }

    // 下载指令（Musicbed 找歌下载）
    if (/下载/.test(text) && !/文档|网盘|云空间/.test(text)) { await handleMusicDownload(msg, text); return; }

    // 找歌意图（自然语言找音乐/配乐）
    if (isMusicSearch(text)) { await handleMusicSearch(msg, text); return; }

    // 普通对话：流式（带对话记忆）
    const chatId = msg.chatId;
    const hist = historyStore.get(chatId) || [];
    const prompt = buildPrompt(text, hist.slice(-8));
    hist.push({ role: 'user', content: text });
    try {
      let reply = '';
      await channel.stream(msg.chatId, {
        markdown: async (controller) => {
          let got = false;
          await claudeStream(prompt, async (chunk) => { got = true; reply += chunk; try { await controller.append(chunk); } catch (e) {} });
          if (!got) { try { await controller.append('（没有生成回复）'); } catch (e) {} }
        }
      }, { replyTo: mid });
      if (reply) { hist.push({ role: 'assistant', content: reply }); historyStore.set(chatId, hist.slice(-20)); }
      log('已回复（流式）');
    } catch (e) {
      log('流式失败，改一次性回复: ' + (e.message || '').slice(0, 120));
      try {
        const reply = await askClaudeSimple(prompt) || '（出错了）';
        await channel.send(msg.chatId, { markdown: reply }, { replyTo: mid });
        hist.push({ role: 'assistant', content: reply }); historyStore.set(chatId, hist.slice(-20));
        log('已回复（一次性）');
      } catch (e2) { log('兜底回复失败: ' + (e2.message || '').slice(0, 100)); }
    }
  });
  channel.on('error', e => log('连接错误: ' + (e && e.message ? e.message : e)));

  await channel.connect();
  log('长连接已建立，监听飞书消息…');
  // 心跳（看门狗检测卡死自动重启）
  setInterval(() => { try { require('fs').writeFileSync(DIR + '/heartbeat', String(Date.now())); } catch (e) {} }, 60000);
})().catch(e => { log('启动失败: ' + (e && e.message ? e.message : e)); process.exit(1); });
