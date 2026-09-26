#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
光感影视评论区自动监控回复
由 launchd 每 2 分钟调用一次。读阅读站评论区，发现孙哥的新留言就调用 Claude
生成回复并发布回评论区（is_admin=1），实现"留言板式"自动交互。
"""
import json, os, subprocess, urllib.request, urllib.parse, fcntl, datetime

BASE = os.path.expanduser('~/guanggan-comment')
SLUG = '留言板'
API = 'https://yuedu.jack-sun.com/read/comment.php?slug=' + urllib.parse.quote(SLUG)
STATE = os.path.join(BASE, 'last_id.txt')
LOG = os.path.join(BASE, 'watcher.log')
LOCK = os.path.join(BASE, '.lock')
CLAUDE = '/Users/jacksun/.local/bin/claude'
WORKDIR = os.path.expanduser('~/guanggan-comment')
SSH = '/usr/bin/ssh'
SSH_HOST = 'jacksun-tencent'
DB = '/www/wwwroot/yuedu.jack-sun.com/read/data/comments.sqlite'


def now():
    return datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')


def log(msg):
    try:
        with open(LOG, 'a') as f:
            f.write('[%s] %s\n' % (now(), msg))
    except Exception:
        pass


def get_comments():
    req = urllib.request.Request(API, headers={'User-Agent': 'Mozilla/5.0'})
    with urllib.request.urlopen(req, timeout=25) as r:
        data = json.loads(r.read().decode('utf-8'))
    return data.get('data', []) if data.get('ok') else []


def read_last():
    try:
        return int(open(STATE).read().strip())
    except Exception:
        return 0


def write_last(i):
    try:
        with open(STATE, 'w') as f:
            f.write(str(i))
    except Exception as e:
        log('写 last_id 失败: %s' % e)


def gen_reply(new_txt, history_txt):
    prompt = (
        '你是"光感影视"孙哥的AI助手，帮孙哥做影视公司获客与经营决策。\n'
        '光感影视最新战略（2026-08-30定案）：孙哥桂林人，13年影视从业，公司效益差靠内容翻身。\n'
        '定位：主号个人IP「桂林拍了13年的影视公司老板」（抖音，三形态=坐着口播+第一视角实拍+本人出镜），'
        '公司号光感影视（小红书企业号+POI）承接转化。\n'
        '核心判断：桂林B端影像内容空白（会议/宣传片/酒店摄影无本地对手），全国模板已验证，直接平移。\n'
        '三条业务线：①B端拍摄（会议/年会/宣传片/酒店）②账号代运营（桂林品牌方内容能力≈0）'
        '③AI定制人生电影（即梦Seedance，定价1980/3980，婚庆公司分销）。\n'
        '打法要点：客户决策四环=刷到/搜到/信/行动；年会季10月中起密集发会议线；'
        '私信30分钟必回+价目区间图；绝不留微信（限流）。完整方案在孙哥电脑 ~/Documents/光感影视复活手册.md。\n'
        '\n这是阅读站留言板，孙哥发想法留言，你以"Claude · 孙哥的AI助手"身份回复。'
        '回复要求：简洁、实用、口语化中文，200字内，直接给回复内容（不要昵称前缀、不要markdown符号、不要客套）。'
        '如孙哥问"该发什么/怎么拍"，按手册选题库给具体条目名。\n'
        '\n已有评论：\n%s\n\n孙哥的新留言：\n%s' % (history_txt, new_txt)
    )
    try:
        proc = subprocess.run(
            [CLAUDE, '-p', prompt],
            cwd=WORKDIR, capture_output=True, text=True, timeout=150,
            env=dict(os.environ, HOME=os.path.expanduser('~'))
        )
        out = (proc.stdout or '').strip()
        return out
    except Exception as e:
        log('claude 调用失败: %s' % e)
        return ''


def post_reply(content):
    safe = content.replace('\\', '\\\\').replace('"', '\\"').replace("'", "''")
    sql = (
        "INSERT INTO comments (slug,nickname,content,is_admin) "
        "VALUES ('%s','Claude · 孙哥的AI助手','%s',1);" % (SLUG, safe)
    )
    cmd = 'sudo sqlite3 "%s" "%s"' % (DB, sql)
    try:
        p = subprocess.run([SSH, SSH_HOST, cmd], timeout=30, capture_output=True)
        if p.returncode == 0:
            return True
        log('ssh 发布返回非0: %s' % p.stderr.decode('utf-8', 'ignore')[:200])
        return False
    except Exception as e:
        log('发布回复异常: %s' % e)
        return False


def main():
    lock_f = open(LOCK, 'w')
    try:
        fcntl.flock(lock_f, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        log('另一个实例运行中，跳过')
        return

    comments = get_comments()
    if not comments:
        log('无评论或读取失败')
        return

    li = read_last()
    max_id = max(c['id'] for c in comments)
    new_user = [c for c in comments if c['id'] > li and c['is_admin'] == 0]

    if new_user:
        log('发现 %d 条孙哥新留言: %s' % (len(new_user), [c['id'] for c in new_user]))
        history = [('%s: %s' % (c['nickname'], c['content'][:150])) for c in comments if c['id'] <= li]
        new_txt = '\n'.join(('%s: %s' % (c['nickname'], c['content'][:400])) for c in new_user)
        reply = gen_reply(new_txt, '\n'.join(history[-20:]))
        if reply:
            log('Claude 回复: %s...' % reply[:80])
            if post_reply(reply[:500]):
                write_last(max_id)
                log('已回复并更新 last_id=%d' % max_id)
            else:
                log('回复发布失败，保留 last_id 待重试')
        else:
            log('未生成回复，保留 last_id 待重试')
    else:
        write_last(max_id)
        log('无新留言，同步 last_id=%d' % max_id)


if __name__ == '__main__':
    main()
