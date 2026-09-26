#!/usr/bin/env node
/**
 * 七牛云按前缀批量删除对象（用于 3 天过期清理 Musicbed 音频）
 * 用法: qiniu_delete.js <前缀，如 musicbed/2026-08-17/>
 * 成功输出删除统计，退出码 0；参数缺失退出 2；部分失败退出 1
 */
const qiniu = require('./node_modules/qiniu');
const fs = require('fs');
const path = require('path');

const CFG = path.join(process.env.HOME, 'guanggan-comment', 'qiniu.json');
const cfg = JSON.parse(fs.readFileSync(CFG, 'utf8'));

const prefix = process.argv[2];
if (!prefix) {
  console.error('用法: qiniu_delete.js <前缀> [排除的歌曲id，逗号分隔]');
  process.exit(2);
}
const exclude = (process.argv[3] || '').split(',').map(s => s.trim()).filter(Boolean);

const zoneMap = {
  'Zone_z0': qiniu.zone.Zone_z0,
  'Zone_z1': qiniu.zone.Zone_z1,
  'Zone_z2': qiniu.zone.Zone_z2,
  'Zone_na0': qiniu.zone.Zone_na0,
  'Zone_as0': qiniu.zone.Zone_as0,
};
const zone = zoneMap[cfg.QINIU_ZONE] || qiniu.zone.Zone_z0;
const mac = new qiniu.auth.digest.Mac(cfg.QINIU_ACCESS_KEY, cfg.QINIU_SECRET_KEY);
const bucketManager = new qiniu.rs.BucketManager(mac, new qiniu.conf.Config({ zone }));

function listAll(pfx) {
  return new Promise((resolve, reject) => {
    const keys = [];
    const loop = (marker) => {
      bucketManager.listPrefix(cfg.QINIU_BUCKET, { prefix: pfx, limit: 500, marker }, (err, body) => {
        if (err) return reject(err);
        if (body && body.items) for (const it of body.items) keys.push(it.key);
        if (body && body.marker) return loop(body.marker);
        resolve(keys);
      });
    };
    loop(null);
  });
}

function delOne(key) {
  return new Promise((resolve) => {
    bucketManager.delete(cfg.QINIU_BUCKET, key, (err, body, info) => {
      if (err || (info && info.statusCode !== 200)) resolve(false);
      else resolve(true);
    });
  });
}

(async () => {
  let keys;
  try {
    keys = await listAll(prefix);
  } catch (e) {
    console.error('列出失败: ' + e.message);
    process.exit(1);
  }
  if (!keys.length) {
    console.log('前缀 ' + prefix + ' 下无对象，无需清理');
    process.exit(0);
  }
  let ok = 0, skipped = 0;
  for (const k of keys) {
    const m = k.match(/\/(\d+)\.mp3$/);
    if (m && exclude.includes(m[1])) { skipped++; continue; }
    if (await delOne(k)) ok++;
  }
  const fail = keys.length - ok - skipped;
  console.log('删除 ' + keys.length + ' 个对象（成功 ' + ok + '，失败 ' + fail + '，跳过收藏 ' + skipped + '）：' + prefix);
  process.exit(fail ? 1 : 0);
})();
