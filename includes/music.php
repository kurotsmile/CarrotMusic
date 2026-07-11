<?php

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

function music_play_icon(): string
{
    return '<svg class="btn-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="currentColor"><path d="M8 5.14v13.72c0 .76.84 1.22 1.48.81l10.78-6.86a.96.96 0 0 0 0-1.62L9.48 4.33A.96.96 0 0 0 8 5.14Z"/></svg>';
}

function music_label(string $key, string $default, ?string $langKey = null): string
{
    return ui_label($key, $default, $langKey);
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

function music_genre_url(string $id): string
{
    return music_url('genre.php?id=' . rawurlencode($id));
}

function music_split_genres(?string $genres): array
{
    return array_values(array_unique(array_filter(array_map('trim', preg_split('/\s*,\s*/', (string) $genres) ?: []))));
}

function music_page_url(string $slug, string $lang = ''): string
{
    $url = music_url('index.php?page=' . rawurlencode(trim($slug)));
    if (trim($lang) !== '') {
        $url .= '&lang=' . rawurlencode(trim($lang));
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
        $stmt = $pdo->query("SELECT name, url, logo, description FROM sites WHERE COALESCE(url, '') <> '' ORDER BY sort_order ASC, name ASC LIMIT 10");
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
        SELECT ? AS genre_id, ? AS title, "" AS description, COUNT(DISTINCT id) AS song_count
        FROM song
        WHERE FIND_IN_SET(REPLACE(?, " ", ""), REPLACE(COALESCE(genre, ""), " ", "")) > 0
    ');
    $stmt->execute([$id, $id, $id]);
    $row = $stmt->fetch();
    return $row && (int) ($row['song_count'] ?? 0) > 0 ? $row : null;
}

function music_has_paid_song(string $songId): bool
{
    return !empty($_SESSION['paid_music_songs'][$songId]);
}

function music_render_header(string $title, string $description = '', string $image = ''): void
{
    $description = $description !== '' ? $description : music_label('music.meta.description', 'CarrotMusic là cổng nghe nhạc online, phân phối và tải MP3 chất lượng cao.');
    $image = $image !== '' ? $image : music_url('favicon/android-chrome-512x512.png');
    $searchQuery = trim((string) ($_GET['q'] ?? ''));
    $lang = current_lang_key();
    $languageOptions = music_language_options($GLOBALS['pdo'] ?? null);
    $styleVersion = is_file(__DIR__ . '/../style.css') ? (string) filemtime(__DIR__ . '/../style.css') : '1';
    ?>
<!doctype html>
<html lang="<?= music_h($lang) ?>">
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= music_h(music_url('style.css?v=' . $styleVersion)) ?>">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
<header class="site-header">
    <a class="brand site-link" href="<?= music_h(music_url('index.php')) ?>">
        <span class="brand-mark">♪</span>
        <span><strong>CarrotMusic</strong><small><?= music_h(music_label('music.brand_tagline', 'Store Music')) ?></small></span>
    </a>
    <form class="header-search" method="get" action="<?= music_h(music_url('index.php')) ?>">
        <input name="q" type="search" value="<?= music_h($searchQuery) ?>" placeholder="<?= music_h(music_label('music.search_placeholder', 'Tìm bài hát hoặc nghệ sĩ')) ?>">
        <button type="submit" aria-label="<?= music_h(music_label('action.search', 'Search')) ?>"><i class="fas fa-search"></i></button>
    </form>
    <nav>
        <a class="site-link" href="<?= music_h(music_url('index.php')) ?>"><?= music_h(music_label('music.nav.explore', 'Explore')) ?></a>
        <a class="site-link" href="<?= music_h(music_url('index.php#genres')) ?>"><?= music_h(music_label('music.nav.genres', 'Genres')) ?></a>
        <a class="site-link" href="<?= music_h(music_url('index.php#artists')) ?>"><?= music_h(music_label('music.nav.artists', 'Artists')) ?></a>
        <?php if ($languageOptions): ?>
            <select class="music-language-select" aria-label="<?= music_h(music_label('aria.choose_language', 'Choose language')) ?>">
                <?php foreach ($languageOptions as $language): ?>
                    <?php $languageKey = (string) ($language['lang_key'] ?? ''); ?>
                    <?php if ($languageKey === '') continue; ?>
                    <option value="<?= music_h($languageKey) ?>" data-icon="<?= music_h($language['icon'] ?? '') ?>" <?= $languageKey === $lang ? 'selected' : '' ?>>
                        <?= music_h(($language['name'] ?? $languageKey) . ' · ' . strtoupper($languageKey)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </nav>
</header>
<main id="app-content">
    <?php
}

function music_render_footer(): void
{
    $footerSites = music_footer_sites($GLOBALS['pdo'] ?? null);
    $footerColumns = [
        music_label('music.footer.organization', 'Organization') => [
            'about' => ['music.footer.about', 'About'],
            'services' => ['music.footer.services', 'Services'],
            'contact' => ['music.footer.contact', 'Contact'],
        ],
        music_label('music.footer.legal', 'Legal') => [
            'privacy-policy' => ['music.footer.privacy_policy', 'Privacy Policy'],
            'terms-of-service' => ['music.footer.terms_of_service', 'Terms of Service'],
            'cookie-policy' => ['music.footer.cookie_policy', 'Cookie Policy'],
            'disclaimer' => ['music.footer.disclaimer', 'Disclaimer'],
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
                <h2><?= music_h(music_label('music.footer.sites', 'Sites')) ?></h2>
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
<script>
cr_player.path = '<?= music_h(music_url('cr_player')) ?>';
cr_player.onCreate('theme_basic_bottom');

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
</script>
</body>
</html>
    <?php
}
