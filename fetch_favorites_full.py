#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""收藏歌曲 → 抓完整版音频 → 七牛 → 回写收藏记录（fullUrl）
从服务器 favorites.json 里挑还没有完整版的收藏歌，用 ego-browser 触发播放抓完整版 ogg，
下载→转 192k mp3→上传七牛(key=musicbed/full/{id}.mp3)→调 musicbed_api.php?action=update_full 回写。
手动跑：python3 fetch_favorites_full.py   可选 --all 强制重抓全部
"""
import json, os, subprocess, sys, time, datetime, fcntl, re

HOME = os.path.expanduser('~')
BASE = os.path.join(HOME, 'guanggan-comment')
NODE = '/Users/jacksun/.local/opt/node-v24.18.0-darwin-arm64/bin/node'
EGO = 'ego-browser'
QINIU_JS = os.path.join(BASE, 'qiniu_upload.js')
FFMPEG = '/opt/homebrew/bin/ffmpeg'
SERVER = 'jacksun-tencent'
SERVER_DATA = '/www/wwwroot/yuedu.jack-sun.com/musicbed/data'
API_BASE = 'https://yuedu.jack-sun.com/musicbed/musicbed_api.php'
LOG = os.path.join(BASE, 'fetch_favorites_full.log')
WORK = os.path.join(BASE, 'full_work')
os.makedirs(WORK, exist_ok=True)

# 抓完整版 ogg URL（复用 musicbedd 的 AUDIO_SCRIPT 逻辑）
AUDIO_SCRIPT = r'''
const task = await useOrCreateTaskSpace('mb full fetch')
const songs = %SONGS%
const results = await (async () => {
  const out = []
  for (const s of songs) {
    try {
      await gotoAndWait(s.songUrl, { timeout: 30 })
      await wait(3)
      await cdp('Network.enable')
      await js(`(() => { const els=[...document.querySelectorAll('[role="dialog"],[class*="Modal"],[class*="banner"],[class*="promo"]')]; for(const e of els){e.style.display='none'} })()`)
      await wait(1)
      const clicked = await js(`(async () => {
        for (let i=0;i<20;i++){ if(document.querySelectorAll('div.playButton').length>=2) break; await new Promise(r=>setTimeout(r,500)); }
        const b=document.querySelectorAll('div.playButton')[1] || document.querySelector('div.playButton')
        if(!b) return 'nobtn'
        b.click(); return 'ok'
      })()`)
      await wait(8)
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
    try:
        r = subprocess.run([EGO, 'nodejs'], input=script, capture_output=True,
                           text=True, timeout=timeout)
        return r.stdout or '', r.stderr or ''
    except subprocess.TimeoutExpired:
        return '', 'ego timeout'


def parse_output(stdout, stderr, marker):
    for line in (stdout + '\n' + stderr).splitlines():
        line = line.strip()
        if line.startswith(marker + '='):
            try:
                return json.loads(line[len(marker) + 1:])
            except Exception:
                return None
    return None


def fetch_ogg_urls(songs):
    ogg_map = {}
    batch = 4
    for i in range(0, len(songs), batch):
        chunk = songs[i:i + batch]
        script = AUDIO_SCRIPT.replace('%SONGS%', json.dumps(chunk))
        out, err = ego_run(script)
        rows = parse_output(out, err, 'AUDIO') or []
        for row in rows:
            if row.get('ogg'):
                ogg_map[row['id']] = row['ogg']
        log('  抓音频 %d/%d' % (min(i + batch, len(songs)), len(songs)))
        time.sleep(1)
    return ogg_map


def process_one(fav, ogg):
    sid = str(fav['id'])
    raw = os.path.join(WORK, sid + '.ogg')
    mp3 = os.path.join(WORK, sid + '.mp3')
    if not os.path.exists(raw) or os.path.getsize(raw) < 10000:
        r = run(['curl', '-s', '-L', '-o', raw, '--max-time', '120', ogg])
        if not os.path.exists(raw) or os.path.getsize(raw) < 10000:
            log('  下载失败: %s' % fav.get('name', sid))
            return None
    if not os.path.exists(mp3):
        r = run([FFMPEG, '-y', '-loglevel', 'error', '-i', raw, '-codec:a', 'libmp3lame', '-b:a', '192k', mp3])
        if r.returncode != 0 or not os.path.exists(mp3):
            log('  转码失败: %s' % fav.get('name', sid))
            return None
    key = 'musicbed/full/%s.mp3' % sid
    r = run([NODE, QINIU_JS, mp3, key], timeout=90)
    url = ''
    for line in (r.stdout or '').splitlines():
        if line.strip().startswith('http'):
            url = line.strip()
            break
    if not url:
        log('  七牛上传失败: %s (%s)' % (fav.get('name', sid), (r.stderr or '').strip()[:80]))
        return None
    return url


def update_full_on_server(sid, url):
    body = json.dumps({'action': 'update_full', 'id': sid, 'fullUrl': url, 'source': 'browse'})
    r = run(['curl', '-s', '-m', '30', '-X', 'POST', '-H', 'Content-Type: application/json',
             '-d', body, API_BASE], timeout=40)
    try:
        d = json.loads(r.stdout)
        return bool(d.get('ok'))
    except Exception:
        log('  回写失败 resp=%s' % (r.stdout or '')[:100])
        return False


def load_favorites():
    r = run(['ssh', '-o', 'ConnectTimeout=10', SERVER,
             'sudo cat %s/favorites_browse.json 2>/dev/null' % SERVER_DATA], timeout=30)
    try:
        d = json.loads(r.stdout)
        return d if isinstance(d, list) else []
    except Exception:
        return []


def build_song_url(sid):
    """用详情 API 拿歌曲页 URL（browse 收藏没存 songUrl）"""
    r = run(['curl', '-s', '-m', '20', 'https://novus-api.musicbed.com/api/songs/%s' % sid])
    try:
        d = json.loads(r.stdout)
        su = d.get('data', {}).get('song_url', '')
        return 'https://www.musicbed.com' + su if su else ''
    except Exception:
        return ''


def run_once():
    favs = load_favorites()
    if not favs:
        log('中文馆收藏为空')
        return
    pending = [f for f in favs if f.get('download') and not f.get('fullUrl')]
    if not pending:
        return
    log('待抓完整版 %d 首' % len(pending))
    songs = []
    for f in pending:
        url = build_song_url(f['id'])
        if url:
            songs.append({'id': f['id'], 'songUrl': url})
        else:
            log('  拿不到歌曲页: %s' % f.get('name', f['id']))
    if not songs:
        return
    ogg_map = fetch_ogg_urls(songs)
    ok = fail = 0
    for fav in pending:
        ogg = ogg_map.get(fav['id'])
        if not ogg:
            fail += 1
            continue
        url = process_one(fav, ogg)
        if not url:
            fail += 1
            continue
        if update_full_on_server(fav['id'], url):
            log('  完整版就绪: %s → %s' % (fav.get('name', fav['id']), url[:70]))
            ok += 1
        else:
            fail += 1
    log('本轮：成功 %d，失败 %d' % (ok, fail))


def main():
    if '--daemon' in sys.argv:
        log('==== 常驻下载服务启动（每 3 分钟轮询） ====')
        while True:
            try:
                run_once()
            except Exception as e:
                log('daemon 出错: %r' % e)
            time.sleep(180)
        return
    lock = open(os.path.join(BASE, 'fetch_favorites_full.lock'), 'w')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except IOError:
        log('已有实例在跑，退出')
        return
    try:
        run_once()
    finally:
        try:
            fcntl.flock(lock, fcntl.LOCK_UN)
        except Exception:
            pass


if __name__ == '__main__':
    main()
