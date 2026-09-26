<?php
// Musicbed 收藏列表页：展示收藏的歌曲，可播放、可取消收藏
header('Cache-Control: no-cache');
$file = __DIR__ . '/data/favorites.json';
$favs = [];
if (file_exists($file)) { $d = json_decode(@file_get_contents($file), true); if (is_array($d)) $favs = $d; }
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/musicbed/favicon.svg" type="image/svg+xml">
<title>我的收藏 · Musicbed 热点</title>
<style>
:root{color-scheme:dark;--bg:#14110f;--paper:#201a15;--paper2:#241d17;--text:#eee4d7;--muted:#9d9081;--line:rgba(255,255,255,.08);--amber:#d1a364;--amber2:#e6bd85}
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;color:var(--text);background:radial-gradient(circle at 50% 0,rgba(209,163,100,.1),transparent 42%),linear-gradient(180deg,#1b1612,var(--bg));font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Helvetica Neue",sans-serif;-webkit-font-smoothing:antialiased;padding:40px 18px 80px}
.wrap{max-width:760px;margin:0 auto}
header{text-align:center;margin-bottom:26px}
.back{display:inline-flex;align-items:center;gap:6px;margin-top:10px;color:var(--muted);font-size:12.5px;text-decoration:none;border:1px solid var(--line);border-radius:99px;padding:7px 16px;transition:all .2s}
.back:hover{color:var(--amber2);border-color:rgba(209,163,100,.4)}
h1{font-family:ui-serif,"Songti SC","STSong",serif;font-size:clamp(26px,4.5vw,34px);font-weight:500;letter-spacing:-.03em;margin-top:12px}
header p{margin:10px 0 0;color:var(--muted);font-size:13px}
.song{display:flex;gap:12px;padding:12px;border:1px solid var(--line);border-radius:14px;background:rgba(33,26,22,.85);margin-bottom:10px;align-items:center}
.song .scover{width:52px;height:52px;flex:0 0 52px;border-radius:10px;overflow:hidden;background:#2a211b;display:grid;place-items:center;color:var(--muted)}
.song .scover img{width:100%;height:100%;object-fit:cover}
.song .sinfo{flex:1;min-width:0}
.song .sname{font-size:14px;font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.song .sartist{font-size:12px;color:var(--muted);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.song .smeta{font-size:11px;color:var(--muted);margin-top:4px}
audio{width:190px;height:32px;flex:0 0 auto}
.rm{flex:0 0 auto;border:1px solid var(--line);background:var(--paper2);color:var(--muted);font-size:12px;padding:6px 12px;border-radius:8px;cursor:pointer;transition:all .15s}
.rm:hover{color:#e08a7a;border-color:rgba(224,138,122,.4)}
.empty{text-align:center;color:var(--muted);padding:70px 0;font-size:14px}
.empty a{color:var(--amber);text-decoration:none}
footer{margin-top:30px;color:#6f6458;font-size:10px;letter-spacing:.12em;text-align:center}
@media(max-width:640px){body{padding:26px 12px 60px}.song{flex-wrap:wrap}.audio{width:100%}audio{width:100%}.rm{width:100%}}
</style>
</head>
<body>
<div class="wrap">
<header>
  <h1>♥ 我的收藏</h1>
  <p>收藏的歌不会被每日清理删除，一直保留</p>
  <a class="back" href="/musicbed/">&larr; 返回播放器</a>
</header>
<?php if (!$favs): ?>
  <div class="empty">还没有收藏。<br><a href="/musicbed/">去播放器里点 ♥ 收藏</a></div>
<?php else: foreach ($favs as $s): ?>
  <div class="song" data-id="<?= (int)$s['id'] ?>">
    <div class="scover"><?php if (!empty($s['cover'])): ?><img src="<?= htmlspecialchars($s['cover']) ?>" loading="lazy" alt=""><?php else: ?>♪<?php endif; ?></div>
    <div class="sinfo">
      <div class="sname"><?= htmlspecialchars($s['name'] ?? '') ?></div>
      <div class="sartist"><?= htmlspecialchars($s['artist'] ?? '') ?></div>
      <div class="smeta"><?php if (!empty($s['bpm'])): ?><?= htmlspecialchars($s['bpm']) ?> BPM<?php endif; ?><?php if (!empty($s['length'])): ?> · <?= htmlspecialchars($s['length']) ?><?php endif; ?></div>
    </div>
    <?php if (!empty($s['audioUrl'])): ?><audio controls preload="none" src="<?= htmlspecialchars($s['audioUrl']) ?>"></audio><?php endif; ?>
    <button class="rm" onclick="unfav(this, <?= (int)$s['id'] ?>)">取消收藏</button>
  </div>
<?php endforeach; endif; ?>
<footer>MUSICBED · MY FAVORITES</footer>
</div>
<script>
async function unfav(btn, id){
  if (!confirm('取消收藏这首歌？')) return;
  const r = await fetch('/musicbed/api.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'remove', song:{id}})
  });
  const j = await r.json();
  if (j.ok) { btn.closest('.song').remove(); if (!document.querySelector('.song')) location.reload(); }
  else alert('操作失败');
}
</script>
</body>
</html>
