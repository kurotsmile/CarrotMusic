<?php
require_once __DIR__ . '/includes/music.php';

$songId = trim((string) ($_GET['id'] ?? ''));
$song = null;
$songArtists = [];
$relatedSongs = [];
$songViewCount = 0;
$paypalConfig = music_paypal_config($pdo ?? null, 'music');
$errorMessage = $db_error ?? '';

if ($pdo instanceof PDO && $songId !== '') {
    try {
        $song = music_fetch_song($pdo, $songId);
        if ($song) {
            music_track_song_view($pdo, (string) $song['id']);
            $songViewCount = music_song_view_count($pdo, (string) $song['id']);
            $songArtists = music_fetch_song_artists($pdo, (string) $song['id']);
            $relatedGenreIds = music_split_genres((string) ($song['genre'] ?? ''));
            $relatedConditions = [];
            $relatedParams = [$songId];
            $songLang = trim((string) ($song['lang'] ?? ''));
            foreach ($relatedGenreIds as $genreIndex => $genreId) {
                $relatedConditions[] = 'FIND_IN_SET(?, REPLACE(COALESCE(s.genre, \'\'), \' \', \'\')) > 0';
                $relatedParams[] = $genreId;
            }
            $relatedConditions[] = 's.artist = ?';
            $relatedParams[] = (string) ($song['artist'] ?? '');
            $relatedWhere = 's.id <> ? AND (' . implode(' OR ', $relatedConditions) . ')';
            if ($songLang !== '') {
                $relatedWhere .= ' AND TRIM(COALESCE(s.lang, \'\')) = ?';
                $relatedParams[] = $songLang;
            }
            $relatedSongs = music_fetch_songs($pdo, 14, $relatedWhere, $relatedParams);
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

if (!$song) {
    http_response_code(404);
    music_render_header(music_label('music.meta.song_not_found_title', 'Không tìm thấy bài hát - ' . music_brand_name()), music_label('music.meta.song_not_found_description', 'Bài hát không tồn tại hoặc đã bị ẩn.'));
    echo '<section class="section"><div class="empty">' . music_h($errorMessage ?: music_label('music.error.song_not_found', 'Không tìm thấy bài hát.')) . '</div></section>';
    music_render_footer();
    exit;
}

music_redirect_to_canonical(music_song_url((string) $song['id']), ['id']);

$artistName = (string) ($song['artist_names'] ?: $song['artist'] ?: music_label('music.label.brand', music_brand_name()));
$songGenreTags = music_split_genres((string) ($song['genre'] ?? ''));
$songAlbum = trim((string) ($song['album'] ?? ''));
$songYear = trim((string) ($song['year'] ?? ''));
$songLang = trim((string) ($song['lang'] ?? ''));
$songYoutubeId = music_youtube_video_id((string) ($song['link_ytb'] ?? ''));
$songCountryCode = '';
$songCountryName = '';
if ($songLang !== '' && $pdo instanceof PDO) {
    try {
        $countryStmt = $pdo->prepare('SELECT name, lang_country FROM country WHERE lang_key = ? AND COALESCE(lang_country, "") <> "" ORDER BY id ASC LIMIT 1');
        $countryStmt->execute([$songLang]);
        $countryRow = $countryStmt->fetch();
        if (is_array($countryRow)) {
            $songCountryCode = strtoupper(trim((string) ($countryRow['lang_country'] ?? '')));
            $songCountryName = trim((string) ($countryRow['name'] ?? ''));
        }
    } catch (Throwable $e) {
        $songCountryCode = '';
        $songCountryName = '';
    }
}
$byArtist = $songArtists
    ? array_map(static fn(array $artist): array => [
        '@type' => 'MusicGroup',
        'name' => (string) $artist['name'],
        'url' => music_artist_url((int) $artist['id'], (string) $artist['name']),
    ], $songArtists)
    : ['@type' => 'MusicGroup', 'name' => $artistName];
$price = music_song_price($song, $paypalConfig);
$canDownload = trim((string) ($song['mp3'] ?? '')) !== '' && ($price <= 0 || music_has_paid_song((string) $song['id']));
$title = (string) $song['name'] . ' - ' . $artistName . ' | ' . music_brand_name();
$description = music_excerpt($song['lyrics'] ?: (($song['album'] ?? '') . ' ' . ($song['genre'] ?? '')), 155);
music_render_header($title, $description, music_cover($song['avatar']));
?>
<article class="detail">
    <aside class="detail-side">
        <figure class="detail-cover detail-cover--motion">
            <img src="<?= music_h(music_cover($song['avatar'])) ?>" alt="<?= music_h($song['name']) ?>">
            <?php if ($songYoutubeId !== ''): ?>
                <button class="song-video-play" type="button" data-video-open aria-label="<?= music_h(music_label('music.video.play', 'Play Video')) ?>" title="<?= music_h(music_label('music.video.play', 'Play Video')) ?>">
                    <i class="fas fa-video" aria-hidden="true"></i>
                </button>
            <?php endif; ?>
        </figure>
        <?= music_app_banners() ?>
    </aside>
    <div class="detail-main">
        <p class="eyebrow"><?= music_h(music_label('music.song.eyebrow', 'Song detail')) ?></p>
        <h1><?= music_h($song['name']) ?></h1>
        <div class="detail-meta">
            <?php if (!$songArtists): ?>
                <span><?= music_h($artistName) ?></span>
            <?php else: ?>
                <?php foreach ($songArtists as $artist): ?>
                    <span><a class="artist-tag site-link" href="<?= music_h(music_artist_url((int) $artist['id'], (string) $artist['name'])) ?>"><?= music_h($artist['name']) ?></a></span>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($songAlbum !== ''): ?>
                <span class="album-tag"><i class="fas fa-compact-disc" aria-hidden="true"></i><?= music_h($songAlbum) ?></span>
            <?php endif; ?>
            <?php foreach ($songGenreTags as $genreTag): ?>
                <span><a class="genre-tag site-link" href="<?= music_h(music_genre_url($genreTag)) ?>"><?= music_h($genreTag) ?></a></span>
            <?php endforeach; ?>
            <?php if ($songYear !== ''): ?><span><a class="year-tag site-link" href="<?= music_h(music_song_year_url((int) $songYear)) ?>"><?= music_h($songYear) ?></a></span><?php endif; ?>
            <?php if ($songLang !== '' && $songCountryCode !== ''): ?>
                <span><a class="lang-tag site-link" href="<?= music_h(music_country_url($songCountryCode)) ?>"><?= music_tourism_icon() ?><?= music_h($songCountryName !== '' ? $songCountryName : $songCountryCode) ?></a></span>
            <?php elseif ($songLang !== ''): ?>
                <span class="lang-tag"><?= music_tourism_icon() ?><?= music_h($songLang) ?></span>
            <?php endif; ?>
            <span class="view-meta">
                <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.4 12s3.5-6.5 9.6-6.5 9.6 6.5 9.6 6.5-3.5 6.5-9.6 6.5S2.4 12 2.4 12Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.7" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                <?= music_h(number_format($songViewCount)) ?> <?= music_h(music_label('label.views', 'views')) ?>
            </span>
        </div>
        <div class="song-actions">
            <?php if (!empty($song['mp3'])): ?>
                <button class="btn" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($artistName) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><?= music_h(music_label('music.action.add_to_playlist', 'Thêm playlist')) ?></button>
            <?php endif; ?>
            <?php if ($canDownload): ?>
                <a class="btn" href="<?= music_h($song['mp3']) ?>" download><?= music_h(music_label('music.action.download_mp3', 'Tải MP3')) ?></a>
            <?php elseif (!empty($song['mp3']) && $paypalConfig['enabled']): ?>
                <a class="btn song-buy-btn" href="<?= music_h(music_url('paypal-create.php?id=' . rawurlencode($song['id']))) ?>"><?= music_h(music_label('music.action.buy_download', 'Buy MP3 downloads')) ?> · <?= music_h(number_format($price, 2) . ' ' . $paypalConfig['currency']) ?></a>
            <?php endif; ?>
            <?php if (!empty($song['link_ytb'])): ?>
                <a class="btn" href="<?= music_h($song['link_ytb']) ?>" target="_blank" rel="noopener noreferrer">
                    <svg width="24px" height="24px" viewBox="0 -7 48 48" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" fill="#000000"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <title>Youtube-color</title> <desc>Created with Sketch.</desc> <defs> </defs> <g id="Icons" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd"> <g id="Color-" transform="translate(-200.000000, -368.000000)" fill="#CE1312"> <path d="M219.044,391.269916 L219.0425,377.687742 L232.0115,384.502244 L219.044,391.269916 Z M247.52,375.334163 C247.52,375.334163 247.0505,372.003199 245.612,370.536366 C243.7865,368.610299 241.7405,368.601235 240.803,368.489448 C234.086,368 224.0105,368 224.0105,368 L223.9895,368 C223.9895,368 213.914,368 207.197,368.489448 C206.258,368.601235 204.2135,368.610299 202.3865,370.536366 C200.948,372.003199 200.48,375.334163 200.48,375.334163 C200.48,375.334163 200,379.246723 200,383.157773 L200,386.82561 C200,390.73817 200.48,394.64922 200.48,394.64922 C200.48,394.64922 200.948,397.980184 202.3865,399.447016 C204.2135,401.373084 206.612,401.312658 207.68,401.513574 C211.52,401.885191 224,402 224,402 C224,402 234.086,401.984894 240.803,401.495446 C241.7405,401.382148 243.7865,401.373084 245.612,399.447016 C247.0505,397.980184 247.52,394.64922 247.52,394.64922 C247.52,394.64922 248,390.73817 248,386.82561 L248,383.157773 C248,379.246723 247.52,375.334163 247.52,375.334163 L247.52,375.334163 Z" id="Youtube"> </path> </g> </g> </g></svg>
                    YouTube
                </a>
            <?php endif; ?>
            <?= music_detail_action_buttons((string) $song['name'], music_song_url((string) $song['id'])) ?>
        </div>
        <?php if (!empty($song['mp3'])): ?>
            <div class="wave-box is-loading" id="song_wave_box">
                <button class="wave-play" type="button" aria-label="<?= music_h(music_label('music.action.play', 'Phát bài hát')) ?>">
                    <?= music_play_icon() ?>
                </button>
                <div class="wave-main">
                    <div id="song_waveform" class="song-waveform">
                        <div class="wave-loading" role="status" aria-live="polite">
                            <span class="wave-loading-spinner" aria-hidden="true"></span>
                            <span><?= music_h(music_label('music.wave.loading', 'Đang tải sóng nhạc')) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($song['mp3']) && !$canDownload && !$paypalConfig['enabled'] && $price > 0): ?>
            <div class="payment-box"><?= music_h(sprintf(music_label('music.song.paypal_disabled', 'Bài hát này có giá %s, nhưng tính năng thanh toán hiện chưa sẵn sàng. Vui lòng quay lại sau.'), number_format($price, 2))) ?></div>
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

<?php if ($songYoutubeId !== ''): ?>
<div class="music-video-modal" id="music_video_modal" aria-hidden="true" data-video-id="<?= music_h($songYoutubeId) ?>">
    <div class="music-video-stage">
        <div id="music_video_player" class="music-video-player"></div>
        <div class="music-video-lyrics" id="music_video_lyrics" aria-hidden="true"></div>
        <div class="music-video-tools">
            <button class="music-video-tool" type="button" data-video-lyrics aria-label="<?= music_h(music_label('music.video.hide_lyrics', 'Ẩn lyrics')) ?>" title="<?= music_h(music_label('music.video.hide_lyrics', 'Ẩn lyrics')) ?>">
                <i class="fas fa-eye" aria-hidden="true"></i>
            </button>
            <button class="music-video-tool" type="button" data-video-close aria-label="<?= music_h(music_label('action.close', 'Close')) ?>" title="<?= music_h(music_label('action.close', 'Close')) ?>">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($relatedSongs): ?>
<section class="section">
    <div class="section-head"><div><h2><?= music_h(music_label('music.related_songs', 'Bài liên quan')) ?></h2><p><?= music_h(music_label('music.related_songs_intro', 'Tiếp tục khám phá những giai điệu có cùng cảm xúc.')) ?></p></div></div>
    <div class="grid">
        <?php foreach ($relatedSongs as $related): ?>
            <article class="song-card">
                <a class="site-link" href="<?= music_h(music_song_url($related['id'])) ?>"><img src="<?= music_h(music_cover($related['avatar'])) ?>" alt="<?= music_h($related['name']) ?>"></a>
                <div class="song-card-body">
                    <a class="song-title site-link" href="<?= music_h(music_song_url($related['id'])) ?>"><?= music_h($related['name']) ?></a>
                    <div class="song-meta"><?= music_h($related['artist_names'] ?: $related['artist']) ?></div>
                    <div class="song-card-actions">
                        <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-url="<?= music_h($related['mp3']) ?>" cr-name="<?= music_h($related['name']) ?>" cr-artist="<?= music_h($related['artist_names'] ?: $related['artist']) ?>" cr-avatar="<?= music_h(music_cover($related['avatar'])) ?>"><?= music_play_icon() ?><?= music_h(music_label('music.action.play', 'Phát')) ?></button>
                        <button class="icon-btn" title="<?= music_h(music_label('music.action.add_to_playlist', 'Add to playlist')) ?>" onclick="cr_player.add_emp(this)" cr-url="<?= music_h($related['mp3']) ?>" cr-name="<?= music_h($related['name']) ?>" cr-artist="<?= music_h($related['artist_names'] ?: $related['artist']) ?>" cr-avatar="<?= music_h(music_cover($related['avatar'])) ?>"><i class="fas fa-plus"></i></button>
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
    const songData = {
        name: <?= json_encode((string) $song['name'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        mp3: <?= json_encode((string) $song['mp3'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        url: <?= json_encode((string) $song['mp3'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        waveform: <?= json_encode(music_audio_proxy_url((string) $song['mp3']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        artist: <?= json_encode($artistName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        album: <?= json_encode($artistName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        avatar: <?= json_encode(music_cover($song['avatar']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        youtube: <?= json_encode((string) ($song['link_ytb'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        is_live: false,
    };

    const bootWaveform = (attempt = 0) => {
        const container = document.getElementById('song_waveform');
        const waveBox = document.getElementById('song_wave_box');
        const playButton = document.querySelector('.wave-play');
        if (!container || !playButton) return;
        if (!window.WaveSurfer || !window.cr_player || !cr_player.audio_player) {
            if (attempt < 80) window.setTimeout(() => bootWaveform(attempt + 1), 100);
            return;
        }

        const audio = cr_player.audio_player;
        const songUrl = new URL(songData.mp3, window.location.href).href;
        const waveformUrl = new URL(songData.waveform || songUrl, window.location.href);
        waveformUrl.searchParams.set('cors', '1');
        const isThisSong = () => audio.src === songUrl;
        const wave = WaveSurfer.create({
            container,
            url: waveformUrl.href,
            height: 86,
            barWidth: 3,
            barGap: 2,
            barRadius: 3,
            cursorColor: '#e11d1d',
            cursorWidth: 2,
            normalize: true,
            interact: false,
            waveColor: '#ffb36f',
            progressColor: '#ff6a00',
        });

        if (typeof wave.setMuted === 'function') wave.setMuted(true);

        const updateState = () => {
            const isPlaying = isThisSong() && !audio.paused && !audio.ended;
            playButton.classList.toggle('is-playing', isPlaying);
            container.classList.toggle('is-playing', isPlaying);
            if (isThisSong() && Number.isFinite(audio.duration) && audio.duration > 0) {
                wave.seekTo(Math.max(0, Math.min(1, audio.currentTime / audio.duration)));
            }
        };

        const setWaveStatus = (status) => {
            waveBox?.classList.toggle('is-loading', status === 'loading');
            waveBox?.classList.toggle('is-error', status === 'error');
        };

        playButton.addEventListener('click', () => {
            if (isThisSong()) {
                cr_player.playOrPause();
            } else {
                cr_player.start(songData, false);
            }
            window.setTimeout(updateState, 80);
        });

        audio.addEventListener('play', updateState);
        audio.addEventListener('pause', updateState);
        audio.addEventListener('ended', updateState);
        audio.addEventListener('timeupdate', updateState);
        audio.addEventListener('loadedmetadata', updateState);
        wave.on('ready', () => {
            setWaveStatus('ready');
            updateState();
        });
        wave.on('error', () => {
            setWaveStatus('error');
            const loading = container.querySelector('.wave-loading span:last-child');
            if (loading) loading.textContent = <?= json_encode(music_label('music.wave.error', 'Chưa tải được sóng nhạc'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        });
        updateState();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => bootWaveform());
    } else {
        bootWaveform();
    }
})();
</script>
<?php endif; ?>

<?php if ($songYoutubeId !== ''): ?>
<script>
(() => {
    const modal = document.getElementById('music_video_modal');
    const openButton = document.querySelector('[data-video-open]');
    const closeButton = modal?.querySelector('[data-video-close]');
    const lyricsButton = modal?.querySelector('[data-video-lyrics]');
    const lyricsLayer = document.getElementById('music_video_lyrics');
    const videoId = modal?.dataset.videoId || '';
    const rawLyrics = <?= json_encode(strip_tags((string) $lyrics), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let player = null;
    let playerReady = false;
    let lyricTimer = null;
    let lyricItems = [];
    let lyricShown = new Set();
    let lastVideoTime = 0;

    if (!modal || !openButton || !videoId) return;

    const loadYouTubeApi = () => new Promise((resolve) => {
        if (window.YT && window.YT.Player) {
            resolve();
            return;
        }
        const previousReady = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = () => {
            if (typeof previousReady === 'function') previousReady();
            resolve();
        };
        if (!document.querySelector('script[src="https://www.youtube.com/iframe_api"]')) {
            const script = document.createElement('script');
            script.src = 'https://www.youtube.com/iframe_api';
            document.head.appendChild(script);
        }
    });

    const splitLongLine = (line, maxLength = 72) => {
        const parts = [];
        let value = line.trim();
        while (value.length > maxLength) {
            let cut = value.lastIndexOf(',', maxLength);
            if (cut < 24) cut = value.lastIndexOf(' ', maxLength);
            if (cut < 24) cut = maxLength;
            parts.push(value.slice(0, cut).trim());
            value = value.slice(cut).trim();
        }
        if (value !== '') parts.push(value);
        return parts;
    };

    const buildLyricLines = () => {
        const text = rawLyrics
            .replace(/\r/g, '\n')
            .replace(/&nbsp;/gi, ' ')
            .replace(/\s+\n/g, '\n')
            .trim();
        if (text === '') return [];

        return text
            .split(/[\n.。!?！？]+/u)
            .map((line) => line.replace(/\s+/g, ' ').trim())
            .filter(Boolean)
            .flatMap((line) => splitLongLine(line));
    };

    const prepareLyricItems = () => {
        const lines = buildLyricLines();
        const duration = player && typeof player.getDuration === 'function' ? player.getDuration() : 0;
        const safeDuration = Number.isFinite(duration) && duration > 20 ? duration : Math.max(120, lines.length * 5);
        const laneCount = window.matchMedia('(max-width: 700px)').matches ? 5 : 8;
        lyricItems = lines.map((text, index) => ({
            text,
            lane: index % laneCount,
            time: ((index + 0.65) / Math.max(1, lines.length + 0.4)) * safeDuration,
        }));
        lyricShown = new Set();
        lastVideoTime = 0;
        lyricsLayer?.replaceChildren();
    };

    const showLyric = (item) => {
        if (!lyricsLayer || modal.classList.contains('is-lyrics-hidden')) return;
        const line = document.createElement('span');
        line.className = 'music-video-lyric-line';
        line.textContent = item.text;
        line.style.setProperty('--lane', String(item.lane));
        line.style.setProperty('--duration', `${Math.max(8, Math.min(15, item.text.length / 7 + 7))}s`);
        lyricsLayer.appendChild(line);
        line.addEventListener('animationend', () => line.remove(), {once: true});
    };

    const startLyrics = () => {
        window.clearInterval(lyricTimer);
        prepareLyricItems();
        lyricTimer = window.setInterval(() => {
            if (!playerReady || !player || typeof player.getCurrentTime !== 'function') return;
            const currentTime = player.getCurrentTime();
            if (currentTime < lastVideoTime - 2) {
                lyricShown = new Set();
                lyricsLayer?.replaceChildren();
            }
            lastVideoTime = currentTime;
            lyricItems.forEach((item, index) => {
                if (!lyricShown.has(index) && currentTime >= item.time) {
                    lyricShown.add(index);
                    showLyric(item);
                }
            });
        }, 260);
    };

    const openVideo = async () => {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('music-video-open');
        await loadYouTubeApi();
        if (!player) {
            player = new YT.Player('music_video_player', {
                videoId,
                playerVars: {autoplay: 1, rel: 0, modestbranding: 1, playsinline: 1},
                events: {
                    onReady(event) {
                        playerReady = true;
                        event.target.playVideo();
                        startLyrics();
                    },
                    onStateChange(event) {
                        if (event.data === YT.PlayerState.PLAYING && lyricItems.length === 0) {
                            startLyrics();
                        }
                    },
                },
            });
        } else {
            player.playVideo();
            startLyrics();
        }
    };

    const closeVideo = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('music-video-open');
        window.clearInterval(lyricTimer);
        lyricsLayer?.replaceChildren();
        if (player && typeof player.pauseVideo === 'function') {
            player.pauseVideo();
        }
    };

    openButton.addEventListener('click', openVideo);
    closeButton?.addEventListener('click', closeVideo);
    lyricsButton?.addEventListener('click', () => {
        const hidden = modal.classList.toggle('is-lyrics-hidden');
        lyricsButton.setAttribute('aria-label', hidden
            ? <?= json_encode(music_label('music.video.show_lyrics', 'Hiện lyrics'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
            : <?= json_encode(music_label('music.video.hide_lyrics', 'Ẩn lyrics'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
        lyricsButton.innerHTML = hidden ? '<i class="fas fa-eye-slash" aria-hidden="true"></i>' : '<i class="fas fa-eye" aria-hidden="true"></i>';
        if (!hidden) {
            lyricsLayer?.replaceChildren();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) closeVideo();
    });
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
