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
        music_label('music.meta.page_not_found_title', 'Không tìm thấy trang - ' . music_brand_name()),
        music_label('music.meta.page_not_found_description', 'Trang bạn đang tìm hiện không tồn tại hoặc đã được chuyển đi.')
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
        music_label('music.meta.page_not_found_title', 'Không tìm thấy trang - ' . music_brand_name()),
        music_label('music.meta.page_not_found_description', 'Trang bạn đang tìm hiện không tồn tại hoặc đã được chuyển đi.')
    );
    echo '<section class="content-page"><div class="empty"><strong>' . music_h(music_label('music.error.page_not_found_colon', 'Không tìm thấy page:')) . '</strong><br>' . music_h($pageSlug) . '</div></section>';
    music_render_footer();
    exit;
}

if ($errorMessage) {
    http_response_code(500);
    music_render_header(
        music_label('music.meta.database_error_title', music_brand_name() . ' tạm thời chưa sẵn sàng'),
        music_label('music.meta.database_error_description', 'Trang đang gặp sự cố kết nối. Vui lòng quay lại sau.')
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
$visibleQuery = [];
parse_str((string) (parse_url($requestUri, PHP_URL_QUERY) ?: ''), $visibleQuery);
$expectedPath = parse_url($canonicalUrl, PHP_URL_PATH) ?: '';
$redirectNeeded = $requestPath !== $expectedPath || isset($visibleQuery['page']) || isset($visibleQuery['slug']) || (isset($visibleQuery['lang']) && $canonicalLang !== $pageLang);

if ($redirectNeeded) {
    $extraQuery = $visibleQuery;
    unset($extraQuery['page'], $extraQuery['slug'], $extraQuery['lang']);
    if ($extraQuery) {
        $canonicalUrl = music_url_with_query($canonicalUrl, $extraQuery);
    }

    header('Location: ' . $canonicalUrl, true, 301);
    exit;
}

$pageTitle = trim((string) ($page['seo_title'] ?? '')) !== ''
    ? (string) $page['seo_title']
    : (string) $page['title'];
$pageTitle .= ' - ' . music_brand_name();
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
