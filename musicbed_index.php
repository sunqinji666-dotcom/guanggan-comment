<?php
// Musicbed 热点歌曲：独立播放器页（下次轮换显示 / 中英对照 / 频谱 / 大进度 / 循环随机 / 歌词）
header('Cache-Control: no-cache');
$dir = __DIR__ . '/data';
$days = [];
if (is_dir($dir)) {
    foreach (glob($dir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].json') as $f) {
        $days[] = basename($f, '.json');
    }
}
rsort($days);
$days = array_slice($days, 0, 3);
$allSongs = [];
foreach ($days as $d) {
    $json = @file_get_contents($dir . '/' . $d . '.json');
    $data = $json ? json_decode($json, true) : null;
    if (!$data) continue;
    if (isset($data['groups']) && is_array($data['groups'])) {
        // 30 秒试听版：按曲风分组
        foreach ($data['groups'] as $g) {
            $gname = $g['zh'] ?? $g['name'] ?? '音乐';
            foreach (($g['songs'] ?? []) as $s) {
                $s['date'] = $d;
                $s['group'] = $gname;
                $allSongs[] = $s;
            }
        }
    } else {
        // 兼容旧结构（完整版）
        foreach (($data['songs'] ?? []) as $s) {
            $s['date'] = $d;
            $s['group'] = '今日精选';
            $allSongs[] = $s;
        }
    }
}
$week = ['日', '一', '二', '三', '四', '五', '六'];
function zdate($d) { $dt = new DateTime($d); return (int)$dt->format('n') . '月' . (int)$dt->format('j') . '日'; }
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/musicbed/favicon.svg" type="image/svg+xml">
<title>Musicbed 热点 · Jacksun</title>
<style>
:root {
  color-scheme: dark;
  --bg:#14110f; --paper:#201a15; --paper2:#241d17; --paper-hover:#2a211b;
  --text:#eee4d7; --muted:#9d9081; --line:rgba(255,255,255,.08); --amber:#d1a364; --amber2:#e6bd85;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{height:100vh;overflow:hidden;color:var(--text);background:radial-gradient(circle at 50% 0,rgba(209,163,100,.1),transparent 42%),linear-gradient(180deg,#1b1612,var(--bg));font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Helvetica Neue",sans-serif;-webkit-font-smoothing:antialiased;display:flex;align-items:center}
.wrap{width:min(1060px,100%);height:100vh;margin:0 auto;padding:22px 18px 10px;display:flex;flex-direction:column}
header{text-align:center;flex:none;margin-bottom:14px}
.head-top{display:inline-flex;align-items:center;gap:10px;margin-bottom:12px}
.mark{display:grid;width:34px;height:34px;place-items:center;border:1px solid rgba(238,228,215,.5);border-radius:50%;font-size:14px}
h1{font-family:ui-serif,"Songti SC","STSong",serif;font-size:clamp(26px,4vw,36px);font-weight:500;letter-spacing:-.03em}
.next{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:12px;padding:8px 16px;border:1px solid var(--line);border-radius:99px;background:rgba(33,26,22,.6);font-size:12.5px;color:var(--muted)}
.next b{color:var(--amber);font-weight:650}
.next .tag{color:var(--amber2)}
.fav-entry{margin-top:10px}
.fav-entry button{display:inline-flex;align-items:center;gap:5px;color:var(--amber);font-size:12.5px;border:1px solid rgba(209,163,100,.3);border-radius:99px;padding:6px 16px;background:transparent;cursor:pointer;font-family:inherit;transition:all .2s}
.fav-entry button:hover{background:rgba(209,163,100,.1);border-color:rgba(209,163,100,.5)}
/* 收藏浮窗列表 */
.fav-item{display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid var(--line)}
.fav-item:last-child{border-bottom:none}
.fav-item .fcover{width:40px;height:40px;flex:0 0 40px;border-radius:8px;overflow:hidden;background:#2a211b;display:grid;place-items:center;color:var(--muted);font-size:14px}
.fav-item .fcover img{width:100%;height:100%;object-fit:cover}
.fav-item .finfo{flex:1;min-width:0}
.fav-item .fname{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fav-item .fartist{font-size:11px;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fav-item audio{width:140px;height:30px;flex:0 0 auto}
.fav-item .rm{flex:0 0 auto;border:1px solid var(--line);background:var(--paper2);color:var(--muted);font-size:11px;padding:4px 10px;border-radius:7px;cursor:pointer;transition:all .15s}
.fav-item .rm:hover{color:#e08a7a;border-color:rgba(224,138,122,.4)}
@media(max-width:640px){.fav-item{flex-wrap:wrap}.fav-item audio{width:100%;order:5}}
main{display:grid;grid-template-columns:minmax(0,430px) 1fr;gap:18px;flex:1;min-height:0;margin-top:6px}
/* ---------- 播放器主体 ---------- */
.player{background:rgba(33,26,22,.82);border:1px solid var(--line);border-radius:20px;padding:16px 18px 14px;display:flex;flex-direction:column;align-items:center;min-height:0;overflow:hidden}
.art{position:relative;width:168px;height:168px;margin:2px 0 14px;flex:none}
.art img{width:100%;height:100%;object-fit:cover;border-radius:50%;border:6px solid #2a211b;box-shadow:0 0 0 1px var(--line),0 18px 50px rgba(0,0,0,.5);animation:spin 22s linear infinite}
.art.paused img{animation-play-state:paused}
.art .spindle{position:absolute;inset:0;display:grid;place-items:center}
.art .spindle span{width:10px;height:10px;border-radius:50%;background:var(--amber);border:2px solid #1b1612}
@keyframes spin{to{transform:rotate(360deg)}}
.song-info{text-align:center;width:100%}
.song-info .title{font-size:17px;font-weight:700;line-height:1.35}
.song-info .artist{font-size:13px;color:var(--muted);margin-top:4px}
.tags{display:flex;gap:6px;flex-wrap:wrap;justify-content:center;margin-top:10px}
.tag{font-size:11px;color:var(--amber);border:1px solid rgba(209,163,100,.35);border-radius:99px;padding:3px 10px}
.tag.mood{color:var(--muted);border-color:var(--line)}
/* 频谱 */
.spectrum{width:100%;height:46px;margin:12px 0 4px;flex:none}
.spectrum canvas{width:100%;height:100%;display:block}
/* 进度条 */
.progress-wrap{width:100%;margin-top:6px}
.progress{width:100%;height:26px;background:transparent;-webkit-appearance:none;appearance:none;cursor:pointer}
.progress::-webkit-slider-runnable-track{height:6px;border-radius:3px;background:linear-gradient(90deg,var(--amber) var(--p,0%),rgba(255,255,255,.12) var(--p,0%))}
.progress::-webkit-slider-thumb{-webkit-appearance:none;width:16px;height:16px;border-radius:50%;background:var(--amber2);margin-top:-5px;border:2px solid #1b1612;box-shadow:0 2px 8px rgba(0,0,0,.5)}
.time{display:flex;justify-content:space-between;font-size:11.5px;color:var(--muted);font-variant-numeric:tabular-nums;margin-top:2px}
/* 控制 */
.controls{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:10px;flex:none}
.cbtn{width:44px;height:44px;border-radius:50%;border:1px solid var(--line);background:var(--paper2);color:var(--text);font-size:17px;cursor:pointer;display:grid;place-items:center;transition:all .18s;user-select:none}
.cbtn:hover{border-color:rgba(209,163,100,.4);background:#2c241d;color:var(--amber2)}
.cbtn.main{width:58px;height:58px;font-size:22px;background:var(--amber);color:#1b1612;border:none}
.cbtn.main:hover{background:var(--amber2)}
.cbtn.active{color:var(--amber);border-color:rgba(209,163,100,.5);box-shadow:0 0 0 1px rgba(209,163,100,.3)}
.cbtn .zh{font-size:0}
/* 歌词 */
/* 歌词灯箱 */
.lb{position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;z-index:90;padding:20px}
.lb.show{display:flex}
.lb-box{width:min(560px,92vw);max-height:82vh;display:flex;flex-direction:column;background:var(--paper2);border:1px solid var(--line);border-radius:18px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.5)}
.lb-head{display:flex;align-items:center;justify-content:space-between;padding:13px 16px;border-bottom:1px solid var(--line)}
.lb-head #lbTitle{font-size:13px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-right:12px;flex:1}
.lb-actions{display:flex;gap:8px;flex:none}
.lb-btn{border:1px solid var(--line);background:var(--paper2);color:var(--text);font-size:12px;padding:6px 14px;border-radius:8px;cursor:pointer;transition:all .15s}
.lb-btn:hover{border-color:rgba(209,163,100,.4);color:var(--amber2)}
.lb-body{padding:18px 20px;overflow-y:auto;font-size:14px;line-height:2;color:var(--text);white-space:pre-wrap;max-height:60vh;scrollbar-width:thin}
.lb-empty{color:var(--muted);text-align:center;padding:30px 0}
/* 列表抽屉遮罩 */
.mask{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:38;display:none}
.mask.show{display:block}
/* 列表按钮：大屏隐藏，小屏显示 */
.list-btn{display:none}
.lyric-box.has{color:var(--text);opacity:.85}
.lyric-empty{font-size:12px;color:var(--muted);text-align:center;padding:18px 0}
/* ---------- 歌曲列表 ---------- */
.list{background:rgba(33,26,22,.6);border:1px solid var(--line);border-radius:20px;padding:14px;overflow-y:auto;min-height:0;scrollbar-width:thin}
.day-group{margin-bottom:14px}
.day-group:last-child{margin-bottom:0}
.day-label{display:flex;align-items:baseline;gap:10px;padding:8px 10px 10px;font-size:15px;font-weight:700;color:var(--amber)}
.day-label small{font-size:11px;color:var(--muted);font-weight:400}
.song-row{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;cursor:pointer;transition:background .15s}
.song-row:hover{background:rgba(255,255,255,.04)}
.song-row.playing{background:rgba(209,163,100,.12)}
.song-row .scover{width:38px;height:38px;flex:0 0 38px;border-radius:8px;overflow:hidden;background:#2a211b}
.song-row .scover img{width:100%;height:100%;object-fit:cover}
.song-row .scover.blank{display:grid;place-items:center;color:var(--muted);font-size:14px}
.song-row .sinfo{flex:1;min-width:0}
.song-row .sname{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.song-row .sartist{font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.song-row .seq{color:var(--muted);font-size:11px;flex:0 0 22px;text-align:center}
.song-row.playing .seq{color:var(--amber)}
.song-row .sind{width:6px;height:6px;border-radius:50%;background:transparent;flex:0 0 6px}
.song-row.playing .sind{background:var(--amber)}
footer{flex:none;color:#6f6458;font-size:10px;letter-spacing:.12em;text-align:center;padding-top:8px}
@media(max-width:760px){
  body{align-items:stretch}
  .wrap{padding:14px 12px 6px}
  main{display:block;flex:1;min-height:0;margin-top:4px}
  .player{height:100%}
  .art{width:112px;height:112px;margin:0 0 10px}
  .song-info .title{font-size:15px}
  .spectrum{height:40px;margin:10px 0 2px}
  .controls{margin-top:8px}
  .list-btn{display:grid}
  .list{position:fixed;top:0;right:0;height:100%;width:min(320px,84vw);border-radius:20px 0 0 20px;margin:0;z-index:40;transform:translateX(105%);transition:transform .32s cubic-bezier(.2,0,0,1);border-left:1px solid rgba(209,163,100,.25)}
  .list.open{transform:translateX(0)}
}
</style>
</head>
<body>
<div class="wrap">
<header>
  <div class="head-top"><span class="mark">J</span><h1>Musicbed 热点</h1></div>
  <div class="next" id="nextInfo">计算下次轮换中…</div>
  <div class="fav-entry"><button type="button" onclick="openFavLb()">♥ 我的收藏</button></div>
</header>
<main>
  <!-- 播放器主体 -->
  <section class="player" id="player">
    <div class="art" id="art"><img id="artImg" src="" alt=""><div class="spindle"><span></span></div></div>
    <div class="song-info">
      <div class="title" id="pTitle">还没有歌曲</div>
      <div class="artist" id="pArtist"></div>
      <div class="tags" id="pTags"></div>
    </div>
    <div class="spectrum"><canvas id="spec"></canvas></div>
    <div class="progress-wrap">
      <input type="range" class="progress" id="seek" min="0" max="1000" value="0">
      <div class="time"><span id="curT">0:00</span><span id="totT">0:00</span></div>
    </div>
    <div class="controls">
      <button class="cbtn" id="btnMode" title="循环模式"><span class="en">↻</span></button>
      <button class="cbtn" id="btnPrev" title="上一曲">⏮</button>
      <button class="cbtn main" id="btnPlay" title="播放/暂停">▶</button>
      <button class="cbtn" id="btnNext" title="下一曲">⏭</button>
      <button class="cbtn" id="btnLyric" title="歌词">词</button>
      <button class="cbtn" id="btnFav" title="收藏">♡</button>
      <button class="cbtn list-btn" id="btnList" title="歌单">☰</button>
    </div>
  </section>
  <!-- 歌曲列表 -->
  <section class="list" id="list"></section>
</main>
<div id="drawerMask" class="mask"></div>
<div id="lyricLb" class="lb">
  <div class="lb-box">
    <div class="lb-head">
      <span id="lbTitle"></span>
      <div class="lb-actions">
        <button class="lb-btn" id="lbCopy">复制</button>
        <button class="lb-btn" id="lbClose">✕</button>
      </div>
    </div>
    <div class="lb-body" id="lbLyric"></div>
  </div>
</div>
<div id="favLb" class="lb">
  <div class="lb-box" style="width:min(520px,94vw)">
    <div class="lb-head"><span>♥ 我的收藏</span><div class="lb-actions"><button class="lb-btn" id="favLbClose">✕</button></div></div>
    <div class="lb-body" id="favList" style="max-height:55vh;padding:4px 0"></div>
  </div>
</div>
<footer>MUSICBED HOT · YUEDU.JACK-SUN.COM</footer>
</div>
<script>
// ---------- 数据 ----------
window.__PLAYLIST = <?= json_encode($allSongs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;

// ---------- 中英对照 ----------
const MOOD_ZH = {Uplifting:'振奋',Carefree:'自在',Serious:'沉稳',Tense:'张力',Happy:'欢快',Peaceful:'宁静',Chill:'松弛',Ecstatic:'亢奋',Emotional:'深情',Dramatic:'戏剧',Hopeful:'希望',Love:'爱意',Sentimental:'感伤',Epic:'史诗',Romantic:'浪漫',Dark:'暗调',Dreamy:'梦幻',Empowering:'力量',Nostalgic:'怀旧',Playful:'俏皮',Mysterious:'神秘',Triumphant:'凯旋',Wonder:'惊叹'};
const GENRE_ZH = {Cinematic:'电影配乐',Electronic:'电子',Ambient:'氛围',Alternative:'独立',Pop:'流行',Rock:'摇滚','Hip Hop':'嘻哈',Soul:'灵魂乐',Indie:'独立',Classical:'古典',Folk:'民谣',World:'世界',Metal:'金属',Jazz:'爵士','Dance':'舞曲','R&B':'节奏布鲁斯'};
const zh = (w, map) => map[w] ? w + ' · ' + map[w] : (w || '');
const MOOD_ROTATION = ['Cinematic','Uplifting','Corporate','Ambient','Electronic','Inspiring','Happy','Peaceful','Emotional','Carefree'];

// ---------- 下次轮换 ----------
(function(){
  const base = new Date(2026, 7, 17, 8, 0, 0);
  const now = new Date();
  const today8 = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 8, 0, 0);
  const target = now >= today8 ? new Date(today8.getTime() + 86400000) : today8;
  const d = Math.round((target - base) / 86400000);
  const moods = [MOOD_ROTATION[d % 10], MOOD_ROTATION[(d + 3) % 10], MOOD_ROTATION[(d + 6) % 10]];
  const wk = ['日','一','二','三','四','五','六'][target.getDay()];
  document.getElementById('nextInfo').innerHTML =
    '下次更新 <b>' + (target.getMonth()+1) + '月' + target.getDate() + '日（周' + wk + '）08:00</b> · 曲风 ' +
    moods.map(m => '<span class="tag">' + m + ' · ' + (MOOD_ZH[m] || GENRE_ZH[m] || '') + '</span>').join(' ');
})();

// ---------- 播放器 ----------
const PL = window.__PLAYLIST || [];
let idx = PL.length ? 0 : -1;
let mode = 0; // 0顺序 1单曲循环 2随机
let queue = []; // 随机时已排序列

const audio = new Audio();
audio.preload = 'none';
document.body.appendChild(audio);

const $ = id => document.getElementById(id);
const fmt = s => { if(!isFinite(s)) return '0:00'; s = Math.floor(s); return Math.floor(s/60) + ':' + String(s%60).padStart(2,'0'); };
const tagsEl = $('pTags'), seekEl = $('seek');
let currentLyric = { title: '', text: '', inst: false };
let favSet = new Set();

// 频谱
let ctx, analyser, rafId;
function ensureAnalyser(){
  if (ctx) return;
  try {
    ctx = new (window.AudioContext || window.webkitAudioContext)();
    analyser = ctx.createAnalyser();
    analyser.fftSize = 256;
    const src = ctx.createMediaElementSource(audio);
    src.connect(analyser); analyser.connect(ctx.destination);
    drawSpec();
  } catch(e){}
}
function drawSpec(){
  const canvas = $('spec'), c = canvas.getContext('2d');
  const W = canvas.clientWidth || 300, H = canvas.clientHeight || 52;
  if (canvas.width !== W*2) canvas.width = W*2;
  if (canvas.height !== H*2) canvas.height = H*2;
  const n = 44, buf = new Uint8Array(analyser ? analyser.frequencyBinCount : 0);
  const draw = () => {
    rafId = requestAnimationFrame(draw);
    c.clearRect(0,0,canvas.width,canvas.height);
    if (!analyser) return;
    analyser.getByteFrequencyData(buf);
    const bw = canvas.width / n;
    const idle = !audio.paused && !audio.ended;
    for (let i=0;i<n;i++){
      let v;
      if (idle) {
        const bin = Math.floor(i * buf.length / n);
        v = buf[bin] / 255;
      } else v = 0.06 + 0.03*Math.sin(i);
      const bh = Math.max(3, v * canvas.height * 0.92);
      const g = c.createLinearGradient(0, canvas.height, 0, canvas.height - bh);
      g.addColorStop(0, 'rgba(209,163,100,.25)');
      g.addColorStop(1, '#d1a364');
      c.fillStyle = g;
      const x = i*bw + bw*0.2, w = bw*0.6;
      c.beginPath();
      c.roundRect(x, canvas.height - bh, w, bh, w/2);
      c.fill();
    }
  };
  draw();
}

// 渲染列表
function renderList(){
  const el = $('list');
  const byGroup = {};
  for (const s of PL) { const g = s.group || '今日精选'; (byGroup[g] = byGroup[g] || []).push(s); }
  el.innerHTML = '';
  for (const gname of Object.keys(byGroup)) {
    const songs = byGroup[gname];
    const grp = document.createElement('div');
    grp.className = 'day-group';
    grp.innerHTML = '<div class="day-label">' + gname + ' <small>' + songs.length + ' 首</small></div>';
    songs.forEach((s, si) => {
      const row = document.createElement('div');
      row.className = 'song-row';
      row.dataset.idx = PL.indexOf(s);
      const cov = s.cover ? '<img src="' + s.cover + '" loading="lazy" alt="">' : '<span>♪</span>';
      row.innerHTML = '<span class="seq">' + (si+1) + '</span>' +
        '<div class="scover' + (s.cover ? '' : ' blank') + '">' + cov + '</div>' +
        '<div class="sinfo"><div class="sname">' + (s.name||'').replace(/</g,'&lt;') + '</div><div class="sartist">' + (s.artist||'') + '</div></div>' +
        '<span class="sind"></span>';
      row.addEventListener('click', () => {
        if (idx !== +row.dataset.idx) { idx = +row.dataset.idx; load(idx, true); }
        closeDrawer();
      });
      el.appendChild(row);
    });
    el.appendChild(grp);
  }
}

function renderInfo(){
  const s = PL[idx];
  if (!s) return;
  $('pTitle').textContent = s.name || '';
  $('pArtist').textContent = s.artist || '';
  $('artImg').src = s.cover || '';
  $('art').classList.add('paused');
  if (!s.cover) $('artImg').removeAttribute('src');
  tagsEl.innerHTML = '';
  const addTag = (t, cls) => { const sp = document.createElement('span'); sp.className = 'tag ' + cls; sp.textContent = t; tagsEl.appendChild(sp); };
  if (s.bpm) addTag(s.bpm + ' BPM · 节奏', '');
  if (s.length) addTag(s.length + ' · 时长', '');
  (s.genres || []).slice(0,2).forEach(g => addTag(zh(g, GENRE_ZH), 'mood'));
  (s.moods || []).slice(0,3).forEach(m => addTag(zh(m, MOOD_ZH), 'mood'));
  // 歌词（存起来，点"词"按钮开灯箱）
  currentLyric = { title: s.name || '', text: s.lyrics || '', inst: !!s.instrumental };
  $('totT').textContent = fmt(s.length ? s.length.split(':').reduce((a,b)=>+a*60+ +b,0) : audio.duration);
  updateFavBtn();
  renderRows();
}

function renderRows(){
  document.querySelectorAll('.song-row').forEach(r => {
    r.classList.toggle('playing', +r.dataset.idx === idx);
  });
}

function load(i, autoplay){
  if (i < 0 || i >= PL.length) return;
  idx = i;
  const s = PL[i];
  audio.src = s.audioUrl;
  renderInfo();
  if (autoplay !== false) audio.play().then(onPlayStart).catch(()=>{});
  if (autoplay !== false) ensureAnalyser();
  if (autoplay !== false && ctx && ctx.state === 'suspended') ctx.resume();
}
function onPlayStart(){
  $('btnPlay').textContent = '❚❚';
  $('art').classList.remove('paused');
  if (ctx && ctx.state === 'suspended') ctx.resume();
}
function onPause(){ $('btnPlay').textContent = '▶'; $('art').classList.add('paused'); }

function nextIdx(){
  if (mode === 2) {
    const rest = PL.map((_,i)=>i).filter(i => i !== idx);
    if (!rest.length) return idx;
    return rest[Math.floor(Math.random()*rest.length)];
  }
  return (idx + 1) % PL.length;
}
function prevIdx(){
  const s = PL[idx];
  if (audio.currentTime > 4) { audio.currentTime = 0; return idx; }
  return (idx - 1 + PL.length) % PL.length;
}

// 事件
audio.addEventListener('play', onPlayStart);
audio.addEventListener('pause', onPause);
audio.addEventListener('ended', () => {
  if (mode === 1) { audio.currentTime = 0; audio.play().catch(()=>{}); return; }
  const ni = nextIdx();
  if (ni !== idx) load(ni, true);
  else { onPause(); }
});
audio.addEventListener('timeupdate', () => {
  if (!audio.duration) return;
  seekEl.value = audio.currentTime / audio.duration * 1000;
  seekEl.style.setProperty('--p', (audio.currentTime / audio.duration * 100) + '%');
  $('curT').textContent = fmt(audio.currentTime);
});
audio.addEventListener('loadedmetadata', () => {
  $('totT').textContent = fmt(audio.duration);
  seekEl.style.setProperty('--p', '0%');
});
seekEl.addEventListener('input', () => {
  if (!audio.duration) return;
  const t = seekEl.value / 1000 * audio.duration;
  audio.currentTime = t;
  $('curT').textContent = fmt(t);
  seekEl.style.setProperty('--p', (seekEl.value / 10) + '%');
});
$('btnPlay').addEventListener('click', () => {
  if (idx < 0) { if (PL.length) load(0, true); return; }
  ensureAnalyser();
  if (audio.paused) audio.play().then(onPlayStart).catch(()=>{});
  else audio.pause();
});
$('btnNext').addEventListener('click', () => { if (PL.length) load(nextIdx(), true); });
$('btnPrev').addEventListener('click', () => { if (PL.length) load(prevIdx(), true); });
$('btnMode').addEventListener('click', () => {
  mode = (mode + 1) % 3;
  $('btnMode').classList.toggle('active', mode !== 0);
  $('btnMode').textContent = mode === 0 ? '↻' : mode === 1 ? '🔂' : '🔀';
  $('btnMode').title = mode === 0 ? '顺序播放' : mode === 1 ? '单曲循环' : '随机播放';
});
// 歌词灯箱
function openLyric(){
  $('lbTitle').textContent = currentLyric.title || '歌词';
  const body = $('lbLyric');
  if (currentLyric.text) { body.className = 'lb-body'; body.textContent = currentLyric.text; }
  else { body.className = 'lb-body lb-empty'; body.textContent = currentLyric.inst ? '纯音乐 · 无歌词' : '暂无歌词'; }
  $('lyricLb').classList.add('show');
}
function closeLyric(){ $('lyricLb').classList.remove('show'); }
$('btnLyric').addEventListener('click', openLyric);
$('lbClose').addEventListener('click', closeLyric);
$('lyricLb').addEventListener('click', (e) => { if (e.target === $('lyricLb')) closeLyric(); });
$('lbCopy').addEventListener('click', async () => {
  if (!currentLyric.text) return;
  try {
    await navigator.clipboard.writeText(currentLyric.text);
    $('lbCopy').textContent = '已复制';
    setTimeout(() => { $('lbCopy').textContent = '复制'; }, 1500);
  } catch(e) { alert('复制失败'); }
});

// 列表抽屉（小屏）
function openDrawer(){ $('list').classList.add('open'); $('drawerMask').classList.add('show'); }
function closeDrawer(){ $('list').classList.remove('open'); $('drawerMask').classList.remove('show'); }
$('btnList').addEventListener('click', openDrawer);
$('drawerMask').addEventListener('click', closeDrawer);
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeLyric(); closeDrawer(); closeFavLb(); } });

// 收藏
async function loadFavs(){
  try {
    const r = await fetch('/musicbed/api.php?action=list');
    const j = await r.json();
    favSet = new Set((j.data || []).map(s => String(s.id)));
    updateFavBtn();
  } catch(e){}
}
function updateFavBtn(){
  const s = PL[idx];
  const fav = s && favSet.has(String(s.id));
  $('btnFav').textContent = fav ? '♥' : '♡';
  $('btnFav').classList.toggle('active', !!fav);
  $('btnFav').title = fav ? '取消收藏' : '收藏';
}
$('btnFav').addEventListener('click', async () => {
  const s = PL[idx];
  if (!s) return;
  const action = favSet.has(String(s.id)) ? 'remove' : 'add';
  try {
    const r = await fetch('/musicbed/api.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action, song: { id: s.id, name: s.name, artist: s.artist, audioUrl: s.audioUrl, cover: s.cover, bpm: s.bpm, length: s.length } })
    });
    const j = await r.json();
    if (j.ok) {
      favSet = new Set((j.data || []).map(x => String(x.id)));
      updateFavBtn();
    }
  } catch(e){}
});

// 收藏浮窗
async function renderFavList(){
  const box = $('favList');
  try {
    const r = await fetch('/musicbed/api.php?action=list');
    const j = await r.json();
    const favs = j.data || [];
    if (!favs.length) { box.innerHTML = '<div class="lb-empty">还没有收藏 ♡<br>在播放器里点 ♥ 收藏你喜欢的歌</div>'; return; }
    box.innerHTML = favs.map(s => '<div class="fav-item" data-id="' + s.id + '">' +
      '<div class="fcover">' + (s.cover ? '<img src="' + s.cover + '" loading="lazy" alt="">' : '♪') + '</div>' +
      '<div class="finfo"><div class="fname">' + String(s.name || '').replace(/</g, '&lt;') + '</div><div class="fartist">' + String(s.artist || '').replace(/</g, '&lt;') + '</div></div>' +
      (s.audioUrl ? '<audio controls preload="none" src="' + s.audioUrl + '"></audio>' : '') +
      '<button class="rm" data-id="' + s.id + '" data-url="' + (s.audioUrl || '') + '" onclick="unfavSong(this)">取消</button></div>').join('');
  } catch(e) { box.innerHTML = '<div class="lb-empty">加载失败</div>'; }
}
async function unfavSong(btn){
  const id = btn.dataset.id;
  const audioUrl = btn.dataset.url || '';
  const r = await fetch('/musicbed/api.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({action:'remove', song:{id, audioUrl}}) });
  const j = await r.json();
  if (j.ok) {
    favSet = new Set((j.data || []).map(x => String(x.id)));
    updateFavBtn();
    await renderFavList();
  } else alert('取消失败');
}
function openFavLb(){ $('favLb').classList.add('show'); renderFavList(); }
function closeFavLb(){ $('favLb').classList.remove('show'); }
$('favLbClose').addEventListener('click', closeFavLb);
$('favLb').addEventListener('click', (e) => { if (e.target === $('favLb')) closeFavLb(); });

// 启动
if (PL.length) renderList();
renderInfo();
loadFavs();
</script>
</body>
</html>
