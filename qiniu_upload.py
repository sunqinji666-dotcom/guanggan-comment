#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
七牛云上传工具（无 SDK，标准库 + curl）
配置文件：~/guanggan-comment/qiniu.json
  { QINIU_ACCESS_KEY, QINIU_SECRET_KEY, QINIU_BUCKET, QINIU_DOMAIN, QINIU_ZONE }
区域端点：Zone_z0=upload.qiniup.com, z1=upload-z1, z2=upload-z2（华南）
"""
import hmac, hashlib, json, base64, time, os, subprocess, urllib.parse

CFG = os.path.join(os.path.expanduser('~'), 'guanggan-comment', 'qiniu.json')

ZONE_HOST = {
    'Zone_z0': 'upload.qiniup.com',
    'Zone_z1': 'upload-z1.qiniup.com',
    'Zone_z2': 'upload-z2.qiniup.com',
    'Zone_na0': 'upload-na0.qiniup.com',
    'Zone_as0': 'upload-as0.qiniup.com',
}


def _urlsafe_b64(data):
    if isinstance(data, str):
        data = data.encode('utf-8')
    return base64.urlsafe_b64encode(data).decode().rstrip('=')


def gen_upload_token(ak, sk, bucket, key=None, expires=3600):
    """生成表单上传凭证（put policy）"""
    deadline = int(time.time()) + expires
    policy = {"scope": "%s:%s" % (bucket, key) if key else bucket, "deadline": deadline}
    encoded_policy = _urlsafe_b64(json.dumps(policy, separators=(',', ':')))
    # 官方 SDK：签名数据即 encoded_policy 本身（token 里也放同一字符串）
    sign = hmac.new(sk.encode(), encoded_policy.encode(), hashlib.sha1).digest()
    encoded_sign = _urlsafe_b64(sign)
    return '%s:%s:%s' % (ak, encoded_sign, encoded_policy)


def load_config():
    with open(CFG) as f:
        return json.load(f)


def upload(local_path, key):
    """上传文件到七牛，成功返回访问 URL，失败抛异常"""
    cfg = load_config()
    ak, sk = cfg['QINIU_ACCESS_KEY'], cfg['QINIU_SECRET_KEY']
    bucket, domain = cfg['QINIU_BUCKET'], cfg['QINIU_DOMAIN']
    zone = cfg.get('QINIU_ZONE', 'Zone_z0')
    host = ZONE_HOST.get(zone, ZONE_HOST['Zone_z0'])
    token = gen_upload_token(ak, sk, bucket, key)

    # curl multipart 上传（表单字段顺序：token, key, file）
    cmd = ['curl', '-s', '-X', 'POST',
           '--max-time', '60',
           '--form-string', 'token=%s' % token,
           '--form-string', 'key=%s' % key,
           '-F', 'file=@%s' % local_path,
           'https://%s' % host]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    out = (proc.stdout or '').strip()
    if proc.returncode != 0 or '"error"' in out:
        raise RuntimeError('七牛上传失败: %s' % (out or proc.stderr))
    return domain.rstrip('/') + '/' + urllib.parse.quote(key)


if __name__ == '__main__':
    import sys
    if len(sys.argv) != 3:
        print('用法: qiniu_upload.py <本地文件> <七牛key>')
        sys.exit(1)
    url = upload(sys.argv[1], sys.argv[2])
    print('上传成功:', url)
