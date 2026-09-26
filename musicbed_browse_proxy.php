<?php
/**
 * Musicbed 中文皮站 · 后端代理 v2
 * 完整复刻官网 Browse 的筛选/搜索/排序/分页（数据与官网一致）
 *
 * 核心：官网 Browse 页面实际使用的 POST /api/search/songs 接口。
 *   该接口需要会话 XSRF，但「访客会话」即可（无需登录账号）：
 *   先 GET novus-api 任意端点拿 XSRF-TOKEN cookie → 再 POST 时带 cookie + X-XSRF-TOKEN header。
 *
 * GET  action=filters                   → 完整筛选维度（流派/情绪/人声/调性/主题/国家，附 slug）
 * GET  action=search&genres=&moods=&vocals=&keys=&chars=&order=&page=&limit=
 *                                       → 筛选结果（与官网一致）+ 分页 meta + 30秒试听 URL
 *
 * 参数：genres/moods/vocals/keys/chars/countries 为 slug 逗号分隔多选；order=default|popularity|newest；page≥1
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir = __DIR__ . '/data';
$cacheDir = $dir . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

const API = 'https://novus-api.musicbed.com';
const XSRF_TTL = 1800;      // XSRF token 缓存 30 分钟
const RESULT_TTL = 300;     // 搜索结果缓存 5 分钟
const FILTERS_TTL = 86400;  // 分类清单缓存 1 天

$JAR = $cacheDir . '/xsrf_jar.txt';
$XSRF_CACHE = $cacheDir . '/xsrf.json';

// ---------- 访客会话：XSRF ----------
function mb_get_xsrf() {
    global $JAR, $XSRF_CACHE, $cacheDir;
    if (is_file($XSRF_CACHE)) {
        $d = json_decode(@file_get_contents($XSRF_CACHE), true);
        if (is_array($d) && time() - $d['ts'] < XSRF_TTL && !empty($d['token'])) return $d['token'];
    }
    // GET novus-api 端点拿 XSRF-TOKEN cookie（set-cookie 域 .musicbed.com）
    $ch = curl_init(API . '/api/user/theme');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_COOKIEJAR      => $JAR,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
    ]);
    $resp = curl_exec($ch);
    $token = null;
    // 从响应头 Set-Cookie 解析 XSRF-TOKEN（可能多段，找 Set-Cookie: XSRF-TOKEN=...）
    if (preg_match('/Set-Cookie:\s*XSRF-TOKEN=([^;]+)/i', (string)$resp, $m)) {
        $token = rawurldecode(trim($m[1]));
    }
    // 兜底：从 cookie jar 读
    if (!$token && is_file($JAR)) {
        $jar = @file_get_contents($JAR);
        if ($jar && preg_match('/XSRF-TOKEN\s+(\S+)/', $jar, $m)) $token = rawurldecode(trim($m[1]));
    }
    if ($token) {
        @file_put_contents($XSRF_CACHE, json_encode(['ts' => time(), 'token' => $token]), LOCK_EX);
    }
    return $token;
}

// ---------- 调 search API ----------
function mb_search($params) {
    global $JAR;
    $token = mb_get_xsrf();
    if (!$token) return [0, '{"status":false,"message":"no xsrf"}'];
    $ch = curl_init(API . '/api/search/songs');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_COOKIEFILE     => $JAR,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://www.musicbed.com/',
            'X-XSRF-TOKEN: ' . $token,
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

// ---------- 组装歌曲 ----------
function mb_build_song($s) {
    if (!is_array($s) || !isset($s['id'])) return null;
    return [
        'id'           => (int)$s['id'],
        'name'         => $s['name'] ?? '',
        'artist'       => $s['album']['artist']['name'] ?? '',
        'album'        => $s['album']['name'] ?? '',
        'cover'        => $s['album']['image']['url'] ?? ($s['album']['image']['base_path'] ?? ''),
        'length'       => $s['length'] ?? '',
        'duration'     => $s['duration'] ?? 0,
        'instrumental' => (bool)($s['instrumental'] ?? false),
        'playback_url' => $s['playback_url'] ?? '',
        'genres'       => array_values(array_filter(array_map(fn($g) => $g['name'] ?? '', $s['genres'] ?? []))),
        'moods'        => array_values(array_filter(array_map(fn($m) => $m['name'] ?? '', $s['moods'] ?? []))),
        'versions'     => (int)($s['versions_count'] ?? 0),
        'song_url'     => $s['song_url'] ?? (!empty($s['slug']) && !empty($s['id']) ? '/songs/' . $s['slug'] . '/' . $s['id'] : ''),
    ];
}

function mb_cache_get($key, $ttl) {
    global $cacheDir;
    $f = $cacheDir . '/' . md5($key) . '.json';
    if (is_file($f) && (time() - filemtime($f)) < $ttl) {
        $d = json_decode(@file_get_contents($f), true);
        if (is_array($d)) return $d;
    }
    return null;
}
function mb_cache_set($key, $data) {
    global $cacheDir;
    @file_put_contents($cacheDir . '/' . md5($key) . '.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// ---------- 解析多选参数 ----------
function mb_list($key) {
    $v = $_GET[$key] ?? '';
    return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
}

$action = $_GET['action'] ?? '';

// ---- filters：完整筛选维度 ----
if ($action === 'filters') {
    $cached = mb_cache_get('filters-v3', FILTERS_TTL);
    if ($cached) { echo json_encode($cached); exit; }
    $out = ['ok' => true, 'dimensions' => []];
    [$code, $body] = mb_search(['exclude' => [], 'include' => [], 'order' => 'default', 'page' => 1, 'query_string' => '', 'limit' => 1]);
    // filters 清单走另一个匿名接口（无需 XSRF）
    $ch = curl_init(API . '/api/search/filters');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'Mozilla/5.0']);
    $fb = curl_exec($ch); curl_close($ch);
    $fj = json_decode((string)$fb, true);
    if (is_array($fj)) {
        $items = $fj['items'] ?? [];
        foreach (['genres', 'moods', 'vocals', 'instruments'] as $dim) {
            $out['dimensions'][$dim] = array_map(fn($x) => ['name' => $x['name'] ?? '', 'slug' => $x['slug'] ?? ''], $items[$dim] ?? []);
        }
        $out['dimensions']['keys']   = array_map(fn($x) => ['name' => $x['name'] ?? '', 'slug' => $x['slug'] ?? ''], $items['music_keys'] ?? []);
        $out['dimensions']['chars']  = array_map(fn($x) => ['name' => $x['name'] ?? '', 'slug' => $x['slug'] ?? ''], $items['chars'] ?? []);
        $out['dimensions']['countries'] = array_map(fn($x) => ['name' => $x['name'] ?? '', 'slug' => $x['slug'] ?? ''], $items['countries'] ?? []);
        $out['dimensions']['artists'] = array_map(fn($x) => ['name' => $x['name'] ?? '', 'slug' => $x['slug'] ?? ''], $items['artists'] ?? []);
    }
    mb_cache_set('filters-v3', $out);
    echo json_encode($out);
    exit;
}

// ---- search：筛选 + 分页 ----
if ($action === 'search') {
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(20, (int)($_GET['limit'] ?? 50)));
    $orderRaw = $_GET['order'] ?? 'default';
    $order = in_array($orderRaw, ['default', 'popularity', 'newest', 'recent'], true) ? $orderRaw : 'default';

    $include = [];
    foreach (['genres', 'moods', 'vocals', 'keys', 'chars', 'countries', 'instruments', 'artists', 'name'] as $dim) {
        $v = mb_list($dim);
        if ($v) $include[$dim] = $v;
    }
    // Lyrical / Instrumental 开关：instrumental=true 纯器乐 / false 有人声
    if (isset($_GET['instrumental']) && ($_GET['instrumental'] === 'true' || $_GET['instrumental'] === 'false')) {
        $include['instrumental'] = $_GET['instrumental'] === 'true';
    }

    $cacheKey = 'search-v4-' . md5(json_encode([$include, $order, $page, $limit]));
    $cached = mb_cache_get($cacheKey, RESULT_TTL);
    if ($cached) { echo json_encode($cached); exit; }

    $qs = [];
    foreach ($include as $k => $v) { if (is_array($v)) $qs[$k] = implode(',', $v); }
    $params = [
        'exclude' => [],
        'include' => $include,
        'order'   => $order,
        'page'    => $page,
        'query_string' => http_build_query($qs),
        'limit'   => $limit,
    ];
    [$code, $body] = mb_search($params);
    if ($code !== 200 || !$body) {
        // 可能 XSRF 过期，强制刷新一次再试
        @unlink($GLOBALS['XSRF_CACHE']);
        [$code, $body] = mb_search($params);
    }
    $j = json_decode((string)$body, true);
    if ($code !== 200 || !is_array($j) || !isset($j['data'])) {
        echo json_encode(['ok' => false, 'error' => 'upstream ' . $code, 'detail' => substr((string)$body, 0, 200)]);
        exit;
    }
    $songs = [];
    foreach ($j['data'] as $s) { $b = mb_build_song($s); if ($b) $songs[] = $b; }
    $meta = $j['meta'] ?? [];
    $out = ['ok' => true, 'meta' => [
        'total' => $meta['total'] ?? 0,
        'current_page' => $meta['current_page'] ?? $page,
        'last_page' => $meta['last_page'] ?? 1,
        'from' => $meta['from'] ?? null,
        'to' => $meta['to'] ?? null,
    ], 'songs' => $songs];
    mb_cache_set($cacheKey, $out);
    echo json_encode($out);
    exit;
}

// ---- search_name：按歌名搜索（Musicbed search/suggestions 接口） ----
if ($action === 'search_name') {
    $q = trim($_GET['q'] ?? '');
    if ($q === '') { echo json_encode(['ok' => false, 'error' => 'need q']); exit; }
    $cacheKey = 'name-' . md5(strtolower($q));
    $cached = mb_cache_get($cacheKey, 600);
    if ($cached) { echo json_encode($cached); exit; }
    $token = mb_get_xsrf();
    $ch = curl_init(API . '/api/search/suggestions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => json_encode(['query' => $q]),
        CURLOPT_COOKIEFILE => $JAR,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-XSRF-TOKEN: ' . $token],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$body, true);
    $songs = [];
    foreach (($j['suggestions'] ?? []) as $s) {
        if (($s['type'] ?? '') === 'songs' && !empty($s['id'])) {
            $songs[] = ['id' => (int)$s['id'], 'name' => $s['name'] ?? '', 'artist' => $s['artist']['name'] ?? ''];
        }
    }
    $out = ['ok' => true, 'songs' => $songs];
    mb_cache_set($cacheKey, $out);
    echo json_encode($out);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown action']);
