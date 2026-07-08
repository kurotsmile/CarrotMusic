<?php
require_once __DIR__ . '/includes/music.php';

$songs = [];
$genres = [];
$artists = [];
$featured = null;
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
        $genres = $pdo->query('
            SELECT COALESCE(g.genre_id, s.genre) AS genre_id, COALESCE(g.title, s.genre) AS title, COUNT(s.id) AS song_count
            FROM song s
            LEFT JOIN song_genre g ON g.genre_id = s.genre
            WHERE TRIM(COALESCE(s.genre, "")) <> ""
            GROUP BY COALESCE(g.genre_id, s.genre), COALESCE(g.title, s.genre)
            ORDER BY song_count DESC, title ASC
            LIMIT 18
        ')->fetchAll();
        if ($searchQuery !== '') {
            $artistStmt = $pdo->prepare('
                SELECT sa.*, COUNT(sam.song_id) AS song_count
                FROM song_artist sa
                LEFT JOIN song_artist_map sam ON sam.artist_id = sa.id
                WHERE sa.name LIKE ?
                GROUP BY sa.id
                ORDER BY song_count DESC, sa.name ASC
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
                ORDER BY song_count DESC, sa.name ASC
                LIMIT 16
            ')->fetchAll();
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$pageTitle = $searchQuery !== ''
    ? 'Tìm "' . $searchQuery . '" - CarrotMusic'
    : 'CarrotMusic - Store Music, nghe online và tải MP3';
music_render_header($pageTitle, 'Cổng phân phối âm nhạc CarrotMusic: nghe online, thêm playlist và mua tải MP3 bằng PayPal.', music_cover($featured['avatar'] ?? ''));
?>
<section class="hero">
    <div>
        <p class="eyebrow">Music distribution platform</p>
        <h1>Nghe nhạc online, tạo playlist và tải MP3.</h1>
        <p>CarrotMusic gom các bài hát trong hệ sinh thái Carrot, hỗ trợ nghe thử tức thì bằng cr_player và thanh toán PayPal để tải file MP3.</p>
        <?php if ($featured): ?>
            <div class="hero-actions">
                <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($featured['mp3']) ?>" cr-name="<?= music_h($featured['name']) ?>" cr-artist="<?= music_h($featured['artist_names'] ?: $featured['artist']) ?>" cr-avatar="<?= music_h(music_cover($featured['avatar'])) ?>">Phát nổi bật</button>
                <a class="btn btn-ghost site-link" href="<?= music_h(music_song_url($featured['id'])) ?>">Xem chi tiết</a>
            </div>
        <?php endif; ?>
    </div>
    <div class="hero-cover">
        <?php if ($featured): ?>
            <img src="<?= music_h(music_cover($featured['avatar'])) ?>" alt="<?= music_h($featured['name']) ?>">
            <div class="hero-cover-info">
                <strong><?= music_h($featured['name']) ?></strong>
                <span><?= music_h($featured['artist_names'] ?: $featured['artist'] ?: 'CarrotMusic') ?></span>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="section">
    <div class="section-head">
        <div>
            <h2><?= $searchQuery !== '' ? 'Kết quả tìm kiếm' : 'Bài hát mới' ?></h2>
            <p><?= $searchQuery !== '' ? 'Từ khóa: "' . music_h($searchQuery) . '"' : 'Phát một bài, hoặc thêm vào playlist đang nghe.' ?></p>
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
                    <div class="song-meta"><?= music_h($song['artist_names'] ?: $song['artist'] ?: 'Unknown artist') ?></div>
                    <div class="song-card-actions">
                        <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($song['artist_names'] ?: $song['artist']) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>">Phát</button>
                        <button class="icon-btn" title="Thêm vào playlist" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($song['artist_names'] ?: $song['artist']) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if (!$songs && !$errorMessage): ?><div class="empty">Chưa có bài hát trong database.</div><?php endif; ?>
</section>

<section class="section" id="genres">
    <div class="section-head"><div><h2>Thể loại</h2><p>Khám phá nhạc theo màu sắc và mood.</p></div></div>
    <div class="pill-list">
        <?php foreach ($genres as $genre): ?>
            <span class="pill"><?= music_h($genre['title'] ?: $genre['genre_id']) ?> · <?= number_format((int) $genre['song_count']) ?></span>
        <?php endforeach; ?>
    </div>
</section>

<section class="section" id="artists">
    <div class="section-head"><div><h2>Nghệ sĩ</h2><p>Hồ sơ nghệ sĩ được quản lý từ CarrotAdmin.</p></div></div>
    <div class="artist-grid">
        <?php foreach ($artists as $artist): ?>
            <a class="artist-card site-link" href="<?= music_h(music_artist_url((int) $artist['id'])) ?>">
                <img src="<?= music_h(music_cover($artist['avatar'])) ?>" alt="<?= music_h($artist['name']) ?>">
                <span><strong><?= music_h($artist['name']) ?></strong><span><?= number_format((int) $artist['song_count']) ?> bài hát</span></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php music_render_footer(); ?>
