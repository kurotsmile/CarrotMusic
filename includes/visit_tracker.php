<?php

function music_visit_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_REAL_IP'] ?? '',
        strtok((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), ',') ?: '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $ip = trim((string) $candidate);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

function music_visit_request_path(): string
{
    return substr((string) ($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? '')), 0, 1024);
}

function music_visit_track_daily_ip(?PDO $pdo): void
{
    if (!$pdo instanceof PDO || PHP_SAPI === 'cli') {
        return;
    }

    $ip = music_visit_client_ip();
    if ($ip === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO visit_daily_ip (
              site, visit_date, ip_address, ip_text, first_seen_at, last_seen_at,
              hits, user_agent, referer, request_path
            )
            VALUES ('music', CURRENT_DATE, INET6_ATON(:ip), :ip_text, NOW(), NOW(), 1, :user_agent, :referer, :request_path)
            ON DUPLICATE KEY UPDATE
              hits = hits + 1,
              last_seen_at = NOW(),
              user_agent = VALUES(user_agent),
              referer = VALUES(referer),
              request_path = VALUES(request_path),
              updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':ip' => $ip,
            ':ip_text' => $ip,
            ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512) ?: null,
            ':referer' => substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1024) ?: null,
            ':request_path' => music_visit_request_path(),
        ]);
    } catch (Throwable $e) {
        error_log('music_visit_track_daily_ip failed: ' . $e->getMessage());
    }
}
