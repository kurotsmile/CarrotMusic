<?php
require_once __DIR__ . '/includes/music.php';

$selectedCountry = strtoupper(trim((string) ($_GET['country'] ?? ($_GET['c'] ?? ''))));
if ($selectedCountry !== '' && !preg_match('/^[A-Z]{2}$/', $selectedCountry)) {
    $selectedCountry = '';
}

$countries = [];
$selectedCountryRow = null;
$songs = [];
$artists = [];
$stats = ['countries' => 0, 'songs' => 0, 'artists' => 0];
$errorMessage = $db_error ?? '';

if ($pdo instanceof PDO) {
    try {
        $countries = $pdo->query('
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
        ')->fetchAll();

        $stats['countries'] = count($countries);
        foreach ($countries as $country) {
            if ($selectedCountry !== '' && strtoupper((string) ($country['country_code'] ?? '')) === $selectedCountry) {
                $selectedCountryRow = $country;
            }
        }
        $stats['songs'] = (int) $pdo->query('
            SELECT COUNT(DISTINCT s.id)
            FROM song s
            INNER JOIN country c ON LOWER(s.lang) = LOWER(c.lang_key)
        ')->fetchColumn();
        $stats['artists'] = (int) $pdo->query('
            SELECT COUNT(DISTINCT sa.id)
            FROM song_artist sa
            INNER JOIN country c ON LOWER(sa.lang_key) = LOWER(c.lang_key)
        ')->fetchColumn();

        if (!$selectedCountryRow && $countries) {
            $selectedCountryRow = $countries[0];
            $selectedCountry = strtoupper((string) ($selectedCountryRow['country_code'] ?? ''));
        }

        if ($selectedCountryRow) {
            $selectedLang = (string) ($selectedCountryRow['lang_key'] ?? '');
            $songs = music_fetch_songs($pdo, 24, 'LOWER(s.lang) = LOWER(?)', [$selectedLang]);

            $artistStmt = $pdo->prepare('
                SELECT sa.*, COUNT(sam.song_id) AS song_count
                FROM song_artist sa
                LEFT JOIN song_artist_map sam ON sam.artist_id = sa.id
                WHERE LOWER(sa.lang_key) = LOWER(?)
                GROUP BY sa.id
                ORDER BY song_count DESC, sa.name ASC
                LIMIT 18
            ');
            $artistStmt->execute([$selectedLang]);
            $artists = $artistStmt->fetchAll();
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$hasCountryRequest = isset($_GET['country']) || isset($_GET['c']);
if ($selectedCountry !== '' && $hasCountryRequest) {
    music_redirect_to_canonical(music_country_url($selectedCountry), ['country', 'c']);
} elseif (!$hasCountryRequest) {
    music_redirect_to_canonical(music_countries_url(), []);
}

$pageTitle = music_label('music.tourism.meta_title', 'Du lịch âm nhạc - ' . music_brand_name());
$pageDescription = music_label('music.tourism.meta_description', 'Khám phá bài hát và nghệ sĩ trên bản đồ thế giới của ' . music_brand_name() . '.');
music_render_header($pageTitle, $pageDescription);

$mapSeries = [];
$countryMeta = [];
foreach ($countries as $country) {
    $code = strtoupper((string) ($country['country_code'] ?? ''));
    if ($code === '') {
        continue;
    }
    $songCount = (int) ($country['song_count'] ?? 0);
    $artistCount = (int) ($country['artist_count'] ?? 0);
    $mapSeries[$code] = $songCount + $artistCount;
    $countryMeta[$code] = [
        'name' => (string) ($country['name'] ?? $code),
        'lang' => (string) ($country['lang_key'] ?? ''),
        'icon' => (string) ($country['icon'] ?? ''),
        'songs' => $songCount,
        'artists' => $artistCount,
        'url' => music_country_url($code),
    ];
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/jsvectormap.min.css">

<section class="tourism-hero">
    <div class="tourism-hero__copy">
        <p class="eyebrow"><?= music_h(music_label('music.tourism.eyebrow', 'Music tourism')) ?></p>
        <h1><?= music_h(music_label('music.tourism.title', 'Du lịch thế giới bằng âm nhạc')) ?></h1>
        <p><?= music_h(music_label('music.tourism.description', 'Chọn một quốc gia trên bản đồ để mở các bài hát và nghệ sĩ có cùng vùng ngôn ngữ trong ' . music_brand_name() . '.')) ?></p>
    </div>
    <div class="tourism-stats" aria-label="<?= music_h(music_label('aria.tourism_stats', 'Music tourism statistics')) ?>">
        <span><strong><?= number_format($stats['countries']) ?></strong><small><?= music_h(music_label('country', 'quốc gia')) ?></small></span>
        <span><strong><?= number_format($stats['songs']) ?></strong><small><?= music_h(music_label('music.label.songs', 'bài hát')) ?></small></span>
        <span><strong><?= number_format($stats['artists']) ?></strong><small><?= music_h(music_label('music.label.artists', 'nghệ sĩ')) ?></small></span>
    </div>
</section>

<section class="tourism-layout">
    <?php if ($errorMessage): ?><div class="empty"><?= music_h($errorMessage) ?></div><?php endif; ?>
    <div class="tourism-map-panel">
        <div class="tourism-map-head">
            <div>
                <h2><?= music_h(music_label('music.tourism.map_title', 'Bản đồ âm nhạc')) ?></h2>
                <p><?= music_h(music_label('music.tourism.map_hint', 'Quốc gia sáng màu hơn có nhiều bài hát và nghệ sĩ hơn.')) ?></p>
            </div>
            <?php if ($selectedCountryRow): ?>
                <a class="tourism-selected-country" href="<?= music_h(music_country_url($selectedCountry)) ?>">
                    <?php if (!empty($selectedCountryRow['icon'])): ?><img src="<?= music_h($selectedCountryRow['icon']) ?>" alt="" loading="lazy"><?php endif; ?>
                    <span><?= music_h($selectedCountryRow['name'] ?? $selectedCountry) ?></span>
                </a>
            <?php endif; ?>
        </div>
        <div id="music-tourism-map" class="tourism-map" data-selected="<?= music_h($selectedCountry) ?>"></div>
    </div>

    <aside class="tourism-country-list" aria-label="<?= music_h(music_label('aria.tourism_country_list', 'Countries with music')) ?>">
        <h2><?= music_h(music_label('music.tourism.country_list', 'Điểm đến nổi bật')) ?></h2>
        <?php foreach (array_slice($countries, 0, 18) as $country): ?>
            <?php $countryCode = strtoupper((string) ($country['country_code'] ?? '')); ?>
            <a class="tourism-country<?= $countryCode === $selectedCountry ? ' is-active' : '' ?>" href="<?= music_h(music_country_url($countryCode)) ?>">
                <?php if (!empty($country['icon'])): ?><img src="<?= music_h($country['icon']) ?>" alt="" loading="lazy"><?php endif; ?>
                <span>
                    <strong><?= music_h($country['name'] ?? $countryCode) ?></strong>
                    <small><?= number_format((int) ($country['song_count'] ?? 0)) ?> <?= music_h(music_label('music.label.songs', 'bài hát')) ?> · <?= number_format((int) ($country['artist_count'] ?? 0)) ?> <?= music_h(music_label('music.label.artists', 'nghệ sĩ')) ?></small>
                </span>
            </a>
        <?php endforeach; ?>
    </aside>
</section>

<section class="section tourism-results">
    <div class="section-head">
        <div>
            <h2>
                <?= music_h($selectedCountryRow ? sprintf(music_label('music.tourism.results_title', 'Âm nhạc tại %s'), (string) ($selectedCountryRow['name'] ?? $selectedCountry)) : music_label('music.tourism.empty_title', 'Chưa có điểm đến')) ?>
            </h2>
            <p><?= music_h(music_label('music.tourism.results_hint', 'Nghe thử bài hát hoặc mở hồ sơ nghệ sĩ để tiếp tục chuyến đi.')) ?></p>
        </div>
    </div>

    <?php if (!$selectedCountryRow): ?>
        <div class="empty"><?= music_h(music_label('music.tourism.no_country', 'Chưa có dữ liệu quốc gia để hiển thị trên bản đồ.')) ?></div>
    <?php else: ?>
        <div class="tourism-result-grid">
            <div>
                <h3><?= music_h(music_label('music.label.songs', 'Bài hát')) ?></h3>
                <?php if (!$songs): ?>
                    <div class="empty"><?= music_h(music_label('music.empty.songs_country', 'Chưa có bài hát cho điểm đến này.')) ?></div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($songs as $song): ?>
                            <article class="song-card">
                                <a class="site-link" href="<?= music_h(music_song_url($song['id'])) ?>">
                                    <img src="<?= music_h(music_cover($song['avatar'])) ?>" alt="<?= music_h($song['name']) ?>">
                                </a>
                                <div class="song-card-body">
                                    <a class="song-title site-link" href="<?= music_h(music_song_url($song['id'])) ?>"><?= music_h($song['name']) ?></a>
                                    <div class="song-meta"><?= music_h($song['artist_names'] ?: $song['artist'] ?: music_label('music.label.unknown_artist', 'Unknown artist')) ?></div>
                                    <div class="song-card-actions">
                                        <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($song['artist_names'] ?: $song['artist']) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><?= music_play_icon() ?><?= music_h(music_label('music.action.play', 'Phát')) ?></button>
                                        <button class="icon-btn" title="<?= music_h(music_label('music.action.add_to_playlist', 'Thêm vào playlist')) ?>" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($song['artist_names'] ?: $song['artist']) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><i class="fas fa-plus"></i></button>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <h3><?= music_h(music_label('music.label.artists', 'Nghệ sĩ')) ?></h3>
                <?php if (!$artists): ?>
                    <div class="empty"><?= music_h(music_label('music.empty.artists_country', 'Chưa có nghệ sĩ cho điểm đến này.')) ?></div>
                <?php else: ?>
	                    <div class="artist-grid">
	                        <?php foreach ($artists as $artist): ?>
	                            <a class="artist-card site-link" href="<?= music_h(music_artist_url((int) $artist['id'], (string) $artist['name'])) ?>">
	                                <img src="<?= music_h(music_cover($artist['avatar'] ?? '')) ?>" alt="<?= music_h($artist['name']) ?>">
	                                <span><strong><?= music_h($artist['name']) ?></strong><small><?= number_format((int) ($artist['song_count'] ?? 0)) ?> <?= music_h(music_label('music.label.songs', 'bài hát')) ?></small></span>
	                            </a>
	                        <?php endforeach; ?>
	                    </div>
	                    <a class="tourism-view-all" href="<?= music_h(music_artists_country_url($selectedCountry)) ?>">
	                        <span><?= music_h(music_label('action.view_all', 'Xem tất cả')) ?></span>
	                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
	                    </a>
	                <?php endif; ?>
	            </div>
        </div>
    <?php endif; ?>
</section>

<script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/jsvectormap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/maps/world.js"></script>
<script>
const musicTourismCountries = <?= json_encode($countryMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const musicTourismSeries = <?= json_encode($mapSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const musicTourismSelected = <?= json_encode($selectedCountry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

if (window.jsVectorMap && document.getElementById('music-tourism-map')) {
    new jsVectorMap({
        selector: '#music-tourism-map',
        map: 'world',
        zoomButtons: true,
        zoomOnScroll: false,
        selectedRegions: musicTourismSelected ? [musicTourismSelected] : [],
        regionStyle: {
            initial: {fill: '#ffd0a8', stroke: '#fff7f2', strokeWidth: .7},
            hover: {fill: '#ff6a00', cursor: 'pointer'},
            selected: {fill: '#e11d1d'},
            selectedHover: {fill: '#c51616'},
        },
        series: {
            regions: [{
                values: musicTourismSeries,
                scale: ['#ffd9c9', '#ff6a00', '#e11d1d'],
                normalizeFunction: 'polynomial',
            }],
        },
        labels: {
            regions: {
                render(code) {
                    return musicTourismCountries[code] ? code : '';
                },
            },
        },
        onRegionTooltipShow(event, tooltip, code) {
            const country = musicTourismCountries[code];
            if (!country) {
                tooltip.text(tooltip.text() + ' · 0');
                return;
            }
            tooltip.text(country.name + ' · ' + country.songs + ' songs · ' + country.artists + ' artists');
        },
        onRegionClick(event, code) {
            const country = musicTourismCountries[code];
            if (!country) return;
            window.location.href = country.url;
        },
    });
}
</script>
<?php music_render_footer(); ?>
