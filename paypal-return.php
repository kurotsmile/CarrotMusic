<?php
require_once __DIR__ . '/includes/music.php';

$songId = trim((string) ($_GET['id'] ?? ''));
$orderId = trim((string) ($_GET['token'] ?? ''));
$payerId = trim((string) ($_GET['PayerID'] ?? ''));
$paypalConfig = music_paypal_config($pdo ?? null, 'music');
$errorMessage = $db_error ?? '';
$noticeMessage = '';
$order = null;
$song = null;

if ($orderId === '') {
    http_response_code(400);
    $errorMessage = 'Thiếu thông tin đơn hàng thanh toán.';
} elseif (!$pdo instanceof PDO) {
    http_response_code(500);
    $errorMessage = $errorMessage ?: music_brand_name() . ' đang gặp sự cố kết nối. Vui lòng thử lại sau.';
} else {
    try {
        $stmt = $pdo->prepare('
            SELECT o.*, s.name AS song_name, s.artist AS song_artist, s.avatar AS song_avatar, s.mp3 AS song_mp3
            FROM song_orders o
            LEFT JOIN song s ON s.id = o.song_id
            WHERE o.paypal_order_id = ? ' . ($songId !== '' ? 'AND o.song_id = ? ' : '') . '
            LIMIT 1
        ');
        $stmt->execute($songId !== '' ? [$orderId, $songId] : [$orderId]);
        $order = $stmt->fetch() ?: null;

        if (!$order) {
            http_response_code(404);
            $errorMessage = 'Không tìm thấy đơn hàng thanh toán này.';
        } elseif (($order['status'] ?? '') !== 'COMPLETED' && $payerId !== '') {
            if (empty($paypalConfig['client_id']) || empty($paypalConfig['client_secret']) || !function_exists('curl_init')) {
                throw new RuntimeException('Cổng thanh toán hiện chưa sẵn sàng.');
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
                throw new RuntimeException('Chưa thể xác minh thanh toán. Vui lòng thử lại sau.');
            }

            $curl = curl_init($baseApi . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture');
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '{}',
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $tokenData['access_token'],
                    'Content-Type: application/json',
                ],
            ]);
            $captureResponse = curl_exec($curl);
            $captureStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $captureData = json_decode((string) $captureResponse, true);
            if (!is_array($captureData)) {
                $captureData = ['status' => 'FAILED', 'raw_response' => (string) $captureResponse];
            }

            if ($captureStatus >= 300 || ($captureData['status'] ?? '') !== 'COMPLETED') {
                $pdo->prepare('UPDATE song_orders SET status = ?, paypal_payload = ? WHERE paypal_order_id = ?')->execute([
                    (string) ($captureData['status'] ?? 'FAILED'),
                    json_encode($captureData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $orderId,
                ]);
                throw new RuntimeException('Thanh toán chưa được hoàn tất.');
            }

            $payerEmail = (string) ($captureData['payer']['email_address'] ?? '');
            $pdo->prepare('
                UPDATE song_orders
                SET status = ?, payer_email = ?, paypal_payload = ?, paid_at = COALESCE(paid_at, NOW())
                WHERE paypal_order_id = ?
            ')->execute([
                (string) ($captureData['status'] ?? 'COMPLETED'),
                $payerEmail,
                json_encode($captureData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $orderId,
            ]);
            $_SESSION['paid_music_songs'][(string) $order['song_id']] = true;
            unset($_SESSION['paypal_music_orders'][$orderId]);
            $noticeMessage = 'Thanh toán đã hoàn tất.';

            $stmt->execute($songId !== '' ? [$orderId, $songId] : [$orderId]);
            $order = $stmt->fetch() ?: $order;
        } elseif (($order['status'] ?? '') === 'COMPLETED') {
            $_SESSION['paid_music_songs'][(string) $order['song_id']] = true;
        } else {
            $noticeMessage = 'Đơn hàng chưa hoàn tất thanh toán.';
        }
    } catch (Throwable $e) {
        http_response_code(402);
        $errorMessage = $e->getMessage();
    }
}

if ($pdo instanceof PDO && !empty($order['song_id'])) {
    $song = music_fetch_song($pdo, (string) $order['song_id']);
}

$isCompleted = (($order['status'] ?? '') === 'COMPLETED');
$title = ($order['song_name'] ?? 'Đơn hàng') . ' - Thanh toán ' . music_brand_name();
music_render_header($title, 'Kết quả thanh toán bài hát trên ' . music_brand_name() . '.', music_cover($order['song_avatar'] ?? ''));
?>
<section class="detail">
    <img class="detail-cover" src="<?= music_h(music_cover($order['song_avatar'] ?? ($song['avatar'] ?? ''))) ?>" alt="">
    <div class="detail-main">
        <p class="eyebrow">Thanh toán</p>
        <?php if ($errorMessage): ?>
            <h1>Không thể xác nhận đơn hàng</h1>
            <div class="payment-box"><?= music_h($errorMessage) ?></div>
        <?php elseif ($isCompleted): ?>
            <h1>MP3 đã sẵn sàng tải</h1>
            <div class="payment-box"><?= music_h($noticeMessage ?: 'Cảm ơn bạn đã thanh toán. Bài hát đã sẵn sàng để tải xuống.') ?></div>
        <?php else: ?>
            <h1>Đơn hàng chưa hoàn tất</h1>
            <div class="payment-box"><?= music_h($noticeMessage ?: 'Đơn hàng này chưa được xác nhận hoàn tất.') ?></div>
        <?php endif; ?>
        <div class="detail-meta">
            <span><?= music_h($order['paypal_order_id'] ?? $orderId) ?></span>
            <span><?= music_h($order['status'] ?? '-') ?></span>
            <span><?= music_h(number_format((float) ($order['amount'] ?? 0), 2) . ' ' . ($order['currency'] ?? 'USD')) ?></span>
        </div>
        <div class="song-actions">
            <?php if ($isCompleted && !empty($order['song_mp3'])): ?>
                <a class="btn btn-primary" href="<?= music_h($order['song_mp3']) ?>" download>Tải MP3</a>
            <?php endif; ?>
            <?php if (!empty($order['song_id'])): ?>
                <a class="btn site-link" href="<?= music_h(music_song_url((string) $order['song_id'])) ?>">Quay lại bài hát</a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php music_render_footer(); ?>
