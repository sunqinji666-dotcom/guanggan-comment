<?php
// Musicbed 中文馆 v5 · 完全复刻官网 Browse 框架：左侧筛选栏 + 右侧歌曲列表 + 底部播放条，仅文字翻译
header('Cache-Control: no-cache');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/musicbed/favicon.svg" type="image/svg+xml">
<title>Musicbed 中文馆 · 找歌</title>
<style>
:root{color-scheme:dark;--bg:#14110f;--paper:#1d1814;--paper2:#221c17;--hover:#2a211b;--text:#eee4d7;--muted:#9d9081;--line:rgba(255,255,255,.08);--amber:#d1a364;--amber2:#e6bd85}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{color:var(--text);background:radial-gradient(circle at 50% 0,rgba(209,163,100,.07),transparent 40%),linear-gradient(180deg,#1b1612,var(--bg));font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Helvetica Neue",sans-serif;-webkit-font-smoothing:antialiased;display:flex;flex-direction:column;height:100vh;overflow:hidden}
/* ---------- 顶部 ---------- */
.topnav{flex:none;background:rgba(20,17,15,.92);border-bottom:1px solid var(--line)}
.navbar{width:min(1240px,100%);margin:0 auto;padding:9px 18px;display:flex;align-items:center;gap:14px}
.brand{display:inline-flex;align-items:center;gap:9px}
.mark{display:grid;width:30px;height:30px;place-items:center;border:1px solid rgba(238,228,215,.5);border-radius:50%;font-size:12px}
.brand b{font-family:ui-serif,"Songti SC","STSong",serif;font-size:16px;font-weight:500}
.brand small{font-size:9.5px;color:var(--muted);display:block;line-height:1}
.navright{margin-left:auto;display:flex;gap:8px}
.navright a{font-size:12px;color:var(--muted);border:1px solid var(--line);border-radius:99px;padding:5px 13px;text-decoration:none}
.navright a.backbtn{color:var(--amber);border-color:rgba(209,163,100,.4)}
.navright a.backbtn:hover{background:rgba(209,163,100,.1);border-color:rgba(209,163,100,.7)}
.navright a:hover{color:var(--amber);border-color:rgba(209,163,100,.4)}
/* 搜索 */
.searchWrap{flex:none;width:min(1240px,100%);margin:10px auto 0;padding:0 18px}
.search{display:flex;border:1px solid var(--line);border-radius:11px;background:var(--paper);overflow:hidden;transition:border .15s}
.search:focus-within{border-color:rgba(209,163,100,.5)}
.search input{flex:1;padding:11px 18px;border:none;background:transparent;color:var(--text);font-size:13.5px;font-family:inherit;outline:none}
.search input::placeholder{color:var(--muted)}
.search button{padding:0 20px;border:none;background:transparent;color:var(--amber);font-size:13px;cursor:pointer;font-family:inherit;font-weight:650}
/* ---------- 主体：左筛选栏 + 右列表 ---------- */
.main{flex:1;min-height:0;width:min(1240px,100%);margin:0 auto;display:flex;position:relative}
/* 左筛选栏 */
.sidebar{flex:0 0 96px;border-right:1px solid var(--line);padding:14px 8px 50px;overflow-y:auto;scrollbar-width:thin;position:relative}
.sidebar .st{font-size:9.5px;color:var(--muted);letter-spacing:.08em;padding:6px 10px 4px}
.stab{display:block;width:100%;text-align:left;padding:7px 10px;border:none;border-radius:8px;background:transparent;color:var(--text);font-size:13px;font-family:inherit;cursor:pointer;transition:background .12s;line-height:1.3}
.stab:hover{background:var(--paper2)}
.stab.on{background:rgba(209,163,100,.14);color:var(--amber2)}
.stab small{display:block;font-size:10px;color:var(--muted);margin-top:1px}
.stab.on small{color:var(--amber)}
.stab.sel{color:var(--amber2)}
.stsep{height:1px;background:var(--line);margin:8px 10px}
/* 右列 */
.content{flex:1;min-width:0;display:flex;flex-direction:column;position:relative}
.rhead{flex:none;display:flex;align-items:baseline;gap:10px;padding:12px 20px 8px}
.rhead .rc{font-size:14px;font-weight:700}
.rhead .rp{margin-left:auto;font-size:12px;color:var(--muted)}
.rhead .sortsel select{padding:6px 10px;border-radius:8px;border:1px solid var(--line);background:var(--paper2);color:var(--text);font-size:12px;font-family:inherit;outline:none;cursor:pointer}
.rows{flex:1;overflow-y:auto;padding:0 14px 20px;scrollbar-width:thin}
.srow{display:flex;align-items:center;gap:12px;padding:13px 14px;border-radius:10px;cursor:pointer;transition:background .12s}
.srow:hover{background:rgba(255,255,255,.035)}
.srow.playing{background:rgba(209,163,100,.1)}
.srow .idx{width:22px;text-align:center;color:var(--muted);font-size:11.5px;flex:0 0 22px;font-variant-numeric:tabular-nums}
.srow.playing .idx{color:var(--amber)}
.srow .cover{width:46px;height:46px;flex:0 0 46px;border-radius:8px;overflow:hidden;background:#2a211b}
.srow .cover img{width:100%;height:100%;object-fit:cover}
.srow .main{flex:1;min-width:0}
.srow .name{font-size:14px;font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.srow.playing .name{color:var(--amber2)}
.srow .meta{display:flex;align-items:baseline;gap:8px;margin-top:2px}
.srow .ver{font-size:11px;color:var(--muted);flex:0 0 auto}
.srow .artist{font-size:11.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.srow .tags{display:flex;gap:4px;flex:0 0 auto;max-width:30%;overflow:hidden}
.srow .tags span{font-size:10px;color:var(--amber);border:1px solid rgba(209,163,100,.28);border-radius:99px;padding:2px 8px;white-space:nowrap}
.srow .tags span.m{color:var(--muted);border-color:var(--line)}
.srow .len{flex:0 0 auto;font-size:11.5px;color:var(--muted);font-variant-numeric:tabular-nums;width:40px;text-align:right}
.srow .acts{display:flex;gap:4px;flex:0 0 auto;opacity:0;transition:opacity .12s}
.srow:hover .acts,.srow.playing .acts{opacity:1}
.acts button{width:30px;height:30px;border-radius:50%;border:1px solid var(--line);background:var(--paper2);color:var(--text);font-size:12px;cursor:pointer;display:grid;place-items:center}
.acts button:hover{border-color:rgba(209,163,100,.4);color:var(--amber2)}
.acts button.fav.on{color:var(--amber);border-color:rgba(209,163,100,.5)}
.acts button.dl{color:var(--muted)}
.acts button.dl.on{color:var(--amber);border-color:rgba(209,163,100,.5)}
.acts button.dl.ready{color:#7fd1a3;border-color:rgba(127,209,163,.5)}
.acts .playm{width:34px;height:34px;font-size:13px}
.loading,.empty{text-align:center;color:var(--muted);padding:50px 0;font-size:13px}
/* 筛选展开面板（右侧浮出，官网同款） */
.fpop{position:absolute;left:96px;top:0;bottom:0;width:270px;background:var(--paper);border-right:1px solid rgba(209,163,100,.25);box-shadow:0 0 40px rgba(0,0,0,.5);z-index:20;display:none;flex-direction:column}
.fpop.open{display:flex}
.fpop-head{display:flex;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line);flex:none}
.fpop-head b{font-size:14px}
.fpop-head .cnt{margin-left:auto;font-size:11px;color:var(--amber)}
.fpop-body{flex:1;overflow-y:auto;padding:8px 8px 12px;scrollbar-width:thin}
.fpop-body .plbl{font-size:11px;font-weight:700;color:var(--muted);letter-spacing:.05em;padding:10px 12px 2px}
.fopt{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;cursor:pointer;font-size:12.5px;color:var(--text);transition:background .1s}
.fopt:hover{background:rgba(255,255,255,.04)}
.fopt.on{color:var(--amber2)}
.fopt .ck{width:16px;height:16px;flex:0 0 16px;border:1px solid var(--line);border-radius:4px;display:grid;place-items:center;font-size:10px;color:transparent}
.fopt.on .ck{color:#1b1612;background:var(--amber);border-color:var(--amber)}
.fopt .zn{margin-left:auto;font-size:10.5px;color:var(--muted)}
.fpop-foot{flex:none;display:flex;gap:8px;padding:10px 14px;border-top:1px solid var(--line)}
.fpop-foot .apply{flex:1;padding:9px;border-radius:9px;border:none;background:var(--amber);color:#1b1612;font-weight:700;font-size:12.5px;cursor:pointer;font-family:inherit}
.fpop-foot .cancel{padding:9px 14px;border-radius:9px;border:1px solid var(--line);background:transparent;color:var(--muted);cursor:pointer;font-family:inherit;font-size:12px}
/* 已选标签条 */
.selbar{flex:none;display:none;flex-wrap:wrap;gap:6px;align-items:center;padding:8px 20px;border-bottom:1px solid var(--line)}
.selbar.show{display:flex}
.seltag{font-size:11px;color:var(--amber);border:1px solid rgba(209,163,100,.35);border-radius:99px;padding:3px 10px;cursor:pointer}
.seltag:hover{background:rgba(209,163,100,.12)}
.selbar .clear{margin-left:auto;font-size:11.5px;color:var(--muted);border:none;background:transparent;cursor:pointer;padding:3px}
/* ---------- 底部播放条（官网同款） ---------- */
.playerbar{flex:none;background:rgba(23,18,14,.95);border-top:1px solid var(--line);z-index:30}
.pbar{width:min(1240px,100%);margin:0 auto;display:flex;align-items:center;gap:18px;padding:9px 18px}
.part{position:relative;width:56px;height:56px;flex:0 0 56px;border-radius:8px;overflow:hidden;background:#2a211b}
.part img{width:100%;height:100%;object-fit:cover}
.part.spin img{animation:spin 16s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.pinfo{flex:0 0 220px;min-width:0}
.pinfo .t{font-size:13.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pinfo .a{font-size:11px;color:var(--muted);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pctrl{display:flex;align-items:center;gap:7px;flex:0 0 auto}
.pbtn{width:36px;height:36px;border-radius:50%;border:1px solid var(--line);background:var(--paper2);color:var(--text);font-size:13px;cursor:pointer;display:grid;place-items:center}
.pbtn:hover{border-color:rgba(209,163,100,.4);color:var(--amber2)}
.pbtn.main{width:46px;height:46px;font-size:16px;background:var(--amber);color:#1b1612;border:none}
.pbtn.main:hover{background:var(--amber2)}
.pbtn.fav{color:var(--muted)}
.pbtn.fav.on{color:var(--amber);border-color:rgba(209,163,100,.5)}
.pbtn.dl{color:var(--muted)}
.pbtn.dl.on{color:var(--amber);border-color:rgba(209,163,100,.5)}
.pbtn.dl.ready{color:#7fd1a3;border-color:rgba(127,209,163,.5)}
.seekwrap{flex:1;min-width:120px;display:flex;align-items:center;gap:10px}
.seekwrap input[type=range]{flex:1;height:16px;-webkit-appearance:none;appearance:none;background:transparent;outline:none;cursor:pointer}
.seekwrap input[type=range]::-webkit-slider-runnable-track{height:5px;border-radius:3px;background:linear-gradient(90deg,var(--amber2) var(--p,0%),rgba(255,255,255,.10) var(--p,0%)),repeating-linear-gradient(90deg,rgba(255,255,255,.08) 0 1px,transparent 1px 8%),rgba(255,255,255,.05);box-shadow:inset 0 1px 2px rgba(0,0,0,.55)}
.seekwrap input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:16px;height:16px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#f7e0ad,#dcab5d 62%,#b07c35);border:2px solid rgba(27,22,18,.9);box-shadow:0 0 0 3px rgba(233,196,125,.14),0 0 12px rgba(233,196,125,.5),0 2px 5px rgba(0,0,0,.5);cursor:pointer;transition:box-shadow .15s,transform .15s;margin-top:-5.5px}
.seekwrap input[type=range]:hover::-webkit-slider-thumb{box-shadow:0 0 0 4px rgba(233,196,125,.22),0 0 18px rgba(233,196,125,.7),0 2px 5px rgba(0,0,0,.5);transform:scale(1.07)}
.tm{font-size:10.5px;color:var(--muted);font-variant-numeric:tabular-nums;flex:0 0 auto}
/* 收藏浮窗 */
.favlb{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:90;display:none;align-items:center;justify-content:center}
.favlb.show{display:flex}
.favlb-box{width:min(580px,94vw);max-height:82vh;background:var(--paper);border:1px solid rgba(209,163,100,.22);border-radius:16px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.5)}
.favlb-head{display:flex;align-items:center;padding:14px 18px;border-bottom:1px solid var(--line);flex:none}
.favlb-head b{font-size:15px}
.favlb-head .x{margin-left:auto;border:1px solid var(--line);background:var(--paper2);color:var(--muted);width:30px;height:30px;border-radius:50%;cursor:pointer}
.favlb-body{flex:1;overflow-y:auto;padding:8px;scrollbar-width:thin}
.favlb-item{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:10px;cursor:pointer}
.favlb-item:hover{background:rgba(255,255,255,.04)}
.favlb-item img{width:42px;height:42px;border-radius:8px;object-fit:cover;flex:0 0 42px}
.favlb-item .fi{flex:1;min-width:0}
.favlb-item .fn{font-size:13px;font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.favlb-item .fa{font-size:11px;color:var(--muted)}
.favlb-item .st{font-size:10px;color:var(--muted);flex:0 0 auto;white-space:nowrap}
.favlb-item .st.dl{color:var(--amber)}
.favlb-item .st.rd{color:#7fd1a3}
.favlb-item .ops{display:flex;gap:4px;flex:0 0 auto}
.favlb-item .ops button{width:30px;height:30px;border-radius:50%;border:1px solid var(--line);background:var(--paper2);color:var(--text);font-size:12px;cursor:pointer;display:grid;place-items:center}
.favlb-item .ops button:hover{border-color:rgba(209,163,100,.4);color:var(--amber2)}
.favlb-empty{text-align:center;color:var(--muted);padding:40px 0;font-size:13px}
footer{flex:none;text-align:center;color:#6f6458;font-size:9px;letter-spacing:.12em;padding:4px 0 8px}
@media(max-width:820px){
  .sidebar{flex-basis:76px}
  .fpop{left:76px}
  .pinfo{flex-basis:150px}
  .srow .tags,.srow .idx{display:none}
  .srow .acts{opacity:1}
}
@media(max-width:560px){
  .sidebar{flex-basis:64px}
  .fpop{left:64px;width:240px}
  .pinfo{flex-basis:110px}
  .brand small,.srow .artist{display:none}
}
</style>
</head>
<body>
<nav class="topnav"><div class="navbar">
  <div class="brand"><span class="mark">♫</span><div><b>Musicbed 中文馆</b><small>结果与官网一致 · 30秒试听</small></div></div>
  <div class="navright"><a href="#" id="favBtn2" style="color:var(--amber)">♥ 收藏</a><a href="/musicbed/" class="backbtn">‹ 返回每日热点</a></div>
</div></nav>

<div class="searchWrap"><div class="search">
  <input id="q" type="search" placeholder="搜索 mood 情绪 / genre 流派 / 主题…" autocomplete="off">
  <button id="qBtn">搜索</button>
</div></div>

<div class="main">
  <!-- 左侧筛选栏（复刻官网） -->
  <aside class="sidebar">
    <div class="st">筛选</div>
    <button class="stab" data-dim="genres">流派</button>
    <button class="stab" data-dim="moods">情绪</button>
    <button class="stab" data-dim="artists">艺人</button>
    <button class="stab" data-dim="chars">主题</button>
    <button class="stab" data-dim="instruments">乐器</button>
    <button class="stab" data-dim="advanced">高级</button>
    <div class="stsep"></div>
    <button class="stab" id="tgLyrical">有人声</button>
    <button class="stab" id="tgInst">纯器乐</button>
    <div style="position:absolute;bottom:8px;left:8px;right:8px;font-size:8.5px;color:#6f6458;line-height:1.5;padding:0 4px">试听 30 秒<br>商用请至官网授权</div>
  </aside>

  <!-- 右侧内容 -->
  <div class="content">
    <div class="rhead">
      <span class="rc" id="rCount">—</span><span class="rp" id="rPage"></span>
      <div class="sortsel"><select id="sort"><option value="default">默认排序</option><option value="popularity">最热</option><option value="newest">最新</option></select></div>
    </div>
    <div class="selbar" id="selbar"></div>
    <div class="rows" id="rows"><div class="loading">加载中…</div></div>
    <div class="empty" id="empty" style="display:none">没有找到匹配的歌曲</div>
    <div style="flex:none;text-align:center;padding:0 0 10px">
      <button class="pbtn" id="prevBtn" style="width:auto;border-radius:99px;padding:0 16px">← 上一页</button>
      <span class="ppage" id="pageInfo" style="font-size:11.5px;color:var(--muted);margin:0 12px"></span>
      <button class="pbtn" id="nextBtn" style="width:auto;border-radius:99px;padding:0 16px">下一页 →</button>
    </div>
  </div>

  <!-- 筛选展开面板 -->
  <div class="fpop" id="fpop">
    <div class="fpop-head"><b id="fpTitle">筛选</b><span class="cnt" id="fpCount"></span></div>
    <div class="fpop-body" id="fpBody"></div>
    <div class="fpop-foot"><button class="cancel" id="fpCancel">清除</button><button class="apply" id="fpApply">应用筛选</button></div>
  </div>
</div>

<!-- 底部播放条（复刻官网） -->
<div class="playerbar">
  <div class="pbar">
    <div class="part" id="pArt"><img id="pImg" alt=""></div>
    <div class="pinfo"><div class="t" id="pT">选择一首歌播放</div><div class="a" id="pA"></div></div>
    <div class="pctrl">
      <button class="pbtn" id="btnPrev">⏮</button>
      <button class="pbtn main" id="btnPlay">▶</button>
      <button class="pbtn" id="btnNext">⏭</button>
    </div>
    <div class="seekwrap">
      <input type="range" id="seek" min="0" max="1000" value="0">
      <span class="tm" id="ptime">0:00 / 0:00</span>
    </div>
    <button class="pbtn fav" id="btnFav" title="普通收藏">♡</button>
    <button class="pbtn dl" id="btnDl" title="需要下载完整版">⬇</button>
  </div>
</div>

<!-- 收藏列表浮窗 -->
<div class="favlb" id="favlb">
  <div class="favlb-box">
    <div class="favlb-head"><b>♥ 中文馆收藏</b><button class="x" id="favlbClose">✕</button></div>
    <div class="favlb-body" id="favlbBody"></div>
  </div>
</div>

<script>
// ---------- 中英对照 ----------
const GENRE_ZH={Alternative:'独立',Ambient:'氛围',Blues:'蓝调',Cinematic:'电影配乐',Classical:'古典',Country:'乡村',Electronic:'电子',Folk:'民谣','Hip Hop':'嘻哈',Indie:'独立',Jazz:'爵士',Pop:'流行','Post Rock':'后摇滚','R&B':'节奏布鲁斯',Rock:'摇滚','Singer-Songwriter':'唱作人',Soul:'灵魂乐','Spoken Word':'口述',Vintage:'复古',World:'世界'};
const MOOD_ZH={Angry:'愤怒',Carefree:'自在',Chill:'松弛',Contemplative:'沉思',Ecstatic:'亢奋',Eerie:'诡异',Happy:'欢快',Love:'爱意',Peaceful:'宁静',Sad:'忧伤',Serious:'沉稳',Somber:'阴郁',Tense:'紧张',Uplifting:'振奋'};
const VOCAL_ZH={Ambient:'氛围',Choir:'合唱',Duet:'二重唱',Female:'女声',Group:'群声',Harmony:'和声',Male:'男声','Oohs & Ahhs':'哼鸣',Screaming:'嘶吼',Shouts:'呐喊'};
const CHAR_ZH={Acoustic:'原声',Adventurous:'冒险',African:'非洲',Aggressive:'激进',Americana:'美式乡村',Anthemic:'激昂',Atmospheric:'氛围',Ballad:'抒情',Beach:'海滩',Beautiful:'优美',Bouncy:'轻快',Caribbean:'加勒比',Celtic:'凯尔特',Chinese:'中国风',Dance:'舞曲',Dark:'暗调',Dramatic:'戏剧',Driving:'推进感',Earthy:'朴实',Epic:'史诗',Ethereal:'空灵',Fun:'趣味',Funky:'放克',Gloomy:'阴郁',Gospel:'福音',Groove:'律动',Holiday:'假日',Industrial:'工业',Italian:'意大利',Korean:'韩语',Latin:'拉丁',Lofi:'低保真',Lounge:'休闲',Lullaby:'摇篮曲',Mandarin:'国语',Metal:'金属','Middle Eastern':'中东',Minimal:'极简',Motown:'摩城',Mysterious:'神秘',Orchestral:'管弦乐',Playful:'俏皮',Psychedelic:'迷幻',Punk:'朋克',Quirky:'古怪',Rap:'说唱',Raw:'粗粝',Reggae:'雷鬼',Retro:'复古','Sci-Fi':'科幻',Sexy:'性感',Soft:'柔和','Solo Instrument':'独奏',Spanish:'西语',Spiritual:'灵性',Surf:'冲浪',Suspense:'悬疑',Tribal:'部落',Upbeat:'欢快',Western:'西部',Whimsical:'奇想',Wild:'狂野',Wondrous:'奇妙',Worship:'敬拜','A Cappella':'无伴奏合唱',Abstract:'抽象',Beat:'节拍','Bluegrass ':'蓝草音乐','Classical Collection':'古典合集',Dubstep:'回响贝斯','East Asian':'东亚','Easy Listening':'轻音乐',Enchanted:'迷人',Espionage:'谍战',Explicit:'露骨','Foreign Language':'外语',French:'法语',Glitchy:'故障感',Grungy:'邋遢摇滚','Native American':'美洲原住民',Norwegian:'挪威','Public Domain':'公有领域',Pulsing:'脉冲感',Russian:'俄语',Shuffle:'曳步',Static:'静电',Swagger:'张扬','Tagalog/Filipino':'他加禄语','Train Beat':'列车节奏','Vintage Collection':'复古合集'};
const COUNTRIES_ZH={Australia:'澳大利亚',Austria:'奥地利',Belgium:'比利时',Brazil:'巴西',Canada:'加拿大','Czech Republic':'捷克',Denmark:'丹麦',Finland:'芬兰',France:'法国',Germany:'德国',Ghana:'加纳',Guatemala:'危地马拉',Honduras:'洪都拉斯','Hong Kong SAR China':'中国香港',India:'印度',Iran:'伊朗',Ireland:'爱尔兰',Israel:'以色列',Italy:'意大利',Jamaica:'牙买加',Japan:'日本',Kenya:'肯尼亚',Laos:'老挝',Macedonia:'马其顿',Mexico:'墨西哥',Netherlands:'荷兰','New Zealand':'新西兰',Nigeria:'尼日利亚',Norway:'挪威',Peru:'秘鲁',Philippines:'菲律宾',Poland:'波兰',Portugal:'葡萄牙',Russia:'俄罗斯',Singapore:'新加坡','South Africa':'南非','South Korea':'韩国',Spain:'西班牙',Sweden:'瑞典',Switzerland:'瑞士',Tunisia:'突尼斯',Turkey:'土耳其','U.S. Minor Outlying Islands':'美国海外小岛',Ukraine:'乌克兰','United Kingdom':'英国','United States':'美国',Venezuela:'委内瑞拉',Zimbabwe:'津巴布韦'};
const DIM_MAP={genres:GENRE_ZH,moods:MOOD_ZH,vocals:VOCAL_ZH,chars:CHAR_ZH,countries:COUNTRIES_ZH};
const DIM_ZH={genres:'流派',moods:'情绪',artists:'艺人',chars:'主题',instruments:'乐器',keys:'调性',countries:'国家',advanced:'高级'};
const DIM_EN={genres:'Genre',moods:'Mood',artists:'Artist',chars:'Attributes',instruments:'Instrument',keys:'Key',countries:'Country'};
const zh=(w,m)=>m[w]?w+'·'+m[w]:w;
function keyZh(name){const m=(name||'').match(/^([A-G])([#b]?)\s+(major|minor)$/);if(!m)return name;const n=m[1]+(m[2]==='#'?'♯':m[2]==='b'?'♭':'');return n+' '+(m[3]==='major'?'大调':'小调');}
function zhName(dim,name){if(dim==='keys')return keyZh(name);const map=DIM_MAP[dim]||{};return map[name]?name+'·'+map[name]:name;}

// ---------- 状态 ----------
let FILTERS=null;
const sel={genres:[],moods:[],artists:[],chars:[],instruments:[],keys:[],countries:[]};
let lyrical='';
let order='default',page=1,totalPages=1,total=0;
let songs=[],curIdx=-1,favSet=new Set(),dlSet=new Set(),fullMap={},favList=[];
let curPop=null; // 当前展开的筛选维度
const audio=new Audio();
const $=id=>document.getElementById(id);
const thumb=u=>u?u.replace('/image/upload/','/image/upload/c_fill,w_160,h_160/'):'';
const esc=s=>String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fmt=s=>{if(!isFinite(s))return'0:00';s=Math.floor(s);return Math.floor(s/60)+':'+String(s%60).padStart(2,'0');};
const zhOf=(dim,slug)=>{const it=(FILTERS?.dimensions[dim]||[]).find(i=>i.slug===slug);if(!it)return slug;return zhName(dim,it.name);};

// ---------- 筛选展开面板 ----------
function fpOpts(dim){
  const list=FILTERS?.dimensions[dim]||[];
  return list.slice(0,80).map(it=>{
    const on=sel[dim]?.includes(it.slug);
    return `<div class="fopt ${on?'on':''}" data-dim="${dim}" data-slug="${esc(it.slug)}"><span class="ck">✓</span>${esc(zhName(dim,it.name))}</div>`;
  }).join('');
}
function openPop(dim){
  if(curPop===dim){closePop();return;}
  curPop=dim;
  $('fpTitle').textContent=DIM_ZH[dim]||dim;
  let body='';
  if(dim==='advanced'){
    body+=`<div class="plbl">调性</div>`+fpOpts('keys')+`<div class="plbl">国家</div>`+fpOpts('countries');
  }else if(dim==='artists'){
    body+=`<input class="artist-search" id="fpArtistQ" placeholder="输入艺人名…" style="width:100%;margin:4px 4px 8px;padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:var(--paper2);color:var(--text);font-size:12px;outline:none">`+fpOpts('artists');
  }else{
    body+=fpOpts(dim);
  }
  $('fpBody').innerHTML=body;
  $('fpop').classList.add('open');
  bindFpOpts(dim);
  if(dim==='artists')bindArtistSearch();
}
function bindArtistSearch(){
  const aq=document.getElementById('fpArtistQ');
  if(!aq)return;
  aq.addEventListener('input',()=>{
    const ql=aq.value.trim().toLowerCase();
    const l=(FILTERS.dimensions.artists||[]).filter(a=>!ql||a.name.toLowerCase().includes(ql)).slice(0,60);
    $('fpBody').innerHTML='<input class="artist-search" id="fpArtistQ" placeholder="输入艺人名…" style="width:100%;margin:4px 4px 8px;padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:var(--paper2);color:var(--text);font-size:12px;outline:none">'+l.map(it=>`<div class="fopt ${sel.artists.includes(it.slug)?'on':''}" data-dim="artists" data-slug="${esc(it.slug)}"><span class="ck">✓</span>${esc(it.name)}</div>`).join('');
    bindFpOpts('artists');
    bindArtistSearch();
  });
}
function updateFpCount(){
  if(!curPop){$('fpCount').textContent='';return;}
  const n=curPop==='advanced'?(sel.keys.length+sel.countries.length):(sel[curPop]?.length||0);
  $('fpCount').textContent=n?n+' 已选':'';
}
function bindFpOpts(dim){
  document.querySelectorAll('#fpBody .fopt').forEach(o=>o.onclick=()=>{
    const a=sel[o.dataset.dim],i=a.indexOf(o.dataset.slug);
    i>=0?a.splice(i,1):a.push(o.dataset.slug);
    o.classList.toggle('on');
    updateFpCount();
  });
  updateFpCount();
}
function closePop(){$('fpop').classList.remove('open');curPop=null;}
function applyPop(){closePop();renderSel();renderStabs();doSearch(1);}
function clearPop(){for(const k of Object.keys(sel))sel[k]=[];lyrical='';closePop();renderSel();renderStabs();setLyricalUI();doSearch(1);}

// ---------- 左栏状态 ----------
function renderStabs(){
  document.querySelectorAll('.stab[data-dim]').forEach(b=>{
    const d=b.dataset.dim;
    b.classList.toggle('sel',d==='advanced'?(sel.keys.length+sel.countries.length>0):sel[d]?.length>0);
    b.classList.toggle('on',curPop===d);
  });
}
function selCount(){return Object.values(sel).reduce((a,b)=>a+b.length,0)+(lyrical?1:0);}
function renderSel(){
  const bar=$('selbar');
  if(!selCount()){bar.classList.remove('show');bar.innerHTML='';return;}
  bar.classList.add('show');
  bar.innerHTML=Object.entries(sel).flatMap(([dim,list])=>list.map(slug=>`<span class="seltag" onclick="removeSel('${dim}','${slug}')">${DIM_ZH[dim]}：${esc(zhOf(dim,slug))} ✕</span>`)).join('')
    +(lyrical?`<span class="seltag" onclick="setLyrical('')">${lyrical==='instrumental'?'纯器乐':'有人声'} ✕</span>`:'')
    +`<button class="clear" onclick="clearPop()">清除全部</button>`;
}
function removeSel(dim,slug){const a=sel[dim],i=a.indexOf(slug);if(i>=0)a.splice(i,1);renderSel();renderStabs();doSearch(1);}
function setLyrical(v){lyrical=v;renderSel();setLyricalUI();doSearch(1);}
function setLyricalUI(){
  document.getElementById('tgLyrical').classList.toggle('on',lyrical==='lyrical');
  document.getElementById('tgInst').classList.toggle('on',lyrical==='instrumental');
}

// ---------- 加载筛选 ----------
async function loadFilters(){
  try{const r=await fetch('browse_proxy.php?action=filters');const j=await r.json();if(!j.ok)throw 0;FILTERS=j;doSearch(1);}
  catch(e){document.getElementById('rows').innerHTML='<div class="empty">加载失败，请刷新</div>';}
}

// ---------- 搜索（中文词 → 分类） ----------
function doSearchFromQ(){
  const q=$('q').value.trim();if(!q)return;
  const ql=q.toLowerCase();let hit=0;
  for(const dim of Object.keys(sel)){
    for(const it of (FILTERS.dimensions[dim]||[])){
      const name=(it.name||'').toLowerCase(),slug=(it.slug||'').toLowerCase();
      const zhv=((DIM_MAP[dim]||{})[it.name]||'').toLowerCase();
      if((name&&name.includes(ql))||(zhv&&zhv.includes(ql))||(slug&&slug.includes(ql))){
        if(!sel[dim].includes(it.slug)){sel[dim].push(it.slug);hit++;}
      }
    }
  }
  if(hit){renderSel();renderStabs();doSearch(1);}
  else alert('没有匹配到分类，试试「电影配乐」「电子」「松弛」等词');
}

// ---------- 拉歌曲 ----------
async function doSearch(p){
  page=Math.max(1,p);
  document.getElementById('rows').innerHTML='<div class="loading">加载中…</div>';
  $('empty').style.display='none';
  const qs=new URLSearchParams({action:'search',order,page});
  for(const [dim,list] of Object.entries(sel)){if(list.length)qs.set(dim,list.join(','));}
  if(lyrical)qs.set('instrumental',lyrical==='instrumental'?'true':'false');
  try{
    const r=await fetch('browse_proxy.php?'+qs);const j=await r.json();
    if(!j.ok)throw 0;
    songs=j.songs||[];curIdx=-1;total=j.meta.total||0;totalPages=j.meta.last_page||1;
    $('rCount').textContent=total+' 首';$('rPage').textContent=page+' / '+totalPages+' 页';
    $('pageInfo').textContent='第 '+page+' / '+totalPages+' 页';
    $('prevBtn').disabled=page<=1;$('nextBtn').disabled=page>=totalPages;
    renderRows();
  }catch(e){document.getElementById('rows').innerHTML='<div class="empty">加载失败，请重试</div>';}
}
function renderRows(){
  const rows=$('rows');
  $('empty').style.display=songs.length?'none':'block';
  if(!songs.length){rows.innerHTML='';return;}
  rows.innerHTML=songs.map((s,i)=>{
    const tg=[...s.genres.slice(0,2).map(x=>'<span>'+esc(zh(x,GENRE_ZH))+'</span>'),...s.moods.slice(0,2).map(x=>'<span class="m">'+esc(zh(x,MOOD_ZH))+'</span>')];
    if(s.instrumental)tg.push('<span class="m">器乐</span>');
    const active=s.id===songs[curIdx]?.id;
    return `<div class="srow ${active?'playing':''}" data-i="${i}">
      <span class="idx">${active?'♫':((page-1)*50+i+1)}</span>
      <div class="cover"><img src="${esc(thumb(s.cover))}" alt="" loading="lazy" onerror="this.style.display='none'"></div>
      <div class="main"><div class="name">${esc(s.name)}</div>
        <div class="meta">${s.versions>0?`<span class="ver">+${s.versions}</span>`:''}<span class="artist">${esc(s.artist)}</span></div></div>
      <div class="tags">${tg.join('')}</div>
      <span class="len">${esc(s.length)}</span>
      <div class="acts">
        <button class="playm" data-act="play">${active?'⏸':'▶'}</button>
        <button class="fav ${favSet.has(String(s.id))?'on':''}" data-act="fav">${favSet.has(String(s.id))?'♥':'♡'}</button>
        <button class="dl ${dlSet.has(String(s.id))?'on':''} ${fullMap[s.id]?'ready':''}" data-act="dl" title="${fullMap[s.id]?'下载完整版':'需要下载'}">${fullMap[s.id]?'✓':'⬇'}</button>
      </div>
    </div>`;
  }).join('');
  rows.querySelectorAll('.srow').forEach(r=>{
    r.onclick=e=>{
      const act=e.target.closest('[data-act]');
      const i=+r.dataset.i;
      if(act){if(act.dataset.act==='fav')toggleFav(i);else if(act.dataset.act==='dl')toggleDl(i);else playSong(i);return;}
      playSong(i);
    };
  });
}

// ---------- 播放 ----------
function playSong(i){
  if(i<0||i>=songs.length)return;
  curIdx=i;const s=songs[i];
  const raw=fullMap[s.id]||s.playback_url;
  const src=raw&&raw.indexOf('30-custom-ogg')>=0?('audio_proxy.php?u='+encodeURIComponent(raw)):raw;
  if(audio.src!==src){audio.src=src;audio.play();}else audio.paused?audio.play():audio.pause();
  $('pImg').src=thumb(s.cover);$('pArt').classList.add('spin');
  $('pT').textContent=s.name;$('pA').textContent=s.artist+' · '+s.album;
  renderFavs();
}
function step(d){if(!songs.length)return;playSong((curIdx+d+songs.length)%songs.length);}
audio.addEventListener('play',()=>{$('btnPlay').textContent='⏸';$('pArt').classList.add('spin');renderRows();});
audio.addEventListener('pause',()=>{$('btnPlay').textContent='▶';$('pArt').classList.remove('spin');});
audio.addEventListener('ended',()=>step(1));
audio.addEventListener('timeupdate',()=>{
  $('ptime').textContent=fmt(audio.currentTime)+' / '+fmt(audio.duration);
  $('seek').value=audio.duration?Math.round(audio.currentTime/audio.duration*1000):0;
  $('seek').style.setProperty('--p',(audio.currentTime/(audio.duration||1)*100)+'%');
});
$('seek').addEventListener('input',e=>{if(audio.duration)audio.currentTime=e.target.value/1000*audio.duration;e.target.style.setProperty('--p',(e.target.value/10)+'%');});
let toastTimer;
function toast(msg){
  let t=document.getElementById('toast');
  if(!t){t=document.createElement('div');t.id='toast';t.style.cssText='position:fixed;left:50%;bottom:120px;transform:translateX(-50%);background:var(--amber);color:#1b1612;padding:10px 20px;border-radius:99px;font-size:13px;font-weight:650;z-index:100;box-shadow:0 8px 30px rgba(0,0,0,.4)';document.body.appendChild(t);}
  t.textContent=msg;t.style.opacity='1';t.style.transition='opacity .3s';
  clearTimeout(toastTimer);toastTimer=setTimeout(()=>{t.style.opacity='0';},2500);
}
async function favApi(action, song){
  try{const r=await fetch('musicbed_api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({source:'browse',action,song})});const j=await r.json();return !!j.ok;}catch(e){return false;}
}
async function toggleFav(i){
  const s=songs[i];if(!s)return;
  const id=String(s.id);const has=favSet.has(id);
  const ok=await favApi(has?'remove':'add',{id:s.id,name:s.name,artist:s.artist,audioUrl:s.playback_url,cover:s.cover,length:s.length,download:dlSet.has(id)});
  if(ok){has?favSet.delete(id):favSet.add(id);loadFavs();renderFavs();}
}
async function toggleDl(i){
  const s=songs[i];if(!s)return;
  const id=String(s.id);
  const full=fullMap[s.id];
  if(full){const a=document.createElement('a');a.href=full;a.download=(s.name||'song')+'.mp3';a.click();return;}
  const ok=await favApi('add',{id:s.id,name:s.name,artist:s.artist,audioUrl:s.playback_url,cover:s.cover,length:s.length,download:true});
  if(ok){favSet.add(id);dlSet.add(id);loadFavs();renderFavs();toast('已加入下载队列，本机将自动抓取完整版');}
}
function renderFavs(){
  const s=songs[curIdx];const id=s?String(s.id):'';
  $('btnFav').textContent=favSet.has(id)?'♥':'♡';
  $('btnFav').classList.toggle('on',favSet.has(id));
  $('btnDl').textContent=(s&&fullMap[s.id])?'✓':'⬇';
  $('btnDl').classList.toggle('on',dlSet.has(id));
  $('btnDl').classList.toggle('ready',!!(s&&fullMap[s.id]));
  renderRows();
}
async function loadFavs(){
  try{const r=await fetch('musicbed_api.php?action=list&source=browse');const j=await r.json();favList=j.data||[];
    favSet=new Set(favList.map(f=>String(f.id)));
    dlSet=new Set(favList.filter(f=>f.download).map(f=>String(f.id)));
    fullMap={};favList.forEach(f=>{if(f.fullUrl)fullMap[f.id]=f.fullUrl;});
    renderFavs();
  }catch(e){}
}
// 收藏列表浮窗
function openFavLb(){$('favlb').classList.add('show');renderFavLb();}
function closeFavLb(){$('favlb').classList.remove('show');}
function renderFavLb(){
  const body=$('favlbBody');
  if(!favList.length){body.innerHTML='<div class="favlb-empty">还没有收藏歌曲，点歌曲行的 ♡ 收藏</div>';return;}
  body.innerHTML=favList.map(f=>{
    const st=f.download?(f.fullUrl?'<span class="st rd">已就绪</span>':'<span class="st dl">下载中…</span>'):'<span class="st">普通</span>';
    return `<div class="favlb-item" data-id="${f.id}">
      <img src="${esc(thumb(f.cover))}" onerror="this.style.display='none'">
      <div class="fi"><div class="fn">${esc(f.name)}</div><div class="fa">${esc(f.artist||'')}</div></div>
      ${st}
      <div class="ops"><button title="播放">▶</button><button title="下载">⬇</button><button title="移除">✕</button></div>
    </div>`;
  }).join('');
  body.querySelectorAll('.favlb-item').forEach(it=>{
    const f=favList.find(x=>String(x.id)===it.dataset.id);
    const [play,dl,rm]=it.querySelectorAll('.ops button');
    play.onclick=()=>{const raw=f.fullUrl||f.audioUrl;const src=raw&&raw.indexOf('30-custom-ogg')>=0?('audio_proxy.php?u='+encodeURIComponent(raw)):raw;if(src){audio.src=src;audio.play();}closeFavLb();};
    dl.onclick=async()=>{
      if(f.fullUrl){const a=document.createElement('a');a.href=f.fullUrl;a.download=(f.name||'song')+'.mp3';a.click();}
      else{const ok=await favApi('add',{id:f.id,name:f.name,artist:f.artist,audioUrl:f.audioUrl,cover:f.cover,length:f.length,download:true});if(ok)loadFavs();}
    };
    rm.onclick=async()=>{await favApi('remove',{id:f.id});loadFavs();renderFavLb();};
  });
}

// ---------- 事件 ----------
document.querySelectorAll('.stab[data-dim]').forEach(b=>b.onclick=()=>openPop(b.dataset.dim));
$('tgLyrical').onclick=()=>{lyrical=lyrical==='lyrical'?'':'lyrical';renderSel();setLyricalUI();doSearch(1);};
$('tgInst').onclick=()=>{lyrical=lyrical==='instrumental'?'':'instrumental';renderSel();setLyricalUI();doSearch(1);};
$('fpCancel').onclick=clearPop;
$('fpApply').onclick=applyPop;
$('qBtn').onclick=doSearchFromQ;
$('q').addEventListener('keydown',e=>{if(e.key==='Enter')doSearchFromQ();});
$('sort').addEventListener('change',e=>{order=e.target.value;doSearch(1);});
$('btnPlay').onclick=()=>{const s=songs[curIdx];if(!s){if(songs.length)playSong(0);return;}if(audio.currentSrc)audio.paused?audio.play():audio.pause();else playSong(curIdx);};
$('btnPrev').onclick=()=>step(-1);
$('btnNext').onclick=()=>step(1);
$('btnFav').onclick=()=>toggleFav(curIdx);
$('btnDl').onclick=()=>toggleDl(curIdx);
$('favBtn2').onclick=e=>{e.preventDefault();openFavLb();};
$('favlbClose').onclick=closeFavLb;
$('favlb').onclick=e=>{if(e.target.id==='favlb')closeFavLb();};
$('prevBtn').onclick=()=>doSearch(page-1);
$('nextBtn').onclick=()=>doSearch(page+1);

loadFilters();loadFavs();
</script>
</body>
</html>
