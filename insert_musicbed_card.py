#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""在阅读站首页 index.php 的 entries 里插入 Musicbed 热点卡片（先备份）"""
import os, shutil

path = '/www/wwwroot/yuedu.jack-sun.com/index.php'
content = open(path, 'r', encoding='utf-8').read()
if '/musicbed/' in content:
    print('卡片已存在，跳过')
    raise SystemExit(0)

bak = path + '.bak-20260817-musicbed-card'
if not os.path.exists(bak):
    shutil.copy2(path, bak)

card = '''      <a class="entry" href="/musicbed/">
        <small>MUSIC · 配乐</small>
        <h2>Musicbed 热点</h2>
        <p>每天 20 首可在线试听的影视配乐，来自 Musicbed 不同分类的热点榜，三天一换。</p>
        <span class="arrow" aria-hidden="true">→</span>
      </a>
    </section>'''

if '</section>' not in content:
    print('找不到 </section>，中止')
    raise SystemExit(1)

new = content.replace('</section>', card, 1)
open(path, 'w', encoding='utf-8').write(new)
print('已插入 Musicbed 卡片并备份到 .bak-20260817-musicbed-card')
