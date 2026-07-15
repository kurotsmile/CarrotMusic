<?php
require_once __DIR__ . '/includes/music.php';

$artistId = (int) ($_GET['id'] ?? 0);
$artist = null;
$songs = [];
$errorMessage = $db_error ?? '';

if ($pdo instanceof PDO && $artistId > 0) {
    try {
        $cacheKey = music_cache_key('music_artist_detail', [
            'id' => $artistId,
        ]);
        $cachedArtist = music_cache_get($cacheKey, 86400);

        if (is_array($cachedArtist)) {
            $artist = is_array($cachedArtist['artist'] ?? null) ? $cachedArtist['artist'] : null;
            $songs = is_array($cachedArtist['songs'] ?? null) ? $cachedArtist['songs'] : [];
        } else {
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

                music_cache_set($cacheKey, [
                    'created_at' => date('c'),
                    'artist' => $artist,
                    'songs' => $songs,
                ]);
            }
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

if (!$artist) {
    http_response_code(404);
    music_render_header(music_label('music.meta.artist_not_found_title', 'Không tìm thấy nghệ sĩ - CarrotMusic'), music_label('music.meta.artist_not_found_description', 'Nghệ sĩ không tồn tại hoặc đã bị ẩn.'));
    echo '<section class="section"><div class="empty">' . music_h($errorMessage ?: music_label('music.error.artist_not_found', 'Không tìm thấy nghệ sĩ.')) . '</div></section>';
    music_render_footer();
    exit;
}

$title = (string) $artist['name'] . ' - ' . music_label('music.artist.role', 'Nghệ sĩ') . ' | CarrotMusic';
$description = music_excerpt($artist['description'] ?? '', 155);
if ($description === '') {
    $description = sprintf(music_label('music.artist.default_description', 'Nghe các bài hát của %s trên CarrotMusic.'), (string) $artist['name']);
}
music_render_header($title, $description, music_cover($artist['avatar']));
?>
<article class="detail">
    <img class="detail-cover" src="<?= music_h(music_cover($artist['avatar'])) ?>" alt="<?= music_h($artist['name']) ?>">
    <div class="detail-main">
        <p class="eyebrow"><?= music_h(music_label('music.artist.eyebrow', 'Artist profile')) ?></p>
        <h1><?= music_h($artist['name']) ?></h1>
        <div class="detail-meta">
            <span><?= music_h($artist['lang_key'] ?? 'vi') ?></span>
            <span><?= number_format(count($songs)) ?> <?= music_h(music_label('music.label.songs', 'bài hát')) ?></span>
        </div>
        <div class="song-actions">
            <?= music_detail_action_buttons((string) $artist['name'], music_artist_url((int) $artist['id'])) ?>
        </div>
        <?php if (!empty($artist['description'])): ?>
            <div class="lyrics">
                <?= $artist['description']; ?>
                <!--nl2br(music_h($artist['description']))-->
            </div>
        <?php endif; ?>
    </div>
</article>

<section class="section">
    <div class="section-head">
        <div>
            <h2><?= music_h(sprintf(music_label('music.artist.songs_heading', 'Bài hát của %s'), $artist['name'])) ?></h2>
            <p><?= music_h(music_label('music.artist.songs_intro', 'Nghe các bài hát nổi bật và lưu lại những giai điệu bạn muốn phát tiếp.')) ?></p>
        </div>
        <?php if ($songs): ?>
            <button class="btn btn-primary js-add-artist-songs" type="button">
                <i class="fas fa-plus"></i>
                <?= music_h(music_label('music.action.add_all_to_playlist', 'Thêm tất cả vào Playlist')) ?>
            </button>
        <?php endif; ?>
    </div>
    <div class="grid">
        <?php foreach ($songs as $song): ?>
            <article class="song-card">
                <a class="site-link" href="<?= music_h(music_song_url($song['id'])) ?>"><img src="<?= music_h(music_cover($song['avatar'])) ?>" alt="<?= music_h($song['name']) ?>"></a>
                <div class="song-card-body">
                    <a class="song-title site-link" href="<?= music_h(music_song_url($song['id'])) ?>"><?= music_h($song['name']) ?></a>
                    <div class="song-meta"><?= music_h($song['artist_names'] ?: $song['artist']) ?></div>
                    <div class="song-card-actions">
                        <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($song['artist_names'] ?: $song['artist']) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><?= music_play_icon() ?><?= music_h(music_label('music.action.play', 'Phát')) ?></button>
                        <button class="icon-btn js-artist-song-add" title="<?= music_h(music_label('music.action.add_to_playlist', 'Thêm vào playlist')) ?>" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($song['artist_names'] ?: $song['artist']) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if (!$songs): ?><div class="empty"><?= music_h(music_label('music.artist.no_songs', 'Nghệ sĩ này chưa có bài hát được liên kết.')) ?></div><?php endif; ?>
</section>

<script>
document.querySelector('.js-add-artist-songs')?.addEventListener('click', async () => {
    const songButtons = Array.from(document.querySelectorAll('.js-artist-song-add')).filter((button) => button.getAttribute('cr-url'));
    const wasEmpty = cr_player.list_song.length === 0;
    songButtons.forEach((button) => {
        cr_player.list_song.push({
            name: button.getAttribute('cr-name') || '',
            mp3: button.getAttribute('cr-url') || '',
            url: button.getAttribute('cr-url') || '',
            artist: button.getAttribute('cr-artist') || '',
            album: button.getAttribute('cr-artist') || '',
            avatar: button.getAttribute('cr-avatar') || '',
            youtube: button.getAttribute('cr-youtube') || '',
            is_live: button.getAttribute('cr-live') === '1',
        });
    });
    if (wasEmpty && cr_player.list_song.length > 0) {
        cr_player.play_by_index(0);
    } else {
        cr_player.uiPlayer();
    }
    await Swal.fire({
        icon: 'success',
        title: <?= json_encode(music_label('music.action.add_all_to_playlist', 'Thêm tất cả vào Playlist'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        text: `${songButtons.length} ${<?= json_encode(music_label('music.label.songs', 'bài hát'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>}`,
        timer: 1400,
        showConfirmButton: false,
    });
});
</script>

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
