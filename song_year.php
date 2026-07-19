<?php
require_once __DIR__ . '/includes/music.php';

$year = (int) ($_GET['year'] ?? 0);
$currentPage = max(1, (int) ($_GET['page_no'] ?? 1));
$songsPerPage = 24;
$totalSongs = 0;
$totalPages = 1;
$songs = [];
$errorMessage = $db_error ?? '';

if ($year < 1000 || $year > 9999) {
    http_response_code(404);
    music_render_header(
        music_label('music.meta.year_not_found_title', 'Không tìm thấy năm - CarrotMusic'),
        music_label('music.meta.year_not_found_description', 'Mốc năm này hiện chưa có bài hát.')
    );
    echo '<section class="section"><div class="empty">' . music_h(music_label('music.error.year_not_found', 'Không tìm thấy bài hát cho mốc năm này.')) . '</div></section>';
    music_render_footer();
    exit;
}

if ($pdo instanceof PDO) {
    try {
        $cacheKey = music_cache_key('music_song_year', [
            'year' => $year,
            'page' => $currentPage,
            'per_page' => $songsPerPage,
        ]);
        $cachedYear = music_cache_get($cacheKey, 86400);

        if (is_array($cachedYear)) {
            $songs = is_array($cachedYear['songs'] ?? null) ? $cachedYear['songs'] : [];
            $totalSongs = (int) ($cachedYear['total_songs'] ?? 0);
            $totalPages = max(1, (int) ($cachedYear['total_pages'] ?? 1));
            $currentPage = min($currentPage, $totalPages);
        } else {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM song WHERE TRIM(COALESCE(year, "")) = ?');
            $countStmt->execute([(string) $year]);
            $totalSongs = (int) $countStmt->fetchColumn();
            $totalPages = max(1, (int) ceil($totalSongs / $songsPerPage));
            $currentPage = min($currentPage, $totalPages);
            $offset = ($currentPage - 1) * $songsPerPage;

            $stmt = $pdo->prepare('
                SELECT s.*, GROUP_CONCAT(DISTINCT sa.name ORDER BY sa.name SEPARATOR ", ") AS artist_names
                FROM song s
                LEFT JOIN song_artist_map sam ON sam.song_id = s.id
                LEFT JOIN song_artist sa ON sa.id = sam.artist_id
                WHERE TRIM(COALESCE(s.year, "")) = ?
                GROUP BY s.id
                ORDER BY s.created_at DESC, s.id ASC
                LIMIT ? OFFSET ?
            ');
            $stmt->bindValue(1, (string) $year, PDO::PARAM_STR);
            $stmt->bindValue(2, $songsPerPage, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $songs = $stmt->fetchAll();

            music_cache_set($cacheKey, [
                'created_at' => date('c'),
                'total_songs' => $totalSongs,
                'total_pages' => $totalPages,
                'songs' => $songs,
            ]);
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$yearTitle = sprintf(music_label('music.year.title', 'Nhạc năm %s'), (string) $year);
music_render_header(
    $yearTitle . ' | CarrotMusic',
    sprintf(music_label('music.year.description', 'Khám phá các bài hát được phát hành trong năm %s trên CarrotMusic.'), (string) $year)
);
?>
<section class="section">
    <div class="section-head">
        <div>
            <h2><?= music_h($yearTitle) ?></h2>
            <p><?= number_format($totalSongs) ?> <?= music_h(music_label('music.label.songs', 'bài hát')) ?> · <?= music_h(music_label('music.year.intro', 'Một lát cắt âm nhạc để nghe lại những giai điệu của năm này.')) ?></p>
        </div>
        <a class="section-view-all" href="<?= music_h(music_url('index.php#timeline')) ?>"><i class="fas fa-arrow-left"></i><?= music_h(music_label('music.timeline', 'Dòng thời gian')) ?></a>
    </div>
    <?php if ($errorMessage): ?><div class="empty"><?= music_h($errorMessage) ?></div><?php endif; ?>
    <?= music_render_pagination($currentPage, $totalPages, static fn(int $page): string => music_song_year_url($year) . '&page_no=' . $page) ?>
    <div class="grid">
        <?php foreach ($songs as $song): ?>
            <?php $songArtist = $song['artist_names'] ?: $song['artist'] ?: music_label('music.label.unknown_artist', 'Unknown artist'); ?>
            <article class="song-card">
                <a class="site-link" href="<?= music_h(music_song_url((string) $song['id'])) ?>">
                    <img src="<?= music_h(music_cover($song['avatar'])) ?>" alt="<?= music_h($song['name']) ?>">
                </a>
                <div class="song-card-body">
                    <a class="song-title site-link" href="<?= music_h(music_song_url((string) $song['id'])) ?>"><?= music_h($song['name']) ?></a>
                    <div class="song-meta"><?= music_h($songArtist) ?></div>
                    <div class="song-card-actions">
                        <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($songArtist) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><?= music_play_icon() ?><?= music_h(music_label('music.action.play', 'Phát')) ?></button>
                        <button class="icon-btn" title="<?= music_h(music_label('music.action.add_to_playlist', 'Thêm vào playlist')) ?>" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($songArtist) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if (!$songs && !$errorMessage): ?><div class="empty"><?= music_h(music_label('music.year.no_songs', 'Chưa có bài hát nào trong mốc năm này.')) ?></div><?php endif; ?>
    <?= music_render_pagination($currentPage, $totalPages, static fn(int $page): string => music_song_year_url($year) . '&page_no=' . $page) ?>
</section>
<?php music_render_footer(); ?>
