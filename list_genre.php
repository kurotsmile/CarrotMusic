<?php
require_once __DIR__ . '/includes/music.php';

$genres = [];
$errorMessage = $db_error ?? '';
$currentPage = max(1, (int) ($_GET['page_no'] ?? 1));
$perPage = 48;
$totalGenres = 0;
$totalPages = 1;

if ($pdo instanceof PDO) {
    try {
        $cacheKey = music_cache_key('music_genres', [
            'page' => $currentPage,
            'per_page' => $perPage,
            'view' => 'genre_cards_v1',
        ]);
        $cachedGenres = music_cache_get($cacheKey, 86400);

        if (is_array($cachedGenres)) {
            $genres = is_array($cachedGenres['genres'] ?? null) ? $cachedGenres['genres'] : [];
            $totalGenres = (int) ($cachedGenres['total_genres'] ?? 0);
            $totalPages = max(1, (int) ($cachedGenres['total_pages'] ?? 1));
            $currentPage = min($currentPage, $totalPages);
        } else {
            $totalGenres = (int) $pdo->query('SELECT COUNT(*) FROM song_genre')->fetchColumn();
            $totalPages = max(1, (int) ceil($totalGenres / $perPage));
            $currentPage = min($currentPage, $totalPages);
            $offset = ($currentPage - 1) * $perPage;

            $stmt = $pdo->prepare('
                SELECT g.genre_id, g.title, g.avatar, g.description, COUNT(DISTINCT s.id) AS song_count
                FROM song_genre g
                LEFT JOIN song s ON FIND_IN_SET(REPLACE(g.genre_id, " ", ""), REPLACE(COALESCE(s.genre, ""), " ", "")) > 0
                GROUP BY g.genre_id, g.title, g.avatar, g.description
                ORDER BY g.title ASC, g.genre_id ASC
                LIMIT ? OFFSET ?
            ');
            $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $genres = $stmt->fetchAll();

            music_cache_set($cacheKey, [
                'created_at' => date('c'),
                'total_genres' => $totalGenres,
                'total_pages' => $totalPages,
                'genres' => $genres,
            ]);
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

music_render_header(
    music_label('music.meta.genre_list_title', 'Tất cả thể loại - CarrotMusic'),
    music_label('music.meta.genre_list_description', 'Khám phá toàn bộ thể loại nhạc trên CarrotMusic.')
);
?>
<section class="section">
    <div class="section-head">
        <div>
            <h2><?= music_h(music_label('music.section.all_genres', 'Tất cả thể loại')) ?></h2>
            <p><?= number_format($totalGenres) ?> <?= music_h(music_label('music.label.genres', 'thể loại')) ?></p>
        </div>
    </div>
    <?php if ($errorMessage): ?><div class="empty"><?= music_h($errorMessage) ?></div><?php endif; ?>
    <?= music_render_pagination($currentPage, $totalPages, static fn(int $page): string => music_url('list_genre.php?page_no=' . $page)) ?>
    <div class="genre-grid genre-grid--list">
        <?php foreach ($genres as $genre): ?>
            <?php $genreAvatar = trim((string) ($genre['avatar'] ?? '')); ?>
            <a class="genre-card genre-card--list site-link" href="<?= music_h(music_genre_url((string) $genre['genre_id'])) ?>">
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
                    <small><?= number_format((int) $genre['song_count']) ?> <?= music_h(music_label('music.label.songs', 'bài hát')) ?></small>
                </span>
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if (!$genres && !$errorMessage): ?><div class="empty"><?= music_h(music_label('music.empty.no_genres', 'Chưa có thể loại.')) ?></div><?php endif; ?>
    <?= music_render_pagination($currentPage, $totalPages, static fn(int $page): string => music_url('list_genre.php?page_no=' . $page)) ?>
</section>
<?php music_render_footer(); ?>
