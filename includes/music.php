<?php
if (!defined('CARROT_SITE_KEY')) {
    define('CARROT_SITE_KEY', 'CarrotMusic');
}
if (!defined('CARROT_SITE_ALIASES')) {
    define('CARROT_SITE_ALIASES', ['CarrotMusic', 'Music', 'Heart Beat Play', 'HeartbeatPlay', 'heartbeatplay.com', 'music.carrot28.com']);
}
require_once __DIR__ . '/../../CarrotHome/config/database.php';
require_once __DIR__ . '/../../CarrotHome/includes/functions.php';
require_once __DIR__ . '/visit_tracker.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function music_apply_language_from_request(?PDO $pdo): void
{
    $requestedLang = strtolower(trim((string) ($_GET['lang'] ?? '')));
    if ($requestedLang === '' || !preg_match('/^[a-z]{2,8}(?:[-_][a-z0-9]{2,8})?$/i', $requestedLang)) {
        return;
    }

    if ($pdo instanceof PDO) {
        try {
            $stmt = $pdo->prepare('SELECT id, lang_key, lang_country FROM country WHERE lang_key = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$requestedLang]);
            $country = $stmt->fetch();
            if ($country) {
                $_SESSION['key_lang'] = (string) $country['lang_key'];
                $_SESSION['country_id'] = (int) $country['id'];
                $_SESSION['lang_country'] = (string) $country['lang_country'];
                return;
            }
        } catch (Throwable $e) {
            // Fall back to the requested key when the country lookup is unavailable.
        }
    }

    $_SESSION['key_lang'] = $requestedLang;
}

music_apply_language_from_request($pdo ?? null);
initialize_language_from_ip($pdo ?? null);
music_visit_track_daily_ip($pdo ?? null);

function music_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function music_cache_dir(): string
{
    return dirname(__DIR__) . '/storage/cache';
}

function music_cache_key(string $prefix, array $parts): string
{
    $readable = [];
    foreach ($parts as $key => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            $value = '0';
        }
        $readable[] = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $key) . '-' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($value));
    }

    return preg_replace('/[^a-z0-9_-]+/i', '-', $prefix) . '_' . implode('_', $readable) . '_' . substr(sha1(json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 12);
}

function music_cache_path(string $key): string
{
    $key = preg_replace('/[^a-z0-9_.-]+/i', '-', $key);
    return music_cache_dir() . '/' . $key . '.json';
}

function music_cache_get(string $key, int $ttlSeconds): ?array
{
    if ($ttlSeconds <= 0) {
        return null;
    }

    $path = music_cache_path($key);
    if (!is_file($path) || (time() - (int) filemtime($path)) > $ttlSeconds) {
        return null;
    }

    $payload = json_decode((string) @file_get_contents($path), true);
    return is_array($payload) ? $payload : null;
}

function music_cache_set(string $key, array $payload): void
{
    $dir = music_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }

    $path = music_cache_path($key);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $path);
    }
}

function music_cache_clear(string $prefix = ''): int
{
    $dir = music_cache_dir();
    if (!is_dir($dir)) {
        return 0;
    }

    $prefix = preg_replace('/[^a-z0-9_.-]+/i', '-', $prefix);
    $pattern = $dir . '/' . ($prefix !== '' ? $prefix . '*' : '*') . '.json';
    $deleted = 0;
    foreach (glob($pattern) ?: [] as $file) {
        if (is_file($file) && @unlink($file)) {
            $deleted++;
        }
    }

    return $deleted;
}

function music_play_icon(): string
{
    return '<svg class="btn-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor"><path d="M8 5.14v13.72c0 .76.84 1.22 1.48.81l10.78-6.86a.96.96 0 0 0 0-1.62L9.48 4.33A.96.96 0 0 0 8 5.14Z"/></svg>';
}

function music_tourism_icon(): string
{
    return '<svg class="tourism-icon" width="15" height="15" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21.2 3.45c.5.5.46 1.34-.09 1.88l-4.94 4.94 2.23 7.88-1.55 1.55-3.74-6.32-3.22 3.22.3 2.33-1.27 1.27-1.12-3.32-3.32-1.12 1.27-1.27 2.33.3 3.22-3.22-6.32-3.74L6.53 6.3l7.88 2.23 4.94-4.94c.54-.55 1.38-.59 1.85-.14Z" fill="currentColor"/></svg>';
}

function music_detail_action_buttons(string $title = '', string $url = ''): string
{
    $title = trim($title);
    $url = trim($url);
    return '<button class="btn js-music-share" type="button" data-share-title="' . music_h($title) . '" data-share-url="' . music_h($url) . '"><i class="fas fa-share-alt"></i>' . music_h(music_label('share', 'Chia sẻ')) . '</button>'
        . '<button class="btn js-music-qr" type="button" data-share-title="' . music_h($title) . '" data-share-url="' . music_h($url) . '"><i class="fas fa-qrcode"></i>' . music_h(music_label('music.action.qr_code', 'QR code')) . '</button>';
}

