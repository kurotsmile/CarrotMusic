<?php
require_once __DIR__ . '/includes/music.php';

$currentMonth = date('Y-m');
$selectedMonth = trim((string) ($_GET['month'] ?? $currentMonth));
if (!preg_match('/^[0-9]{4}-[0-9]{2}$/', $selectedMonth)) {
    $selectedMonth = $currentMonth;
}
if ($selectedMonth > $currentMonth) {
    $selectedMonth = $currentMonth;
}

$currentPage = max(1, (int) ($_GET['page_no'] ?? 1));
$perPage = 48;
$totalSongs = 0;
$totalPages = 1;
$songs = [];
$errorMessage = $db_error ?? '';
$rankLangKey = current_lang_key();

if ($pdo instanceof PDO) {
    try {
        $cacheKey = music_cache_key('music_monthly_rank', [
            'month' => $selectedMonth,
            'lang' => $rankLangKey,
            'page' => $currentPage,
            'per_page' => $perPage,
            'view' => 'rank_detail_v1',
        ]);
        $cachedRank = music_cache_get($cacheKey, 1800);
        if (is_array($cachedRank)) {
            $songs = is_array($cachedRank['songs'] ?? null) ? $cachedRank['songs'] : [];
            $totalSongs = (int) ($cachedRank['total_songs'] ?? 0);
            $totalPages = max(1, (int) ($cachedRank['total_pages'] ?? 1));
            $currentPage = min($currentPage, $totalPages);
        } else {
            $totalSongs = music_count_monthly_rank_songs($pdo, $selectedMonth, $rankLangKey);
            $totalPages = max(1, (int) ceil($totalSongs / $perPage));
            $currentPage = min($currentPage, $totalPages);
            $offset = ($currentPage - 1) * $perPage;
            $songs = music_fetch_monthly_rank_songs($pdo, $selectedMonth, $perPage, $offset, $rankLangKey);
            music_cache_set($cacheKey, [
                'created_at' => date('c'),
                'total_songs' => $totalSongs,
                'total_pages' => $totalPages,
                'songs' => $songs,
            ]);
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$monthLabel = date('m/Y', strtotime($selectedMonth . '-01'));
$prevMonth = date('Y-m', strtotime($selectedMonth . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($selectedMonth . '-01 +1 month'));
$nextDisabled = $nextMonth > $currentMonth;
$pageTitle = sprintf(music_label('music.rank.heading', 'Bảng xếp hạng tháng %s - ' . music_brand_name()), $monthLabel);
music_render_header($pageTitle, sprintf(music_label('music.rank.meta_description', 'Bảng xếp hạng bài hát theo lượt nghe trong tháng %s trên ' . music_brand_name() . '.'), $monthLabel));
?>
<section class="section rank-section">
    <div class="section-head rank-head">
        <div>
            <h2><?= music_h(sprintf(music_label('music.rank.heading', 'Bảng xếp hạng tháng %s'), $monthLabel)) ?></h2>
            <p><?= number_format($totalSongs) ?> <?= music_h(music_label('music.label.songs', 'bài hát')) ?></p>
        </div>
        <div class="rank-month-controls" data-current-month="<?= music_h($currentMonth) ?>" data-selected-month="<?= music_h($selectedMonth) ?>">
            <a class="section-view-all" href="<?= music_h(music_rank_url($prevMonth)) ?>"><i class="fas fa-chevron-left"></i><?= music_h(music_label('action.previous', 'Trước')) ?></a>
            <button class="section-view-all" type="button" data-rank-month-select><i class="fas fa-calendar-alt"></i><?= music_h(music_label('music.rank.select_other', 'Select Other')) ?></button>
            <span class="rank-month-current"><?= music_h($monthLabel) ?></span>
            <a class="section-view-all <?= $nextDisabled ? 'is-disabled' : '' ?>" href="<?= music_h($nextDisabled ? music_rank_url($selectedMonth) : music_rank_url($nextMonth)) ?>" aria-disabled="<?= $nextDisabled ? 'true' : 'false' ?>"><?= music_h(music_label('action.next', 'Tiếp')) ?><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>
    <?php if ($errorMessage): ?><div class="empty"><?= music_h($errorMessage) ?></div><?php endif; ?>
    <?= music_render_pagination($currentPage, $totalPages, static fn(int $page): string => music_url_with_query(music_rank_url($selectedMonth), ['page_no' => $page])) ?>
    <div class="rank-list">
        <?php foreach ($songs as $index => $song): ?>
            <?php
            $rankNumber = (($currentPage - 1) * $perPage) + $index + 1;
            $rankClass = $rankNumber <= 3 ? ' is-top-' . $rankNumber : '';
            $rankIcon = $rankNumber === 1 ? 'fa-crown' : ($rankNumber === 2 ? 'fa-medal' : ($rankNumber === 3 ? 'fa-award' : 'fa-hashtag'));
            $songArtist = $song['artist_names'] ?: $song['artist'] ?: music_label('music.label.unknown_artist', 'Unknown artist');
            $songUrl = music_song_url((string) $song['id'], (string) ($song['lang'] ?? ''));
            ?>
            <article class="rank-row">
                <span class="rank-row-number<?= music_h($rankClass) ?>"><i class="fas <?= music_h($rankIcon) ?>"></i><?= number_format($rankNumber) ?></span>
                <a class="site-link rank-row-cover" href="<?= music_h($songUrl) ?>"><img src="<?= music_h(music_cover($song['avatar'])) ?>" alt="<?= music_h($song['name']) ?>"></a>
                <div class="rank-row-main">
                    <a class="song-title site-link" href="<?= music_h($songUrl) ?>"><?= music_h($song['name']) ?></a>
                    <div class="song-meta"><?= music_h($songArtist) ?></div>
                </div>
                <div class="rank-row-views"><?= number_format((int) ($song['view_count'] ?? 0)) ?> <span><?= music_h(music_label('music.listen.count', 'lượt nghe')) ?></span></div>
                <div class="song-card-actions rank-row-actions">
                    <button class="btn btn-primary" onclick="cr_player.play_emp(this)" cr-id="<?= music_h($song['id']) ?>" cr-link="<?= music_h($songUrl) ?>" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($songArtist) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><?= music_play_icon() ?><?= music_h(music_label('music.action.play', 'Phát')) ?></button>
                    <button class="icon-btn" title="<?= music_h(music_label('music.action.add_to_playlist', 'Thêm vào playlist')) ?>" onclick="cr_player.add_emp(this)" cr-id="<?= music_h($song['id']) ?>" cr-link="<?= music_h($songUrl) ?>" cr-url="<?= music_h($song['mp3']) ?>" cr-name="<?= music_h($song['name']) ?>" cr-artist="<?= music_h($songArtist) ?>" cr-avatar="<?= music_h(music_cover($song['avatar'])) ?>"><i class="fas fa-plus"></i></button>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if (!$songs && !$errorMessage): ?><div class="empty"><?= music_h(music_label('music.rank.empty_month', 'Tháng này chưa có dữ liệu xếp hạng.')) ?></div><?php endif; ?>
    <?= music_render_pagination($currentPage, $totalPages, static fn(int $page): string => music_url_with_query(music_rank_url($selectedMonth), ['page_no' => $page])) ?>
</section>
<script>
(() => {
    const controls = document.querySelector('.rank-month-controls');
    const button = document.querySelector('[data-rank-month-select]');
    if (!controls || !button || !window.Swal) return;
    button.addEventListener('click', async () => {
        const result = await Swal.fire({
            title: <?= json_encode(music_label('music.rank.select_month', 'Chọn tháng'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            html: `<input id="rank_month_input" class="swal2-input" type="month" max="${controls.dataset.currentMonth}" value="${controls.dataset.selectedMonth}">`,
            confirmButtonText: <?= json_encode(music_label('action.view', 'Xem'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            showCancelButton: true,
            preConfirm: () => document.getElementById('rank_month_input')?.value || ''
        });
        if (!result.isConfirmed || !/^[0-9]{4}-[0-9]{2}$/.test(result.value || '')) return;
        const url = new URL(<?= json_encode(music_absolute_url('rank'), JSON_UNESCAPED_SLASHES) ?>);
        url.searchParams.set('month', result.value);
        window.location.href = url.toString();
    });
})();
</script>
<?php music_render_footer(); ?>
