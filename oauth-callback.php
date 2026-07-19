<?php
require_once __DIR__ . '/includes/music.php';

function music_oauth_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function music_oauth_redirect_error(string $message): void
{
    header('Location: index.php?oauth_error=' . rawurlencode($message));
    exit;
}

function music_oauth_current_callback_url(): string
{
    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $scheme = $forwardedProto !== ''
        ? explode(',', $forwardedProto)[0]
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . ($path === '' ? '' : $path) . '/oauth-callback.php';
}

function music_oauth_request(string $url, array $options = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Server cần bật PHP cURL để đăng nhập mạng xã hội.');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $options['headers'] ?? [],
    ]);
    if (!empty($options['post'])) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $options['post']);
    }

    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException($error ?: 'Không gọi được OAuth API.');
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data)) {
        parse_str((string) $response, $data);
    }
    if ($status >= 400) {
        throw new RuntimeException((string) ($data['error_description'] ?? $data['error'] ?? $response));
    }

    return $data;
}

function music_oauth_login_user(PDO $pdo, string $email, string $name, string $provider, string $avatar = ''): void
{
    $email = trim($email);
    $name = trim($name) ?: $email;
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Không lấy được email từ tài khoản ' . $provider . '.');
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $updates = [];
        $params = [];
        if (trim((string) ($user['name'] ?? '')) === '' && $name !== '') {
            $updates[] = 'name = ?';
            $params[] = $name;
        }
        if (trim((string) ($user['avatar'] ?? '')) === '' && $avatar !== '') {
            $updates[] = 'avatar = ?';
            $params[] = $avatar;
        }
        if ($updates) {
            $params[] = (int) $user['id'];
            $pdo->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
        }
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO users (created_at, email, lang, name, avatar, password, role, status_share, type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            date('Y-m-d H:i:s'),
            $email,
            current_lang_key(),
            $name,
            $avatar,
            '',
            'user',
            'private',
            $provider,
        ]);
        $user = [
            'id' => (int) $pdo->lastInsertId(),
            'name' => $name,
            'email' => $email,
            'role' => 'user',
            'avatar' => $avatar,
        ];
    }

    session_regenerate_id(true);
    $_SESSION['home_user_id'] = (int) $user['id'];
    $_SESSION['home_user_name'] = (string) ($user['name'] ?? $name);
    $_SESSION['home_user_email'] = (string) ($user['email'] ?? $email);
    $_SESSION['home_user_role'] = (string) ($user['role'] ?? 'user');
    $_SESSION['home_user_avatar'] = (string) ($user['avatar'] ?? $avatar);
}

