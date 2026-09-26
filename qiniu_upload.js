#!/usr/bin/env node
/**
 * 七牛云上传（官方 SDK，可靠）
 * 用法: qiniu_upload.js <本地文件> <七牛key>
 * 成功输出访问 URL（含域名），失败退出码 1
 */
const qiniu = require('./node_modules/qiniu');
const fs = require('fs');
const path = require('path');

const CFG = path.join(process.env.HOME, 'guanggan-comment', 'qiniu.json');
const cfg = JSON.parse(fs.readFileSync(CFG, 'utf8'));

const [local, key] = process.argv.slice(2);
if (!local || !key) {
  console.error('用法: qiniu_upload.js <本地文件> <key>');
  process.exit(2);
}
if (!fs.existsSync(local)) {
  console.error('文件不存在: ' + local);
  process.exit(2);
}

const zoneMap = {
  'Zone_z0': qiniu.zone.Zone_z0,
  'Zone_z1': qiniu.zone.Zone_z1,
  'Zone_z2': qiniu.zone.Zone_z2,
  'Zone_na0': qiniu.zone.Zone_na0,
  'Zone_as0': qiniu.zone.Zone_as0,
};
const zone = zoneMap[cfg.QINIU_ZONE] || qiniu.zone.Zone_z0;

const mac = new qiniu.auth.digest.Mac(cfg.QINIU_ACCESS_KEY, cfg.QINIU_SECRET_KEY);
const putPolicy = new qiniu.rs.PutPolicy({ scope: cfg.QINIU_BUCKET + ':' + key });
const token = putPolicy.uploadToken(mac);
const config = new qiniu.conf.Config({ zone });
const formUploader = new qiniu.form_up.FormUploader(config);

formUploader.putFile(token, key, local, new qiniu.form_up.PutExtra(), (err, body, info) => {
  if (err || !info || info.statusCode !== 200 || !body || body.key !== key) {
    console.error('七牛上传失败: ' + (err ? err.message : JSON.stringify(body)));
    process.exit(1);
  }
  // 通过 yuedu 站 nginx 反代提供 https 访问（七牛 CDN 无 https 证书）
  console.log('https://yuedu.jack-sun.com/qiniu/' + key);
  process.exit(0);
});
