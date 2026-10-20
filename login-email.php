<?php
require_once __DIR__ . '/includes/music.php';

music_start_session();

header('Content-Type: application/json; charset=utf-8');

function music_email_login_response(bool $ok, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'ok' => $ok,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    music_email_login_response(false, music_label('error.method_not_allowed', 'Method not allowed.'), 405);
}

if (!($pdo ?? null) instanceof PDO) {
    music_email_login_response(false, $db_error ?? music_label('error.mysql_connection', 'MySQL connection error.'), 500);
}

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

try {
    if ($email === '' || $password === '') {
        throw new RuntimeException(music_label('login.error_required', 'Please enter your email and password.'));
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(music_label('login.error_email', 'Please enter a valid email address.'));
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $storedPassword = (string) ($user['password'] ?? '');
    $passwordOk = $user && $storedPassword !== '' && hash_equals($storedPassword, $password);

    if (!$user || !$passwordOk) {
        throw new RuntimeException(music_label('login.error_invalid', 'Incorrect email or password.'));
    }

    session_regenerate_id(true);
    $_SESSION['home_user_id'] = (int) $user['id'];
    $_SESSION['home_user_name'] = (string) ($user['name'] ?? '');
    $_SESSION['home_user_email'] = (string) ($user['email'] ?? '');
    $_SESSION['home_user_role'] = (string) ($user['role'] ?? 'user');
    $_SESSION['home_user_avatar'] = (string) ($user['avatar'] ?? '');
    unset($_SESSION['home_login_redirect']);

    music_email_login_response(true, music_label('login.success', 'Signed in successfully.'), 200, [
        'user' => [
            'id' => (int) $user['id'],
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
        ],
    ]);
} catch (PDOException $e) {
    music_email_login_response(false, music_label('login.error_database', 'Could not check this account because the users database is not ready.'), 500);
} catch (Throwable $e) {
    music_email_login_response(false, $e->getMessage(), 401);
}
