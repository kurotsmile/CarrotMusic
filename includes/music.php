<?php

require_once __DIR__ . '/../../CarrotHome/config/database.php';
require_once __DIR__ . '/visit_tracker.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

music_visit_track_daily_ip($pdo ?? null);

function music_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

function music_song_url(string $id): string
{
    return music_url('song.php?id=' . rawurlencode($id));
}

function music_artist_url(int $id): string
{
    return music_url('artist.php?id=' . $id);
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

function music_fetch_artist(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM song_artist WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function music_has_paid_song(string $songId): bool
{
    return !empty($_SESSION['paid_music_songs'][$songId]);
}

function music_render_header(string $title, string $description = '', string $image = ''): void
{
    $description = $description !== '' ? $description : 'CarrotMusic là cổng nghe nhạc online, phân phối và tải MP3 chất lượng cao.';
    $image = $image !== '' ? $image : music_url('favicon/android-chrome-512x512.png');
    $searchQuery = trim((string) ($_GET['q'] ?? ''));
    ?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= music_h($title) ?></title>
    <meta name="description" content="<?= music_h($description) ?>">
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
    <link rel="stylesheet" href="<?= music_h(music_url('style.css?v2')) ?>">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
<header class="site-header">
    <a class="brand site-link" href="<?= music_h(music_url('index.php')) ?>">
        <span class="brand-mark">♪</span>
        <span><strong>CarrotMusic</strong><small>Store Music</small></span>
    </a>
    <form class="header-search" method="get" action="<?= music_h(music_url('index.php')) ?>">
        <input name="q" type="search" value="<?= music_h($searchQuery) ?>" placeholder="Tìm bài hát hoặc nghệ sĩ">
        <button type="submit" aria-label="Tìm kiếm"><i class="fas fa-search"></i></button>
    </form>
    <nav>
        <a class="site-link" href="<?= music_h(music_url('index.php')) ?>">Khám phá</a>
        <a class="site-link" href="<?= music_h(music_url('index.php#genres')) ?>">Thể loại</a>
        <a class="site-link" href="<?= music_h(music_url('index.php#artists')) ?>">Nghệ sĩ</a>
    </nav>
</header>
<main id="app-content">
    <?php
}

function music_render_footer(): void
{
    ?>
</main>
<footer class="site-footer">
    <div class="footer-brand">
        <img src="<?= music_h(music_url('carrot_28.png')) ?>" alt="Carrot28">
        <div>
            <strong>Carrot28</strong>
            <p>Hệ sinh thái sản phẩm số Carrot28 kết nối app, game, nội dung và âm nhạc trong một nền tảng sáng tạo.</p>
        </div>
    </div>
    <span>CarrotMusic: nghe online, tạo playlist và mua tải MP3 bằng PayPal.</span>
</footer>
<script src="<?= music_h(music_url('cr_player/cr_player.js')) ?>"></script>
<script>
cr_player.path = '<?= music_h(music_url('cr_player')) ?>';
cr_player.onCreate('theme_basic_bottom');

document.addEventListener('click', async (event) => {
    const link = event.target.closest('a.site-link');
    if (!link || link.target || link.origin !== window.location.origin) return;
    if (link.hash && link.pathname === window.location.pathname) return;
    event.preventDefault();
    try {
        const response = await fetch(link.href, {headers: {'X-CarrotMusic-PJAX': '1'}});
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const content = doc.querySelector('#app-content');
        if (!content) {
            window.location.href = link.href;
            return;
        }
        document.title = doc.title;
        document.querySelector('#app-content').innerHTML = content.innerHTML;
        history.pushState({}, doc.title, link.href);
        window.scrollTo({top: 0, behavior: 'smooth'});
    } catch (error) {
        window.location.href = link.href;
    }
});

document.addEventListener('submit', async (event) => {
    const form = event.target.closest('.header-search');
    if (!form) return;
    event.preventDefault();
    const action = form.action || window.location.href;
    const params = new URLSearchParams(new FormData(form));
    const url = action + (action.includes('?') ? '&' : '?') + params.toString();
    try {
        const response = await fetch(url, {headers: {'X-CarrotMusic-PJAX': '1'}});
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const content = doc.querySelector('#app-content');
        if (!content) {
            window.location.href = url;
            return;
        }
        document.title = doc.title;
        document.querySelector('#app-content').innerHTML = content.innerHTML;
        history.pushState({}, doc.title, url);
        window.scrollTo({top: 0, behavior: 'smooth'});
    } catch (error) {
        window.location.href = url;
    }
});

window.addEventListener('popstate', () => window.location.reload());
</script>
</body>
</html>
    <?php
}
