#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""给已生成的 Musicbed 歌单 JSON 补封面 + 艺术家（一次性补全工具）
用法: python3 backfill_meta.py <日期>  默认今天
"""
import json, os, subprocess, sys, datetime

SERVER = 'jacksun-tencent'
SERVER_DATA = '/www/wwwroot/yuedu.jack-sun.com/musicbed/data'
DATE = sys.argv[1] if len(sys.argv) > 1 else datetime.date.today().strftime('%Y-%m-%d')

def run(cmd, **kw):
    return subprocess.run(cmd, capture_output=True, text=True, **kw)

# 读服务器 JSON
r = run(['ssh', '-o', 'ConnectTimeout=10', SERVER, 'cat %s/%s.json' % (SERVER_DATA, DATE)])
if r.returncode != 0:
    print('读取失败: ' + DATE)
    sys.exit(1)
data = json.loads(r.stdout)
songs = data.get('songs', [])
ids = [str(s['id']) for s in songs]
print('共 %d 首，查 API 补全封面/艺术家...' % len(ids))

script = r'''
const task = await useOrCreateTaskSpace('补封面')
const ids = %IDS%
const out = await (async () => {
  const res = {}
  for (let i = 0; i < ids.length; i += 6) {
    const chunk = ids.slice(i, i + 6)
    const rows = await Promise.all(chunk.map(async (id) => {
      try {
        const r = await fetch('https://novus-api.musicbed.com/api/songs/' + id, { headers: { 'Accept': 'application/json' } })
        const d = (JSON.parse(await r.text())).data || {}
        const album = d.album || {}
        let cover = ''
        if (album.image && album.image.url) {
          const p = album.image.url.replace(/^https?:\/\/cdn\.musicbed\.com\/image\/upload\//, '')
          cover = 'https://cdn.musicbed.com/image/upload/c_fill,dpr_auto,f_auto,g_auto,q_auto:best,w_400,h_400/' + p
        }
        const artist = (album.artist && album.artist.name) ? album.artist.name : ''
        const lyrics = (typeof d.lyrics === 'string' && d.lyrics.trim()) ? d.lyrics : ''
        return { id, cover, artist, lyrics }
      } catch (e) { return { id, cover: '', artist: '', lyrics: '' } }
    }))
    for (const x of rows) res[x.id] = { cover: x.cover, artist: x.artist, lyrics: x.lyrics }
  }
  return res
})()
cliLog('META=' + JSON.stringify(out))
const done = await completeTaskSpace(task.id, { keep: false })
'''
r = run(['ego-browser', 'nodejs'], input=script.replace('%IDS%', json.dumps(ids)), timeout=300)
out_text = (r.stdout or '') + (r.stderr or '')
meta = None
for line in out_text.splitlines():
    if line.startswith('META='):
        try:
            meta = json.loads(line[5:])
        except Exception:
            meta = None
        break
if not meta:
    print('META 解析失败: ' + out_text[-300:])
    sys.exit(1)

cnt = 0
for s in songs:
    key = str(s['id'])
    if key in meta:
        m = meta[key]
        if m.get('cover'):
            s['cover'] = m['cover']
        if m.get('artist'):
            s['artist'] = m['artist']
        if m.get('lyrics'):
            s['lyrics'] = m['lyrics']
        cnt += 1

tmp = '/tmp/mb_meta_%s.json' % DATE
with open(tmp, 'w', encoding='utf-8') as f:
    json.dump(data, f, ensure_ascii=False, indent=1)
run(['scp', '-o', 'ConnectTimeout=10', tmp, '%s:/tmp/%s.json' % (SERVER, DATE)], timeout=60)
run(['ssh', '-o', 'ConnectTimeout=10', SERVER,
     'sudo mv /tmp/%s.json %s/%s.json && sudo chown www:www %s/%s.json'
     % (DATE, SERVER_DATA, DATE, SERVER_DATA, DATE)], timeout=60)
print('补全 %d 首封面/艺术家/歌词，已推回服务器' % cnt)
