<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

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

function music_visit_ensure_tracking_tables(PDO $pdo, string $site): void
{
    $site = preg_replace('/[^a-z0-9_-]/i', '', $site) ?: 'web';
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS visit_daily_ip (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          site VARCHAR(32) NOT NULL DEFAULT '{$site}',
          visit_date DATE NOT NULL,
          ip_address VARBINARY(16) NOT NULL,
          ip_text VARCHAR(45) NOT NULL,
          first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          hits INT UNSIGNED NOT NULL DEFAULT 1,
          user_agent VARCHAR(512) DEFAULT NULL,
          referer VARCHAR(1024) DEFAULT NULL,
          request_path VARCHAR(1024) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_visit_daily_ip (site, visit_date, ip_address),
          KEY idx_visit_site_date (site, visit_date),
          KEY idx_visit_date (visit_date),
          KEY idx_visit_last_seen_at (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS visit_hourly_ip (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          site VARCHAR(32) NOT NULL DEFAULT '{$site}',
          visit_date DATE NOT NULL,
          visit_hour TINYINT UNSIGNED NOT NULL,
          ip_address VARBINARY(16) NOT NULL,
          ip_text VARCHAR(45) NOT NULL,
          first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          hits INT UNSIGNED NOT NULL DEFAULT 1,
          user_agent VARCHAR(512) DEFAULT NULL,
          referer VARCHAR(1024) DEFAULT NULL,
          request_path VARCHAR(1024) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_visit_hourly_ip (site, visit_date, visit_hour, ip_address),
          KEY idx_visit_hourly_site_date_hour (site, visit_date, visit_hour),
          KEY idx_visit_hourly_date_hour (visit_date, visit_hour),
          KEY idx_visit_hourly_last_seen_at (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
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
        music_visit_ensure_tracking_tables($pdo, 'music');
        $visitDate = date('Y-m-d');
        $seenAt = date('Y-m-d H:i:s');
        $visitHour = (int) date('G');
        $stmt = $pdo->prepare("
            INSERT INTO visit_daily_ip (
              site, visit_date, ip_address, ip_text, first_seen_at, last_seen_at,
              hits, user_agent, referer, request_path
            )
            VALUES ('music', :visit_date, INET6_ATON(:ip), :ip_text, :first_seen_at, :last_seen_at, 1, :user_agent, :referer, :request_path)
            ON DUPLICATE KEY UPDATE
              hits = hits + 1,
              last_seen_at = VALUES(last_seen_at),
              user_agent = VALUES(user_agent),
              referer = VALUES(referer),
              request_path = VALUES(request_path),
              updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':visit_date' => $visitDate,
            ':first_seen_at' => $seenAt,
            ':last_seen_at' => $seenAt,
            ':ip' => $ip,
            ':ip_text' => $ip,
            ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512) ?: null,
            ':referer' => substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1024) ?: null,
            ':request_path' => music_visit_request_path(),
        ]);
    } catch (Throwable $e) {
        error_log('music_visit_track_daily_ip daily failed: ' . $e->getMessage());
        return;
    }

    try {
        $hourlyStmt = $pdo->prepare("
            INSERT INTO visit_hourly_ip (
              site, visit_date, visit_hour, ip_address, ip_text, first_seen_at, last_seen_at,
              hits, user_agent, referer, request_path
            )
            VALUES ('music', :visit_date, :visit_hour, INET6_ATON(:ip), :ip_text, :first_seen_at, :last_seen_at, 1, :user_agent, :referer, :request_path)
            ON DUPLICATE KEY UPDATE
              hits = hits + 1,
              last_seen_at = VALUES(last_seen_at),
              user_agent = VALUES(user_agent),
              referer = VALUES(referer),
              request_path = VALUES(request_path),
              updated_at = CURRENT_TIMESTAMP
        ");
        $hourlyStmt->execute([
            ':visit_date' => $visitDate,
            ':visit_hour' => $visitHour,
            ':first_seen_at' => $seenAt,
            ':last_seen_at' => $seenAt,
            ':ip' => $ip,
            ':ip_text' => $ip,
            ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512) ?: null,
            ':referer' => substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1024) ?: null,
            ':request_path' => music_visit_request_path(),
        ]);
    } catch (Throwable $e) {
        error_log('music_visit_track_daily_ip hourly failed: ' . $e->getMessage());
    }
}

