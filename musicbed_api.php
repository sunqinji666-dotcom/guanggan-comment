<?php
// Musicbed 收藏 API：增删查（数据存 data/favorites.json）
// GET  ?action=list                     → 收藏列表
// POST {action:'add', song:{...}}       → 添加收藏
// POST {action:'remove', song:{id}}     → 取消收藏
header('Content-Type: application/json');
$dir = __DIR__ . '/data';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
// source: hot=每日热点页(favorites.json) / browse=中文馆(favorites_browse.json)
$source = ($_GET['source'] ?? 'hot') === 'browse' ? 'browse' : 'hot';
$file = ($source === 'browse') ? $dir . '/favorites_browse.json' : $dir . '/favorites.json';

function mb_load($file) {
    if (!file_exists($file)) return [];
    $d = json_decode(@file_get_contents($file), true);
    return is_array($d) ? array_values($d) : [];
}
function mb_save($file, $data) {
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'list') {
        echo json_encode(['ok' => true, 'data' => mb_load($file)]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'unknown action']);
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($input['source'] ?? 'hot') === 'browse') $file = $dir . '/favorites_browse.json';
    $action = $input['action'] ?? '';
    $song = $input['song'] ?? null;
    $favs = mb_load($file);
    if ($action === 'add' && is_array($song) && isset($song['id'])) {
        $favs = array_values(array_filter($favs, function ($f) use ($song) { return (int)$f['id'] !== (int)$song['id']; }));
        $favs[] = [
            'id'       => (int)$song['id'],
            'name'     => $song['name'] ?? '',
            'artist'   => $song['artist'] ?? '',
            'audioUrl' => $song['audioUrl'] ?? '',
            'cover'    => $song['cover'] ?? '',
            'bpm'      => $song['bpm'] ?? '',
            'length'   => $song['length'] ?? '',
            'download' => !empty($song['download']),
            'date'     => date('Y-m-d'),
        ];
        mb_save($file, $favs);
        echo json_encode(['ok' => true, 'data' => $favs]);
    } elseif ($action === 'update_full') {
        // 本机抓完整版成功后回写 fullUrl（POST {action:'update_full', id, fullUrl}）
        $id = (int)($input['id'] ?? 0);
        $fullUrl = trim($input['fullUrl'] ?? '');
        if ($id <= 0 || $fullUrl === '') {
            echo json_encode(['ok' => false, 'error' => 'bad update_full']);
            exit;
        }
        foreach ($favs as &$f) {
            if ((int)$f['id'] === $id) { $f['fullUrl'] = $fullUrl; break; }
        }
        unset($f);
        mb_save($file, $favs);
        echo json_encode(['ok' => true, 'data' => $favs]);
    } elseif ($action === 'queue_download') {
        // 把歌加入下载队列（source=browse，强制 download=true）
        $song = $input['song'] ?? null;
        if (!is_array($song) || !isset($song['id'])) {
            echo json_encode(['ok' => false, 'error' => 'bad song']);
            exit;
        }
        $favs = array_values(array_filter($favs, function ($f) use ($song) { return (int)$f['id'] !== (int)$song['id']; }));
        $favs[] = [
            'id'       => (int)$song['id'],
            'name'     => $song['name'] ?? '',
            'artist'   => $song['artist'] ?? '',
            'audioUrl' => $song['audioUrl'] ?? '',
            'cover'    => $song['cover'] ?? '',
            'length'   => $song['length'] ?? '',
            'download' => true,
            'date'     => date('Y-m-d'),
        ];
        mb_save($file, $favs);
        echo json_encode(['ok' => true, 'data' => $favs]);
    } elseif ($action === 'remove' && is_array($song) && isset($song['id'])) {
        $favs = array_values(array_filter($favs, function ($f) use ($song) { return (int)$f['id'] !== (int)$song['id']; }));
        mb_save($file, $favs);
        // 取消收藏 → 记录待清理（本机每日任务会及时删七牛音频）
        $audioUrl = $song['audioUrl'] ?? '';
        if ($audioUrl) {
            $key = preg_replace('#^https?://[^/]+/qiniu/#', '', $audioUrl);
            if (preg_match('#^musicbed/#', $key)) {
                $removedFile = $dir . '/removed.json';
                $removed = [];
                if (file_exists($removedFile)) { $rd = json_decode(@file_get_contents($removedFile), true); if (is_array($rd)) $removed = $rd; }
                $removed[] = ['id' => (int)$song['id'], 'key' => $key, 'removed_at' => date('Y-m-d H:i:s')];
                mb_save($removedFile, $removed);
            }
        }
        echo json_encode(['ok' => true, 'data' => $favs]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'bad request']);
    }
} else {
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
}
