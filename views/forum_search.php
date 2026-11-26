<?php
/**
 * Privacy-aware classic search results (forum mode).
 *
 * @var array  $threads
 * @var int    $total
 * @var int    $totalPages
 * @var int    $page
 * @var string $sort
 * @var string $searchQuery
 */
$coreViews = dirname(__DIR__, 3) . '/views';
$pageTitle = \t('search_results');
$searchQuery = (string)($searchQuery ?? '');
$total = (int)($total ?? 0);
$listContext = 'search';
$isSearch = true;

include $coreViews . '/header.php';
\render_header($pageTitle);
?>
<header class="page-head">
    <div>
        <h1 class="page-title"><?= \escape($pageTitle) ?></h1>
        <p class="page-subtitle">
            <?php if ($searchQuery !== ''): ?>
                <?= \t('search_summary', ['n' => $total, 'q' => \escape($searchQuery)]) ?>
            <?php else: ?>
                <?= \escape(\pt('socialbored', 'search_empty_query')) ?>
            <?php endif; ?>
        </p>
    </div>
    <?php if (function_exists('is_logged_in') && \is_logged_in()): ?>
        <a href="<?= \escape(\url('new_thread')) ?>" class="btn btn-brand d-none d-md-inline-flex">
            <i class="fas fa-pen-to-square me-2"></i><?= \escape(\t('new_thread')) ?>
        </a>
    <?php endif; ?>
</header>

<form class="search-inline" method="GET" action="<?= \escape(\url('search')) ?>">
    <i class="fas fa-search"></i>
    <input type="text" name="q" value="<?= \escape($searchQuery) ?>" placeholder="<?= \escape(\t('search')) ?>…" required>
    <button type="submit" class="btn btn-brand btn-sm"><?= \escape(\t('search')) ?></button>
</form>

<?php include $coreViews . '/partials/thread_list.php'; ?>
<?php \render_footer(); ?>