function music_ensure_song_search_log_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS song_search_log (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          query VARCHAR(255) NOT NULL,
          normalized_query VARCHAR(255) NOT NULL,
          lang VARCHAR(24) DEFAULT NULL,
          result_count INT UNSIGNED NOT NULL DEFAULT 0,
          ip_address VARBINARY(16) DEFAULT NULL,
          ip_text VARCHAR(45) DEFAULT NULL,
          user_agent VARCHAR(512) DEFAULT NULL,
          referer VARCHAR(1024) DEFAULT NULL,
          request_path VARCHAR(1024) DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_song_search_log_created (created_at),
          KEY idx_song_search_log_query (normalized_query),
          KEY idx_song_search_log_lang_created (lang, created_at),
          KEY idx_song_search_log_ip_created (ip_text, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function music_log_song_search(?PDO $pdo, string $query, int $resultCount = 0): void
{
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    if (!$pdo instanceof PDO || PHP_SAPI === 'cli' || $query === '') {
        return;
    }

    $query = function_exists('mb_substr') ? mb_substr($query, 0, 255, 'UTF-8') : substr($query, 0, 255);
    $normalizedQuery = function_exists('mb_strtolower') ? mb_strtolower($query, 'UTF-8') : strtolower($query);
    $ip = music_visit_client_ip();

    try {
        music_ensure_song_search_log_table($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO song_search_log (
              query, normalized_query, lang, result_count, ip_address, ip_text,
              user_agent, referer, request_path
            )
            VALUES (
              :query, :normalized_query, :lang, :result_count,
              IF(:ip_for_null = '', NULL, INET6_ATON(:ip_for_aton)), NULLIF(:ip_text, ''),
              :user_agent, :referer, :request_path
            )
        ");
        $stmt->execute([
            ':query' => $query,
            ':normalized_query' => $normalizedQuery,
            ':lang' => substr((string) (function_exists('current_lang_key') ? current_lang_key() : ($_SESSION['key_lang'] ?? '')), 0, 24) ?: null,
            ':result_count' => max(0, $resultCount),
            ':ip_for_null' => $ip,
            ':ip_for_aton' => $ip,
            ':ip_text' => $ip,
            ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512) ?: null,
            ':referer' => substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1024) ?: null,
            ':request_path' => music_visit_request_path(),
        ]);
    } catch (Throwable $e) {
        error_log('music_log_song_search failed: ' . $e->getMessage());
    }
}

function music_track_song_view(?PDO $pdo, string $songId): void
{
    $songId = trim($songId);
    if (!$pdo instanceof PDO || PHP_SAPI === 'cli' || $songId === '') {
        return;
    }

    $ip = music_visit_client_ip();
    if ($ip === '') {
        return;
    }

    try {
        $viewDate = date('Y-m-d');
        $seenAt = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            INSERT INTO song_view (
              song_id, view_date, ip_address, ip_text, first_seen_at, last_seen_at,
              hits, user_agent, referer, request_path
            )
            VALUES (:song_id, :view_date, INET6_ATON(:ip), :ip_text, :first_seen_at, :last_seen_at, 1, :user_agent, :referer, :request_path)
            ON DUPLICATE KEY UPDATE
              hits = hits + 1,
              last_seen_at = VALUES(last_seen_at),
              user_agent = VALUES(user_agent),
              referer = VALUES(referer),
              request_path = VALUES(request_path),
              updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':song_id' => $songId,
            ':view_date' => $viewDate,
            ':first_seen_at' => $seenAt,
            ':last_seen_at' => $seenAt,
            ':ip' => $ip,
            ':ip_text' => $ip,
            ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512) ?: null,
            ':referer' => substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 1024) ?: null,
            ':request_path' => music_visit_request_path(),
        ]);
    } catch (Throwable $e) {
        error_log('music_track_song_view failed: ' . $e->getMessage());
    }
}

function music_song_view_count(?PDO $pdo, string $songId): int
{
    $songId = trim($songId);
    if (!$pdo instanceof PDO || $songId === '') {
        return 0;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM song_view WHERE song_id = :song_id');
        $stmt->execute([':song_id' => $songId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('music_song_view_count failed: ' . $e->getMessage());
        return 0;
    }
}
