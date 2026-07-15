<?php
require_once __DIR__ . '/includes/music.php';

$songId = trim((string) ($_GET['id'] ?? ''));
$paypalConfig = music_paypal_config($pdo ?? null, 'music');

if (!$pdo instanceof PDO || $songId === '' || !$paypalConfig['enabled']) {
    http_response_code(400);
    exit('Invalid payment request.');
}

$song = music_fetch_song($pdo, $songId);
if (!$song || empty($song['mp3'])) {
    http_response_code(404);
    exit('MP3 is not available.');
}

$price = music_song_price($song, $paypalConfig);
if ($price <= 0) {
    $_SESSION['paid_music_songs'][$songId] = true;
    header('Location: ' . music_song_url($songId));
    exit;
}

if (empty($paypalConfig['client_id']) || empty($paypalConfig['client_secret']) || !function_exists('curl_init')) {
    http_response_code(500);
    exit('Cổng thanh toán hiện chưa sẵn sàng. Vui lòng quay lại sau.');
}

$baseApi = !empty($paypalConfig['sandbox']) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
$auth = base64_encode($paypalConfig['client_id'] . ':' . $paypalConfig['client_secret']);

$curl = curl_init($baseApi . '/v1/oauth2/token');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
    CURLOPT_HTTPHEADER => [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);
$tokenResponse = curl_exec($curl);
$tokenStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
$tokenData = json_decode((string) $tokenResponse, true);
if ($tokenStatus >= 300 || empty($tokenData['access_token'])) {
    http_response_code(502);
    exit('Chưa thể kết nối đến cổng thanh toán. Vui lòng thử lại sau.');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'music.carrot28.com';
$returnUrl = $scheme . '://' . $host . music_url('paypal-return.php?id=' . rawurlencode($songId));
$cancelUrl = $scheme . '://' . $host . music_song_url($songId);

$payload = [
    'intent' => 'CAPTURE',
    'purchase_units' => [[
        'reference_id' => $songId,
        'description' => 'MP3 download for ' . (string) $song['name'],
        'amount' => [
            'currency_code' => $paypalConfig['currency'],
            'value' => number_format($price, 2, '.', ''),
        ],
    ]],
    'application_context' => [
        'return_url' => $returnUrl,
        'cancel_url' => $cancelUrl,
        'user_action' => 'PAY_NOW',
    ],
];

$curl = curl_init($baseApi . '/v2/checkout/orders');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $tokenData['access_token'],
        'Content-Type: application/json',
    ],
]);
$orderResponse = curl_exec($curl);
$orderStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
$orderData = json_decode((string) $orderResponse, true);
if ($orderStatus >= 300 || empty($orderData['id']) || empty($orderData['links'])) {
    http_response_code(502);
    exit('Chưa thể tạo đơn thanh toán. Vui lòng thử lại sau.');
}

$stmt = $pdo->prepare('
    INSERT INTO song_orders (song_id, user_id, paypal_order_id, status, amount, currency, paypal_payload)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE status = VALUES(status), amount = VALUES(amount), currency = VALUES(currency), paypal_payload = VALUES(paypal_payload)
');
$stmt->execute([
    $songId,
    !empty($_SESSION['home_user_id']) ? (int) $_SESSION['home_user_id'] : null,
    $orderData['id'],
    (string) ($orderData['status'] ?? 'CREATED'),
    number_format($price, 2, '.', ''),
    $paypalConfig['currency'],
    json_encode($orderData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

$_SESSION['paypal_music_orders'][$orderData['id']] = $songId;
foreach ($orderData['links'] as $link) {
    if (($link['rel'] ?? '') === 'approve' && !empty($link['href'])) {
        header('Location: ' . $link['href']);
        exit;
    }
}

http_response_code(502);
exit('Chưa nhận được liên kết xác nhận thanh toán. Vui lòng thử lại sau.');
