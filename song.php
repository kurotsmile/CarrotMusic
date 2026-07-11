<?php
require_once __DIR__ . '/includes/music.php';

$songId = trim((string) ($_GET['id'] ?? ''));
$song = null;
$songArtists = [];
$relatedSongs = [];
$paypalConfig = music_paypal_config($pdo ?? null, 'music');
$errorMessage = $db_error ?? '';

if ($pdo instanceof PDO && $songId !== '') {
    try {
        $song = music_fetch_song($pdo, $songId);
        if ($song) {
            $songArtists = music_fetch_song_artists($pdo, (string) $song['id']);
            $relatedGenreIds = music_split_genres((string) ($song['genre'] ?? ''));
            $relatedConditions = [];
            $relatedParams = [$songId];
            foreach ($relatedGenreIds as $genreIndex => $genreId) {
                $relatedConditions[] = 'FIND_IN_SET(?, REPLACE(COALESCE(s.genre, \'\'), \' \', \'\')) > 0';
                $relatedParams[] = $genreId;
            }
            $relatedConditions[] = 's.artist = ?';
            $relatedParams[] = (string) ($song['artist'] ?? '');
            $relatedSongs = music_fetch_songs($pdo, 8, 's.id <> ? AND (' . implode(' OR ', $relatedConditions) . ')', $relatedParams);
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

if (!$song) {
    http_response_code(404);
    music_render_header(music_label('music.meta.song_not_found_title', 'Không tìm thấy bài hát - CarrotMusic'), music_label('music.meta.song_not_found_description', 'Bài hát không tồn tại hoặc đã bị ẩn.'));
    echo '<section class="section"><div class="empty">' . music_h($errorMessage ?: music_label('music.error.song_not_found', 'Không tìm thấy bài hát.')) . '</div></section>';
    music_render_footer();
    exit;
}

$artistName = (string) ($song['artist_names'] ?: $song['artist'] ?: music_label('music.label.brand', 'CarrotMusic'));
$songGenreTags = music_split_genres((string) ($song['genre'] ?? ''));
$byArtist = $songArtists
    ? array_map(static fn(array $artist): array => [
        '@type' => 'MusicGroup',
        'name' => (string) $artist['name'],
        'url' => music_artist_url((int) $artist['id']),
    ], $songArtists)
    : ['@type' => 'MusicGroup', 'name' => $artistName];
$price = music_song_price($song, $paypalConfig);
$canDownload = trim((string) ($song['mp3'] ?? '')) !== '' && ($price <= 0 || music_has_paid_song((string) $song['id']));
$title = (string) $song['name'] . ' - ' . $artistName . ' | CarrotMusic';
$description = music_excerpt($song['lyrics'] ?: (($song['album'] ?? '') . ' ' . ($song['genre'] ?? '')), 155);
music_render_header($title, $description, music_cover($song['avatar']));
?>
<article class="detail">
    <img class="detail-cover" src="<?= music_h(music_cover($song['avatar'])) ?>" alt="<?= music_h($song['name']) ?>">
    <div class="detail-main">
        <p class="eyebrow"><?= music_h(music_label('music.song.eyebrow', 'Song detail')) ?></p>
        <h1><?= music_h($song['name']) ?></h1>
        <div class="detail-meta">
            <?php if (!$songArtists): ?>
                <span><?= music_h($artistName) ?></span>
            <?php else: ?>
                <?php foreach ($songArtists as $artist): ?>
                    <span><a class="artist-tag site-link" href="<?= music_h(music_artist_url((int) $artist['id'])) ?>"><?= music_h($artist['name']) ?></a></span>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php foreach ($songGenreTags as $genreTag): ?>
                <span><a class="genre-tag site-link" href="<?= music_h(music_genre_url($genreTag)) ?>"><?= music_h($genreTag) ?></a></span>
            <?php endforeach; ?>
            <?php if (!empty($song['year'])): ?><span><?= music_h($song['year']) ?></span><?php endif; ?>
            <?php if (!empty($song['lang'])): ?><span><?= music_h($song['lang']) ?></span><?php endif; ?>
        </div>
        <div class="song-actions">
            <?php if (!empty($song['mp3'])): ?>
                <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($artistName) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><?= music_play_icon() ?><?= music_h(music_label('music.action.play_song', 'Phát bài hát')) ?></button>
                <button class="btn" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($artistName) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><?= music_h(music_label('music.action.add_playlist', 'Thêm playlist')) ?></button>
            <?php endif; ?>
            <?php if ($canDownload): ?>
                <a class="btn" href="<?= music_h($song['mp3']) ?>" download><?= music_h(music_label('music.action.download_mp3', 'Tải MP3')) ?></a>
            <?php elseif (!empty($song['mp3']) && $paypalConfig['enabled']): ?>
                <a class="btn" href="<?= music_h(music_url('paypal-create.php?id=' . rawurlencode($song['id']))) ?>"><?= music_h(music_label('music.action.buy_download', 'Mua tải MP3')) ?> · <?= music_h(number_format($price, 2) . ' ' . $paypalConfig['currency']) ?></a>
            <?php endif; ?>
            <?php if (!empty($song['link_ytb'])): ?>
                <a class="btn" href="<?= music_h($song['link_ytb']) ?>" target="_blank" rel="noopener noreferrer">
                    <svg width="24px" height="24px" viewBox="0 -7 48 48" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" fill="#000000"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <title>Youtube-color</title> <desc>Created with Sketch.</desc> <defs> </defs> <g id="Icons" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd"> <g id="Color-" transform="translate(-200.000000, -368.000000)" fill="#CE1312"> <path d="M219.044,391.269916 L219.0425,377.687742 L232.0115,384.502244 L219.044,391.269916 Z M247.52,375.334163 C247.52,375.334163 247.0505,372.003199 245.612,370.536366 C243.7865,368.610299 241.7405,368.601235 240.803,368.489448 C234.086,368 224.0105,368 224.0105,368 L223.9895,368 C223.9895,368 213.914,368 207.197,368.489448 C206.258,368.601235 204.2135,368.610299 202.3865,370.536366 C200.948,372.003199 200.48,375.334163 200.48,375.334163 C200.48,375.334163 200,379.246723 200,383.157773 L200,386.82561 C200,390.73817 200.48,394.64922 200.48,394.64922 C200.48,394.64922 200.948,397.980184 202.3865,399.447016 C204.2135,401.373084 206.612,401.312658 207.68,401.513574 C211.52,401.885191 224,402 224,402 C224,402 234.086,401.984894 240.803,401.495446 C241.7405,401.382148 243.7865,401.373084 245.612,399.447016 C247.0505,397.980184 247.52,394.64922 247.52,394.64922 C247.52,394.64922 248,390.73817 248,386.82561 L248,383.157773 C248,379.246723 247.52,375.334163 247.52,375.334163 L247.52,375.334163 Z" id="Youtube"> </path> </g> </g> </g></svg>
                    YouTube
                </a>
            <?php endif; ?>
        </div>
        <?php if (!empty($song['mp3'])): ?>
            <div class="wave-box">
                <button class="wave-play" type="button" aria-label="<?= music_h(music_label('music.action.play_song', 'Phát bài hát')) ?>">
                    <?= music_play_icon() ?>
                </button>
                <div class="wave-main">
                    <div class="wave-title"><?= music_h(music_label('music.song.waveform', 'Sóng âm thanh')) ?></div>
                    <div id="song_waveform" class="song-waveform"></div>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($song['mp3']) && !$canDownload && !$paypalConfig['enabled'] && $price > 0): ?>
            <div class="payment-box"><?= music_h(sprintf(music_label('music.song.paypal_disabled', 'Bài hát có giá %s nhưng PayPal CarrotMusic chưa được bật.'), number_format($price, 2))) ?></div>
        <?php endif; ?>

        <?php
        $lyrics = $song['lyrics'] ?? '';
        ?>

        <?php if ($lyrics): ?>
        <div class="lyrics">
        <?=
            $lyrics !== strip_tags($lyrics)
                ? $lyrics
                : nl2br(htmlspecialchars($lyrics, ENT_QUOTES, 'UTF-8'));
        ?>
        </div>
        <?php endif; ?>
    </div>
</article>

<?php if ($relatedSongs): ?>
<section class="section">
    <div class="section-head"><div><h2><?= music_h(music_label('music.section.related_songs', 'Bài liên quan')) ?></h2><p><?= music_h(music_label('music.section.related_songs_intro', 'Tiếp tục nghe mà không dừng player hiện tại.')) ?></p></div></div>
    <div class="grid">
        <?php foreach ($relatedSongs as $related): ?>
            <article class="song-card">
                <a class="site-link" href="<?= music_h(music_song_url($related['id'])) ?>"><img src="<?= music_h(music_cover($related['avatar'])) ?>" alt="<?= music_h($related['name']) ?>"></a>
                <div class="song-card-body">
                    <a class="song-title site-link" href="<?= music_h(music_song_url($related['id'])) ?>"><?= music_h($related['name']) ?></a>
                    <div class="song-meta"><?= music_h($related['artist_names'] ?: $related['artist']) ?></div>
                    <div class="song-card-actions">
                        <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($related['mp3']) ?>" cr-name="<?= music_h($related['name']) ?>" cr-artist="<?= music_h($related['artist_names'] ?: $related['artist']) ?>" cr-avatar="<?= music_h(music_cover($related['avatar'])) ?>"><?= music_play_icon() ?><?= music_h(music_label('music.action.play', 'Phát')) ?></button>
                        <button class="icon-btn" title="<?= music_h(music_label('music.action.add_to_playlist', 'Thêm vào playlist')) ?>" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($related['mp3']) ?>" cr-name="<?= music_h($related['name']) ?>" cr-artist="<?= music_h($related['artist_names'] ?: $related['artist']) ?>" cr-avatar="<?= music_h(music_cover($related['avatar'])) ?>"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($song['mp3'])): ?>
<script src="https://unpkg.com/wavesurfer.js@7/dist/wavesurfer.min.js"></script>
<script>
(() => {
    const container = document.getElementById('song_waveform');
    const playButton = document.querySelector('.wave-play');
    if (!container || !playButton || !window.WaveSurfer) return;

    const wave = WaveSurfer.create({
        container,
        url: <?= json_encode((string) $song['mp3'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        height: 86,
        barWidth: 3,
        barGap: 2,
        barRadius: 3,
        waveColor: '#ffb36f',
        progressColor: '#ff6a00',
        cursorColor: '#e11d1d',
        cursorWidth: 2,
        normalize: true,
    });

    playButton.addEventListener('click', () => wave.playPause());
    wave.on('play', () => playButton.classList.add('is-playing'));
    wave.on('pause', () => playButton.classList.remove('is-playing'));
    wave.on('finish', () => playButton.classList.remove('is-playing'));
})();
</script>
<?php endif; ?>

<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'MusicRecording',
    'name' => (string) $song['name'],
    'byArtist' => $byArtist,
    'image' => music_cover($song['avatar']),
    'inAlbum' => (string) ($song['album'] ?? ''),
    'genre' => $songGenreTags ?: (string) ($song['genre'] ?? ''),
    'url' => music_song_url((string) $song['id']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<?php music_render_footer(); ?>
