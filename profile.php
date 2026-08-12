<?php
require_once __DIR__ . '/includes/music.php';

if (isset($_GET['logout'])) {
    unset($_SESSION['home_user_id'], $_SESSION['home_user_name'], $_SESSION['home_user_email'], $_SESSION['home_user_role'], $_SESSION['home_user_avatar']);
    header('Location: ' . music_home_url());
    exit;
}

if (empty($_SESSION['home_user_id'])) {
    header('Location: ' . music_url_with_query(music_home_url(), ['oauth_error' => music_label('login.required', 'Vui lòng đăng nhập để xem hồ sơ.')]));
    exit;
}

$message = '';
$errorMessage = '';
$user = null;
$playlists = [];
$orders = [];
$activePlaylistId = (int) ($_GET['playlist_id'] ?? 0);
$requestedMode = (string) ($_GET['mode'] ?? '');
$profileMode = in_array($requestedMode, ['edit', 'playlist', 'order'], true) ? $requestedMode : 'view';

if (!$pdo instanceof PDO) {
    $errorMessage = $db_error ?? music_label('error.mysql_connection', 'Lỗi kết nối MySQL.');
} else {
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['playlist_action'])) {
            $playlistAction = trim((string) ($_POST['playlist_action'] ?? ''));
            $playlistId = (int) ($_POST['playlist_id'] ?? 0);
            $profileMode = 'playlist';

            if ($playlistAction === 'create') {
                $playlistName = trim((string) ($_POST['playlist_name'] ?? ''));
                $playlistDescription = trim((string) ($_POST['playlist_description'] ?? ''));
                if ($playlistName === '') {
                    throw new RuntimeException(music_label('playlist.error_name', 'Vui lòng nhập tên playlist.'));
                }
                $stmt = $pdo->prepare('INSERT INTO music_playlist (user_id, name, description, songs_json) VALUES (?, ?, ?, ?)');
                $stmt->execute([(int) $_SESSION['home_user_id'], $playlistName, $playlistDescription, '[]']);
                $message = music_label('playlist.created', 'Đã tạo playlist.');
            } elseif ($playlistAction === 'update') {
                $playlistName = trim((string) ($_POST['playlist_name'] ?? ''));
                $playlistDescription = trim((string) ($_POST['playlist_description'] ?? ''));
                if ($playlistId <= 0 || $playlistName === '') {
                    throw new RuntimeException(music_label('playlist.error_update', 'Thông tin playlist không hợp lệ.'));
                }
                $stmt = $pdo->prepare('UPDATE music_playlist SET name = ?, description = ? WHERE id = ? AND user_id = ?');
                $stmt->execute([$playlistName, $playlistDescription, $playlistId, (int) $_SESSION['home_user_id']]);
                $message = music_label('playlist.updated', 'Đã cập nhật playlist.');
            } elseif ($playlistAction === 'delete') {
                if ($playlistId <= 0) {
                    throw new RuntimeException(music_label('playlist.not_found', 'Không tìm thấy playlist.'));
                }
                $stmt = $pdo->prepare('DELETE FROM music_playlist WHERE id = ? AND user_id = ?');
                $stmt->execute([$playlistId, (int) $_SESSION['home_user_id']]);
                $message = music_label('playlist.deleted', 'Đã xóa playlist.');
            } elseif ($playlistAction === 'remove_song') {
                $songIndex = (int) ($_POST['song_index'] ?? -1);
                $stmt = $pdo->prepare('SELECT songs_json FROM music_playlist WHERE id = ? AND user_id = ? LIMIT 1');
                $stmt->execute([$playlistId, (int) $_SESSION['home_user_id']]);
                $playlistRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$playlistRow) {
                    throw new RuntimeException(music_label('playlist.not_found', 'Không tìm thấy playlist.'));
                }
                $songs = json_decode((string) ($playlistRow['songs_json'] ?? '[]'), true);
                $songs = is_array($songs) ? array_values($songs) : [];
                if (!array_key_exists($songIndex, $songs)) {
                    throw new RuntimeException(music_label('playlist.song_not_found', 'Không tìm thấy bài hát trong playlist.'));
                }
                array_splice($songs, $songIndex, 1);
                $stmt = $pdo->prepare('UPDATE music_playlist SET songs_json = ? WHERE id = ? AND user_id = ?');
                $stmt->execute([json_encode($songs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $playlistId, (int) $_SESSION['home_user_id']]);
                $message = music_label('playlist.song_removed', 'Đã xóa bài hát khỏi playlist.');
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $birthday = trim((string) ($_POST['birthday'] ?? ''));
            $avatar = trim((string) ($_POST['avatar'] ?? ''));
            $address = trim((string) ($_POST['address'] ?? ''));
            $lang = trim((string) ($_POST['lang'] ?? current_lang_key()));
            $sex = trim((string) ($_POST['sex'] ?? ''));

            if ($name === '') {
                throw new RuntimeException(music_label('profile.error_name', 'Vui lòng nhập tên.'));
            }

            $stmt = $pdo->prepare('
                UPDATE users
                SET name = ?, phone = ?, birthday = ?, avatar = ?, address = ?, lang = ?, sex = ?
                WHERE id = ?
            ');
            $stmt->execute([$name, $phone, $birthday, $avatar, $address, $lang, $sex, (int) $_SESSION['home_user_id']]);
            $_SESSION['home_user_name'] = $name;
            $_SESSION['home_user_avatar'] = $avatar;
            $message = music_label('profile.saved', 'Đã cập nhật thông tin.');
            $profileMode = 'view';
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['home_user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['home_user_id'], $_SESSION['home_user_name'], $_SESSION['home_user_email'], $_SESSION['home_user_role'], $_SESSION['home_user_avatar']);
            header('Location: ' . music_url_with_query(music_home_url(), ['oauth_error' => music_label('profile.not_found', 'Không tìm thấy tài khoản.')]));
            exit;
        }

        $userEmail = trim((string) ($user['email'] ?? ''));
        if ($profileMode === 'order') {
            $orderStmt = $pdo->prepare('
                SELECT o.*, s.name AS song_name, s.artist AS song_artist, s.avatar AS song_avatar, s.mp3 AS song_mp3, s.lang AS song_lang
                FROM song_orders o
                LEFT JOIN song s ON s.id = o.song_id
                WHERE o.user_id = ? OR (LOWER(COALESCE(o.payer_email, "")) = LOWER(?) AND ? <> "")
                ORDER BY COALESCE(o.paid_at, o.created_at) DESC, o.id DESC
            ');
            $orderStmt->execute([(int) $_SESSION['home_user_id'], $userEmail, $userEmail]);
            $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $playlistStmt = $pdo->prepare('SELECT * FROM music_playlist WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
        $playlistStmt->execute([(int) $_SESSION['home_user_id']]);
        foreach ($playlistStmt->fetchAll(PDO::FETCH_ASSOC) as $playlistRow) {
            $songs = json_decode((string) ($playlistRow['songs_json'] ?? '[]'), true);
            $playlistRow['songs'] = is_array($songs) ? array_values($songs) : [];
            $playlists[] = $playlistRow;
        }
        if ($profileMode === 'playlist' && $playlists) {
            $playlistIds = array_map(static fn(array $playlist): int => (int) $playlist['id'], $playlists);
            if ($activePlaylistId <= 0 || !in_array($activePlaylistId, $playlistIds, true)) {
                $activePlaylistId = (int) $playlists[0]['id'];
            }
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$profileName = trim((string) ($user['name'] ?? ''));
$profileEmail = trim((string) ($user['email'] ?? ''));
$profileAvatar = trim((string) ($user['avatar'] ?? ''));
$profileInitial = strtoupper(substr($profileName !== '' ? $profileName : $profileEmail, 0, 1) ?: 'U');
$profileJoined = trim((string) ($user['created_at'] ?? ''));
$profileType = trim((string) ($user['type'] ?? ''));
$profileLang = trim((string) ($user['lang'] ?? current_lang_key()));
$profileSex = trim((string) ($user['sex'] ?? ''));

music_render_header(
    music_label('profile.title', 'Profile') . ' | ' . music_brand_name(),
    music_label('profile.description', 'View and update your ' . music_brand_name() . ' profile.'),
    $profileAvatar
);
?>

<section class="music-profile-page">
    <div class="music-profile-hero">
        <div class="music-profile-avatar">
            <?php if ($profileAvatar !== ''): ?>
                <img src="<?= music_h($profileAvatar) ?>" alt="">
            <?php else: ?>
                <span><?= music_h($profileInitial) ?></span>
            <?php endif; ?>
        </div>
        <div class="music-profile-copy">
            <p class="eyebrow"><?= music_h(music_label('profile.eyebrow', 'Account')) ?></p>
            <h1><?= music_h($profileName !== '' ? $profileName : $profileEmail) ?></h1>
            <p><?= music_h($profileEmail) ?></p>
            <div class="music-profile-meta">
                <?php if ($profileLang !== ''): ?><span><i class="fas fa-language"></i><?= music_h(strtoupper($profileLang)) ?></span><?php endif; ?>
                <?php if ($profileType !== ''): ?><span><i class="fas fa-user-tag"></i><?= music_h($profileType) ?></span><?php endif; ?>
                <?php if ($profileJoined !== ''): ?><span><i class="fas fa-calendar-alt"></i><?= music_h($profileJoined) ?></span><?php endif; ?>
            </div>
        </div>
    </div>

    <nav class="music-profile-tabs" aria-label="<?= music_h(music_label('aria.profile_navigation', 'Profile navigation')) ?>">
        <a class="<?= $profileMode === 'view' ? 'is-active' : '' ?>" href="<?= music_h(music_url('profile.php')) ?>">
            <i class="fas fa-user"></i><?= music_h(music_label('profile.tab_information', 'Information')) ?>
        </a>
        <a class="<?= $profileMode === 'edit' ? 'is-active' : '' ?>" href="<?= music_h(music_url('profile.php?mode=edit')) ?>">
            <i class="fas fa-pen"></i><?= music_h(music_label('action.edit', 'Edit')) ?>
        </a>
        <a class="<?= $profileMode === 'playlist' ? 'is-active' : '' ?>" href="<?= music_h(music_url('profile.php?mode=playlist')) ?>">
            <i class="fas fa-list-ul"></i><?= music_h(music_label('profile.tab_playlist', 'Playlist')) ?>
        </a>
        <a class="<?= $profileMode === 'order' ? 'is-active' : '' ?>" href="<?= music_h(music_url('profile.php?mode=order')) ?>">
            <i class="fas fa-receipt"></i><?= music_h(music_label('profile.tab_order', 'Order')) ?>
        </a>
        <a href="<?= music_h(music_url('profile.php?logout=1')) ?>">
            <i class="fas fa-sign-out-alt"></i><?= music_h(music_label('action.logout', 'Logout')) ?>
        </a>
    </nav>

    <?php if ($message): ?><div class="music-profile-alert music-profile-alert--success"><?= music_h($message) ?></div><?php endif; ?>
    <?php if ($errorMessage): ?><div class="music-profile-alert music-profile-alert--error"><?= music_h($errorMessage) ?></div><?php endif; ?>

    <?php if ($profileMode === 'edit'): ?>
        <form class="music-profile-form" method="post">
            <div class="music-profile-form-grid">
                <label>
                    <span><?= music_h(music_label('label.name', 'Name')) ?></span>
                    <input name="name" value="<?= music_h($profileName) ?>" required>
                </label>
                <label>
                    <span><?= music_h(music_label('label.email', 'Email')) ?></span>
                    <input value="<?= music_h($profileEmail) ?>" disabled>
                </label>
                <label>
                    <span><?= music_h(music_label('label.phone', 'Phone')) ?></span>
                    <input name="phone" value="<?= music_h($user['phone'] ?? '') ?>">
                </label>
                <label>
                    <span><?= music_h(music_label('label.birthday', 'Birthday')) ?></span>
                    <input name="birthday" type="date" value="<?= music_h($user['birthday'] ?? '') ?>">
                </label>
                <label>
                    <span><?= music_h(music_label('label.lang', 'Language')) ?></span>
                    <input name="lang" value="<?= music_h($profileLang) ?>">
                </label>
                <label>
                    <span><?= music_h(music_label('label.sex', 'Sex')) ?></span>
                    <select class="music-profile-sex-select" name="sex">
                        <option value=""></option>
                        <option value="male" <?= $profileSex === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= $profileSex === 'female' ? 'selected' : '' ?>>Female</option>
                    </select>
                </label>
            </div>
            <label>
                <span><?= music_h(music_label('label.avatar', 'Avatar URL')) ?></span>
                <input name="avatar" value="<?= music_h($profileAvatar) ?>">
            </label>
            <label>
                <span><?= music_h(music_label('label.address', 'Address')) ?></span>
                <textarea name="address" rows="3"><?= music_h($user['address'] ?? '') ?></textarea>
            </label>
            <div class="music-profile-actions">
                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i><?= music_h(music_label('action.save', 'Save')) ?></button>
                <a class="btn" href="<?= music_h(music_url('profile.php')) ?>"><i class="fas fa-times"></i><?= music_h(music_label('action.cancel', 'Cancel')) ?></a>
            </div>
        </form>
    <?php elseif ($profileMode === 'playlist'): ?>
        <section class="music-profile-playlists">
            <form class="music-profile-form" method="post">
                <input type="hidden" name="playlist_action" value="create">
                <div class="music-profile-form-grid">
                    <label>
                        <span><?= music_h(music_label('playlist.new_name', 'Playlist mới')) ?></span>
                        <input name="playlist_name" maxlength="255" required>
                    </label>
                    <label>
                        <span><?= music_h(music_label('label.description', 'Description')) ?></span>
                        <input name="playlist_description">
                    </label>
                </div>
                <button class="btn btn-primary" type="submit"><i class="fas fa-plus"></i><?= music_h(music_label('action.create', 'Tạo')) ?></button>
            </form>

            <?php if ($playlists): ?>
                <nav class="music-profile-subtabs" aria-label="<?= music_h(music_label('profile.tab_playlist', 'Playlist')) ?>">
                    <?php foreach ($playlists as $playlistTab): ?>
                        <?php $playlistTabId = (int) $playlistTab['id']; ?>
                        <a class="<?= $playlistTabId === $activePlaylistId ? 'is-active' : '' ?>" href="<?= music_h(music_url('profile.php?mode=playlist&playlist_id=' . $playlistTabId)) ?>">
                            <i class="fas fa-list-ul"></i>
                            <span><?= music_h($playlistTab['name'] ?? '') ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <div class="music-playlist-list">
                <?php foreach ($playlists as $playlist): ?>
                    <?php
                    $playlistSongs = is_array($playlist['songs'] ?? null) ? $playlist['songs'] : [];
                    $playlistId = (int) $playlist['id'];
                    if ($playlistId !== $activePlaylistId) {
                        continue;
                    }
                    ?>
                    <article class="music-playlist-card">
                        <form class="music-playlist-edit" method="post">
                            <input type="hidden" name="playlist_action" value="update">
                            <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">
                            <label>
                                <span><?= music_h(music_label('label.name', 'Name')) ?></span>
                                <input name="playlist_name" value="<?= music_h($playlist['name'] ?? '') ?>" maxlength="255" required>
                            </label>
                            <label>
                                <span><?= music_h(music_label('label.description', 'Description')) ?></span>
                                <input name="playlist_description" value="<?= music_h($playlist['description'] ?? '') ?>">
                            </label>
                            <div class="music-playlist-actions">
                                <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i><?= music_h(music_label('action.save', 'Save')) ?></button>
                                <button class="btn js-profile-playlist-play" type="button" data-playlist='<?= music_h(json_encode($playlistSongs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'><i class="fas fa-play"></i><?= music_h(music_label('music.action.play', 'Phát')) ?></button>
                                <button class="icon-btn music-playlist-delete-btn" type="submit" form="music-playlist-delete-<?= $playlistId ?>" title="<?= music_h(music_label('action.delete', 'Delete')) ?>"><i class="fas fa-trash"></i></button>
                            </div>
                        </form>
                        <form id="music-playlist-delete-<?= $playlistId ?>" class="music-playlist-delete" method="post" onsubmit="return confirm('<?= music_h(music_label('playlist.confirm_delete', 'Xóa playlist này?')) ?>');">
                            <input type="hidden" name="playlist_action" value="delete">
                            <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">
                        </form>

                        <div class="music-playlist-songs">
                            <strong><?= number_format(count($playlistSongs)) ?> <?= music_h(music_label('music.label.songs', 'bài hát')) ?></strong>
                            <?php foreach ($playlistSongs as $songIndex => $playlistSong): ?>
                                <div class="music-playlist-song-row">
                                    <img src="<?= music_h(music_cover($playlistSong['avatar'] ?? '')) ?>" alt="">
                                    <span>
                                        <b><?= music_h($playlistSong['name'] ?? '') ?></b>
                                        <small><?= music_h($playlistSong['artist'] ?? '') ?></small>
                                    </span>
                                    <button class="icon-btn" type="button" onclick="cr_player.start({id: <?= json_encode((string) ($playlistSong['id'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, name: <?= json_encode((string) ($playlistSong['name'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, artist: <?= json_encode((string) ($playlistSong['artist'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, mp3: <?= json_encode((string) ($playlistSong['mp3'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, avatar: <?= json_encode(music_cover($playlistSong['avatar'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>}, false)" title="<?= music_h(music_label('music.action.play', 'Phát')) ?>"><i class="fas fa-play"></i></button>
                                    <form method="post" onsubmit="return confirm('<?= music_h(music_label('playlist.confirm_remove_song', 'Xóa bài hát khỏi playlist?')) ?>');">
                                        <input type="hidden" name="playlist_action" value="remove_song">
                                        <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">
                                        <input type="hidden" name="song_index" value="<?= (int) $songIndex ?>">
                                        <button class="icon-btn" type="submit" title="<?= music_h(music_label('action.delete', 'Delete')) ?>"><i class="fas fa-times"></i></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$playlistSongs): ?><div class="empty"><?= music_h(music_label('playlist.empty', 'Playlist này chưa có bài hát.')) ?></div><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$playlists): ?><div class="empty"><?= music_h(music_label('playlist.no_playlist', 'Bạn chưa có playlist nào.')) ?></div><?php endif; ?>
            </div>
        </section>
    <?php elseif ($profileMode === 'order'): ?>
        <section class="music-profile-orders">
            <div class="music-order-head">
                <div>
                    <h2><?= music_h(music_label('profile.orders_title', 'Order')) ?></h2>
                    <p><?= music_h(music_label('profile.orders_intro', 'Quản lý các đơn MP3 đã mua bằng email tài khoản của bạn.')) ?></p>
                </div>
                <span><?= number_format(count($orders)) ?> <?= music_h(music_label('profile.orders_count', 'đơn hàng')) ?></span>
            </div>
            <div class="music-order-list">
                <?php foreach ($orders as $order): ?>
                    <?php
                    $orderStatus = strtoupper(trim((string) ($order['status'] ?? '')));
                    $orderCompleted = $orderStatus === 'COMPLETED';
                    $orderSongUrl = !empty($order['song_id']) ? music_song_url((string) $order['song_id'], (string) ($order['song_lang'] ?? '')) : '';
                    ?>
                    <article class="music-order-row">
                        <img src="<?= music_h(music_cover($order['song_avatar'] ?? '')) ?>" alt="">
                        <div class="music-order-copy">
                            <strong><?= music_h($order['song_name'] ?? music_label('music.label.song', 'Song')) ?></strong>
                            <small><?= music_h($order['song_artist'] ?? '') ?></small>
                            <em><?= music_h(trim((string) ($order['payer_email'] ?? '')) !== '' ? (string) $order['payer_email'] : ($order['paypal_order_id'] ?? '')) ?></em>
                        </div>
                        <div class="music-order-meta">
                            <span class="music-order-status <?= $orderCompleted ? 'is-completed' : 'is-pending' ?>"><?= music_h($orderStatus !== '' ? $orderStatus : '-') ?></span>
                            <b><?= music_h(number_format((float) ($order['amount'] ?? 0), 2) . ' ' . ($order['currency'] ?? 'USD')) ?></b>
                            <small><?= music_h($order['paid_at'] ?? $order['created_at'] ?? '') ?></small>
                        </div>
                        <div class="music-order-actions">
                            <?php if ($orderSongUrl !== ''): ?>
                                <a class="icon-btn site-link" href="<?= music_h($orderSongUrl) ?>" title="<?= music_h(music_label('music.action.view_song', 'Xem bài hát')) ?>"><i class="fas fa-info"></i></a>
                            <?php endif; ?>
                            <?php if ($orderCompleted && !empty($order['song_mp3'])): ?>
                                <a class="icon-btn" href="<?= music_h($order['song_mp3']) ?>" download title="<?= music_h(music_label('music.action.download_mp3', 'Tải MP3')) ?>"><i class="fas fa-download"></i></a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$orders): ?><div class="empty"><?= music_h(music_label('profile.orders_empty', 'Bạn chưa có đơn hàng MP3 nào.')) ?></div><?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <div class="music-profile-summary">
            <div>
                <span><?= music_h(music_label('label.name', 'Name')) ?></span>
                <strong><?= music_h($profileName !== '' ? $profileName : '-') ?></strong>
            </div>
            <div>
                <span><?= music_h(music_label('label.email', 'Email')) ?></span>
                <strong><?= music_h($profileEmail !== '' ? $profileEmail : '-') ?></strong>
            </div>
            <div>
                <span><?= music_h(music_label('label.phone', 'Phone')) ?></span>
                <strong><?= music_h(trim((string) ($user['phone'] ?? '')) !== '' ? (string) $user['phone'] : '-') ?></strong>
            </div>
            <div>
                <span><?= music_h(music_label('label.birthday', 'Birthday')) ?></span>
                <strong><?= music_h(trim((string) ($user['birthday'] ?? '')) !== '' ? (string) $user['birthday'] : '-') ?></strong>
            </div>
            <div>
                <span><?= music_h(music_label('label.sex', 'Sex')) ?></span>
                <strong><?= music_h($profileSex !== '' ? $profileSex : '-') ?></strong>
            </div>
            <div>
                <span><?= music_h(music_label('label.address', 'Address')) ?></span>
                <strong><?= music_h(trim((string) ($user['address'] ?? '')) !== '' ? (string) $user['address'] : '-') ?></strong>
            </div>
        </div>
    <?php endif; ?>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.jQuery && jQuery.fn.select2) {
    jQuery('.music-profile-sex-select').select2({
      width: '100%',
      minimumResultsForSearch: Infinity,
      dropdownParent: jQuery('.music-profile-page'),
      dropdownCssClass: 'music-profile-sex-dropdown'
    });
  }
  document.querySelectorAll('.js-profile-playlist-play').forEach((button) => {
    button.addEventListener('click', () => {
      let songs = [];
      try {
        songs = JSON.parse(button.dataset.playlist || '[]');
      } catch (error) {
        songs = [];
      }
      songs = songs.filter((song) => song && song.mp3);
      if (!songs.length || !window.cr_player) return;
      cr_player.list_song = songs.map((song) => ({
        id: song.id || '',
        name: song.name || 'Song',
        artist: song.artist || 'Heart Beat Play',
        mp3: song.mp3,
        avatar: song.avatar || cr_player.path + '/song.png',
      }));
      cr_player.index_play_cur = 0;
      cr_player.play_by_index(0);
    });
  });
});
</script>

<?php music_render_footer(); ?>
