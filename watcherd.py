#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
孙哥网页 Claude 聊天系统 —— 常驻守护进程
==========================================
由 launchd KeepAlive 常驻。秒级轮询 chat API：
  1. 发现孙哥的新留言（status=pending）→ 插入 AI 消息 status='thinking'（前端显示"正在思考中"）
  2. 调 claude -p 流式执行（--output-format stream-json --verbose --include-partial-messages）
  3. 流式文字分批写回（前端打字机）；工具调用状态写回（🔧 正在使用 XX）
  4. 生成图片上传到服务器 uploads/（img 字段回显）
  5. 完成 → status='done'

数据库操作全部走 chat API 的 admin 通道（X-Admin-Token），不直连服务器。
"""
import json, os, subprocess, urllib.request, urllib.parse, datetime, time, fcntl, sys, base64

BASE = os.path.expanduser('~/guanggan-comment')
API = 'https://yuedu.jack-sun.com/chat/api.php'
ADMIN_TOKEN = 'sk-chat-20260815-jackson-7f3e9c'
STATE = os.path.join(BASE, 'chat_last_id.txt')
LOG = os.path.join(BASE, 'chatd.log')
LOCK = os.path.join(BASE, '.chatd.lock')
OUT_DIR = os.path.join(BASE, 'out')
INBOX_DIR = os.path.join(BASE, 'inbox')
GEN_REQ = os.path.join(BASE, 'gen_req.txt')
QWEN_CFG = os.path.join(BASE, 'qwen.json')
QWEN_API = 'https://dashscope.aliyuncs.com/compatible-mode/v1'
CLAUDE = '/Users/jacksun/.local/bin/claude'
NODE = '/Users/jacksun/.local/opt/node-v24.18.0-darwin-arm64/bin/node'
SSH_HOST = 'jacksun-tencent'
SLUG = 'chat'
POLL_INTERVAL = 0.8
HISTORY_LIMIT = 30
FLUSH_SECONDS = 0.6          # 流式文本攒批间隔（秒）
CLAUDE_TIMEOUT = 300
IMG_MAX = 2048               # 图片 2K 上限
QWEN_IMAGE_COST = 0.03       # 千问生图单张费用估算（USD，约 ¥0.22，可按实际调整）
os.makedirs(INBOX_DIR, exist_ok=True)

os.makedirs(OUT_DIR, exist_ok=True)


def now():
    return datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')


def log(msg):
    try:
        with open(LOG, 'a') as f:
            f.write('[%s] %s\n' % (now(), msg))
    except Exception:
        pass


def http(url, body=None, admin=False, timeout=25):
    data = json.dumps(body).encode('utf-8') if body is not None else None
    req = urllib.request.Request(url, data=data, method='POST' if body is not None else 'GET')
    req.add_header('Content-Type', 'application/json')
    req.add_header('User-Agent', 'Mozilla/5.0 chatd')
    if admin:
        req.add_header('X-Admin-Token', ADMIN_TOKEN)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode('utf-8'))


def api_get(after):
    url = '%s?slug=%s&after=%d' % (API, SLUG, after)
    try:
        j = http(url, timeout=15)
        return j.get('data', []) if j.get('ok') else []
    except Exception as e:
        log('读取增量失败: %s' % e)
        return None


def insert_ai(reply_to):
    try:
        j = http(API, {'action': 'insert_ai', 'slug': SLUG, 'reply_to': reply_to}, admin=True)
        return j.get('id') if j.get('ok') else None
    except Exception as e:
        log('insert_ai 失败: %s' % e)
        return None


def update_ai(mid, **fields):
    body = {'action': 'update_ai', 'slug': SLUG, 'id': mid}
    body.update(fields)
    try:
        http(API, body, admin=True, timeout=15)
    except Exception as e:
        log('update_ai(%s) 失败: %s' % (mid, e))


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


def qwen_call(path, payload, timeout=60):
    """调千问兼容 API，返回 JSON 或 None"""
    try:
        cfg = json.load(open(QWEN_CFG))
        req = urllib.request.Request(
            QWEN_API + path,
            data=json.dumps(payload).encode('utf-8'),
            headers={'Authorization': 'Bearer ' + cfg['DASHSCOPE_API_KEY'],
                     'Content-Type': 'application/json'})
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read().decode('utf-8'))
    except Exception as e:
        log('千问 API 调用失败(%s): %s' % (path, str(e)[:150]))
        return None


def qwen_vl_describe(image_url):
    """千问看图：识别图片内容，返回描述文本"""
    cfg = json.load(open(QWEN_CFG))
    payload = {
        "model": cfg.get('VL_MODEL', 'qwen-vl-max'),
        "messages": [{"role": "user", "content": [
            {"type": "image_url", "image_url": {"url": image_url}},
            {"type": "text", "text": "请详细描述这张图片的内容：主要物体/人物、场景、图中的文字、整体布局。这是孙哥发来的图，描述要具体、用中文。"}
        ]}]
    }
    j = qwen_call('/chat/completions', payload, timeout=50)
    if j and 'choices' in j:
        return j['choices'][0]['message']['content'][:1500]
    return ''


def _qwen_image_once(prompt, ai_id=None):
    """单次异步生图：提交+轮询。成功返回 (out, None)，失败返回 (None, errmsg)。ai_id 用于推送进度到对话窗口"""
    try:
        cfg = json.load(open(QWEN_CFG))
        headers = {'Authorization': 'Bearer ' + cfg['DASHSCOPE_API_KEY'],
                   'Content-Type': 'application/json',
                   'X-DashScope-Async': 'enable'}
        prompt = ' '.join(prompt.split())   # 压缩空白/换行
        body = {"model": "qwen-image", "input": {"prompt": prompt},
                "parameters": {"size": "1024*1024", "n": 1}}
        req = urllib.request.Request(
            'https://dashscope.aliyuncs.com/api/v1/services/aigc/text2image/image-synthesis',
            data=json.dumps(body).encode('utf-8'), headers=headers)
        with urllib.request.urlopen(req, timeout=40) as r:
            j = json.loads(r.read().decode('utf-8'))
        task_id = j.get('output', {}).get('task_id', '')
        if not task_id:
            return None, '提交失败: %s' % str(j)[:150]
        log('生图任务已提交: %s' % task_id[:16])
        for attempt in range(40):
            time.sleep(3)
            if ai_id:
                # 进度：排队/生成阶段估算 10%→80%
                update_ai(ai_id, tool='image:' + str(min(10 + attempt * 8, 80)))
            try:
                req2 = urllib.request.Request(
                    'https://dashscope.aliyuncs.com/api/v1/tasks/' + task_id, headers=headers)
                with urllib.request.urlopen(req2, timeout=30) as r:
                    j2 = json.loads(r.read().decode('utf-8'))
                st = j2.get('output', {}).get('task_status', '')
                if st == 'SUCCEEDED':
                    if ai_id:
                        update_ai(ai_id, tool='image:100')
                    url = j2['output']['results'][0]['url']
                    out = os.path.join(OUT_DIR, 'chat_gen_%d.png' % int(time.time()))
                    urllib.request.urlretrieve(url, out)
                    return out, None
                if st in ('FAILED', 'CANCELED'):
                    msg = (j2.get('output', {}) or {}).get('message') or st
                    return None, '任务%s: %s' % (st, str(msg)[:150])
            except Exception as e:
                time.sleep(1)
        return None, '轮询超时'
    except Exception as e:
        return None, str(e)[:200]


def qwen_image_generate(prompt, ai_id=None):
    """千问生图（异步任务模式），带重试。返回本地 PNG 路径"""
    for attempt in range(2):
        out, err = _qwen_image_once(prompt, ai_id)
        if out:
            return out
        log('生图第%d次失败: %s' % (attempt + 1, err))
        if attempt == 0:
            time.sleep(2)
    return None


def check_gen_request(ai_id, start_time):
    """检查 claude 是否写了生图指令，有则调千问生图并上传，更新 img 字段"""
    try:
        if not os.path.exists(GEN_REQ):
            return
        mt = os.path.getmtime(GEN_REQ)
        if mt <= start_time:
            return
        prompt = open(GEN_REQ).read().strip()
        if not prompt:
            return
        log('检测到生图指令: %s' % prompt[:60])
        update_ai(ai_id, tool='image:0')   # 前端显示"生成图片中"
        img_path = qwen_image_generate(prompt, ai_id)
        if img_path:
            # 压缩 2K 后上传
            compressed = compress_image(img_path)
            urls = upload_file(ai_id, compressed)
            if urls:
                update_ai(ai_id, img=urls[0])
                update_ai(ai_id, cost_add=QWEN_IMAGE_COST)   # 生图费用累加进费用单
                log('生图完成并已上传: %s 费用+$%.4f' % (urls[0], QWEN_IMAGE_COST))
        else:
            log('生图失败')
    except Exception as e:
        log('生图处理异常: %s' % str(e)[:150])


def compress_image(local_path):
    """用 sips 压缩到 2K/JPEG85，返回压缩后路径"""
    if not os.path.exists(local_path):
        return local_path
    out = local_path.rsplit('.', 1)[0] + '_2k.jpg'
    try:
        r = subprocess.run(
            ['sips', '-Z', str(IMG_MAX), '-s', 'format', 'jpeg', '-s', 'formatOptions', '85',
             local_path, '--out', out],
            capture_output=True, timeout=120)
        if r.returncode == 0 and os.path.exists(out):
            log('图片已压缩 %s -> %s (%.0fKB)' % (local_path.rsplit('/',1)[-1],
                                                    out.rsplit('/',1)[-1], os.path.getsize(out)/1024))
            return out
    except Exception as e:
        log('sips 压缩失败: %s' % str(e)[:100])
    return local_path


def upload_file(mid, local_path):
    """上传单个文件（优先七牛，fallback scp），返回 URL 列表"""
    if not os.path.exists(local_path):
        return []
    fname = os.path.basename(local_path)
    key = 'chat/%d/%s' % (mid, fname)
    qn_js = os.path.join(BASE, 'qiniu_upload.js')
    # 1) 七牛
    try:
        p = subprocess.run([NODE, qn_js, local_path, key],
                           capture_output=True, text=True, timeout=60)
        out = (p.stdout or '').strip()
        if p.returncode == 0 and out.startswith('http'):
            log('七牛上传成功: %s' % out)
            return [out]
        log('七牛上传失败(%s): %s' % (fname, ((p.stderr or '').strip())[:200]))
    except Exception as e:
        log('七牛上传异常(%s): %s' % (fname, e))
    # 2) fallback scp
    try:
        tmp = '/tmp/chat_%d_%s' % (mid, fname)
        subprocess.run(['scp', '-o', 'ConnectTimeout=10', local_path, '%s:%s' % (SSH_HOST, tmp)],
                       timeout=30, capture_output=True)
        subprocess.run(
            ['ssh', '-o', 'ConnectTimeout=10', SSH_HOST,
             'sudo mv %s /www/wwwroot/yuedu.jack-sun.com/chat/uploads/%s && '
             'sudo chown www:www /www/wwwroot/yuedu.jack-sun.com/chat/uploads/%s' % (tmp, tmp.rsplit('/',1)[-1], tmp.rsplit('/',1)[-1])],
            timeout=30, capture_output=True)
        url = 'https://yuedu.jack-sun.com/chat/uploads/%s' % tmp.rsplit('/',1)[-1]
        log('图片上传(兜底)成功: %s' % url)
        return [url]
    except Exception as e:
        log('图片上传失败 %s: %s' % (fname, e))
    return []


def build_prompt(new_msg, history, img_desc=''):
    """构建 claude -p 的 prompt：系统角色 + 对话历史 + 新消息（可含图片描述）"""
    ctx = (
        '你是孙哥的 AI 助手「Claude」，运行在孙哥自己的聊天网页里，孙哥把消息/指令发给你，你直接执行并回复。\n'
        '要求：\n'
        '1. 回复用简体中文，口语化、直接、简洁（除非孙哥要求详细）。不要客套，不要用 markdown 标题。\n'
        '2. 需要实时信息、查资料、搜索时，直接用 WebSearch / WebFetch 联网搜索，别凭印象编造。\n'
        '3. 孙哥要图片/配图/示意图时，把生图提示词（一段详细、具体的描述，中英文皆可）写到文件 '
        '~/guanggan-comment/gen_req.txt（文件里只写提示词这一行内容），然后用文字告诉孙哥图片正在生成。\n'
        '4. 孙哥要打开网页、查网页实时内容、操作网页、网页截图时，用 ego-browser 浏览器技能'
        '（命令格式：ego-browser nodejs <<EOF ... EOF）。网页截图务必用 captureScreenshot 指定完整保存路径，'
        '保存到 ~/guanggan-comment/out/ 目录下（文件名 chat_ 开头，例如 ~/guanggan-comment/out/chat_baidu.png）。'
        '回复时绝对不要用 markdown 图片语法或本地路径，直接用文字说"图片已生成"。'
        '注意：除了 ego-browser 命令，你没有任何其他命令执行能力。\n'
        '5. 你没有删除文件的能力（工具里没有删除类工具），也绝不删除、移动、重命名任何文件；'
        '不要修改或覆盖任何现有文件，除非孙哥明确要求；只做孙哥要求的事情，不碰服务器或无关文件，不乱发外部请求。\n'
        '6. 孙哥是影视公司（光感影视）老板，聊公司/营销/拍片话题时结合这个背景。\n'
    )
    lines = [ctx, '', '你们之前的对话：']
    if history:
        for h in history:
            who = '孙哥' if h['is_admin'] == 0 else 'Claude'
            lines.append('%s: %s' % (who, h['content'][:300]))
    else:
        lines.append('（暂无，这是第一条）')
    lines.append('')
    if img_desc:
        lines.append('孙哥刚才发来一张图片，图片内容如下：')
        lines.append(img_desc)
        lines.append('')
    lines.append('孙哥刚才的新消息：')
    lines.append(new_msg['content'][:800])
    lines.append('')
    lines.append('现在请回复孙哥。')
    return '\n'.join(lines)


def fetch_history(before_id):
    """取孙哥消息 id 之前的最近 N 条对话（含 AI），用于上下文"""
    try:
        all_ = api_get(0)
        if not all_:
            return []
        prev = [m for m in all_ if m['id'] < before_id]
        return prev[-HISTORY_LIMIT:]
    except Exception:
        return []


def stream_claude(prompt, on_text, on_tool):
    """调 claude 流式执行，回调 on_text(完整文本) / on_tool(工具名)。返回 (final_text, ok, cost_usd)"""
    cmd = [
        CLAUDE, '-p', prompt,
        '--output-format', 'stream-json',
        '--verbose',
        '--include-partial-messages',
        # 权限全部放开（孙哥要求：能力与主会话一致，可执行命令/读写文件/联网）
        '--dangerously-skip-permissions',
    ]
    env = dict(os.environ, HOME=os.path.expanduser('~'))
    try:
        proc = subprocess.Popen(cmd, cwd=BASE, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, text=True, bufsize=1, env=env)
    except Exception as e:
        log('启动 claude 失败: %s' % e)
        return '', False, 0

    buf = ''
    full = ''
    ok = False
    cost = 0.0
    last_flush = time.time()
    start = time.time()
    try:
        for line in proc.stdout:
            line = line.strip()
            if not line:
                continue
            try:
                d = json.loads(line)
            except Exception:
                continue
            t = d.get('type')
            if t == 'stream_event':
                ev = d.get('event', {}) or {}
                et = ev.get('type')
                if et == 'content_block_delta':
                    delta = ev.get('delta', {}) or {}
                    if delta.get('type') == 'text_delta':
                        buf += delta.get('text', '')
                elif et == 'content_block_start':
                    cb = ev.get('content_block', {}) or {}
                    if cb.get('type') == 'tool_use':
                        on_tool(cb.get('name', ''))
                elif et == 'message_stop':
                    ok = True
            elif t == 'tool_use':
                on_tool(d.get('name', ''))
            elif t == 'assistant':
                # 完整快照：抓取 tool_use 与文本（兜底）
                msg = d.get('message', {}) or {}
                for block in (msg.get('content') or []):
                    if block.get('type') == 'tool_use':
                        on_tool(block.get('name', ''))
            elif t == 'result':
                if d.get('is_error'):
                    ok = False
                    log('claude result is_error: %s' % str(d)[:200])
                else:
                    ok = True
                    # 用 DeepSeek 真实单价重算（CLI 的 total_cost_usd 按 Anthropic 定价估，虚高约40倍）
                    usage = d.get('usage') or {}
                    it = int(usage.get('input_tokens') or 0)
                    ot = int(usage.get('output_tokens') or 0)
                    cr = int(usage.get('cache_read_input_tokens') or 0)
                    cc = int(usage.get('cache_creation_input_tokens') or 0)
                    miss = max(0, it - cr - cc)
                    cost = (miss * 0.14 + (cr + cc) * 0.0028 + ot * 0.28) / 1e6
                # result.result 为最终文本兜底
                rr = d.get('result')
                if rr and isinstance(rr, str) and len(rr) > len(full):
                    buf = rr

            # 攒批 flush
            if buf:
                full = buf
                if time.time() - last_flush >= FLUSH_SECONDS:
                    on_text(full)
                    last_flush = time.time()

            if time.time() - start > CLAUDE_TIMEOUT:
                log('claude 超时，终止')
                proc.kill()
                break

        # 收尾 flush
        if buf:
            full = buf
            on_text(full)
    except Exception as e:
        log('流式读取异常: %s' % e)

    try:
        proc.wait(timeout=10)
    except Exception:
        proc.kill()
    return full, ok, cost


def upload_images(mid, start_time):
    """把 claude 生成的 out/ 新图片 + /tmp 兜底截图，先压缩 2K 再上传（七牛优先），返回图片 URL 列表"""
    urls = []
    files = []
    try:
        files += [os.path.join(OUT_DIR, f) for f in os.listdir(OUT_DIR)
                  if os.path.getmtime(os.path.join(OUT_DIR, f)) > start_time
                  and f.lower().endswith(('.png', '.jpg', '.jpeg', '.gif', '.webp'))]
    except Exception:
        pass
    # /tmp 兜底：claude 用 ego-browser 截图可能存到 /tmp（如 baidu_screenshot.png）
    try:
        for f in os.listdir('/tmp'):
            p = os.path.join('/tmp', f)
            try:
                if os.path.getmtime(p) > start_time and f.lower().endswith(('.png', '.jpg', '.jpeg', '.gif', '.webp')) \
                        and os.path.getsize(p) > 10240:
                    files.append(p)
            except Exception:
                pass
    except Exception:
        pass
    seen = set()
    for local in files:
        if local in seen:
            continue
        seen.add(local)
        compressed = compress_image(local)
        urls.extend(upload_file(mid, compressed))
        # 清理 /tmp 的截图，避免重复上传
        if local.startswith('/tmp/'):
            for p in (local, compressed):
                if p.startswith('/tmp/'):
                    try:
                        os.remove(p)
                    except Exception:
                        pass
    return urls


def handle_message(m):
    """处理一条孙哥的新留言。返回 True 表示完成。"""
    uid = m['id']
    text = m.get('content', '')
    log('处理孙哥留言 #%d: %s' % (uid, text[:60]))

    ai_id = insert_ai(uid)
    if not ai_id:
        log('插入 AI 消息失败，跳过')
        return True
    log('已插入 AI 消息 #%d (thinking)' % ai_id)

    history = fetch_history(uid)

    # 看图：孙哥消息带图片 → 千问 qwen-vl 识别，喂给 claude
    img_desc = ''
    img_url = m.get('img') or ''
    if img_url:
        log('孙哥发了图，调用 qwen-vl 识别: %s' % img_url[:80])
        img_desc = qwen_vl_describe(img_url)
        log('图片识别: %s' % (img_desc[:80] + '...' if img_desc else '（识别失败）'))

    prompt = build_prompt(m, history, img_desc)
    start = time.time()

    def on_text(full):
        update_ai(ai_id, content=full, status='streaming')

    def on_tool(name):
        log('工具调用: %s' % name)
        update_ai(ai_id, tool=name)

    final, ok, cost = stream_claude(prompt, on_text, on_tool)

    if ok and final:
        update_ai(ai_id, content=final, status='done')
        log('完成 #%d -> AI #%d (done)' % (uid, ai_id))
    else:
        update_ai(ai_id, content=final or '抱歉，这次我没能完成（出错了）。请再试一次。', status='error')
        log('错误 #%d -> AI #%d (error)' % (uid, ai_id))

    # 费用写回（USD）
    if cost:
        update_ai(ai_id, cost=cost)
        log('本轮费用: $%.4f' % cost)

    # claude 直接生成的图片
    urls = upload_images(ai_id, start)
    if urls:
        update_ai(ai_id, img=urls[0])
    else:
        # 无直接图时，检查生图指令（claude 写了 gen_req.txt 则调千问生图）
        check_gen_request(ai_id, start)
    return True


def main():
    lock_f = open(LOCK, 'w')
    try:
        fcntl.flock(lock_f, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        log('已有实例在运行，退出')
        sys.exit(0)

    last_id = read_last()
    log('chatd 启动，last_id=%d，轮询间隔 %.1fs' % (last_id, POLL_INTERVAL))

    while True:
        try:
            data = api_get(last_id)
            if data is None:
                time.sleep(POLL_INTERVAL)
                continue
            # 取孙哥的 pending 留言（is_admin=0 且 status='pending'）
            pending = [x for x in data if x.get('is_admin') == 0 and x.get('status') == 'pending']
            if pending:
                for m in pending:
                    handle_message(m)
                max_id = max(x['id'] for x in data)
                last_id = max(last_id, max_id)
                write_last(last_id)
            else:
                # 无待处理，推进游标
                for x in data:
                    last_id = max(last_id, x['id'])
                write_last(last_id)
        except Exception as e:
            log('主循环异常: %s' % e)
        time.sleep(POLL_INTERVAL)


if __name__ == '__main__':
    main()
