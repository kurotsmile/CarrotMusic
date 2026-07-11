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
        $totalGenres = (int) $pdo->query('SELECT COUNT(*) FROM song_genre')->fetchColumn();
        $totalPages = max(1, (int) ceil($totalGenres / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;

        $stmt = $pdo->prepare('
            SELECT g.genre_id, g.title, g.description, COUNT(DISTINCT s.id) AS song_count
            FROM song_genre g
            LEFT JOIN song s ON FIND_IN_SET(REPLACE(g.genre_id, " ", ""), REPLACE(COALESCE(s.genre, ""), " ", "")) > 0
            GROUP BY g.genre_id, g.title, g.description
            ORDER BY g.title ASC, g.genre_id ASC
            LIMIT ? OFFSET ?
        ');
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $genres = $stmt->fetchAll();
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
    <div class="pill-list">
        <?php foreach ($genres as $genre): ?>
            <a class="pill site-link" href="<?= music_h(music_genre_url((string) $genre['genre_id'])) ?>"><?= music_h($genre['title'] ?: $genre['genre_id']) ?> · <?= number_format((int) $genre['song_count']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (!$genres && !$errorMessage): ?><div class="empty"><?= music_h(music_label('music.empty.no_genres', 'Chưa có thể loại.')) ?></div><?php endif; ?>
    <?= music_render_pagination($currentPage, $totalPages, static fn(int $page): string => music_url('list_genre.php?page_no=' . $page)) ?>
</section>
<?php music_render_footer(); ?>
