#!/usr/bin/env node
/**
 * Claude Code → rightapi(Luna) 模型伪装代理
 * Claude Code 以为连官方 Anthropic（claude-sonnet-4-5），代理把模型改成 gpt-5.6-terra 转发 rightapi，
 * 并把 rightapi 的非流式响应转成 Anthropic 流式 SSE（Claude Code 需要流式）。
 * 监听 127.0.0.1:8099
 */
const http = require('http');
const https = require('https');

const RIGHTAPI_HOST = 'rightapi.ai';
const RIGHTAPI_PATH = '/codex/v1/messages';
const RIGHTAPI_KEY = 'sk-fdbea8f0f8374dc0830d02b342914ad1';
const DISGUISE_MODEL = 'gpt-5.6-terra';
const PORT = 8099;

function log(msg) {
  const line = '[' + new Date().toLocaleString('zh-CN') + '] ' + msg;
  console.log(line);
  try { require('fs').appendFileSync('/opt/mb-agent/luna_proxy.log', line + '\n'); } catch (e) {}
}

function forwardToRightapi(body) {
  return new Promise((resolve, reject) => {
    const req = https.request({
      host: RIGHTAPI_HOST, path: RIGHTAPI_PATH, method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + RIGHTAPI_KEY, 'anthropic-version': '2023-06-01', 'Content-Length': Buffer.byteLength(body) }
    }, res => {
      let d = ''; res.on('data', c => d += c); res.on('end', () => resolve({ status: res.statusCode, body: d }));
    });
    req.on('error', reject);
    req.write(body); req.end();
  });
}

/** 把完整 Anthropic 响应转成 SSE 事件流（打字机） */
function anthropicToSSE(json, res) {
  const j = json;
  const text = (j.content || []).filter(b => b.type === 'text').map(b => b.text).join('');
  const stopReason = j.stop_reason || 'end_turn';
  const outTokens = (j.usage && j.usage.output_tokens) || text.length;
  const model = j.model || DISGUISE_MODEL;
  const write = (event, data) => { res.write('event: ' + event + '\ndata: ' + JSON.stringify(data) + '\n\n'); };
  // message_start
  write('message_start', { type: 'message_start', message: { id: j.id || ('msg_' + Date.now()), type: 'message', role: 'assistant', model, content: [], stop_reason: null, stop_sequence: null, usage: { input_tokens: 0, output_tokens: 0 } } });
  // content_block_start
  write('content_block_start', { type: 'content_block_start', index: 0, content_block: { type: 'text', text: '' } });
  // 分块 text_delta
  const CHUNK = 12;
  for (let i = 0; i < text.length; i += CHUNK) {
    write('content_block_delta', { type: 'content_block_delta', index: 0, delta: { type: 'text_delta', text: text.slice(i, i + CHUNK) } });
  }
  // content_block_stop
  write('content_block_stop', { type: 'content_block_stop', index: 0 });
  // message_delta
  write('message_delta', { type: 'message_delta', delta: { stop_reason: stopReason, stop_sequence: null }, usage: { output_tokens: outTokens } });
  // message_stop
  write('message_stop', { type: 'message_stop' });
  res.end();
}

const server = http.createServer((req, res) => {
  const path = req.url.split('?')[0];
  // 1. messages（主请求）
  if (path === '/v1/messages') {
    let body = '';
    req.on('data', c => body += c);
    req.on('end', async () => {
      let payload = {};
      try { payload = JSON.parse(body); } catch (e) {}
      const stream = !!payload.stream;
      // 改模型，去掉 stream（rightapi 不支持）
      payload.model = DISGUISE_MODEL;
      delete payload.stream;
      log('转发 messages: model=' + DISGUISE_MODEL + (stream ? ' (stream)' : ''));
      try {
        const r = await forwardToRightapi(JSON.stringify(payload));
        if (r.status !== 200) {
          log('rightapi 返回 ' + r.status + ': ' + r.body.slice(0, 150));
          res.writeHead(r.status, { 'Content-Type': 'application/json' });
          res.end(r.body);
          return;
        }
        let j; try { j = JSON.parse(r.body); } catch (e) { res.writeHead(500); res.end('bad upstream'); return; }
        if (stream) {
          res.writeHead(200, { 'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache', 'Connection': 'keep-alive' });
          anthropicToSSE(j, res);
        } else {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify(j));
        }
      } catch (e) { log('转发失败: ' + e.message); res.writeHead(502); res.end('proxy error'); }
    });
    return;
  }
  // 2. models（校验用，mock 含伪装模型）
  if (path === '/v1/models' || path === '/models') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ data: [{ id: DISGUISE_MODEL, object: 'model', owned_by: 'openai', supported_endpoint_types: ['anthropic', 'openai'] }] }));
    return;
  }
  // 3. count_tokens（mock）
  if (path.includes('count_tokens')) {
    let body = '';
    req.on('data', c => body += c);
    req.on('end', () => {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ input_tokens: 100, output_tokens: 1 }));
    });
    return;
  }
  res.writeHead(404); res.end();
});

server.listen(PORT, '127.0.0.1', () => log('Luna 代理启动 http://127.0.0.1:' + PORT));
