<?php
require_once __DIR__ . '/includes/music.php';

$pageSlug = trim((string) ($_GET['page'] ?? $_GET['slug'] ?? ''));
if ($pageSlug !== '') {
    include __DIR__ . '/page.php';
    exit;
}

$songs = [];
$popularSongs = [];
$genres = [];
$artists = [];
$timelineYears = [];
$featured = null;
$stats = ['songs' => 0, 'artists' => 0, 'genres' => 0];
$errorMessage = $db_error ?? '';
$searchQuery = trim((string) ($_GET['q'] ?? ''));

if ($pdo instanceof PDO) {
    try {
        $cacheTtl = $searchQuery === '' ? 86400 : 900;
        $cacheKey = music_cache_key('music_home', [
            'lang' => current_lang_key(),
            'q' => $searchQuery !== '' ? sha1($searchQuery) : '',
            'view' => 'genre_cards_timeline_v4_24_new_songs_18_popular',
        ]);
        $cachedHome = music_cache_get($cacheKey, $cacheTtl);

        if (is_array($cachedHome)) {
            $songs = is_array($cachedHome['songs'] ?? null) ? $cachedHome['songs'] : [];
            $popularSongs = is_array($cachedHome['popular_songs'] ?? null) ? $cachedHome['popular_songs'] : [];
            $stats = is_array($cachedHome['stats'] ?? null) ? array_merge($stats, $cachedHome['stats']) : $stats;
            $genres = is_array($cachedHome['genres'] ?? null) ? $cachedHome['genres'] : [];
            $artists = is_array($cachedHome['artists'] ?? null) ? $cachedHome['artists'] : [];
            $timelineYears = is_array($cachedHome['timeline_years'] ?? null) ? $cachedHome['timeline_years'] : [];
        } else {
            if ($searchQuery !== '') {
                $searchValue = '%' . $searchQuery . '%';
                $songs = music_fetch_songs($pdo, 48, '
                    (s.id LIKE ? OR s.name LIKE ? OR s.artist LIKE ? OR s.album LIKE ? OR s.genre LIKE ?
                     OR EXISTS (
                        SELECT 1
                        FROM song_artist_map search_map
                        INNER JOIN song_artist search_artist ON search_artist.id = search_map.artist_id
                        WHERE search_map.song_id = s.id AND search_artist.name LIKE ?
                     ))
                ', [$searchValue, $searchValue, $searchValue, $searchValue, $searchValue, $searchValue]);
            } else {
                $songs = music_fetch_songs($pdo, 24);
                try {
                    $popularSongs = music_fetch_popular_songs($pdo, 18);
                } catch (Throwable $popularError) {
                    error_log('music_fetch_popular_songs failed: ' . $popularError->getMessage());
                    $popularSongs = [];
                }
            }
            $stats['songs'] = (int) $pdo->query('SELECT COUNT(*) FROM song')->fetchColumn();
            $stats['artists'] = (int) $pdo->query('SELECT COUNT(*) FROM song_artist')->fetchColumn();
            $stats['genres'] = (int) $pdo->query('SELECT COUNT(*) FROM song_genre')->fetchColumn();
            $genres = $pdo->query('
                SELECT g.genre_id, g.title, g.avatar, COUNT(DISTINCT s.id) AS song_count
                FROM song_genre g
                LEFT JOIN song s ON FIND_IN_SET(REPLACE(g.genre_id, " ", ""), REPLACE(COALESCE(s.genre, ""), " ", "")) > 0
                GROUP BY g.genre_id, g.title, g.avatar
                ORDER BY RAND()
                LIMIT 18
            ')->fetchAll();
            if ($searchQuery !== '') {
                $artistStmt = $pdo->prepare('
                    SELECT sa.*, COUNT(sam.song_id) AS song_count
                    FROM song_artist sa
                    LEFT JOIN song_artist_map sam ON sam.artist_id = sa.id
                    WHERE sa.name LIKE ?
                    GROUP BY sa.id
                    ORDER BY RAND()
                    LIMIT 16
                ');
                $artistStmt->execute(['%' . $searchQuery . '%']);
                $artists = $artistStmt->fetchAll();
            } else {
                $artists = $pdo->query('
                    SELECT sa.*, COUNT(sam.song_id) AS song_count
                    FROM song_artist sa
                    LEFT JOIN song_artist_map sam ON sam.artist_id = sa.id
                    GROUP BY sa.id
                    ORDER BY RAND()
                    LIMIT 16
                ')->fetchAll();
            }
            $timelineYears = $pdo->query('
                SELECT CAST(TRIM(year) AS UNSIGNED) AS song_year, COUNT(*) AS song_count
                FROM song
                WHERE TRIM(COALESCE(year, "")) REGEXP "^[0-9]{4}$"
                GROUP BY song_year
                ORDER BY song_year DESC
                LIMIT 36
            ')->fetchAll();

            music_cache_set($cacheKey, [
                'created_at' => date('c'),
                'songs' => $songs,
                'popular_songs' => $popularSongs,
                'stats' => $stats,
                'genres' => $genres,
                'artists' => $artists,
                'timeline_years' => $timelineYears,
            ]);
        }
        if ($searchQuery !== '') {
            music_log_song_search($pdo, $searchQuery, count($songs));
        }
        $featured = $songs[0] ?? null;
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$pageTitle = $searchQuery !== ''
    ? sprintf(music_label('music.meta.search_title', 'Tìm "%s" - CarrotMusic'), $searchQuery)
    : music_label('music.meta.home_title', 'CarrotMusic - Nghe nhạc mỗi ngày');
music_render_header($pageTitle, music_label('music.meta.home_description', 'Không gian nghe nhạc CarrotMusic với những bài hát được tuyển chọn, dễ nghe và luôn sẵn sàng đồng hành cùng bạn.'), music_cover($featured['avatar'] ?? ''));
$heroSlides = [
    [
        'title' => music_label('music.hero.slide_artists_title', 'Nghệ sĩ CarrotMusic'),
        'description' => music_label('music.hero.slide_artists_desc', 'Khám phá hồ sơ nghệ sĩ, các bài hát nổi bật và danh sách phát được cập nhật liên tục.'),
        'image' => music_url('images/bn_artist.png'),
        'url' => music_url('list_artist.php'),
    ],
    [
        'title' => music_label('music.hero.slide_genres_title', 'Thế giới thể loại'),
        'description' => music_label('music.hero.slide_genres_desc', 'Đi sâu vào từng màu sắc âm nhạc, từ pop đại chúng đến những mood nghe riêng biệt.'),
        'image' => music_url('images/bn_genre.png'),
        'url' => music_url('list_genre.php'),
    ],
    [
        'title' => music_label('music.hero.slide_time_title', 'Hoài niệm dòng thời gian'),
        'description' => music_label('music.hero.slide_time_desc', 'Nghe nhạc theo mốc năm và tìm lại cảm giác của từng giai đoạn trong ký ức.'),
        'image' => music_url('images/bn_time.png'),
        'url' => music_url('index.php#genres'),
    ],
    [
        'title' => music_label('music.hero.slide_app_title', 'Heartbeat Music'),
        'description' => music_label('music.hero.slide_app_desc', 'Ứng dụng nghe nhạc dành cho những playlist cá nhân, nhẹ nhàng và luôn sẵn sàng phát.'),
        'image' => music_url('images/bn_app.png'),
        'url' => 'https://home.carrot28.com/Music-for-life',
    ],
];
?>
<section class="hero">
    <div class="hero-content">
        <p class="eyebrow"><?= music_h(music_label('music.hero.eyebrow', 'Không gian âm nhạc của bạn')) ?></p>
        <h1><?= music_h(music_label('music.hero.title', 'Listen to music online, create playlists, and download MP3s.')) ?></h1>
        <p><?= music_h(music_label('music.hero.description', 'Tìm bài hát hợp tâm trạng, nghe ngay trong trình phát và lưu lại những giai điệu bạn muốn quay lại nhiều lần.')) ?></p>
        <div class="hero-stats" aria-label="<?= music_h(music_label('aria.music_stats', 'Music statistics')) ?>">
            <div class="hero-stat hero-stat--static">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18V5l10-2v13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6" cy="18" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="16" cy="16" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                <span><strong><?= number_format($stats['songs']) ?></strong><small><?= music_h(music_label('music.label.songs', 'bài hát')) ?></small></span>
            </div>
            <a class="hero-stat" href="<?= music_h(music_url('list_artist.php')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21a8 8 0 0 1 16 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <span><strong><?= number_format($stats['artists']) ?></strong><small><?= music_h(music_label('music.label.artists', 'nghệ sĩ')) ?></small></span>
            </a>
            <a class="hero-stat" href="<?= music_h(music_url('list_genre.php')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h10M4 17h7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="m17 14 3 3-3 3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span><strong><?= number_format($stats['genres']) ?></strong><small><?= music_h(music_label('music.label.genres', 'thể loại')) ?></small></span>
            </a>
        </div>
    </div>
    <div class="hero-slider" aria-label="<?= music_h(music_label('aria.hero_slider', 'Featured music banners')) ?>">
        <div class="hero-slider-track">
            <?php foreach ($heroSlides as $slideIndex => $slide): ?>
                <article class="hero-slide <?= $slideIndex === 0 ? 'is-active' : '' ?>" data-slide="<?= $slideIndex ?>">
                    <img src="<?= music_h($slide['image']) ?>" alt="<?= music_h($slide['title']) ?>">
                    <div class="hero-slide-copy">
                        <strong><?= music_h($slide['title']) ?></strong>
                        <p><?= music_h($slide['description']) ?></p>
                        <?php if ($slide['url'] !== ''): ?>
                            <a class="hero-detail-link site-link" href="<?= music_h($slide['url']) ?>"><?= music_h(music_label('music.action.view_detail', 'Xem chi tiết')) ?></a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="hero-slider-controls">
            <button type="button" class="hero-slider-btn" data-hero-prev aria-label="<?= music_h(music_label('aria.previous_slide', 'Previous slide')) ?>">
                <i class="fas fa-chevron-left"></i>
            </button>
            <div class="hero-slider-dots" aria-hidden="true">
                <?php foreach ($heroSlides as $slideIndex => $slide): ?>
                    <span class="<?= $slideIndex === 0 ? 'is-active' : '' ?>"></span>
                <?php endforeach; ?>
            </div>
            <button type="button" class="hero-slider-btn" data-hero-next aria-label="<?= music_h(music_label('aria.next_slide', 'Next slide')) ?>">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <div>
            <h2><?= music_h($searchQuery !== '' ? music_label('music.section.search_results', 'Kết quả tìm kiếm') : music_label('music.new_songs', 'New song')) ?></h2>
            <p><?= music_h($searchQuery !== '' ? sprintf(music_label('music.section.search_hint', 'Từ khóa: "%s"'), $searchQuery) : music_label('music.new_songs_intro', 'Chọn một bài để nghe ngay hoặc lưu vào danh sách yêu thích của bạn.')) ?></p>
        </div>
    </div>
    <?php if ($errorMessage): ?><div class="empty"><?= music_h($errorMessage) ?></div><?php endif; ?>
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
    <?php if (!$songs && !$errorMessage): ?><div class="empty"><?= music_h(music_label('music.empty.no_songs', 'Hiện chưa có bài hát nào để hiển thị.')) ?></div><?php endif; ?>
</section>

<?php if ($searchQuery === '' && $popularSongs): ?>
<section class="section">
    <div class="section-head">
        <div>
            <h2><?= music_h(music_label('music.section.popular_songs', 'Nghe nhiều nhất')) ?></h2>
            <p><?= music_h(music_label('music.section.popular_songs_intro', 'Những giai điệu đang được nhiều người chọn nghe gần đây.')) ?></p>
        </div>
    </div>
    <div class="grid">
        <?php foreach ($popularSongs as $rankIndex => $song): ?>
            <?php $songRank = $rankIndex + 1; ?>
            <article class="song-card">
                <a class="site-link song-card-cover" href="<?= music_h(music_song_url($song['id'])) ?>">
                    <img src="<?= music_h(music_cover($song['avatar'])) ?>" alt="<?= music_h($song['name']) ?>">
                    <?php if ($songRank <= 10): ?>
                        <?php
                        $rankClass = $songRank <= 3 ? ' is-top-' . $songRank : '';
                        $rankIcon = $songRank === 1 ? 'fa-crown' : ($songRank === 2 ? 'fa-medal' : ($songRank === 3 ? 'fa-award' : ''));
                        ?>
                        <span class="song-rank-badge<?= $rankClass ?>" aria-label="<?= music_h(sprintf(music_label('aria.song_rank', 'Rank %s'), (string) $songRank)) ?>">
                            <?php if ($rankIcon !== ''): ?><i class="fas <?= music_h($rankIcon) ?>" aria-hidden="true"></i><?php endif; ?>
                            <span>#<?= number_format($songRank) ?></span>
                        </span>
                    <?php endif; ?>
                </a>
                <div class="song-card-body">
                    <a class="song-title site-link" href="<?= music_h(music_song_url($song['id'])) ?>"><?= music_h($song['name']) ?></a>
                    <div class="song-meta">
                        <?= music_h($song['artist_names'] ?: $song['artist'] ?: music_label('music.label.unknown_artist', 'Unknown artist')) ?>
                        · <?= number_format((int) ($song['view_count'] ?? 0)) ?> <?= music_h(music_label('music.listen.count', 'lượt nghe')) ?>
                    </div>
                    <div class="song-card-actions">
                        <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($song['artist_names'] ?: $song['artist']) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><?= music_play_icon() ?><?= music_h(music_label('music.action.play', 'Phát')) ?></button>
                        <button class="icon-btn" title="<?= music_h(music_label('music.action.add_to_playlist', 'Thêm vào playlist')) ?>" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($song['artist_names'] ?: $song['artist']) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="section" id="genres">
    <div class="section-head">
        <div><h2><?= music_h(music_label('music.label.genres', 'Thể loại')) ?></h2><p><?= music_h(music_label('music.genres_intro', 'Khám phá nhạc theo màu sắc và mood.')) ?></p></div>
        <a class="section-view-all" href="<?= music_h(music_url('list_genre.php')) ?>"><?= music_h(music_label('action.view_all', 'Xem tất cả')) ?><i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="genre-grid">
        <?php foreach ($genres as $genre): ?>
            <?php $genreAvatar = trim((string) ($genre['avatar'] ?? '')); ?>
            <a class="genre-card site-link" href="<?= music_h(music_genre_url((string) $genre['genre_id'])) ?>">
                <?php if ($genreAvatar !== ''): ?>
                    <img src="<?= music_h(music_cover($genreAvatar)) ?>" alt="<?= music_h($genre['title'] ?: $genre['genre_id']) ?>">
                <?php else: ?>
                    <span class="genre-card-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false">
                            <path d="M5 7h9M5 12h7M5 17h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            <path d="M15 16V7l5-1v9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="13" cy="17" r="2" fill="none" stroke="currentColor" stroke-width="1.8"/>
                            <circle cx="18" cy="16" r="2" fill="none" stroke="currentColor" stroke-width="1.8"/>
                        </svg>
                    </span>
                <?php endif; ?>
                <span>
                    <strong><?= music_h($genre['title'] ?: $genre['genre_id']) ?></strong>
                </span>
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="section" id="artists">
    <div class="section-head">
        <div><h2><?= music_h(music_label('music.label.artists', 'Nghệ sĩ')) ?></h2><p><?= music_h(music_label('music.artists_intro', 'Gặp gỡ những giọng ca và tác giả đứng sau các bài hát bạn yêu thích.')) ?></p></div>
        <a class="section-view-all" href="<?= music_h(music_url('list_artist.php')) ?>"><?= music_h(music_label('action.view_all', 'Xem tất cả')) ?><i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="artist-grid">
        <?php foreach ($artists as $artist): ?>
            <a class="artist-card site-link" href="<?= music_h(music_artist_url((int) $artist['id'])) ?>">
                <img src="<?= music_h(music_cover($artist['avatar'])) ?>" alt="<?= music_h($artist['name']) ?>">
                <span><strong><?= music_h($artist['name']) ?></strong><span><?= number_format((int) $artist['song_count']) ?> <?= music_h(music_label('music.label.songs', 'bài hát')) ?></span></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($timelineYears): ?>
<section class="section" id="timeline">
    <div class="section-head">
        <div>
            <h2><?= music_h(music_label('music.timeline', 'Dòng thời gian và hoài niệm')) ?></h2>
            <p><?= music_h(music_label('music.timeline_intro', 'Lướt qua từng năm phát hành và tìm lại những giai điệu gắn với ký ức của bạn.')) ?></p>
        </div>
        <div class="timeline-controls" aria-label="<?= music_h(music_label('aria.timeline_controls', 'Timeline controls')) ?>">
            <button type="button" class="timeline-btn" data-timeline-prev aria-label="<?= music_h(music_label('aria.previous_page', 'Previous page')) ?>"><i class="fas fa-chevron-left"></i></button>
            <button type="button" class="timeline-btn" data-timeline-next aria-label="<?= music_h(music_label('aria.next_page', 'Next page')) ?>"><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
    <div class="timeline-slider" aria-label="<?= music_h(music_label('aria.song_year_timeline', 'Song year timeline')) ?>">
        <div class="timeline-viewport">
            <div class="timeline-track">
                <?php foreach ($timelineYears as $yearIndex => $yearRow): ?>
                    <?php $songYear = (int) ($yearRow['song_year'] ?? 0); ?>
                    <?php if ($songYear <= 0) continue; ?>
                    <a class="timeline-year <?= $yearIndex % 2 === 0 ? 'is-above' : 'is-below' ?> site-link" href="<?= music_h(music_song_year_url($songYear)) ?>">
                        <span class="timeline-node" aria-hidden="true"></span>
                        <span class="timeline-label"><?= number_format($songYear, 0, '', '') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>
<script>
(() => {
    const slider = document.querySelector('.hero-slider');
    if (!slider) return;
    const slides = Array.from(slider.querySelectorAll('.hero-slide'));
    const dots = Array.from(slider.querySelectorAll('.hero-slider-dots span'));
    let active = 0;
    let timer = null;

    const show = (index) => {
        active = (index + slides.length) % slides.length;
        slides.forEach((slide, slideIndex) => slide.classList.toggle('is-active', slideIndex === active));
        dots.forEach((dot, dotIndex) => dot.classList.toggle('is-active', dotIndex === active));
    };
    const start = () => {
        window.clearInterval(timer);
        timer = window.setInterval(() => show(active + 1), 5200);
    };

    slider.querySelector('[data-hero-prev]')?.addEventListener('click', () => {
        show(active - 1);
        start();
    });
    slider.querySelector('[data-hero-next]')?.addEventListener('click', () => {
        show(active + 1);
        start();
    });
    start();
})();

(() => {
    const timeline = document.querySelector('#timeline');
    if (!timeline) return;

    const viewport = timeline.querySelector('.timeline-viewport');
    const track = timeline.querySelector('.timeline-track');
    const prev = timeline.querySelector('[data-timeline-prev]');
    const next = timeline.querySelector('[data-timeline-next]');
    if (!viewport || !track || !prev || !next) return;

    let offset = 0;
    let startX = 0;
    let startOffset = 0;
    let isDragging = false;
    let didDrag = false;

    const maxOffset = () => Math.max(0, track.scrollWidth - viewport.clientWidth);
    const clamp = (value) => Math.min(Math.max(value, 0), maxOffset());
    const render = (value = offset, animate = true) => {
        offset = clamp(value);
        track.style.transition = animate ? 'transform .32s ease' : 'none';
        track.style.transform = `translateX(${-offset}px)`;
        prev.disabled = offset <= 1;
        next.disabled = offset >= maxOffset() - 1;
    };
    const pageSize = () => Math.max(120, viewport.clientWidth - 60);

    prev.addEventListener('click', () => render(offset - pageSize()));
    next.addEventListener('click', () => render(offset + pageSize()));
    viewport.addEventListener('pointerdown', (event) => {
        if (event.target.closest('.timeline-year')) return;
        isDragging = true;
        didDrag = false;
        startX = event.clientX;
        startOffset = offset;
        viewport.setPointerCapture?.(event.pointerId);
        render(offset, false);
    });
    viewport.addEventListener('pointermove', (event) => {
        if (!isDragging) return;
        if (Math.abs(event.clientX - startX) > 14) didDrag = true;
        render(startOffset - (event.clientX - startX), false);
    });
    const finishDrag = () => {
        if (!isDragging) return;
        isDragging = false;
        render(offset, true);
    };
    viewport.addEventListener('pointerup', finishDrag);
    viewport.addEventListener('pointercancel', finishDrag);
    window.addEventListener('resize', () => render(offset, false));
    render(0, false);
})();
</script>
<?php music_render_footer(); ?>