function music_label(string $key, string $default, ?string $langKey = null): string
{
    return ui_label($key, $default, $langKey);
}

function music_brand_name(): string
{
    return 'Heart Beat Play';
}

function music_primary_host(): string
{
    return 'heartbeatplay.com';
}

function music_site_origin(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $host = $host !== '' ? preg_replace('/:\d+$/', '', $host) : music_primary_host();
    if ($host === 'music.carrot28.com') {
        $host = music_primary_host();
    }

    $scheme = 'https';
    if (
        $host !== music_primary_host()
        && empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && empty($_SERVER['HTTPS'])
        && in_array($host, ['localhost', '127.0.0.1'], true)
    ) {
        $scheme = 'http';
    }

    return $scheme . '://' . $host;
}

function music_base_path(): string
{
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    return $script === '/' ? '' : rtrim($script, '/');
}

function music_url(string $path = ''): string
{
    return music_base_path() . '/' . ltrim($path, '/');
}

function music_absolute_url(string $path = ''): string
{
    return rtrim(music_site_origin(), '/') . music_url($path);
}

function music_slug(string $value): string
{
    $value = trim(rawurldecode((string) $value));
    if (function_exists('seo_slug_text')) {
        $value = seo_slug_text($value);
    }
    $value = str_replace(['_', '+'], '-', $value);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && trim($ascii) !== '') {
            $value = $ascii;
        }
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');
    return $value !== '' ? $value : 'music';
}

function music_url_with_query(string $url, array $query): string
{
    $query = array_filter($query, static fn($value): bool => trim((string) $value) !== '');
    if (!$query) {
        return $url;
    }
    return $url . (strpos($url, '?') !== false ? '&' : '?') . http_build_query($query);
}

function music_redirect_to_canonical(string $canonicalUrl, array $removeQueryKeys = []): void
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '';
    parse_str((string) (parse_url($requestUri, PHP_URL_QUERY) ?: ''), $visibleQuery);
    $expectedPath = parse_url($canonicalUrl, PHP_URL_PATH) ?: '';
    $redirectNeeded = $requestPath !== $expectedPath;
    foreach ($removeQueryKeys as $key) {
        if (isset($visibleQuery[$key])) {
            $redirectNeeded = true;
            break;
        }
    }

    if (!$redirectNeeded) {
        return;
    }

    $extraQuery = $visibleQuery;
    foreach ($removeQueryKeys as $key) {
        unset($extraQuery[$key]);
    }
    if ($extraQuery) {
        $canonicalUrl = music_url_with_query($canonicalUrl, $extraQuery);
    }
    header('Location: ' . $canonicalUrl, true, 301);
    exit;
}

function music_song_url(string $id): string
{
    return music_url(music_slug($id));
}

function music_home_url(string $fragment = ''): string
{
    $url = music_url('');
    $fragment = ltrim(trim($fragment), '#');
    return $fragment !== '' ? $url . '#' . rawurlencode($fragment) : $url;
}

function music_artist_url(int $id, string $name = ''): string
{
    $slug = trim($name) !== '' ? music_slug($name) : (string) $id;
    return music_url('artist/' . rawurlencode($slug));
}

function music_genre_url(string $id, string $title = ''): string
{
    $slug = trim($title) !== '' ? music_slug($title) : music_slug($id);
    return music_url('genre/' . rawurlencode($slug));
}

function music_song_year_url(int $year): string
{
    return music_url('year/' . rawurlencode((string) $year));
}

function music_artists_url(): string
{
    return music_url('artists');
}

function music_genres_url(): string
{
    return music_url('genres');
}

function music_countries_url(): string
{
    return music_url('countries');
}

function music_country_url(string $countryCode): string
{
    return music_url('country/' . rawurlencode(strtoupper(trim($countryCode))));
}

function music_audio_proxy_url(string $url): string
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    if ($host === 'nas.carrot28.com') {
        return music_url('audio-proxy.php?url=' . rawurlencode($url));
    }
    return $url;
}

function music_app_banners(): string
{
    return '<a class="music-app-banner" href="https://carrot28.com/Music-for-life" target="_blank" rel="noopener noreferrer">'
        . '<img src="' . music_h(music_url('images/bn_app.png')) . '" alt="">'
        . '<span>'
        . '<small>' . music_h(music_label('music.app_banner.eyebrow', 'Nghe nhạc mọi thiết bị')) . '</small>'
        . '<strong>Heartbeat Music</strong>'
        . '<em>' . music_h(music_label('music.app_banner.cta', 'Khám phá ứng dụng')) . '</em>'
        . '</span>'
        . '</a>'
        . '<a class="music-app-banner music-app-banner--android" href="https://play.google.com/store/apps/details?id=com.carrotstore.heartbeatmusic" target="_blank" rel="noopener noreferrer">'
        . '<img src="' . music_h(music_url('favicon/android-chrome-192x192.png')) . '" alt="">'
        . '<span>'
        . '<small>' . music_h(music_label('music.android_banner.eyebrow', 'Tải ứng dụng Android')) . '</small>'
        . '<strong>Heart Beat Play</strong>'
        . '<em>' . music_h(music_label('music.android_banner.cta', 'Mở trên Google Play')) . '</em>'
        . '</span>'
        . '</a>';
}