if (!$pdo instanceof PDO) {
    music_oauth_redirect_error($db_error ?? 'Không thể kết nối database.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supabase_token') {
    try {
        $accessToken = trim((string) ($_POST['access_token'] ?? ''));
        $projectUrl = rtrim((string) ($_SESSION['oauth_supabase_project_url'] ?? ''), '/');
        $apiKey = (string) ($_SESSION['oauth_supabase_api_key'] ?? '');
        $provider = (string) ($_SESSION['oauth_provider'] ?? 'supabase');
        if ($accessToken === '' || $projectUrl === '' || $apiKey === '') {
            throw new RuntimeException('Thiếu Supabase token hoặc API config.');
        }

        $user = music_oauth_request($projectUrl . '/auth/v1/user', [
            'headers' => [
                'apikey: ' . $apiKey,
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);
        $metadata = is_array($user['user_metadata'] ?? null) ? $user['user_metadata'] : [];
        music_oauth_login_user(
            $pdo,
            (string) ($user['email'] ?? ''),
            (string) ($metadata['full_name'] ?? $metadata['name'] ?? $user['email'] ?? ''),
            $provider,
            (string) ($metadata['avatar_url'] ?? $metadata['picture'] ?? '')
        );
        music_oauth_json(['success' => true, 'redirect' => 'index.php']);
    } catch (Throwable $e) {
        music_oauth_json(['success' => false, 'message' => $e->getMessage()], 400);
    }
}

if (isset($_GET['error'])) {
    music_oauth_redirect_error((string) ($_GET['error_description'] ?? $_GET['error']));
}

if (empty($_GET['code']) && (string) ($_SESSION['oauth_mode'] ?? '') === 'supabase') {
    ?>
<!doctype html>
<html lang="vi">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>OAuth Callback</title></head>
<body>
<script>
(function () {
  var hash = new URLSearchParams((window.location.hash || '').replace(/^#/, ''));
  var token = hash.get('access_token');
  if (!token) {
    window.location.href = 'index.php?oauth_error=' + encodeURIComponent('Không nhận được Supabase access token.');
    return;
  }
  fetch('oauth-callback.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
    credentials: 'same-origin',
    body: 'action=supabase_token&access_token=' + encodeURIComponent(token)
  })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (!data || !data.success) throw new Error(data && data.message ? data.message : 'OAuth failed');
      window.location.href = data.redirect || 'index.php';
    })
    .catch(function (error) {
      window.location.href = 'index.php?oauth_error=' + encodeURIComponent(error.message || 'OAuth failed');
    });
})();
</script>
</body>
</html>
    <?php
    exit;
}

try {
    $provider = (string) ($_SESSION['oauth_provider'] ?? '');
    $mode = (string) ($_SESSION['oauth_mode'] ?? '');
    $code = trim((string) ($_GET['code'] ?? ''));
    $state = trim((string) ($_GET['state'] ?? ''));
    if ($code === '' || $provider === '' || $mode !== 'direct') {
        throw new RuntimeException('OAuth callback không hợp lệ.');
    }
    if ($state === '' || !hash_equals((string) ($_SESSION['oauth_state'] ?? ''), $state)) {
        throw new RuntimeException('OAuth state không hợp lệ.');
    }

    $clientId = (string) ($_SESSION['oauth_client_id'] ?? '');
    $clientSecret = (string) ($_SESSION['oauth_client_secret'] ?? '');
    $redirectUri = trim((string) ($_SESSION['oauth_redirect_uri'] ?? ''));
    if ($redirectUri === '') {
        $redirectUri = music_oauth_current_callback_url();
    }

    if ($provider === 'github') {
        $token = music_oauth_request('https://github.com/login/oauth/access_token', [
            'headers' => ['Accept: application/json'],
            'post' => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]),
        ]);
        $accessToken = (string) ($token['access_token'] ?? '');
        $profile = music_oauth_request('https://api.github.com/user', [
            'headers' => ['Authorization: Bearer ' . $accessToken, 'User-Agent: CarrotMusic', 'Accept: application/vnd.github+json'],
        ]);
        $emails = music_oauth_request('https://api.github.com/user/emails', [
            'headers' => ['Authorization: Bearer ' . $accessToken, 'User-Agent: CarrotMusic', 'Accept: application/vnd.github+json'],
        ]);
        $email = (string) ($profile['email'] ?? '');
        foreach ($emails as $emailRow) {
            if (!empty($emailRow['primary']) && !empty($emailRow['verified']) && !empty($emailRow['email'])) {
                $email = (string) $emailRow['email'];
                break;
            }
        }
        music_oauth_login_user($pdo, $email, (string) ($profile['name'] ?? $profile['login'] ?? ''), 'github', (string) ($profile['avatar_url'] ?? ''));
    } elseif ($provider === 'google') {
        $token = music_oauth_request('https://oauth2.googleapis.com/token', [
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'post' => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
            ]),
        ]);
        $accessToken = (string) ($token['access_token'] ?? '');
        $profile = music_oauth_request('https://openidconnect.googleapis.com/v1/userinfo', [
            'headers' => ['Authorization: Bearer ' . $accessToken],
        ]);
        music_oauth_login_user($pdo, (string) ($profile['email'] ?? ''), (string) ($profile['name'] ?? ''), 'google', (string) ($profile['picture'] ?? ''));
    } else {
        throw new RuntimeException('Provider chưa được hỗ trợ.');
    }

    header('Location: index.php');
    exit;
} catch (Throwable $e) {
    music_oauth_redirect_error($e->getMessage());
}
