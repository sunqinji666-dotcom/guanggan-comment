#!/usr/bin/env node
/**
 * Musicbed 每日热点 · 服务器自治版（30 秒试听）
 * 每天 10 个曲风（从曲风池轮换），每曲风 10 首，共 100 首，audioUrl = 30 秒试听（公开 API 直取，无需抓完整版）
 * 纯 API 生成：browse_proxy 按流派/情绪筛选 → playback_url → 生成 {date}.json → systemd timer 每日 08:00
 */
const fs = require('fs');
const https = require('https');

const DATA = '/www/wwwroot/yuedu.jack-sun.com/musicbed/data';
const BROWSE = 'https://yuedu.jack-sun.com/musicbed/browse_proxy.php';
const DIR = '/opt/mb-agent';
const KEEP_DAYS = 3;
const MOODS_PER_DAY = 10;
const SONGS_PER_MOOD = 10;
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126.0 Safari/537.36';

// 曲风池（流派 + 情绪混合）
const MOOD_POOL = [
  { en: 'Cinematic', zh: '电影配乐', type: 'genre', slug: 'cinematic' },
  { en: 'Ambient', zh: '氛围', type: 'genre', slug: 'ambient' },
  { en: 'Electronic', zh: '电子', type: 'genre', slug: 'electronic' },
  { en: 'Classical', zh: '古典', type: 'genre', slug: 'classical' },
  { en: 'Folk', zh: '民谣', type: 'genre', slug: 'folk' },
  { en: 'Rock', zh: '摇滚', type: 'genre', slug: 'rock' },
  { en: 'Pop', zh: '流行', type: 'genre', slug: 'pop' },
  { en: 'Jazz', zh: '爵士', type: 'genre', slug: 'jazz' },
  { en: 'Soul', zh: '灵魂乐', type: 'genre', slug: 'soul' },
  { en: 'World', zh: '世界', type: 'genre', slug: 'world' },
  { en: 'Chill', zh: '松弛', type: 'mood', slug: 'chill' },
  { en: 'Happy', zh: '欢快', type: 'mood', slug: 'happy' },
  { en: 'Uplifting', zh: '振奋', type: 'mood', slug: 'uplifting' },
  { en: 'Peaceful', zh: '宁静', type: 'mood', slug: 'peaceful' },
  { en: 'Tense', zh: '紧张', type: 'mood', slug: 'tense' },
  { en: 'Love', zh: '爱意', type: 'mood', slug: 'love' },
  { en: 'Sad', zh: '忧伤', type: 'mood', slug: 'sad' },
  { en: 'Ecstatic', zh: '亢奋', type: 'mood', slug: 'ecstatic' },
  { en: 'Carefree', zh: '自在', type: 'mood', slug: 'carefree' },
  { en: 'Contemplative', zh: '沉思', type: 'mood', slug: 'contemplative' },
];

function log(msg) {
  const line = '[' + new Date().toLocaleString('zh-CN') + '] ' + msg;
  console.log(line);
  try { fs.appendFileSync(DIR + '/daily_hot.log', line + '\n'); } catch (e) {}
}
function today() { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

function httpJson(url) {
  return new Promise((resolve, reject) => {
    https.get(url, { headers: { 'User-Agent': UA, 'Accept': 'application/json' } }, res => {
      let d = ''; res.on('data', c => d += c); res.on('end', () => { try { resolve(JSON.parse(d)); } catch (e) { reject(new Error('bad json')); } });
    }).on('error', reject);
  });
}

// 每天轮换的曲风（按日期偏移选 10 个）
function todaysMoods() {
  const days = Math.round((Date.now() - new Date(2026, 7, 17).getTime()) / 86400000);
  const out = [];
  for (let i = 0; i < MOODS_PER_DAY; i++) out.push(MOOD_POOL[(days * 2 + i) % MOOD_POOL.length]);
  return out;
}

// 每曲风拉 SONGS_PER_MOOD 首（30 秒试听 playback_url）
async function fetchMoodSongs(mood) {
  const key = mood.type === 'genre' ? 'genres' : 'moods';
  const url = BROWSE + '?action=search&' + key + '=' + encodeURIComponent(mood.slug) + '&order=popularity&page=1&limit=' + SONGS_PER_MOOD;
  try {
    const j = await httpJson(url);
    return (j.songs || []).map(s => ({
      id: s.id, name: s.name, artist: s.artist || '', cover: s.cover || '', length: s.length || '',
      audioUrl: s.playback_url || '', moods: s.moods || [], genres: s.genres || []
    })).filter(s => s.id && s.audioUrl);
  } catch (e) { log('曲风拉取失败 ' + mood.en + ': ' + e.message); return []; }
}

// 清理 3 天前 json
function cleanup(date) {
  const old = new Date(Date.now() - KEEP_DAYS * 86400000);
  const oldStr = old.getFullYear() + '-' + String(old.getMonth() + 1).padStart(2, '0') + '-' + String(old.getDate()).padStart(2, '0');
  try { fs.unlinkSync(DATA + '/' + oldStr + '.json'); log('已清理 ' + oldStr); } catch (e) {}
}

(async () => {
  const date = today();
  log('==== 每日热点开始（30秒试听版） ' + date + ' ====');
  try {
    if (fs.existsSync(DATA + '/' + date + '.json')) { log('今天已生成，跳过'); cleanup(date); return; }
    const moods = todaysMoods();
    log('今日曲风: ' + moods.map(m => m.zh).join(', '));
    const groups = [];
    for (const mood of moods) {
      const songs = await fetchMoodSongs(mood);
      if (songs.length) groups.push({ name: mood.zh, en: mood.en, songs });
      log('  ' + mood.zh + ' → ' + songs.length + ' 首');
    }
    if (!groups.length) { log('所有曲风都为空，作罢'); cleanup(date); return; }
    const total = groups.reduce((n, g) => n + g.songs.length, 0);
    fs.writeFileSync(DATA + '/' + date + '.json', JSON.stringify({ date, groups }, null, 1), 'utf8');
    log('已生成 ' + date + '.json，共 ' + groups.length + ' 组 ' + total + ' 首');
    cleanup(date);
    log('==== 完成 ====');
  } catch (e) { log('出错: ' + (e && e.message || e)); }
})();
