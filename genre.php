<?php
require_once __DIR__ . '/includes/music.php';

$genreId = trim((string) ($_GET['id'] ?? ''));
$genre = null;
$songs = [];
$currentPage = max(1, (int) ($_GET['page_no'] ?? 1));
$songsPerPage = 24;
$totalSongs = 0;
$totalPages = 1;
$errorMessage = $db_error ?? '';

$renderGenrePagination = static function (string $genreId, int $currentPage, int $totalPages): string {
    if ($totalPages <= 1) {
        return '';
    }

    $items = [];
    $pageUrl = static fn(int $page): string => music_genre_url($genreId) . '&page_no=' . $page;
    $items[] = '<a class="pagination-link' . ($currentPage <= 1 ? ' is-disabled' : '') . '" href="' . music_h($pageUrl(max(1, $currentPage - 1))) . '" aria-label="' . music_h(music_label('aria.previous_page', 'Previous page')) . '">&lsaquo;</a>';

    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    if ($start > 1) {
        $items[] = '<a class="pagination-link" href="' . music_h($pageUrl(1)) . '">1</a>';
        if ($start > 2) {
            $items[] = '<span class="pagination-ellipsis">...</span>';
        }
    }
    for ($page = $start; $page <= $end; $page++) {
        $items[] = '<a class="pagination-link' . ($page === $currentPage ? ' is-active' : '') . '" href="' . music_h($pageUrl($page)) . '">' . number_format($page) . '</a>';
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $items[] = '<span class="pagination-ellipsis">...</span>';
        }
        $items[] = '<a class="pagination-link" href="' . music_h($pageUrl($totalPages)) . '">' . number_format($totalPages) . '</a>';
    }

    $items[] = '<a class="pagination-link' . ($currentPage >= $totalPages ? ' is-disabled' : '') . '" href="' . music_h($pageUrl(min($totalPages, $currentPage + 1))) . '" aria-label="' . music_h(music_label('aria.next_page', 'Next page')) . '">&rsaquo;</a>';

    return '<nav class="music-pagination" aria-label="' . music_h(music_label('aria.pagination', 'Pagination')) . '">' . implode('', $items) . '</nav>';
};

if ($pdo instanceof PDO && $genreId !== '') {
    try {
        $genre = music_fetch_genre($pdo, $genreId);
        if ($genre) {
            $totalSongs = (int) ($genre['song_count'] ?? 0);
            $totalPages = max(1, (int) ceil($totalSongs / $songsPerPage));
            $currentPage = min($currentPage, $totalPages);
            $offset = ($currentPage - 1) * $songsPerPage;
            $stmt = $pdo->prepare('
                SELECT s.*, GROUP_CONCAT(DISTINCT sa.name ORDER BY sa.name SEPARATOR ", ") AS artist_names
                FROM song s
                LEFT JOIN song_artist_map sam ON sam.song_id = s.id
                LEFT JOIN song_artist sa ON sa.id = sam.artist_id
                WHERE FIND_IN_SET(REPLACE(?, " ", ""), REPLACE(COALESCE(s.genre, ""), " ", "")) > 0
                GROUP BY s.id
                ORDER BY s.created_at DESC, s.id ASC
                LIMIT ? OFFSET ?
            ');
            $stmt->bindValue(1, $genreId, PDO::PARAM_STR);
            $stmt->bindValue(2, $songsPerPage, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $songs = $stmt->fetchAll();
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

if (!$genre) {
    http_response_code(404);
    music_render_header(music_label('music.meta.genre_not_found_title', 'Không tìm thấy thể loại - CarrotMusic'), music_label('music.meta.genre_not_found_description', 'Thể loại không tồn tại hoặc chưa có bài hát.'));
    echo '<section class="section"><div class="empty">' . music_h($errorMessage ?: music_label('music.error.genre_not_found', 'Không tìm thấy thể loại.')) . '</div></section>';
    music_render_footer();
    exit;
}

$genreTitle = trim((string) ($genre['title'] ?? '')) ?: (string) ($genre['genre_id'] ?? $genreId);
$description = music_excerpt($genre['description'] ?? '', 155);
if ($description === '') {
    $description = sprintf(music_label('music.genre.default_description', 'Khám phá các bài hát thuộc thể loại %s trên CarrotMusic.'), $genreTitle);
}

music_render_header($genreTitle . ' - ' . music_label('music.genre.role', 'Thể loại') . ' | CarrotMusic', $description);
?>
<article class="detail">
    <img class="detail-cover" src="<?= music_h(music_url('cr_player/song.png')) ?>" alt="<?= music_h($genreTitle) ?>">
    <div class="detail-main">
        <p class="eyebrow"><?= music_h(music_label('music.genre.eyebrow', 'Genre detail')) ?></p>
        <h1><?= music_h($genreTitle) ?></h1>
        <div class="detail-meta">
            <span><?= music_h($genre['genre_id'] ?? $genreId) ?></span>
            <span><?= number_format($totalSongs) ?> <?= music_h(music_label('music.label.songs', 'bài hát')) ?></span>
        </div>
        <?php if (!empty($genre['description'])): ?>
            <div class="lyrics">
                <?= $genre['description']; ?>
            </div>
        <?php endif; ?>
    </div>
</article>

<section class="section">
    <div class="section-head">
        <div>
            <h2><?= music_h(sprintf(music_label('music.genre.songs_heading', 'Bài hát thuộc %s'), $genreTitle)) ?></h2>
            <p><?= music_h(music_label('music.genre.songs_intro', 'Phát ngay hoặc thêm vào playlist đang chạy.')) ?></p>
        </div>
    </div>
    <?= $renderGenrePagination((string) ($genre['genre_id'] ?? $genreId), $currentPage, $totalPages) ?>
    <div class="grid">
        <?php foreach ($songs as $song): ?>
            <?php $songArtist = $song['artist_names'] ?: $song['artist']; ?>
            <article class="song-card">
                <a class="site-link" href="<?= music_h(music_song_url($song['id'])) ?>"><img src="<?= music_h(music_cover($song['avatar'])) ?>" alt="<?= music_h($song['name']) ?>"></a>
                <div class="song-card-body">
                    <a class="song-title site-link" href="<?= music_h(music_song_url($song['id'])) ?>"><?= music_h($song['name']) ?></a>
                    <div class="song-meta"><?= music_h($songArtist) ?></div>
                    <div class="song-card-actions">
                        <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($songArtist) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><?= music_play_icon() ?><?= music_h(music_label('music.action.play', 'Phát')) ?></button>
                        <button class="icon-btn" title="<?= music_h(music_label('music.action.add_to_playlist', 'Thêm vào playlist')) ?>" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($songArtist) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if (!$songs): ?><div class="empty"><?= music_h(music_label('music.genre.no_songs', 'Thể loại này chưa có bài hát.')) ?></div><?php endif; ?>
    <?= $renderGenrePagination((string) ($genre['genre_id'] ?? $genreId), $currentPage, $totalPages) ?>
</section>

<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $genreTitle,
    'description' => $description,
    'url' => music_genre_url((string) ($genre['genre_id'] ?? $genreId)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<?php music_render_footer(); ?>
