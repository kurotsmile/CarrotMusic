<?php
require_once __DIR__ . '/includes/music.php';

music_redirect_to_canonical(music_artists_url(), []);

$artists = [];
$countries = [];
$selectedCountry = strtoupper(trim((string) ($_GET['country'] ?? ($_GET['c'] ?? ''))));
if ($selectedCountry !== '' && !preg_match('/^[A-Z]{2}$/', $selectedCountry)) {
    $selectedCountry = '';
}
$selectedCountryRow = null;
$selectedLangKey = '';
$errorMessage = $db_error ?? '';
$currentPage = max(1, (int) ($_GET['page_no'] ?? 1));
$perPage = 48;
$totalArtists = 0;
$totalPages = 1;

if ($pdo instanceof PDO) {
    try {
        $countries = $pdo->query('
            SELECT
                MIN(c.id) AS id,
                MIN(c.icon) AS icon,
                MIN(c.name) AS name,
                c.lang_key,
                UPPER(c.lang_country) AS country_code,
                COUNT(DISTINCT sa.id) AS artist_count
            FROM country c
            INNER JOIN song_artist sa ON LOWER(sa.lang_key) = LOWER(c.lang_key)
            WHERE COALESCE(c.lang_country, "") <> ""
            GROUP BY c.lang_key, country_code
            HAVING artist_count > 0
            ORDER BY artist_count DESC, name ASC
        ')->fetchAll();

        foreach ($countries as $country) {
            if ($selectedCountry !== '' && strtoupper((string) ($country['country_code'] ?? '')) === $selectedCountry) {
                $selectedCountryRow = $country;
                $selectedLangKey = (string) ($country['lang_key'] ?? '');
                break;
            }
        }

        if ($selectedCountry !== '' && !$selectedCountryRow) {
            $selectedCountry = '';
        }

        $cacheKey = music_cache_key('music_artists', [
            'page' => $currentPage,
            'per_page' => $perPage,
            'country' => $selectedCountry,
            'lang' => $selectedLangKey,
        ]);
        $cachedArtists = music_cache_get($cacheKey, 86400);

        if (is_array($cachedArtists)) {
            $artists = is_array($cachedArtists['artists'] ?? null) ? $cachedArtists['artists'] : [];
            $totalArtists = (int) ($cachedArtists['total_artists'] ?? 0);
            $totalPages = max(1, (int) ($cachedArtists['total_pages'] ?? 1));
            $currentPage = min($currentPage, $totalPages);
        } else {
            if ($selectedLangKey !== '') {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM song_artist WHERE LOWER(lang_key) = LOWER(?)');
                $countStmt->execute([$selectedLangKey]);
                $totalArtists = (int) $countStmt->fetchColumn();
            } else {
                $totalArtists = (int) $pdo->query('SELECT COUNT(*) FROM song_artist')->fetchColumn();
            }
            $totalPages = max(1, (int) ceil($totalArtists / $perPage));
            $currentPage = min($currentPage, $totalPages);
            $offset = ($currentPage - 1) * $perPage;

            if ($selectedLangKey !== '') {
                $stmt = $pdo->prepare('
                    SELECT sa.*, COUNT(DISTINCT sam.song_id) AS song_count
                    FROM song_artist sa
                    LEFT JOIN song_artist_map sam ON sam.artist_id = sa.id
                    WHERE LOWER(sa.lang_key) = LOWER(?)
                    GROUP BY sa.id
                    ORDER BY sa.name ASC, sa.id ASC
                    LIMIT ? OFFSET ?
                ');
                $stmt->bindValue(1, $selectedLangKey);
                $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
                $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            } else {
                $stmt = $pdo->prepare('
                    SELECT sa.*, COUNT(DISTINCT sam.song_id) AS song_count
                    FROM song_artist sa
                    LEFT JOIN song_artist_map sam ON sam.artist_id = sa.id
                    GROUP BY sa.id
                    ORDER BY sa.name ASC, sa.id ASC
                    LIMIT ? OFFSET ?
                ');
                $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
                $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            }
            $stmt->execute();
            $artists = $stmt->fetchAll();

            music_cache_set($cacheKey, [
                'created_at' => date('c'),
                'total_artists' => $totalArtists,
                'total_pages' => $totalPages,
                'artists' => $artists,
            ]);
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$selectedCountryName = trim((string) ($selectedCountryRow['name'] ?? ''));
$selectedCountryLabel = $selectedCountryName !== '' ? $selectedCountryName : $selectedCountry;
$artistListTitle = $selectedCountry !== ''
    ? sprintf(music_label('music.meta.artist_country_list_title', 'Nghệ sĩ tại %s - ' . music_brand_name()), $selectedCountryLabel)
    : music_label('music.meta.artist_list_title', 'Tất cả nghệ sĩ - ' . music_brand_name());
$artistListDescription = $selectedCountry !== ''
    ? sprintf(music_label('music.meta.artist_country_list_description', 'Khám phá các nghệ sĩ tại %s trên ' . music_brand_name() . '.'), $selectedCountryLabel)
    : music_label('music.meta.artist_list_description', 'Khám phá toàn bộ nghệ sĩ trên ' . music_brand_name() . '.');

music_render_header($artistListTitle, $artistListDescription);
?>
<section class="section">
    <div class="section-head">
        <div>
            <h2><?= music_h($selectedCountry !== '' ? sprintf(music_label('music.artists_country_heading', 'Nghệ sĩ tại %s'), $selectedCountryLabel) : music_label('music.all_artists', 'All artists')) ?></h2>
            <p><?= number_format($totalArtists) ?> <?= music_h(music_label('music.label.artists', 'nghệ sĩ')) ?></p>
        </div>
    </div>
    <?php if ($countries): ?>
        <div class="artist-country-filter" aria-label="<?= music_h(music_label('music.filter.artist_country', 'Lọc nghệ sĩ theo quốc gia')) ?>">
            <a class="<?= $selectedCountry === '' ? 'is-active' : '' ?>" href="<?= music_h(music_artists_url()) ?>">
                <span class="artist-country-icon"><?= music_tourism_icon() ?></span>
                <strong><?= music_h(music_label('music.filter.all_countries', 'Tất cả quốc gia')) ?></strong>
            </a>
            <?php foreach ($countries as $country): ?>
                <?php
                $countryCode = strtoupper((string) ($country['country_code'] ?? ''));
                if ($countryCode === '') {
                    continue;
                }
                ?>
                <a class="<?= $countryCode === $selectedCountry ? 'is-active' : '' ?>" href="<?= music_h(music_artists_country_url($countryCode)) ?>">
                    <?php if (!empty($country['icon'])): ?>
                        <img src="<?= music_h($country['icon']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <span class="artist-country-icon"><?= music_h($countryCode) ?></span>
                    <?php endif; ?>
                    <span>
                        <strong><?= music_h($country['name'] ?? $countryCode) ?></strong>
                        <small><?= number_format((int) ($country['artist_count'] ?? 0)) ?> <?= music_h(music_label('music.label.artists', 'nghệ sĩ')) ?></small>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($errorMessage): ?><div class="empty"><?= music_h($errorMessage) ?></div><?php endif; ?>
    <?= music_render_pagination($currentPage, $totalPages, static fn(int $page): string => music_url_with_query(music_artists_url(), ['country' => $selectedCountry, 'page_no' => $page])) ?>
    <div class="artist-grid">
        <?php foreach ($artists as $artist): ?>
            <a class="artist-card site-link" href="<?= music_h(music_artist_url((int) $artist['id'], (string) $artist['name'])) ?>">
                <img src="<?= music_h(music_cover($artist['avatar'])) ?>" alt="<?= music_h($artist['name']) ?>">
                <span><strong><?= music_h($artist['name']) ?></strong><span><?= number_format((int) $artist['song_count']) ?> <?= music_h(music_label('music.label.songs', 'bài hát')) ?></span></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if (!$artists && !$errorMessage): ?><div class="empty"><?= music_h(music_label('music.empty.no_artists', 'Chưa có nghệ sĩ.')) ?></div><?php endif; ?>
    <?= music_render_pagination($currentPage, $totalPages, static fn(int $page): string => music_url_with_query(music_artists_url(), ['country' => $selectedCountry, 'page_no' => $page])) ?>
</section>
<?php music_render_footer(); ?>
