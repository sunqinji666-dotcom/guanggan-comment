#!/usr/bin/env node
/**
 * Musicbed 完整版下载 · 服务器自治版
 * 读 favorites_browse.json 里 download=true 且无 fullUrl 的歌，
 * playwright(注入孙哥登录 cookie) 打开歌曲页→点主播放→抓完整版 ogg→curl 下载→ffmpeg 192k→七牛→update_full 回写。
 * 用法：node fetch_full.js [--daemon]
 */
const { chromium } = require('/opt/mb-agent/node_modules/playwright');
const qiniu = require('/opt/mb-agent/node_modules/qiniu');
const fs = require('fs');
const { execSync } = require('child_process');
const https = require('https');

const DIR = '/opt/mb-agent';
const DATA = '/www/wwwroot/yuedu.jack-sun.com/musicbed/data/favorites_browse.json';
const API = 'https://yuedu.jack-sun.com/musicbed/musicbed_api.php';
const FFMPEG = '/usr/bin/ffmpeg';
const QINIU_BASE = 'https://yuedu.jack-sun.com/qiniu/';
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0 Safari/537.36';
const POLL = 180;

function log(msg) {
  const line = '[' + new Date().toLocaleString('zh-CN') + '] ' + msg;
  console.log(line);
  try { fs.appendFileSync(DIR + '/fetch_full.log', line + '\n'); } catch (e) {}
}
function readJson(p) { try { return JSON.parse(fs.readFileSync(p, 'utf8')); } catch (e) { return null; } }

function httpJson(url, body) {
  return new Promise((resolve, reject) => {
    const data = body ? JSON.stringify(body) : null;
    const u = new URL(url);
    const req = https.request(u, {
      method: body ? 'POST' : 'GET',
      headers: {
        'User-Agent': UA, 'Accept': 'application/json', 'Content-Type': 'application/json',
        ...(data ? { 'Content-Length': Buffer.byteLength(data) } : {})
      }
    }, res => {
      let d = ''; res.on('data', c => d += c); res.on('end', () => { try { resolve(JSON.parse(d)); } catch (e) { reject(new Error('bad json')); } });
    });
    req.on('error', reject);
    if (data) req.write(data);
    req.end();
  });
}

function buildSongUrl(sid) {
  return httpJson('https://novus-api.musicbed.com/api/songs/' + sid)
    .then(j => { const su = j && j.data && j.data.song_url; return su ? 'https://www.musicbed.com' + su : ''; })
    .catch(() => '');
}

async function fetchFullOgg(songUrl, sid) {
  const ck = readJson(DIR + '/mb_cookies.json') || {};
  if (!ck.xsrf || !ck.session) { log('  缺登录 cookie，无法抓完整版'); return ''; }
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-gpu'] });
  try {
    const ctx = await browser.newContext({ userAgent: UA });
    await ctx.addCookies([
      { name: 'XSRF-TOKEN', value: ck.xsrf, domain: '.musicbed.com', path: '/' },
      { name: 'musicbed_session_n', value: ck.session, domain: '.musicbed.com', path: '/' }
    ]);
    const page = await ctx.newPage();
    const reqs = [];
    page.on('request', r => { const u = r.url(); if (u.includes('/public/' + sid + '/ogg/')) reqs.push(u); });
    await page.goto(songUrl, { timeout: 40000, waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5000);
    await page.evaluate(() => { document.querySelectorAll('[role=dialog],[class*=Modal]').forEach(e => e.style.display = 'none'); });
    await page.waitForSelector('div.playButton', { timeout: 15000 });
    const btn = await page.evaluateHandle(() => {
      const bs = [...document.querySelectorAll('div.playButton')];
      return bs.reduce((a, b) => b.getBoundingClientRect().width > a.getBoundingClientRect().width ? b : a);
    });
    await btn.click();
    await page.waitForTimeout(12000);
    return reqs[0] || '';
  } finally { try { await browser.close(); } catch (e) {} }
}

function download(url, out) {
  try { execSync('curl -s -L --max-time 120 -o "' + out + '" "' + url + '"', { stdio: 'ignore' }); } catch (e) {}
  return fs.existsSync(out) && fs.statSync(out).size > 10000;
}
function transcode(raw, mp3) {
  try { execSync(FFMPEG + ' -y -loglevel error -i "' + raw + '" -codec:a libmp3lame -b:a 192k "' + mp3 + '"', { stdio: 'ignore' }); } catch (e) {}
  return fs.existsSync(mp3);
}
function qiniuUpload(local, key) {
  return new Promise(resolve => {
    try {
      const cfg = readJson(DIR + '/qiniu.json') || {};
      const mac = new qiniu.auth.digest.Mac(cfg.QINIU_ACCESS_KEY, cfg.QINIU_SECRET_KEY);
      const put = new qiniu.rs.PutPolicy({ scope: cfg.QINIU_BUCKET + ':' + key });
      const token = put.uploadToken(mac);
      const config = new qiniu.conf.Config({ zone: qiniu.zone[cfg.QINIU_ZONE] });
      new qiniu.form_up.FormUploader(config).putFile(token, key, local, new qiniu.form_up.PutExtra(), err => {
        resolve(err ? null : QINIU_BASE + key);
      });
    } catch (e) { resolve(null); }
  });
}
async function updateFull(sid, url) {
  try { const j = await httpJson(API, { source: 'browse', action: 'update_full', id: sid, fullUrl: url }); return !!j.ok; } catch (e) { return false; }
}

async function processFav(fav) {
  const sid = String(fav.id);
  const work = DIR + '/work'; fs.mkdirSync(work, { recursive: true });
  try {
    const songUrl = await buildSongUrl(sid);
    if (!songUrl) { log('  拿不到歌曲页: ' + sid); return false; }
    const ogg = await fetchFullOgg(songUrl, sid);
    if (!ogg) { log('  抓不到完整版: ' + sid); return false; }
    const raw = work + '/' + sid + '.ogg', mp3 = work + '/' + sid + '.mp3';
    if (!download(ogg, raw)) { log('  下载失败: ' + sid); return false; }
    if (!transcode(raw, mp3)) { log('  转码失败: ' + sid); return false; }
    const url = await qiniuUpload(mp3, 'musicbed/full/' + sid + '.mp3');
    if (!url) { log('  七牛上传失败: ' + sid); return false; }
    if (await updateFull(sid, url)) { log('  完整版就绪: ' + sid + ' → ' + url.slice(0, 70)); return true; }
    log('  回写失败: ' + sid); return false;
  } catch (e) { log('  处理异常 ' + sid + ': ' + e.message); return false; }
}

async function runOnce() {
  const favs = readJson(DATA) || [];
  if (!favs.length) return;
  const pending = favs.filter(f => f.download && !f.fullUrl);
  if (!pending.length) return;
  log('待抓完整版 ' + pending.length + ' 首（最多 3 并发）');
  const BATCH = 3; // 最多同时下 3 首
  for (let i = 0; i < pending.length; i += BATCH) {
    const chunk = pending.slice(i, i + BATCH);
    await Promise.all(chunk.map(fav => processFav(fav)));
  }
}

(async () => {
  const isDaemon = process.argv.includes('--daemon');
  if (isDaemon) log('==== 常驻下载服务启动（每 ' + POLL + 's 轮询） ====');
  do {
    try { await runOnce(); } catch (e) { log('daemon 出错: ' + e.message); }
    if (!isDaemon) break;
    await new Promise(r => setTimeout(r, POLL * 1000));
  } while (isDaemon);
})();
