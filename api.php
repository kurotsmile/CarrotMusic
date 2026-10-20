<?php
require_once __DIR__ . '/includes/music.php';

music_start_session();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');

function music_api_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function music_api_absolute_url(?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return music_absolute_url('cr_player/song.png');
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    if (strpos($url, '//') === 0) {
        return 'https:' . $url;
    }
    return music_absolute_url(ltrim($url, '/'));
}

function music_api_clean_text(?string $text): string
{
    $text = (string) $text;
    $text = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $text);
    $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $text);
    $text = preg_replace('/<\s*\/\s*p\s*>/i', "\n\n", (string) $text);
    $text = strip_tags((string) $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+\n/", "\n", (string) $text);
    $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);
    return trim((string) $text);
}

function music_api_song(array $song): array
{
    $lang = trim((string) ($song['lang'] ?? ''));
    $mp3 = trim((string) ($song['mp3'] ?? ''));
    $lyrics = music_api_clean_text((string) ($song['lyrics'] ?? ''));
    $artistIds = array_filter(array_map('trim', explode(',', (string) ($song['artist_ids'] ?? ''))));

    return [
        'id' => (string) ($song['id'] ?? ''),
        'name' => (string) ($song['name'] ?? ''),
        'artist' => (string) (($song['artist_names'] ?? '') ?: ($song['artist'] ?? '')),
        'artist_id' => (string) ($artistIds[0] ?? ''),
        'album' => (string) ($song['album'] ?? ''),
        'genre' => (string) ($song['genre'] ?? ''),
        'year' => (string) ($song['year'] ?? ''),
        'lang' => $lang,
        'country' => (string) ($song['country_name'] ?? ''),
        'avatar' => music_api_absolute_url(music_cover((string) ($song['avatar'] ?? ''))),
        'mp3' => $mp3 !== '' ? music_api_absolute_url($mp3) : '',
        'url' => music_absolute_url(ltrim(music_song_url((string) ($song['id'] ?? ''), $lang), '/')),
        'lyrics' => $lyrics,
        'description' => music_excerpt($lyrics !== '' ? $lyrics : (string) (($song['album'] ?? '') . ' ' . ($song['genre'] ?? '')), 180),
        'view_count' => (int) ($song['view_count'] ?? 0),
    ];
}

function music_api_artist(array $artist, int $songCount = 0): array
{
    $id = (int) ($artist['id'] ?? 0);
    $name = (string) ($artist['name'] ?? '');

    return [
        'id' => (string) $id,
        'name' => $name,
        'lang' => (string) ($artist['lang_key'] ?? ''),
        'avatar' => music_api_absolute_url(music_cover((string) ($artist['avatar'] ?? ''))),
        'description' => music_api_clean_text((string) ($artist['description'] ?? '')),
        'song_count' => $songCount,
        'url' => music_absolute_url(ltrim(music_artist_url($id, $name), '/')),
    ];
}

function music_api_language(array $language): array
{
    return [
        'id' => (int) ($language['id'] ?? 0),
        'name' => (string) ($language['name'] ?? ''),
        'lang' => (string) ($language['lang_key'] ?? ''),
        'country' => (string) ($language['lang_country'] ?? ''),
        'icon' => music_api_absolute_url((string) ($language['icon'] ?? '')),
    ];
}

function music_api_menu_item(string $id, string $title, string $subtitle = '', string $avatar = '', string $type = ''): array
{
    return [
        'id' => $id,
        'title' => $title,
        'subtitle' => $subtitle,
        'avatar' => trim($avatar) !== '' ? music_api_absolute_url($avatar) : '',
        'type' => $type,
    ];
}

$action = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'home')));
$limit = max(1, min(60, (int) ($_GET['limit'] ?? 28)));
$query = trim((string) ($_GET['q'] ?? ''));
$lang = strtolower(trim((string) ($_GET['lang'] ?? current_lang_key())));

