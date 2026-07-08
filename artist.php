<?php
require_once __DIR__ . '/includes/music.php';

$artistId = (int) ($_GET['id'] ?? 0);
$artist = null;
$songs = [];
$errorMessage = $db_error ?? '';

if ($pdo instanceof PDO && $artistId > 0) {
    try {
        $artist = music_fetch_artist($pdo, $artistId);
        if ($artist) {
            $stmt = $pdo->prepare('
                SELECT s.*, GROUP_CONCAT(DISTINCT sa.name ORDER BY sa.name SEPARATOR ", ") AS artist_names
                FROM song s
                INNER JOIN song_artist_map target_map ON target_map.song_id = s.id AND target_map.artist_id = ?
                LEFT JOIN song_artist_map sam ON sam.song_id = s.id
                LEFT JOIN song_artist sa ON sa.id = sam.artist_id
                GROUP BY s.id
                ORDER BY s.created_at DESC, s.id ASC
            ');
            $stmt->execute([$artistId]);
            $songs = $stmt->fetchAll();
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

if (!$artist) {
    http_response_code(404);
    music_render_header('Không tìm thấy nghệ sĩ - CarrotMusic', 'Nghệ sĩ không tồn tại hoặc đã bị ẩn.');
    echo '<section class="section"><div class="empty">' . music_h($errorMessage ?: 'Không tìm thấy nghệ sĩ.') . '</div></section>';
    music_render_footer();
    exit;
}

$title = (string) $artist['name'] . ' - Nghệ sĩ | CarrotMusic';
$description = music_excerpt($artist['description'] ?? '', 155) ?: 'Nghe các bài hát của ' . (string) $artist['name'] . ' trên CarrotMusic.';
music_render_header($title, $description, music_cover($artist['avatar']));
?>
<article class="detail">
    <img class="detail-cover" src="<?= music_h(music_cover($artist['avatar'])) ?>" alt="<?= music_h($artist['name']) ?>">
    <div class="detail-main">
        <p class="eyebrow">Artist profile</p>
        <h1><?= music_h($artist['name']) ?></h1>
        <div class="detail-meta">
            <span><?= music_h($artist['lang_key'] ?? 'vi') ?></span>
            <span><?= number_format(count($songs)) ?> bài hát</span>
        </div>
        <?php if (!empty($artist['description'])): ?>
            <div class="lyrics"><?= nl2br(music_h($artist['description'])) ?></div>
        <?php endif; ?>
    </div>
</article>

<section class="section">
    <div class="section-head"><div><h2>Bài hát của <?= music_h($artist['name']) ?></h2><p>Phát ngay hoặc thêm vào playlist đang chạy.</p></div></div>
    <div class="grid">
        <?php foreach ($songs as $song): ?>
            <article class="song-card">
                <a class="site-link" href="<?= music_h(music_song_url($song['id'])) ?>"><img src="<?= music_h(music_cover($song['avatar'])) ?>" alt="<?= music_h($song['name']) ?>"></a>
                <div class="song-card-body">
                    <a class="song-title site-link" href="<?= music_h(music_song_url($song['id'])) ?>"><?= music_h($song['name']) ?></a>
                    <div class="song-meta"><?= music_h($song['artist_names'] ?: $song['artist']) ?></div>
                    <div class="song-card-actions">
                        <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($song['artist_names'] ?: $song['artist']) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>">Phát</button>
                        <button class="icon-btn" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($song['artist_names'] ?: $song['artist']) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if (!$songs): ?><div class="empty">Nghệ sĩ này chưa có bài hát được liên kết.</div><?php endif; ?>
</section>

<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'MusicGroup',
    'name' => (string) $artist['name'],
    'image' => music_cover($artist['avatar']),
    'description' => $description,
    'url' => music_artist_url((int) $artist['id']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<?php music_render_footer(); ?>
