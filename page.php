<?php
require_once __DIR__ . '/includes/music.php';

$pageSlug = trim((string) ($_GET['page'] ?? ($_GET['slug'] ?? '')));
$slugCandidates = function_exists('slug_lookup_candidates') ? slug_lookup_candidates($pageSlug) : array_filter([$pageSlug]);
$pageLang = trim((string) ($_GET['lang'] ?? current_lang_key()));
$page = null;
$errorMessage = $db_error ?? '';

if (!$slugCandidates) {
    http_response_code(404);
    music_render_header(
        music_label('music.meta.page_not_found_title', 'Page not found - CarrotMusic'),
        music_label('music.meta.page_not_found_description', 'The requested page was not found.')
    );
    echo '<section class="content-page"><div class="empty"><strong>' . music_h(music_label('music.error.page_not_found', 'Không tìm thấy page.')) . '</strong><br>' . music_h(music_label('error.missing_slug', 'Thiếu tham số slug.')) . '</div></section>';
    music_render_footer();
    exit;
}

if ($pdo instanceof PDO) {
    try {
        $page = music_fetch_page($pdo, $pageSlug, $pageLang);
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

if (!$page && !$errorMessage) {
    http_response_code(404);
    music_render_header(
        music_label('music.meta.page_not_found_title', 'Page not found - CarrotMusic'),
        music_label('music.meta.page_not_found_description', 'The requested page was not found.')
    );
    echo '<section class="content-page"><div class="empty"><strong>' . music_h(music_label('music.error.page_not_found_colon', 'Không tìm thấy page:')) . '</strong><br>' . music_h($pageSlug) . '</div></section>';
    music_render_footer();
    exit;
}

if ($errorMessage) {
    http_response_code(500);
    music_render_header(
        music_label('music.meta.database_error_title', 'Database error - CarrotMusic'),
        music_label('music.meta.database_error_description', 'Database connection error.')
    );
    echo '<section class="content-page"><div class="empty"><strong>' . music_h(music_label('error.mysql', 'Lỗi MySQL:')) . '</strong><br>' . music_h($errorMessage) . '</div></section>';
    music_render_footer();
    exit;
}

$canonicalSlug = seo_slug_text((string) ($page['slug'] ?? $pageSlug));
$canonicalLang = trim((string) ($page['lang'] ?? '')) ?: $pageLang;
$canonicalUrl = music_page_url($canonicalSlug, $canonicalLang);
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '';
$expectedPath = parse_url($canonicalUrl, PHP_URL_PATH) ?: '';
$redirectNeeded = $requestPath !== $expectedPath || isset($_GET['slug']) || (string) $pageSlug !== $canonicalSlug || $canonicalLang !== $pageLang;

if ($redirectNeeded) {
    $extraQuery = $_GET;
    unset($extraQuery['page'], $extraQuery['slug'], $extraQuery['lang']);
    if ($extraQuery) {
        $canonicalUrl .= '&' . http_build_query($extraQuery);
    }

    header('Location: ' . $canonicalUrl, true, 301);
    exit;
}

$pageTitle = trim((string) ($page['seo_title'] ?? '')) !== ''
    ? (string) $page['seo_title']
    : (string) $page['title'];
$pageTitle .= ' - CarrotMusic';
$pageDescription = trim((string) ($page['seo_description'] ?? '')) !== ''
    ? (string) $page['seo_description']
    : (string) $page['title'];

music_render_header($pageTitle, $pageDescription, music_cover(''));
?>
<article class="content-page">
  <header class="content-page__header">
    <h1><?= music_h((string) $page['title']) ?></h1>
  </header>

  <div class="content-page__body">
    <?= $page['content_html'] ?>
  </div>
</article>

<?php music_render_footer(); ?>