if (!$pdo instanceof PDO) {
    music_api_json(['ok' => false, 'error' => $db_error ?? 'Database unavailable'], 503);
}

try {
    if ($action === 'register') {
        header('Cache-Control: no-store, max-age=0');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            music_api_json(['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        if ($name === '' || $email === '' || $password === '') {
            music_api_json(['ok' => false, 'error' => 'Please enter your name, email, and password.'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            music_api_json(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
        }
        if (strlen($password) < 6) {
            music_api_json(['ok' => false, 'error' => 'Password must be at least 6 characters.'], 400);
        }
        if ($password !== $passwordConfirm) {
            music_api_json(['ok' => false, 'error' => 'Password confirmation does not match.'], 400);
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            music_api_json(['ok' => false, 'error' => 'This email is already registered.'], 409);
        }

        $stmt = $pdo->prepare('
            INSERT INTO users (created_at, email, lang, name, password, role, status_share, type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            date('Y-m-d H:i:s'),
            $email,
            $lang !== '' ? $lang : current_lang_key(),
            $name,
            $password,
            'user',
            'private',
            'normal',
        ]);

        session_regenerate_id(true);
        $_SESSION['home_user_id'] = (int) $pdo->lastInsertId();
        $_SESSION['home_user_name'] = $name;
        $_SESSION['home_user_email'] = $email;
        $_SESSION['home_user_role'] = 'user';
        $_SESSION['home_user_avatar'] = '';
        unset($_SESSION['home_login_redirect']);

        music_api_json([
            'ok' => true,
            'user' => [
                'id' => (int) $_SESSION['home_user_id'],
                'name' => $name,
                'email' => $email,
            ],
        ]);
    }

    if ($action === 'languages') {
        $languages = music_language_options($pdo);
        music_api_json(['ok' => true, 'languages' => array_map('music_api_language', $languages)]);
    }

    if ($action === 'genres') {
        $stmt = $pdo->prepare('
            SELECT g.genre_id, g.title, g.avatar, COUNT(DISTINCT s.id) AS song_count
            FROM song_genre g
            LEFT JOIN song s ON FIND_IN_SET(REPLACE(g.genre_id, " ", ""), REPLACE(COALESCE(s.genre, ""), " ", "")) > 0
            GROUP BY g.genre_id, g.title, g.avatar
            ORDER BY g.title ASC, g.genre_id ASC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        foreach ($stmt->fetchAll() as $genre) {
            $items[] = music_api_menu_item(
                (string) ($genre['genre_id'] ?? ''),
                (string) (($genre['title'] ?? '') ?: ($genre['genre_id'] ?? '')),
                number_format((int) ($genre['song_count'] ?? 0)) . ' songs',
                (string) ($genre['avatar'] ?? ''),
                'genre'
            );
        }
        music_api_json(['ok' => true, 'items' => $items]);
    }

    if ($action === 'artists') {
        $where = '';
        $params = [];
        if ($lang !== '') {
            $where = 'WHERE LOWER(sa.lang_key) = LOWER(?)';
            $params[] = $lang;
        }
        $stmt = $pdo->prepare('
            SELECT sa.*, COUNT(DISTINCT sam.song_id) AS song_count
            FROM song_artist sa
            LEFT JOIN song_artist_map sam ON sam.artist_id = sa.id
            ' . $where . '
            GROUP BY sa.id
            ORDER BY song_count DESC, sa.name ASC, sa.id ASC
            LIMIT ?
        ');
        $bindIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($bindIndex++, $param);
        }
        $stmt->bindValue($bindIndex, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        foreach ($stmt->fetchAll() as $artist) {
            $items[] = music_api_menu_item(
                (string) ($artist['id'] ?? ''),
                (string) ($artist['name'] ?? ''),
                number_format((int) ($artist['song_count'] ?? 0)) . ' songs',
                (string) ($artist['avatar'] ?? ''),
                'artist'
            );
        }
        music_api_json(['ok' => true, 'items' => $items]);
    }

    if ($action === 'memories') {
        $stmt = $pdo->prepare('
            SELECT CAST(TRIM(year) AS UNSIGNED) AS song_year, COUNT(*) AS song_count
            FROM song
            WHERE TRIM(COALESCE(year, "")) REGEXP "^[0-9]{4}$"
            GROUP BY song_year
            ORDER BY song_year DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        foreach ($stmt->fetchAll() as $year) {
            $yearValue = (string) ($year['song_year'] ?? '');
            $items[] = music_api_menu_item(
                $yearValue,
                'Music from ' . $yearValue,
                number_format((int) ($year['song_count'] ?? 0)) . ' songs',
                '',
                'memory'
            );
        }
        music_api_json(['ok' => true, 'items' => $items]);
    }

    if ($action === 'tourism') {
        $stmt = $pdo->prepare('
            SELECT
                MIN(c.id) AS id,
                MIN(c.icon) AS icon,
                MIN(c.name) AS name,
                c.lang_key,
                UPPER(c.lang_country) AS country_code,
                COUNT(DISTINCT s.id) AS song_count,
                COUNT(DISTINCT sa.id) AS artist_count
            FROM country c
            LEFT JOIN song s ON LOWER(s.lang) = LOWER(c.lang_key)
            LEFT JOIN song_artist sa ON LOWER(sa.lang_key) = LOWER(c.lang_key)
            WHERE COALESCE(c.lang_country, "") <> ""
            GROUP BY c.lang_key, country_code
            HAVING song_count > 0 OR artist_count > 0
            ORDER BY song_count DESC, artist_count DESC, name ASC
            LIMIT ?
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        foreach ($stmt->fetchAll() as $country) {
            $items[] = music_api_menu_item(
                (string) ($country['lang_key'] ?? ''),
                (string) (($country['name'] ?? '') ?: ($country['country_code'] ?? '')),
                number_format((int) ($country['song_count'] ?? 0)) . ' songs · ' . number_format((int) ($country['artist_count'] ?? 0)) . ' artists',
                (string) ($country['icon'] ?? ''),
                'tourism'
            );
        }
        music_api_json(['ok' => true, 'items' => $items]);
    }

    if ($action === 'song') {
        $id = trim((string) ($_GET['id'] ?? ''));
        if ($id === '') {
            music_api_json(['ok' => false, 'error' => 'Missing song id'], 400);
        }
        $song = music_fetch_song($pdo, $id);
        if (!$song) {
            music_api_json(['ok' => false, 'error' => 'Song not found'], 404);
        }
        music_api_json(['ok' => true, 'song' => music_api_song($song)]);
    }

    if ($action === 'artist') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            music_api_json(['ok' => false, 'error' => 'Missing artist id'], 400);
        }
        $artist = music_fetch_artist($pdo, $id);
        if (!$artist) {
            music_api_json(['ok' => false, 'error' => 'Artist not found'], 404);
        }
        $stmt = $pdo->prepare('
            SELECT s.*, GROUP_CONCAT(DISTINCT sa.name ORDER BY sa.name SEPARATOR ", ") AS artist_names,
                   GROUP_CONCAT(DISTINCT sa.id ORDER BY sa.name SEPARATOR ",") AS artist_ids
            FROM song s
            INNER JOIN song_artist_map target_map ON target_map.song_id = s.id AND target_map.artist_id = ?
            LEFT JOIN song_artist_map sam ON sam.song_id = s.id
            LEFT JOIN song_artist sa ON sa.id = sam.artist_id
            GROUP BY s.id
            ORDER BY s.`date` DESC, s.id DESC
            LIMIT ?
        ');
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $songs = $stmt->fetchAll();
        music_api_json([
            'ok' => true,
            'artist' => music_api_artist($artist, count($songs)),
            'songs' => array_map('music_api_song', $songs),
        ]);
    }

    if ($action === 'genre_songs') {
        $id = trim((string) ($_GET['id'] ?? ''));
        if ($id === '') {
            music_api_json(['ok' => false, 'error' => 'Missing genre id'], 400);
        }
        $where = 'FIND_IN_SET(REPLACE(?, " ", ""), REPLACE(COALESCE(s.genre, ""), " ", "")) > 0';
        $params = [$id];
        if ($lang !== '') {
            $where .= ' AND TRIM(COALESCE(s.lang, "")) = ?';
            $params[] = $lang;
        }
        $songs = music_fetch_songs($pdo, $limit, $where, $params);
        music_api_json(['ok' => true, 'songs' => array_map('music_api_song', $songs)]);
    }

    if ($action === 'year_songs') {
        $id = trim((string) ($_GET['id'] ?? ''));
        if ($id === '') {
            music_api_json(['ok' => false, 'error' => 'Missing year'], 400);
        }
        $where = 'TRIM(COALESCE(s.year, "")) = ?';
        $params = [$id];
        if ($lang !== '') {
            $where .= ' AND TRIM(COALESCE(s.lang, "")) = ?';
            $params[] = $lang;
        }
        $songs = music_fetch_songs($pdo, $limit, $where, $params);
        music_api_json(['ok' => true, 'songs' => array_map('music_api_song', $songs)]);
    }

    if ($action === 'country_songs') {
        $id = strtolower(trim((string) ($_GET['id'] ?? '')));
        if ($id === '') {
            music_api_json(['ok' => false, 'error' => 'Missing country'], 400);
        }
        $songs = music_fetch_songs($pdo, $limit, 'LOWER(TRIM(COALESCE(s.lang, ""))) = ?', [$id]);
        music_api_json(['ok' => true, 'songs' => array_map('music_api_song', $songs)]);
    }

    if ($action === 'search' || $query !== '') {
        $value = '%' . $query . '%';
        $where = '
            (s.id LIKE ? OR s.name LIKE ? OR s.artist LIKE ? OR s.album LIKE ? OR s.genre LIKE ?
             OR EXISTS (
                SELECT 1
                FROM song_artist_map search_map
                INNER JOIN song_artist search_artist ON search_artist.id = search_map.artist_id
                WHERE search_map.song_id = s.id AND search_artist.name LIKE ?
             ))
        ';
        $params = [$value, $value, $value, $value, $value, $value];
        if ($lang !== '') {
            $where .= ' AND TRIM(COALESCE(s.lang, "")) = ?';
            $params[] = $lang;
        }
        $songs = music_fetch_songs($pdo, $limit, $where, $params);
        music_api_json(['ok' => true, 'songs' => array_map('music_api_song', $songs)]);
    }

    if ($action === 'rank') {
        $month = trim((string) ($_GET['month'] ?? date('Y-m')));
        $songs = music_fetch_monthly_rank_songs($pdo, $month, $limit, 0, $lang);
        music_api_json(['ok' => true, 'songs' => array_map('music_api_song', $songs)]);
    }

    $worldSongs = music_fetch_songs($pdo, $limit);
    $songs = $worldSongs;
    if ($lang !== '') {
        $songs = music_fetch_songs($pdo, $limit, 'TRIM(COALESCE(s.lang, "")) = ?', [$lang]);
    }
    $rankSongs = music_fetch_monthly_rank_songs($pdo, date('Y-m'), min(14, $limit), 0, $lang);

    music_api_json([
        'ok' => true,
        'brand' => 'Heartbeat Music',
        'source' => music_site_origin(),
        'songs' => array_map('music_api_song', $songs),
        'world_songs' => array_map('music_api_song', $worldSongs),
        'rank_songs' => array_map('music_api_song', $rankSongs),
    ]);
} catch (Throwable $e) {
    music_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
