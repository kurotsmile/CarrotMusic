<?php
require_once __DIR__ . '/includes/music.php';

$songId = trim((string) ($_GET['id'] ?? ''));
$song = null;
$relatedSongs = [];
$paypalConfig = music_paypal_config($pdo ?? null, 'music');
$errorMessage = $db_error ?? '';

if ($pdo instanceof PDO && $songId !== '') {
    try {
        $song = music_fetch_song($pdo, $songId);
        if ($song) {
            $relatedSongs = music_fetch_songs($pdo, 8, 's.id <> ? AND (s.genre = ? OR s.artist = ?)', [$songId, (string) ($song['genre'] ?? ''), (string) ($song['artist'] ?? '')]);
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

if (!$song) {
    http_response_code(404);
    music_render_header('Không tìm thấy bài hát - CarrotMusic', 'Bài hát không tồn tại hoặc đã bị ẩn.');
    echo '<section class="section"><div class="empty">' . music_h($errorMessage ?: 'Không tìm thấy bài hát.') . '</div></section>';
    music_render_footer();
    exit;
}

$artistName = (string) ($song['artist_names'] ?: $song['artist'] ?: 'CarrotMusic');
$price = music_song_price($song, $paypalConfig);
$canDownload = trim((string) ($song['mp3'] ?? '')) !== '' && ($price <= 0 || music_has_paid_song((string) $song['id']));
$title = (string) $song['name'] . ' - ' . $artistName . ' | CarrotMusic';
$description = music_excerpt($song['lyrics'] ?: (($song['album'] ?? '') . ' ' . ($song['genre'] ?? '')), 155);
music_render_header($title, $description, music_cover($song['avatar']));
?>
<article class="detail">
    <img class="detail-cover" src="<?= music_h(music_cover($song['avatar'])) ?>" alt="<?= music_h($song['name']) ?>">
    <div class="detail-main">
        <p class="eyebrow">Song detail</p>
        <h1><?= music_h($song['name']) ?></h1>
        <div class="detail-meta">
            <span><?= music_h($artistName) ?></span>
            <?php if (!empty($song['genre'])): ?><span><?= music_h($song['genre']) ?></span><?php endif; ?>
            <?php if (!empty($song['year'])): ?><span><?= music_h($song['year']) ?></span><?php endif; ?>
            <?php if (!empty($song['lang'])): ?><span><?= music_h($song['lang']) ?></span><?php endif; ?>
        </div>
        <div class="song-actions">
            <?php if (!empty($song['mp3'])): ?>
                <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($artistName) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>">Phát bài hát</button>
                <button class="btn" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($artistName) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>">Thêm playlist</button>
            <?php endif; ?>
            <?php if ($canDownload): ?>
                <a class="btn" href="<?= music_h($song['mp3']) ?>" download>Tải MP3</a>
            <?php elseif (!empty($song['mp3']) && $paypalConfig['enabled']): ?>
                <a class="btn" href="<?= music_h(music_url('paypal-create.php?id=' . rawurlencode($song['id']))) ?>">Mua tải MP3 · <?= music_h(number_format($price, 2) . ' ' . $paypalConfig['currency']) ?></a>
            <?php endif; ?>
            <?php if (!empty($song['link_ytb'])): ?>
                <a class="btn" href="<?= music_h($song['link_ytb']) ?>" target="_blank" rel="noopener noreferrer">YouTube</a>
            <?php endif; ?>
        </div>
        <?php if (!empty($song['mp3']) && !$canDownload && !$paypalConfig['enabled'] && $price > 0): ?>
            <div class="payment-box">Bài hát có giá <?= music_h(number_format($price, 2)) ?> nhưng PayPal CarrotMusic chưa được bật.</div>
        <?php endif; ?>
        <?php if (!empty($song['lyrics'])): ?>
            <div class="lyrics"><?= $song['lyrics'] ?></div>
        <?php endif; ?>
    </div>
</article>

<?php if ($relatedSongs): ?>
<section class="section">
    <div class="section-head"><div><h2>Bài liên quan</h2><p>Tiếp tục nghe mà không dừng player hiện tại.</p></div></div>
    <div class="grid">
        <?php foreach ($relatedSongs as $related): ?>
            <article class="song-card">
                <a class="site-link" href="<?= music_h(music_song_url($related['id'])) ?>"><img src="<?= music_h(music_cover($related['avatar'])) ?>" alt="<?= music_h($related['name']) ?>"></a>
                <div class="song-card-body">
                    <a class="song-title site-link" href="<?= music_h(music_song_url($related['id'])) ?>"><?= music_h($related['name']) ?></a>
                    <div class="song-meta"><?= music_h($related['artist_names'] ?: $related['artist']) ?></div>
                    <div class="song-card-actions">
                        <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($related['mp3']) ?>" cr-name="<?= music_h($related['name']) ?>" cr-artist="<?= music_h($related['artist_names'] ?: $related['artist']) ?>" cr-avatar="<?= music_h(music_cover($related['avatar'])) ?>">Phát</button>
                        <button class="icon-btn" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($related['mp3']) ?>" cr-name="<?= music_h($related['name']) ?>" cr-artist="<?= music_h($related['artist_names'] ?: $related['artist']) ?>" cr-avatar="<?= music_h(music_cover($related['avatar'])) ?>"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'MusicRecording',
    'name' => (string) $song['name'],
    'byArtist' => ['@type' => 'MusicGroup', 'name' => $artistName],
    'image' => music_cover($song['avatar']),
    'inAlbum' => (string) ($song['album'] ?? ''),
    'genre' => (string) ($song['genre'] ?? ''),
    'url' => music_song_url((string) $song['id']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<?php music_render_footer(); ?>
