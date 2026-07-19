<?php
require_once __DIR__ . '/includes/music.php';

$artists = [];
$errorMessage = $db_error ?? '';
$currentPage = max(1, (int) ($_GET['page_no'] ?? 1));
$perPage = 48;
$totalArtists = 0;
$totalPages = 1;

if ($pdo instanceof PDO) {
    try {
        $cacheKey = music_cache_key('music_artists', [
            'page' => $currentPage,
            'per_page' => $perPage,
        ]);
        $cachedArtists = music_cache_get($cacheKey, 86400);

        if (is_array($cachedArtists)) {
            $artists = is_array($cachedArtists['artists'] ?? null) ? $cachedArtists['artists'] : [];
            $totalArtists = (int) ($cachedArtists['total_artists'] ?? 0);
            $totalPages = max(1, (int) ($cachedArtists['total_pages'] ?? 1));
            $currentPage = min($currentPage, $totalPages);
        } else {
            $totalArtists = (int) $pdo->query('SELECT COUNT(*) FROM song_artist')->fetchColumn();
            $totalPages = max(1, (int) ceil($totalArtists / $perPage));
            $currentPage = min($currentPage, $totalPages);
            $offset = ($currentPage - 1) * $perPage;

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

music_render_header(
    music_label('music.meta.artist_list_title', 'Tất cả nghệ sĩ - CarrotMusic'),
    music_label('music.meta.artist_list_description', 'Khám phá toàn bộ nghệ sĩ trên CarrotMusic.')
);
?>
<section class="section">
    <div class="section-head">
        <div>
            <h2><?= music_h(music_label('music.all_artists', 'All artists')) ?></h2>
            <p><?= number_format($totalArtists) ?> <?= music_h(music_label('music.label.artists', 'nghệ sĩ')) ?></p>
        </div>
    </div>
    <?php if ($errorMessage): ?><div class="empty"><?= music_h($errorMessage) ?></div><?php endif; ?>
    <?= music_render_pagination($currentPage, $totalPages, static fn(int $page): string => music_url('list_artist.php?page_no=' . $page)) ?>
    <div class="artist-grid">
        <?php foreach ($artists as $artist): ?>
            <a class="artist-card site-link" href="<?= music_h(music_artist_url((int) $artist['id'])) ?>">
                <img src="<?= music_h(music_cover($artist['avatar'])) ?>" alt="<?= music_h($artist['name']) ?>">
                <span><strong><?= music_h($artist['name']) ?></strong><span><?= number_format((int) $artist['song_count']) ?> <?= music_h(music_label('music.label.songs', 'bài hát')) ?></span></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if (!$artists && !$errorMessage): ?><div class="empty"><?= music_h(music_label('music.empty.no_artists', 'Chưa có nghệ sĩ.')) ?></div><?php endif; ?>
    <?= music_render_pagination($currentPage, $totalPages, static fn(int $page): string => music_url('list_artist.php?page_no=' . $page)) ?>
</section>
<?php music_render_footer(); ?>