function music_youtube_video_id(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    $path = trim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');
    parse_str((string) (parse_url($url, PHP_URL_QUERY) ?: ''), $query);

    if (!empty($query['v']) && preg_match('/^[a-zA-Z0-9_-]{6,20}$/', (string) $query['v'])) {
        return (string) $query['v'];
    }
    if (strpos($host, 'youtu.be') !== false && preg_match('/^[a-zA-Z0-9_-]{6,20}$/', $path)) {
        return $path;
    }
    if (strpos($host, 'youtube.com') !== false && preg_match('~(?:embed|shorts)/([a-zA-Z0-9_-]{6,20})~', $path, $match)) {
        return $match[1];
    }

    return '';
}

function music_split_genres(?string $genres): array
{
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/\s*,\s*/', (string) $genres) ?: []))));
}

function music_page_url(string $slug, string $lang = ''): string
{
    $url = music_url('page/' . rawurlencode(music_slug(trim($slug))));
    if (trim($lang) !== '') {
        $url = music_url_with_query($url, ['lang' => trim($lang)]);
    }
    return $url;
}

function music_fetch_page(PDO $pdo, string $slug, string $lang = ''): ?array
{
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }

    $lang = trim($lang) !== '' ? trim($lang) : current_lang_key();
    $slugCandidates = function_exists('slug_lookup_candidates') ? slug_lookup_candidates($slug) : [$slug];
    $slugCandidates = array_values(array_filter(array_map('trim', $slugCandidates)));
    if (!$slugCandidates) {
        return null;
    }

    try {
        $placeholders = [];
        $orderCases = [];
        $params = [
            ':lang_filter' => $lang,
            ':lang_order' => $lang,
        ];
        foreach ($slugCandidates as $index => $candidate) {
            $key = ':slug' . $index;
            $orderKey = ':slug_order' . $index;
            $placeholders[] = $key;
            $orderCases[] = 'WHEN ' . $orderKey . ' THEN ' . $index;
            $params[$key] = $candidate;
            $params[$orderKey] = $candidate;
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM page
            WHERE slug IN (" . implode(',', $placeholders) . ")
              AND (lang = :lang_filter OR lang = '' OR lang IS NULL)
            ORDER BY CASE slug " . implode(' ', $orderCases) . " ELSE 999 END, CASE WHEN lang = :lang_order THEN 0 ELSE 1 END
            LIMIT 1
        ");
        $stmt->execute($params);
        $page = $stmt->fetch();
        return $page ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function music_page_exists(PDO $pdo, string $slug, string $lang = ''): bool
{
    return music_fetch_page($pdo, $slug, $lang) !== null;
}

function music_language_options(?PDO $pdo): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }

    try {
        return $pdo->query('
            SELECT MIN(id) AS id, MIN(icon) AS icon, MIN(name) AS name, lang_key, GROUP_CONCAT(DISTINCT lang_country ORDER BY lang_country SEPARATOR ", ") AS lang_country
            FROM country
            GROUP BY lang_key
            ORDER BY name ASC
        ')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function music_cover(?string $url): string
{
    $url = trim((string) $url);
    return $url !== '' ? $url : music_url('cr_player/song.png');
}

function music_excerpt(?string $text, int $limit = 150): string
{
    $text = trim(strip_tags((string) $text));
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '...');
    }
    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function music_footer_sites(?PDO $pdo): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }

    try {
        static $hasStatus = null;
        if ($hasStatus === null) {
            $columns = $pdo->query('SHOW COLUMNS FROM sites')->fetchAll(PDO::FETCH_COLUMN);
            $hasStatus = in_array('status', $columns, true);
        }
        $statusWhere = $hasStatus ? " AND COALESCE(status, 'active') = 'active'" : '';
        $stmt = $pdo->query("SELECT name, url, logo, description FROM sites WHERE COALESCE(url, '') <> ''{$statusWhere} ORDER BY sort_order ASC, name ASC LIMIT 10");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function music_render_pagination(int $currentPage, int $totalPages, callable $pageUrl): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $items = [];
    $items[] = '<a class="pagination-link' . ($currentPage <= 1 ? ' is-disabled' : '') . '" href="' . music_h((string) $pageUrl(max(1, $currentPage - 1))) . '" aria-label="' . music_h(music_label('aria.previous_page', 'Previous page')) . '">&lsaquo;</a>';

    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    if ($start > 1) {
        $items[] = '<a class="pagination-link" href="' . music_h((string) $pageUrl(1)) . '">1</a>';
        if ($start > 2) {
            $items[] = '<span class="pagination-ellipsis">...</span>';
        }
    }
    for ($page = $start; $page <= $end; $page++) {
        $items[] = '<a class="pagination-link' . ($page === $currentPage ? ' is-active' : '') . '" href="' . music_h((string) $pageUrl($page)) . '">' . number_format($page) . '</a>';
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $items[] = '<span class="pagination-ellipsis">...</span>';
        }
        $items[] = '<a class="pagination-link" href="' . music_h((string) $pageUrl($totalPages)) . '">' . number_format($totalPages) . '</a>';
    }

    $items[] = '<a class="pagination-link' . ($currentPage >= $totalPages ? ' is-disabled' : '') . '" href="' . music_h((string) $pageUrl(min($totalPages, $currentPage + 1))) . '" aria-label="' . music_h(music_label('aria.next_page', 'Next page')) . '">&rsaquo;</a>';

    return '<nav class="music-pagination" aria-label="' . music_h(music_label('aria.pagination', 'Pagination')) . '">' . implode('', $items) . '</nav>';
}

