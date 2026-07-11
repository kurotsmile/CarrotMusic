<?php
require_once __DIR__ . '/includes/music.php';

$pageSlug = trim((string) ($_GET['page'] ?? $_GET['slug'] ?? ''));
if ($pageSlug !== '') {
    include __DIR__ . '/page.php';
    exit;
}

$songs = [];
$genres = [];
$artists = [];
$featured = null;
$stats = ['songs' => 0, 'artists' => 0, 'genres' => 0];
$errorMessage = $db_error ?? '';
$searchQuery = trim((string) ($_GET['q'] ?? ''));

if ($pdo instanceof PDO) {
    try {
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
            $songs = music_fetch_songs($pdo, 36);
        }
        $featured = $songs[0] ?? null;
        $stats['songs'] = (int) $pdo->query('SELECT COUNT(*) FROM song')->fetchColumn();
        $stats['artists'] = (int) $pdo->query('SELECT COUNT(*) FROM song_artist')->fetchColumn();
        $stats['genres'] = (int) $pdo->query('SELECT COUNT(*) FROM song_genre')->fetchColumn();
        $genres = $pdo->query('
            SELECT g.genre_id, g.title, COUNT(DISTINCT s.id) AS song_count
            FROM song_genre g
            LEFT JOIN song s ON FIND_IN_SET(REPLACE(g.genre_id, " ", ""), REPLACE(COALESCE(s.genre, ""), " ", "")) > 0
            GROUP BY g.genre_id, g.title
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
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$pageTitle = $searchQuery !== ''
    ? sprintf(music_label('music.meta.search_title', 'Tìm "%s" - CarrotMusic'), $searchQuery)
    : music_label('music.meta.home_title', 'CarrotMusic - Store Music, nghe online và tải MP3');
music_render_header($pageTitle, music_label('music.meta.home_description', 'Cổng phân phối âm nhạc CarrotMusic: nghe online, thêm playlist và mua tải MP3 bằng PayPal.'), music_cover($featured['avatar'] ?? ''));
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
        <p class="eyebrow"><?= music_h(music_label('music.hero.eyebrow', 'Music distribution platform')) ?></p>
        <h1><?= music_h(music_label('music.hero.title', 'Nghe nhạc online, tạo playlist và tải MP3.')) ?></h1>
        <p><?= music_h(music_label('music.hero.description', 'CarrotMusic gom các bài hát trong hệ sinh thái Carrot, hỗ trợ nghe thử tức thì bằng cr_player và thanh toán PayPal để tải file MP3.')) ?></p>
        <div class="hero-stats" aria-label="<?= music_h(music_label('aria.music_stats', 'Music statistics')) ?>">
            <a class="hero-stat" href="<?= music_h(music_url('index.php')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18V5l10-2v13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="6" cy="18" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="16" cy="16" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                <span><strong><?= number_format($stats['songs']) ?></strong><small><?= music_h(music_label('music.label.songs', 'bài hát')) ?></small></span>
            </a>
            <a class="hero-stat" href="<?= music_h(music_url('list_artist.php')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21a8 8 0 0 1 16 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <span><strong><?= number_format($stats['artists']) ?></strong><small><?= music_h(music_label('music.label.artists', 'nghệ sĩ')) ?></small></span>
            </a>
            <a class="hero-stat" href="<?= music_h(music_url('list_genre.php')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h10M4 17h7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="m17 14 3 3-3 3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span><strong><?= number_format($stats['genres']) ?></strong><small><?= music_h(music_label('music.label.genres', 'thể loại')) ?></small></span>
            </a>
        </div>
        <?php if ($featured): ?>
            <div class="hero-actions">
                <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($featured['mp3']) ?>" cr-name="<?= music_h($featured['name']) ?>" cr-artist="<?= music_h($featured['artist_names'] ?: $featured['artist']) ?>" cr-avatar="<?= music_h(music_cover($featured['avatar'])) ?>"><?= music_play_icon() ?><?= music_h(music_label('music.action.play_featured', 'Phát nổi bật')) ?></button>
                <a class="btn btn-ghost site-link" href="<?= music_h(music_song_url($featured['id'])) ?>"><?= music_h(music_label('music.action.view_detail', 'Xem chi tiết')) ?></a>
            </div>
        <?php endif; ?>
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
            <h2><?= music_h($searchQuery !== '' ? music_label('music.section.search_results', 'Kết quả tìm kiếm') : music_label('music.section.new_songs', 'Bài hát mới')) ?></h2>
            <p><?= music_h($searchQuery !== '' ? sprintf(music_label('music.section.search_hint', 'Từ khóa: "%s"'), $searchQuery) : music_label('music.section.new_songs_intro', 'Phát một bài, hoặc thêm vào playlist đang nghe.')) ?></p>
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
    <?php if (!$songs && !$errorMessage): ?><div class="empty"><?= music_h(music_label('music.empty.no_songs', 'Chưa có bài hát trong database.')) ?></div><?php endif; ?>
</section>

<section class="section" id="genres">
    <div class="section-head">
        <div><h2><?= music_h(music_label('music.section.genres', 'Thể loại')) ?></h2><p><?= music_h(music_label('music.section.genres_intro', 'Khám phá nhạc theo màu sắc và mood.')) ?></p></div>
        <a class="section-view-all" href="<?= music_h(music_url('list_genre.php')) ?>"><?= music_h(music_label('music.action.view_all', 'Xem tất cả')) ?><i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="pill-list">
        <?php foreach ($genres as $genre): ?>
            <a class="pill site-link" href="<?= music_h(music_genre_url((string) $genre['genre_id'])) ?>"><?= music_h($genre['title'] ?: $genre['genre_id']) ?> · <?= number_format((int) $genre['song_count']) ?></a>
        <?php endforeach; ?>
    </div>
</section>

<section class="section" id="artists">
    <div class="section-head">
        <div><h2><?= music_h(music_label('music.section.artists', 'Nghệ sĩ')) ?></h2><p><?= music_h(music_label('music.section.artists_intro', 'Hồ sơ nghệ sĩ được quản lý từ CarrotAdmin.')) ?></p></div>
        <a class="section-view-all" href="<?= music_h(music_url('list_artist.php')) ?>"><?= music_h(music_label('music.action.view_all', 'Xem tất cả')) ?><i class="fas fa-arrow-right"></i></a>
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
</script>
<?php music_render_footer(); ?>
