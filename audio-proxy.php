<?php
require_once __DIR__ . '/includes/music.php';

$url = trim((string) ($_GET['url'] ?? ''));
$parts = parse_url($url);
$scheme = strtolower((string) ($parts['scheme'] ?? ''));
$host = strtolower((string) ($parts['host'] ?? ''));
$path = (string) ($parts['path'] ?? '');
$allowedHosts = ['nas.carrot28.com'];

if (!in_array($scheme, ['http', 'https'], true) || !in_array($host, $allowedHosts, true) || !preg_match('/\.mp3$/i', $path)) {
    http_response_code(400);
    exit('Invalid audio URL.');
}

$origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '') {
    $originHost = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?: ''));
    if (in_array($originHost, [music_primary_host(), 'music.carrot28.com'], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

header('Content-Type: audio/mpeg');
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Range');
    exit;
}

if (!function_exists('curl_init')) {
    $context = stream_context_create([
        'http' => [
            'method' => $_SERVER['REQUEST_METHOD'] === 'HEAD' ? 'HEAD' : 'GET',
            'header' => !empty($_SERVER['HTTP_RANGE']) ? 'Range: ' . $_SERVER['HTTP_RANGE'] . "\r\n" : '',
            'ignore_errors' => true,
        ],
    ]);
    @readfile($url, false, $context);
    exit;
}

$curl = curl_init($url);
$requestHeaders = ['User-Agent: Heart Beat Play Audio Proxy'];
if (!empty($_SERVER['HTTP_RANGE'])) {
    $requestHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
}

curl_setopt_array($curl, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_HTTPHEADER => $requestHeaders,
    CURLOPT_NOBODY => $_SERVER['REQUEST_METHOD'] === 'HEAD',
    CURLOPT_HEADER => false,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine): int {
        $trimmed = trim($headerLine);
        if ($trimmed === '') {
            return strlen($headerLine);
        }
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $trimmed, $match)) {
            $status = (int) $match[1];
            if ($status >= 200 && $status < 600) {
                http_response_code($status);
            }
            return strlen($headerLine);
        }

        $separator = strpos($trimmed, ':');
        if ($separator === false) {
            return strlen($headerLine);
        }
        $name = strtolower(substr($trimmed, 0, $separator));
        if (in_array($name, ['content-length', 'content-range', 'last-modified', 'etag'], true)) {
            header($trimmed, false);
        }
        return strlen($headerLine);
    },
    CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk): int {
        echo $chunk;
        if (function_exists('flush')) {
            flush();
        }
        return strlen($chunk);
    },
]);

$ok = curl_exec($curl);
if ($ok === false && !headers_sent()) {
    http_response_code(502);
}
curl_close($curl);
