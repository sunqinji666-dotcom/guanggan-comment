#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Musicbed 每日热点歌曲抓取守护
选曲(当日 mood 轮换)→ego-browser 抓完整音频→ffmpeg 转 128k mp3→上传七牛→生成 JSON 推服务器→3天过期清理
触发: launchd com.guanggan.musicbed.plist 每天 08:00（也可手动跑）
"""
import json, os, subprocess, sys, time, shutil, datetime, re, fcntl

# ---------- 配置 ----------
HOME = os.path.expanduser('~')
BASE = os.path.join(HOME, 'guanggan-comment')
NODE = '/Users/jacksun/.local/opt/node-v24.18.0-darwin-arm64/bin/node'
EGO = 'ego-browser'
QINIU_JS = os.path.join(BASE, 'qiniu_upload.js')
QINIU_DEL = os.path.join(BASE, 'qiniu_delete.js')
FFMPEG = '/opt/homebrew/bin/ffmpeg'
SERVER = 'jacksun-tencent'
SERVER_DATA = '/www/wwwroot/yuedu.jack-sun.com/musicbed/data'
LOG = os.path.join(BASE, 'musicbedd.log')
WORK = os.path.join(BASE, 'musicbed_work')
KEEP_DAYS = 3          # 七牛+服务器保留天数
DEDUP_DAYS = 14        # 近 14 天用过的歌不再选
TOP_N = 20             # 每天 20 首
MOOD_ROTATION = ['Cinematic', 'Uplifting', 'Corporate', 'Ambient', 'Electronic',
                 'Inspiring', 'Happy', 'Peaceful', 'Emotional', 'Carefree']


def log(msg):
    line = '[%s] %s' % (datetime.datetime.now().strftime('%m-%d %H:%M:%S'), msg)
    print(line, flush=True)
    try:
        with open(LOG, 'a') as f:
            f.write(line + '\n')
    except Exception:
        pass


def run(cmd, timeout=300):
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        return subprocess.CompletedProcess(cmd, 124, '', 'timeout')


def ego_run(script, timeout=900):
    """调 ego-browser nodejs，stdin 传脚本，返回 (stdout, stderr)"""
    try:
        r = subprocess.run([EGO, 'nodejs'], input=script, capture_output=True,
                           text=True, timeout=timeout)
        return r.stdout or '', r.stderr or ''
    except subprocess.TimeoutExpired:
        return '', 'ego timeout'


def parse_output(stdout, stderr, marker):
    """从 ego 输出解析 CANDIDATES=... / AUDIO=... JSON（cliLog 输出在 stderr）"""
    for line in (stdout + '\n' + stderr).splitlines():
        line = line.strip()
        if line.startswith(marker + '='):
            try:
                return json.loads(line[len(marker) + 1:])
            except Exception:
                return None
    return None


# ---------- 阶段1: 候选池 ----------
CANDIDATE_SCRIPT = r'''
const task = await useOrCreateTaskSpace('musicbed daily')
const urls = %URLS%
const ids = new Set()
const covers = {}
for (const u of urls) {
  await gotoAndWait(u, { timeout: 30 })
  await wait(3)
  const found = await js(`(() => {
    const out = []
    const grabBg = (el) => {
      const bg = getComputedStyle(el).backgroundImage || ''
      const m = bg.match(/url\\(["']?(.+?)["']?\\)/)
      return m ? m[1].replace(/^https?:/, '') : ''
    }
    for (const a of document.querySelectorAll('a[href*="/songs/"]')) {
      const m = (a.href||'').match(/\\/songs\\/[^/]+\\/(\\d+)/)
      if (!m) continue
      let src = ''
      const img = a.querySelector('img')
      if (img) src = img.currentSrc || img.src || img.getAttribute('data-src') || img.getAttribute('data-lazy-src') || ''
      if (!src) {
        const box = a.querySelector('[class*="cover" i], [class*="art" i], [class*="img" i]')
        if (box) src = grabBg(box)
        if (!src) src = grabBg(a)
      }
      out.push({ id: m[1], img: src })
    }
    return out
  })()`)
  for (const f of found) { ids.add(f.id); if (f.img && !covers[f.id]) covers[f.id] = f.img }
}
const list = [...ids]
const results = []
for (let i = 0; i < list.length; i += 6) {
  const chunk = list.slice(i, i + 6)
  const res = await Promise.all(chunk.map(async (id) => {
    try {
      const r = await fetch('https://novus-api.musicbed.com/api/songs/' + id, { headers: { 'Accept': 'application/json' } })
      const j = JSON.parse(await r.text())
      const d = j.data || {}
      const gm = a => Array.isArray(a) ? a.map(x => (x && x.name) || x) : []
      const album = d.album || {}
      let cover = covers[id] || ''
      if (!cover && album.image && album.image.url) {
        const p = album.image.url.replace(/^https?:\\/\\/cdn\\.musicbed\\.com\\/image\\/upload\\//, '')
        cover = 'https://cdn.musicbed.com/image/upload/c_fill,dpr_auto,f_auto,g_auto,q_auto:best,w_400,h_400/' + p
      }
      const artist = (album.artist && album.artist.name) ? album.artist.name : ''
      const lyrics = (typeof d.lyrics === 'string' && d.lyrics.trim()) ? d.lyrics : ''
      return { id: Number(id), name: d.name || '', artist: artist, bpm: d.bpm || '', length: d.length || '',
        instrumental: !!d.instrumental, genres: gm(d.genres), moods: gm(d.moods),
        songUrl: 'https://www.musicbed.com' + (d.song_url || ''), cover: cover, lyrics: lyrics }
    } catch (e) { return null }
  }))
  results.push(...res.filter(Boolean))
}
cliLog('CANDIDATES=' + JSON.stringify(results))
const done = await completeTaskSpace(task.id, { keep: false })
cliLog('DONE=' + JSON.stringify(done))
'''


def fetch_candidates():
    urls = ['https://www.musicbed.com/songs?mood=cinematic',
            'https://www.musicbed.com/songs?mood=inspiring',
            'https://www.musicbed.com/songs?mood=uplifting',
            'https://www.musicbed.com/songs?mood=corporate']
    script = CANDIDATE_SCRIPT.replace('%URLS%', json.dumps(urls))
    out, err = ego_run(script)
    cands = parse_output(out, err, 'CANDIDATES')
    if not cands:
        log('候选池解析失败，ego stderr: ' + (err or '').strip()[:300])
        return []
    return [c for c in cands if c.get('songUrl')]


# ---------- 阶段2: 选歌 ----------
def get_day_moods():
    days = (datetime.date.today() - datetime.date(2026, 8, 17)).days
    n = len(MOOD_ROTATION)
    return [MOOD_ROTATION[days % n], MOOD_ROTATION[(days + 3) % n], MOOD_ROTATION[(days + 6) % n]]


def pick_songs(candidates, day_moods, used_ids):
    cands = [c for c in candidates if c['id'] not in used_ids]
    if not cands:
        return []
    inst = [c for c in cands if c['instrumental']]
    vocal = [c for c in cands if not c['instrumental']]
    chosen = []
    chosen_ids = set()
    per_mood = (TOP_N // len(day_moods)) + 1

    def mood_hit(c, mood):
        return mood.lower() in [m.lower() for m in c['moods']]

    for mood in day_moods:
        pool = [c for c in inst if mood_hit(c, mood)] + [c for c in vocal if mood_hit(c, mood)]
        for c in pool:
            if len(chosen) >= TOP_N:
                break
            if c['id'] not in chosen_ids:
                chosen.append(c)
                chosen_ids.add(c['id'])
    # 补位
    if len(chosen) < TOP_N:
        rest = [c for c in cands if c['id'] not in chosen_ids]
        rest.sort(key=lambda c: sum(1 for m in day_moods if mood_hit(c, m)) * 2 + (1 if c['instrumental'] else 0),
                  reverse=True)
        chosen += rest[:TOP_N - len(chosen)]
    return chosen[:TOP_N]


def load_used_ids():
    used = set()
    r = run(['ssh', '-o', 'ConnectTimeout=10', SERVER, 'ls %s/ 2>/dev/null' % SERVER_DATA])
    for f in (r.stdout or '').split():
        f = f.strip()
        m = re.match(r'(\d{4}-\d{2}-\d{2})\.json$', f)
        if not m:
            continue
        try:
            fd = datetime.datetime.strptime(m.group(1), '%Y-%m-%d').date()
        except ValueError:
            continue
        if (datetime.date.today() - fd).days <= DEDUP_DAYS:
            r2 = run(['ssh', '-o', 'ConnectTimeout=10', SERVER, 'cat %s/%s' % (SERVER_DATA, f)])
            try:
                data = json.loads(r2.stdout)
                for s in data.get('songs', []):
                    if 'id' in s:
                        used.add(s['id'])
            except Exception:
                pass
    return used


# ---------- 阶段3: 抓音频 URL ----------
AUDIO_SCRIPT = r'''
const task = await useOrCreateTaskSpace('musicbed audio')
const songs = %SONGS%
const results = await (async () => {
  const out = []
  for (const s of songs) {
    try {
      await gotoAndWait(s.songUrl, { timeout: 30 })
      await wait(2)
      await drainEvents()
      await cdp('Network.enable')
      await js(`(() => { const els=[...document.querySelectorAll('[role="dialog"],[class*="Modal"],[class*="banner"],[class*="promo"]')]; for(const e of els){e.style.display='none'} })()`)
      await wait(1)
      await js(`(() => { const b=document.querySelectorAll('div.playButton')[1]; if(b){b.click(); return 'ok'} return 'nobtn' })()`)
      await wait(7)
      const events = await drainEvents()
      const ogg = [...new Set(events.map(e => e?.params?.request?.url || '').filter(Boolean))]
        .find(u => u.includes('/public/' + s.id + '/ogg/'))
      out.push({ id: s.id, ogg: ogg || '' })
    } catch (e) { out.push({ id: s.id, ogg: '' }) }
  }
  return out
})()
cliLog('AUDIO=' + JSON.stringify(results))
const done = await completeTaskSpace(task.id, { keep: false })
cliLog('DONE=' + JSON.stringify(done))
'''


def fetch_ogg_urls(songs):
    ogg_map = {}
    batch = 5
    for i in range(0, len(songs), batch):
        chunk = songs[i:i + batch]
        script = AUDIO_SCRIPT.replace('%SONGS%', json.dumps(chunk))
        out, err = ego_run(script)
        rows = parse_output(out, err, 'AUDIO') or []
        for row in rows:
            if row.get('ogg'):
                ogg_map[row['id']] = row['ogg']
        log('  抓音频 %d/%d 完成' % (min(i + batch, len(songs)), len(songs)))
        time.sleep(1)
    return ogg_map


# ---------- 阶段4: 下载转码上传 ----------
def process_one(song, ogg, date):
    workdir = os.path.join(WORK, date)
    os.makedirs(workdir, exist_ok=True)
    raw = os.path.join(workdir, '%s.ogg' % song['id'])
    mp3 = os.path.join(workdir, '%s.mp3' % song['id'])
    if not os.path.exists(raw) or os.path.getsize(raw) < 10000:
        r = run(['curl', '-s', '-L', '-o', raw, '--max-time', '90', ogg])
        if not os.path.exists(raw) or os.path.getsize(raw) < 10000:
            log('  下载失败: %s' % song['name'])
            return None
    if not os.path.exists(mp3):
        r = run([FFMPEG, '-y', '-loglevel', 'error', '-i', raw, '-codec:a', 'libmp3lame', '-b:a', '128k', mp3])
        if r.returncode != 0 or not os.path.exists(mp3):
            log('  转码失败: %s' % song['name'])
            return None
    key = 'musicbed/%s/%s.mp3' % (date, song['id'])
    r = run([NODE, QINIU_JS, mp3, key])
    url = ''
    for line in (r.stdout or '').splitlines():
        if line.strip().startswith('http'):
            url = line.strip()
            break
    if not url:
        log('  七牛上传失败: %s' % song['name'])
        return None
    return {'id': song['id'], 'name': song['name'], 'artist': song.get('artist', ''),
            'bpm': song.get('bpm', ''), 'length': song.get('length', ''),
            'genres': song.get('genres', []), 'moods': song.get('moods', []),
            'audioUrl': url, 'cover': song.get('cover', ''), 'date': date}


# ---------- 阶段5: JSON 推送 + 清理 ----------
def push_json(date, playlist):
    data_dir = os.path.join(WORK, date)
    os.makedirs(data_dir, exist_ok=True)
    json_path = os.path.join(data_dir, '%s.json' % date)
    with open(json_path, 'w', encoding='utf-8') as f:
        json.dump({'date': date, 'songs': playlist}, f, ensure_ascii=False, indent=1)
    r = run(['scp', '-o', 'ConnectTimeout=10', json_path, '%s:/tmp/%s.json' % (SERVER, date)], timeout=60)
    if r.returncode != 0:
        log('scp 失败: ' + (r.stderr or '').strip()[:200])
        return False
    r = run(['ssh', '-o', 'ConnectTimeout=10', SERVER,
             'sudo mkdir -p %s && sudo mv /tmp/%s.json %s/%s.json && sudo chown www:www %s/%s.json'
             % (SERVER_DATA, date, SERVER_DATA, date, SERVER_DATA, date)], timeout=60)
    if r.returncode != 0:
        log('服务器移动失败: ' + (r.stderr or '').strip()[:200])
        return False
    return True


def load_fav_ids():
    """拉取服务器收藏列表里的歌曲 id（清理时跳过，收藏的歌永久保留）"""
    r = run(['ssh', '-o', 'ConnectTimeout=10', SERVER,
             'sudo cat %s/favorites.json 2>/dev/null' % SERVER_DATA], timeout=30)
    try:
        d = json.loads(r.stdout)
        return set(str(s.get('id')) for s in d if isinstance(s, dict) and s.get('id'))
    except Exception:
        return set()


def process_removed():
    """处理被取消收藏的歌：不在活跃歌单里的立即删七牛音频（及时清理）"""
    r = run(['ssh', '-o', 'ConnectTimeout=10', SERVER,
             'sudo cat %s/removed.json 2>/dev/null' % SERVER_DATA], timeout=30)
    try:
        removed = json.loads(r.stdout)
    except Exception:
        return
    if not removed:
        return
    # 活跃歌单日期（服务器现有 json）
    r2 = run(['ssh', '-o', 'ConnectTimeout=10', SERVER,
              'ls %s/*.json 2>/dev/null' % SERVER_DATA], timeout=30)
    active = set()
    for f in (r2.stdout or '').split():
        m = re.search(r'/(\d{4}-\d{2}-\d{2})\.json$', f.strip())
        if m:
            active.add(m.group(1))
    fav_ids = load_fav_ids()
    kept = []
    for item in removed:
        key = item.get('key', '')
        sid = str(item.get('id', ''))
        m = re.match(r'musicbed/(\d{4}-\d{2}-\d{2})/', key)
        if sid in fav_ids:
            continue  # 又重新收藏了，无需清理，直接移除记录
        in_active = bool(m and m.group(1) in active)
        if in_active:
            kept.append(item)  # 歌单还在用，等过期再删
        else:
            r3 = run([NODE, QINIU_DEL, key], timeout=60)
            log('已清理取消收藏的歌: %s (%s)' % (key, (r3.stdout or r3.stderr or '').strip()[:80]))
    if kept != removed:
        tmp = '/tmp/mb_removed.json'
        try:
            with open(tmp, 'w') as f:
                json.dump(kept, f)
            run(['scp', '-o', 'ConnectTimeout=10', tmp, '%s:/tmp/mb_removed.json' % SERVER], timeout=30)
            run(['ssh', '-o', 'ConnectTimeout=10', SERVER,
                 'sudo mv /tmp/mb_removed.json %s/removed.json' % SERVER_DATA], timeout=30)
        except Exception:
            pass


def cleanup(date):
    old = (datetime.date.today() - datetime.timedelta(days=KEEP_DAYS)).strftime('%Y-%m-%d')
    process_removed()
    # 拉收藏 id，清理时跳过（收藏的歌音频永久保留）
    fav_ids = load_fav_ids()
    excl = ','.join(sorted(fav_ids))
    if fav_ids:
        log('清理时跳过 %d 首已收藏的歌曲' % len(fav_ids))
    # 七牛删旧前缀（跳过收藏）
    r = run([NODE, QINIU_DEL, 'musicbed/%s/' % old, excl], timeout=120)
    log('七牛清理 %s: %s' % (old, (r.stdout or '').strip() or (r.stderr or '').strip()[:100]))
    # 服务器删旧 JSON（歌单 JSON 可删，收藏记录在 favorites.json 不受影响）
    run(['ssh', '-o', 'ConnectTimeout=10', SERVER, 'sudo rm -f %s/%s.json' % (SERVER_DATA, old)], timeout=30)
    # 本地删旧工作目录
    shutil.rmtree(os.path.join(WORK, old), ignore_errors=True)


def main():
    lock_path = os.path.join(BASE, 'musicbedd.lock')
    lock = open(lock_path, 'w')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except IOError:
        log('已有实例在跑，退出')
        return
    date = datetime.date.today().strftime('%Y-%m-%d')
    log('==== 开始 Musicbed 每日任务 %s ====' % date)
    try:
        # 今天已生成则只清理
        r = run(['ssh', '-o', 'ConnectTimeout=10', SERVER,
                 'test -f %s/%s.json && echo yes' % (SERVER_DATA, date)], timeout=30)
        if r.stdout.strip() == 'yes':
            log('今天已生成，跳过抓取，仅做过期清理')
            cleanup(date)
            return
        cands = fetch_candidates()
        if not cands:
            log('候选池为空，今天作罢')
            cleanup(date)
            return
        day_moods = get_day_moods()
        used = load_used_ids()
        songs = pick_songs(cands, day_moods, used)
        log('候选 %d 首，当日曲风 %s，选定 %d 首' % (len(cands), ','.join(day_moods), len(songs)))
        if not songs:
            log('没有可选歌曲，今天作罢')
            cleanup(date)
            return
        ogg_map = fetch_ogg_urls(songs)
        if not ogg_map:
            log('一首音频都没抓到，今天作罢')
            cleanup(date)
            return
        playlist = []
        for s in songs:
            ogg = ogg_map.get(s['id'], '')
            if not ogg:
                log('  无音频跳过: %s' % s['name'])
                continue
            item = process_one(s, ogg, date)
            if item:
                playlist.append(item)
        if not playlist:
            log('处理 0 首，今天作罢')
            cleanup(date)
            return
        if push_json(date, playlist):
            log('已推送 %d 首到服务器' % len(playlist))
        cleanup(date)
        log('==== 完成 ====')
    except Exception as e:
        log('出错: %r' % e)
        import traceback
        log(traceback.format_exc()[:800])
    finally:
        try:
            fcntl.flock(lock, fcntl.LOCK_UN)
        except Exception:
            pass


if __name__ == '__main__':
    main()
