<?php
require_once __DIR__ . '/includes/music.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int) ($_SESSION['home_user_id'] ?? 0);
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $db_error ?? music_label('error.mysql_connection', 'Lỗi kết nối MySQL.')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => music_label('login.required', 'Vui lòng đăng nhập.')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'list'));

try {
    if ($action === 'list') {
        $stmt = $pdo->prepare('SELECT * FROM music_playlist WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
        $stmt->execute([$userId]);
        $playlists = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $songs = json_decode((string) ($row['songs_json'] ?? '[]'), true);
            $playlists[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
                'songs' => is_array($songs) ? array_values($songs) : [],
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }
        echo json_encode(['ok' => true, 'playlists' => $playlists], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($name === '') {
            throw new RuntimeException(music_label('playlist.error_name', 'Vui lòng nhập tên playlist.'));
        }
        $stmt = $pdo->prepare('INSERT INTO music_playlist (user_id, name, description, songs_json) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $name, $description, '[]']);
        echo json_encode(['ok' => true, 'playlist' => ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'description' => $description, 'songs' => []]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'update') {
        $playlistId = (int) ($_POST['playlist_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($playlistId <= 0 || $name === '') {
            throw new RuntimeException(music_label('playlist.error_update', 'Thông tin playlist không hợp lệ.'));
        }
        $stmt = $pdo->prepare('UPDATE music_playlist SET name = ?, description = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$name, $description, $playlistId, $userId]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'delete') {
        $playlistId = (int) ($_POST['playlist_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM music_playlist WHERE id = ? AND user_id = ?');
        $stmt->execute([$playlistId, $userId]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'add_song') {
        $playlistId = (int) ($_POST['playlist_id'] ?? 0);
        $song = [
            'id' => trim((string) ($_POST['song_id'] ?? '')),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'artist' => trim((string) ($_POST['artist'] ?? '')),
            'mp3' => trim((string) ($_POST['mp3'] ?? '')),
            'avatar' => trim((string) ($_POST['avatar'] ?? '')),
            'added_at' => date('c'),
        ];
        if ($playlistId <= 0 || $song['name'] === '' || $song['mp3'] === '') {
            throw new RuntimeException(music_label('playlist.error_song', 'Không thể lưu bài hát này vào playlist.'));
        }

        $stmt = $pdo->prepare('SELECT songs_json FROM music_playlist WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$playlistId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(music_label('playlist.not_found', 'Không tìm thấy playlist.'));
        }

        $songs = json_decode((string) ($row['songs_json'] ?? '[]'), true);
        $songs = is_array($songs) ? array_values($songs) : [];
        $fingerprint = $song['id'] !== '' ? 'id:' . $song['id'] : 'mp3:' . $song['mp3'];
        foreach ($songs as $item) {
            $itemFingerprint = trim((string) ($item['id'] ?? '')) !== '' ? 'id:' . trim((string) $item['id']) : 'mp3:' . trim((string) ($item['mp3'] ?? ''));
            if ($itemFingerprint === $fingerprint) {
                echo json_encode(['ok' => true, 'message' => music_label('playlist.song_exists', 'Bài hát đã có trong playlist.')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }

        $songs[] = $song;
        $stmt = $pdo->prepare('UPDATE music_playlist SET songs_json = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([json_encode($songs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $playlistId, $userId]);
        echo json_encode(['ok' => true, 'message' => music_label('playlist.song_saved', 'Đã lưu bài hát vào playlist.')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    throw new RuntimeException(music_label('playlist.error_action', 'Tác vụ playlist không hợp lệ.'));
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
