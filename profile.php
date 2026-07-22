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
$profileMode = ($_GET['mode'] ?? '') === 'edit' ? 'edit' : 'view';

if (!$pdo instanceof PDO) {
    $errorMessage = $db_error ?? music_label('error.mysql_connection', 'Lỗi kết nối MySQL.');
} else {
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
});
</script>

<?php music_render_footer(); ?>
