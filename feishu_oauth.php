<?php
// 飞书 OAuth 回调：接收授权码 code，换 user_access_token，存到 /opt/mb-agent/user_token.json
header('Content-Type: text/html; charset=utf-8');
$code = $_GET['code'] ?? '';
if ($code === '') { echo '缺少授权码 code'; exit; }

$cfg = json_decode(@file_get_contents('/opt/mb-agent/feishu.json'), true);
if (!$cfg || empty($cfg['appId']) || empty($cfg['appSecret'])) { echo 'feishu.json 读取失败'; exit; }

$body = json_encode([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'app_id' => $cfg['appId'],
    'app_secret' => $cfg['appSecret'],
]);
$ch = curl_init('https://open.feishu.cn/open-apis/authen/v2/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 20,
]);
$res = curl_exec($ch);
curl_close($ch);
$j = json_decode((string)$res, true);

if (isset($j['access_token']) && $j['access_token']) {
    $data = [
        'access_token' => $j['access_token'],
        'refresh_token' => $j['refresh_token'] ?? '',
        'expires_at' => time() + (int)($j['expires_in'] ?? 7200),
        'refresh_expires_at' => time() + (int)($j['refresh_expires_in'] ?? 0),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if (file_put_contents('/opt/mb-agent/user_token.json', json_encode($data, JSON_PRETTY_PRINT), LOCK_EX)) {
        echo '<h2>✅ 飞书授权成功！</h2><p>你可以回到飞书使用了，机器人现在能读你的云空间和日历。</p>';
    } else {
        echo '授权成功但写入 token 失败（检查 /opt/mb-agent 权限）';
    }
} else {
    echo '<h2>❌ 授权失败</h2><pre>' . htmlspecialchars(json_encode($j, JSON_UNESCAPED_UNICODE)) . '</pre>';
}