function music_paypal_config(?PDO $pdo, string $site = 'music'): array
{
    $defaults = [
        'enabled' => false,
        'sandbox' => true,
        'client_id' => '',
        'client_secret' => '',
        'currency' => 'USD',
        'amount' => '0.00',
    ];

    if (!$pdo instanceof PDO) {
        return $defaults;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM paypal_config WHERE site = ? LIMIT 1');
        $stmt->execute([$site]);
        $row = $stmt->fetch();
        if (!$row) {
            return $defaults;
        }
        $mode = ($row['active_mode'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
        $prefix = $mode === 'live' ? 'live' : 'sandbox';
        return [
            'enabled' => !empty($row['enabled']),
            'sandbox' => $mode !== 'live',
            'client_id' => (string) ($row[$prefix . '_client_id'] ?? ''),
            'client_secret' => (string) ($row[$prefix . '_client_secret'] ?? ''),
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'amount' => (string) ($row['amount'] ?? '0.00'),
        ];
    } catch (Throwable $e) {
        return $defaults;
    }
}

function music_song_price(array $song, array $paypalConfig): float
{
    return max(0, (float) ($paypalConfig['amount'] ?? 0));
}

function music_fetch_songs(PDO $pdo, int $limit = 24, string $where = '', array $params = []): array
{
    $sql = '
        SELECT s.*, GROUP_CONCAT(DISTINCT sa.name ORDER BY sa.name SEPARATOR ", ") AS artist_names
        FROM song s
        LEFT JOIN song_artist_map sam ON sam.song_id = s.id
        LEFT JOIN song_artist sa ON sa.id = sam.artist_id
        WHERE 1 = 1
    ';
    if ($where !== '') {
        $sql .= ' AND ' . $where;
    }
    $sql .= ' GROUP BY s.id ORDER BY s.created_at DESC, s.id ASC LIMIT ' . max(1, $limit);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function music_fetch_popular_songs(PDO $pdo, int $limit = 14, string $where = '', array $params = []): array
{
    $sql = '
        SELECT s.*, popular.view_count,
               GROUP_CONCAT(DISTINCT sa.name ORDER BY sa.name SEPARATOR ", ") AS artist_names
        FROM (
            SELECT song_id, COUNT(*) AS view_count, MAX(last_seen_at) AS last_viewed_at
            FROM song_view
            GROUP BY song_id
        ) popular
        INNER JOIN song s ON s.id = popular.song_id
        LEFT JOIN song_artist_map sam ON sam.song_id = s.id
        LEFT JOIN song_artist sa ON sa.id = sam.artist_id
        WHERE 1 = 1
    ';
    if ($where !== '') {
        $sql .= ' AND ' . $where;
    }
    $sql .= '
        GROUP BY s.id
        ORDER BY popular.view_count DESC, MAX(popular.last_viewed_at) DESC, s.id ASC
        LIMIT ' . max(1, $limit);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function music_fetch_song(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('
        SELECT s.*, GROUP_CONCAT(DISTINCT sa.name ORDER BY sa.name SEPARATOR ", ") AS artist_names,
               GROUP_CONCAT(DISTINCT sa.id ORDER BY sa.name SEPARATOR ",") AS artist_ids
        FROM song s
        LEFT JOIN song_artist_map sam ON sam.song_id = s.id
        LEFT JOIN song_artist sa ON sa.id = sam.artist_id
        WHERE s.id = ?
        GROUP BY s.id
        LIMIT 1
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function music_fetch_song_artists(PDO $pdo, string $songId): array
{
    $stmt = $pdo->prepare('
        SELECT sa.id, sa.name
        FROM song_artist_map sam
        INNER JOIN song_artist sa ON sa.id = sam.artist_id
        WHERE sam.song_id = ?
        ORDER BY sa.name ASC, sa.id ASC
    ');
    $stmt->execute([$songId]);
    return $stmt->fetchAll();
}

function music_fetch_artist(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM song_artist WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function music_fetch_artist_by_slug(PDO $pdo, string $slug): ?array
{
    $slug = music_slug($slug);
    if ($slug === '') {
        return null;
    }

    $stmt = $pdo->query('SELECT * FROM song_artist ORDER BY name ASC, id ASC');
    foreach ($stmt ? $stmt->fetchAll() : [] as $artist) {
        if (music_slug((string) ($artist['name'] ?? '')) === $slug || (string) ($artist['id'] ?? '') === $slug) {
            return $artist;
        }
    }

    return null;
}

function music_fetch_genre(PDO $pdo, string $id): ?array
{
    $id = trim($id);
    if ($id === '') {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT g.*, COUNT(DISTINCT s.id) AS song_count
        FROM song_genre g
        LEFT JOIN song s ON FIND_IN_SET(REPLACE(g.genre_id, " ", ""), REPLACE(COALESCE(s.genre, ""), " ", "")) > 0
        WHERE g.genre_id = ?
        GROUP BY g.genre_id
        LIMIT 1
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    $stmt = $pdo->prepare('
        SELECT ? AS genre_id, ? AS title, "" AS avatar, "" AS description, COUNT(DISTINCT id) AS song_count
        FROM song
        WHERE FIND_IN_SET(REPLACE(?, " ", ""), REPLACE(COALESCE(genre, ""), " ", "")) > 0
    ');
    $stmt->execute([$id, $id, $id]);
    $row = $stmt->fetch();
    return $row && (int) ($row['song_count'] ?? 0) > 0 ? $row : null;
}

function music_fetch_genre_by_slug(PDO $pdo, string $slug): ?array
{
    $slug = music_slug($slug);
    if ($slug === '') {
        return null;
    }

    try {
        $stmt = $pdo->query('SELECT genre_id, title FROM song_genre ORDER BY title ASC, genre_id ASC');
        foreach ($stmt ? $stmt->fetchAll() : [] as $genre) {
            $genreId = (string) ($genre['genre_id'] ?? '');
            $genreTitle = (string) ($genre['title'] ?? '');
            if (music_slug($genreTitle !== '' ? $genreTitle : $genreId) === $slug || music_slug($genreId) === $slug) {
                return music_fetch_genre($pdo, $genreId);
            }
        }
    } catch (Throwable $e) {
    }

    return music_fetch_genre($pdo, $slug);
}

function music_has_paid_song(string $songId): bool
{
    return !empty($_SESSION['paid_music_songs'][$songId]);
}

function music_render_header(string $title, string $description = '', string $image = ''): void
{
    $description = $description !== '' ? $description : music_label('music.meta.description', music_brand_name() . ' là nơi bạn tìm, nghe và lưu lại những bài hát hợp tâm trạng mỗi ngày.');
    $image = $image !== '' ? $image : music_url('favicon/android-chrome-512x512.png');
    $searchQuery = trim((string) ($_GET['q'] ?? ''));
    $lang = current_lang_key();
    $languageOptions = music_language_options($GLOBALS['pdo'] ?? null);
    $musicUser = null;
    if (!empty($_SESSION['home_user_id']) && ($GLOBALS['pdo'] ?? null) instanceof PDO) {
        try {
            $userStmt = $GLOBALS['pdo']->prepare('SELECT id, name, email, avatar FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([(int) $_SESSION['home_user_id']]);
            $musicUser = $userStmt->fetch() ?: null;
        } catch (Throwable $e) {
            $musicUser = null;
        }
    }
    $styleVersion = is_file(__DIR__ . '/../style.css') ? (string) filemtime(__DIR__ . '/../style.css') : '1';
    ?>
<!doctype html>
<html lang="<?= music_h($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= music_h($title) ?></title>
    <meta name="description" content="<?= music_h($description) ?>">
    <?= carrot_google_search_verification_meta($GLOBALS['pdo'] ?? null, 'CarrotMusic') ?>
    <meta property="og:title" content="<?= music_h($title) ?>">
    <meta property="og:description" content="<?= music_h($description) ?>">
    <meta property="og:image" content="<?= music_h($image) ?>">
    <meta property="og:type" content="music.song">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= music_h(music_url('favicon/apple-touch-icon.png')) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= music_h(music_url('favicon/favicon-32x32.png')) ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= music_h(music_url('favicon/favicon-16x16.png')) ?>">
    <link rel="manifest" href="<?= music_h(music_url('favicon/site.webmanifest')) ?>">
    <link rel="shortcut icon" href="<?= music_h(music_url('favicon/favicon.ico')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/tippy.js@6/dist/tippy.css">
    <link rel="stylesheet" href="<?= music_h(music_url('style.css?v=' . $styleVersion)) ?>">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/@popperjs/core@2"></script>
    <script src="https://unpkg.com/tippy.js@6"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
<header class="site-header">
    <a class="brand site-link" href="<?= music_h(music_home_url()) ?>">
        <span class="brand-mark">♪</span>
        <span><strong><?= music_h(music_brand_name()) ?></strong><small><?= music_h(music_label('music.brand_tagline', 'Listen to music every day')) ?></small></span>
    </a>
    <form class="header-search" method="get" action="<?= music_h(music_home_url()) ?>">
        <input name="q" type="search" value="<?= music_h($searchQuery) ?>" placeholder="<?= music_h(music_label('music.search_placeholder', 'Tìm bài hát hoặc nghệ sĩ')) ?>">
        <button type="submit" aria-label="<?= music_h(music_label('action.search', 'Search')) ?>"><i class="fas fa-search"></i></button>
    </form>
    <nav>
        <span class="header-links">
            <a class="site-link" href="<?= music_h(music_home_url()) ?>"><?= music_h(music_label('nav.explore', 'Explore')) ?></a>
            <a class="site-link" href="<?= music_h(music_home_url('genres')) ?>"><?= music_h(music_label('music.label.genres', 'Genres')) ?></a>
            <a class="site-link" href="<?= music_h(music_home_url('artists')) ?>"><?= music_h(music_label('music.label.artists', 'Artists')) ?></a>
            <a class="site-link" href="<?= music_h(music_countries_url()) ?>"><?= music_h(music_label('music.label.tourism', 'Du lịch')) ?></a>
        </span>
        <span class="header-tools">
            <?php if ($languageOptions): ?>
                <span class="header-language">
                    <select class="music-language-select" aria-label="<?= music_h(music_label('aria.choose_language', 'Choose language')) ?>">
                        <?php foreach ($languageOptions as $language): ?>
                            <?php $languageKey = (string) ($language['lang_key'] ?? ''); ?>
                            <?php if ($languageKey === '') continue; ?>
                            <option value="<?= music_h($languageKey) ?>" data-icon="<?= music_h($language['icon'] ?? '') ?>" <?= $languageKey === $lang ? 'selected' : '' ?>>
                                <?= music_h(($language['name'] ?? $languageKey) . ' · ' . strtoupper($languageKey)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </span>
            <?php endif; ?>
            <span class="header-auth">
                <?php if ($musicUser): ?>
                    <?php
                    $musicUserName = trim((string) ($musicUser['name'] ?? '')) ?: (string) ($musicUser['email'] ?? '');
                    $musicUserAvatar = trim((string) ($musicUser['avatar'] ?? ''));
                    $musicUserInitial = strtoupper(substr($musicUserName, 0, 1) ?: 'U');
                    ?>
                    <a class="music-profile-button" href="<?= music_h(music_url('profile.php')) ?>" aria-label="<?= music_h(music_label('nav.profile', 'Profile')) ?>">
                        <?php if ($musicUserAvatar !== ''): ?>
                            <img src="<?= music_h($musicUserAvatar) ?>" alt="">
                        <?php else: ?>
                            <span><?= music_h($musicUserInitial) ?></span>
                        <?php endif; ?>
                    </a>
                <?php else: ?>
                    <button class="music-login-button js-music-login" type="button" aria-label="<?= music_h(music_label('nav.login', 'Login')) ?>">
                        <i class="fas fa-user-circle" aria-hidden="true"></i>
                        <?= music_h(music_label('nav.login', 'Login')) ?>
                    </button>
                <?php endif; ?>
            </span>
        </span>
    </nav>
</header>
<main id="app-content">
    <?php
}

function music_render_footer(): void
{
    $footerSites = music_footer_sites($GLOBALS['pdo'] ?? null);
    $footerColumns = [
        music_label('footer.company', 'Organization') => [
            'about' => ['footer.about', 'About'],
            'services' => ['footer.services', 'Services'],
            'contact' => ['footer.contact', 'Contact'],
        ],
        music_label('footer.legal', 'Legal') => [
            'privacy-policy' => ['footer.privacy_policy', 'Privacy Policy'],
            'terms-of-service' => ['footer.terms_of_service', 'Terms of Service'],
            'cookie-policy' => ['footer.cookie_policy', 'Cookie Policy'],
            'disclaimer' => ['footer.disclaimer', 'Disclaimer'],
        ],
    ];
    ?>
</main>
<footer class="site-footer">
    <div class="footer-brand">
        <a href="https://home.carrot28.com" target="_blank">
        <img src="<?= music_h(music_url('carrot_28.png')) ?>" alt="Carrot28">
        </a>
        <div>
            <strong>Carrot28</strong>
            <p><?= music_h(music_label('music.footer.description', 'Hệ sinh thái sản phẩm số Carrot28 kết nối app, game, nội dung và âm nhạc trong một nền tảng sáng tạo.')) ?></p>
        </div>
    </div>
    <nav class="footer-menu<?= !empty($footerSites) ? ' has-sites' : '' ?>" aria-label="<?= music_h(music_label('aria.footer_navigation', 'Footer navigation')) ?>">
        <?php foreach ($footerColumns as $columnTitle => $links): ?>
            <div class="footer-column">
                <h2><?= music_h($columnTitle) ?></h2>
                <?php foreach ($links as $slug => [$labelKey, $labelDefault]): ?>
                    <?php
                    $currentLang = current_lang_key();
                    $pageUrl = music_page_url($slug, $currentLang);
                    ?>
                    <a class="site-link" href="<?= music_h($pageUrl) ?>"><?= music_h(music_label($labelKey, $labelDefault)) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!empty($footerSites)): ?>
            <div class="footer-column footer-sites">
                <h2><?= music_h(music_label('footer.ecosystem', 'Sites')) ?></h2>
                <?php foreach ($footerSites as $site): ?>
                    <?php
                    $siteName = trim((string) ($site['name'] ?? ''));
                    $siteUrl = trim((string) ($site['url'] ?? ''));
                    $siteLogo = trim((string) ($site['logo'] ?? ''));
                    $siteDescription = music_excerpt($site['description'] ?? '', 54);
                    if ($siteName === '' || $siteUrl === '') {
                        continue;
                    }
                    ?>
                    <a class="footer-site-link" href="<?= music_h($siteUrl) ?>" target="_blank" rel="noopener noreferrer">
                        <?php if ($siteLogo !== ''): ?>
                            <img src="<?= music_h($siteLogo) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="footer-site-icon"><i class="fas fa-globe"></i></span>
                        <?php endif; ?>
                        <span>
                            <strong><?= music_h($siteName) ?></strong>
                            <?php if ($siteDescription !== ''): ?>
                                <small><?= music_h($siteDescription) ?></small>
                            <?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </nav>
</footer>
<script src="<?= music_h(music_url('cr_player/cr_player.js')) ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
cr_player.path = '<?= music_h(music_url('cr_player')) ?>';
cr_player.onCreate('theme_basic_bottom');

const musicOauthError = new URLSearchParams(window.location.search).get('oauth_error');
if (musicOauthError) {
    Swal.fire({
        icon: 'error',
        title: <?= json_encode(music_label('login.error_title', 'Không thể đăng nhập'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        text: musicOauthError,
        confirmButtonColor: '#ff6a00',
    });
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('oauth_error');
    window.history.replaceState(null, document.title, cleanUrl.toString());
}

const musicCurrentShareUrl = (button) => {
    const rawUrl = button?.dataset.shareUrl || window.location.href;
    try {
        return new URL(rawUrl, window.location.href).toString();
    } catch (error) {
        return window.location.href;
    }
};

const musicCopyText = async (text) => {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.left = '-9999px';
    document.body.appendChild(field);
    field.select();
    document.execCommand('copy');
    field.remove();
};

document.addEventListener('click', async (event) => {
    const loginButton = event.target.closest('.js-music-login');
    if (loginButton) {
        Swal.fire({
            title: <?= json_encode(music_label('login.title', 'Login'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            html: `
                <div class="music-login-popup">
                    <p><?= music_h(music_label('login.intro', 'Sign in to your Carrot account.')) ?></p>
                    <div class="music-login-providers">
                        <a href="<?= music_h(music_url('social-login.php?provider=google')) ?>" aria-label="Google">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285f4" d="M21.6 12.2c0-.7-.1-1.3-.2-1.8H12v3.5h5.4c-.2 1.1-.9 2.1-1.9 2.7v2.2h3c1.8-1.6 3.1-3.9 3.1-6.6Z"/><path fill="#34a853" d="M12 22c2.7 0 5-.9 6.6-2.5l-3-2.2c-.8.5-1.9.9-3.6.9-2.6 0-4.8-1.7-5.6-4.1H3.3v2.3C4.9 19.7 8.2 22 12 22Z"/><path fill="#fbbc05" d="M6.4 14.1c-.2-.6-.3-1.3-.3-2.1s.1-1.4.3-2.1V7.6H3.3C2.5 8.9 2.1 10.4 2.1 12s.4 3.1 1.2 4.4l3.1-2.3Z"/><path fill="#ea4335" d="M12 5.8c1.5 0 2.8.5 3.8 1.5l2.8-2.8C17 2.9 14.7 2 12 2 8.2 2 4.9 4.3 3.3 7.6l3.1 2.3c.8-2.4 3-4.1 5.6-4.1Z"/></svg>
                            <span>Google</span>
                        </a>
                        <a href="<?= music_h(music_url('social-login.php?provider=twitter_x')) ?>" aria-label="X">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18.3 2.8h3.3l-7.2 8.2 8.5 10.2h-6.6l-5.2-6.2-6 6.2H1.8l7.7-8.7L1.4 2.8h6.8l4.7 5.7 5.4-5.7Zm-1.2 16.6h1.8L7.2 4.5H5.3l11.8 14.9Z"/></svg>
                            <span>X</span>
                        </a>
                        <a href="<?= music_h(music_url('social-login.php?provider=github')) ?>" aria-label="GitHub">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-3.2 19.5c.5.1.7-.2.7-.5v-1.8c-2.9.6-3.5-1.2-3.5-1.2-.5-1.1-1.1-1.4-1.1-1.4-.9-.6.1-.6.1-.6 1 0 1.5 1 1.5 1 .9 1.5 2.3 1.1 2.9.8.1-.6.3-1.1.6-1.3-2.3-.3-4.7-1.1-4.7-5A3.9 3.9 0 0 1 6.4 8.7c-.1-.3-.5-1.3.1-2.7 0 0 .8-.3 2.8 1a9.6 9.6 0 0 1 5.2 0c2-1.3 2.8-1 2.8-1 .6 1.4.2 2.4.1 2.7a3.9 3.9 0 0 1 1.1 2.8c0 3.9-2.4 4.7-4.7 5 .4.3.7.9.7 1.8V21c0 .3.2.6.7.5A10 10 0 0 0 12 2Z"/></svg>
                            <span>GitHub</span>
                        </a>
                    </div>
                    <a class="music-login-email" href="../CarrotHome/login.php?redirect=${encodeURIComponent(window.location.href)}"><?= music_h(music_label('login.with_email', 'Login with email')) ?></a>
                </div>
            `,
            showConfirmButton: false,
            showCloseButton: true,
            customClass: {popup: 'music-login-swal'},
            background: '#fff7f2',
        });
        return;
    }

    const shareButton = event.target.closest('.js-music-share');
    if (shareButton) {
        const shareUrl = musicCurrentShareUrl(shareButton);
        const shareTitle = shareButton.dataset.shareTitle || document.title;
        if (navigator.share) {
            try {
                await navigator.share({title: shareTitle, url: shareUrl});
                return;
            } catch (error) {
                if (error.name === 'AbortError') return;
            }
        }

        try {
            await musicCopyText(shareUrl);
            Swal.fire({
                icon: 'success',
                title: <?= json_encode(music_label('music.share.copied_title', 'Đã sao chép liên kết'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                text: shareUrl,
                confirmButtonColor: '#ff6a00',
            });
        } catch (error) {
            Swal.fire({
                icon: 'info',
                title: <?= json_encode(music_label('music.share.copy_title', 'Liên kết chia sẻ'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                text: shareUrl,
                confirmButtonColor: '#ff6a00',
            });
        }
        return;
    }

    const qrButton = event.target.closest('.js-music-qr');
    if (!qrButton) return;

    const shareUrl = musicCurrentShareUrl(qrButton);
    const shareTitle = qrButton.dataset.shareTitle || document.title;
    Swal.fire({
        title: shareTitle,
        html: '<div class="music-qr-modal"><div id="music_qr_code"></div><p>' + shareUrl.replace(/[&<>"']/g, (char) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char])) + '</p></div>',
        confirmButtonColor: '#ff6a00',
        didOpen: () => {
            const target = document.getElementById('music_qr_code');
            if (target && window.QRCode) {
                new QRCode(target, {
                    text: shareUrl,
                    width: 220,
                    height: 220,
                    colorDark: '#100b09',
                    colorLight: '#fff7f2',
                    correctLevel: QRCode.CorrectLevel.H,
                });
            } else if (target) {
                const fallbackLink = document.createElement('a');
                fallbackLink.className = 'btn btn-primary';
                fallbackLink.href = shareUrl;
                fallbackLink.target = '_blank';
                fallbackLink.rel = 'noopener noreferrer';
                fallbackLink.textContent = <?= json_encode(music_label('music.action.open_link', 'Mở liên kết'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                target.appendChild(fallbackLink);
            }
        },
    });
});

const musicLanguageTemplate = (item) => {
    if (!item.id) {
        return item.text;
    }

    const icon = item.element ? item.element.dataset.icon : '';
    const label = jQuery('<span class="music-language-option"></span>');
    if (icon) {
        label.append(jQuery('<img alt="" loading="lazy">').attr('src', icon));
    }
    label.append(document.createTextNode(item.text));
    return label;
};

if (window.jQuery && jQuery.fn.select2) {
    jQuery('.music-language-select').select2({
        width: 'style',
        dropdownCssClass: 'music-language-dropdown',
        minimumResultsForSearch: 8,
        templateResult: musicLanguageTemplate,
        templateSelection: musicLanguageTemplate,
        escapeMarkup: (markup) => markup,
    }).on('change', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('lang', this.value);
        window.location.href = url.toString();
    });
} else {
    document.addEventListener('change', (event) => {
        const select = event.target.closest('.music-language-select');
        if (!select) return;
        const url = new URL(window.location.href);
        url.searchParams.set('lang', select.value);
        window.location.href = url.toString();
    });
}

if (window.tippy) {
    document.querySelectorAll('.icon-btn[title]').forEach((item) => {
        item.dataset.tooltipLabel = item.getAttribute('title') || '';
        item.removeAttribute('title');
    });
    tippy('button[aria-label], a[aria-label], .icon-btn[data-tooltip-label]', {
        content(reference) {
            return reference.getAttribute('aria-label') || reference.dataset.tooltipLabel || reference.getAttribute('title') || '';
        },
        theme: 'carrot',
        placement: 'bottom',
        delay: [120, 40],
        touch: ['hold', 450],
        ignoreAttributes: true,
    });
}
</script>
</body>
</html>
    <?php
}
