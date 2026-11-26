<?php
/**
 * Classic forum home used by the /forum escape hatch in social/both mode.
 * Reuses the core header, page head and thread list so the layout stays
 * familiar, but keeps sort/pagination links on /forum.
 *
 * @var array $threads
 * @var int   $total
 * @var int   $totalPages
 * @var int   $page
 * @var string $sort
 */
$coreViews = dirname(__DIR__, 3) . '/views';
$pageTitle = \t('all_discussions');
$listContext = 'home';
$searchQuery = '';
$isSearch = false;
$listUrlBase = $listUrlBase ?? 'forum';
$listUrlArgs = $listUrlArgs ?? [];

include $coreViews . '/header.php';
\render_header($pageTitle);
?>
<header class="page-head">
    <div>
        <h1 class="page-title"><?= \escape($pageTitle) ?></h1>
        <p class="page-subtitle"><?= \escape(\t('discussions_count', ['n' => \compact_number($total ?? 0)])) ?></p>
    </div>
    <?php if (function_exists('is_logged_in') && \is_logged_in()): ?>
        <a href="<?= \escape(\url('new_thread')) ?>" class="btn btn-brand d-none d-md-inline-flex">
            <i class="fas fa-pen-to-square me-2"></i><?= \escape(\t('new_thread')) ?>
        </a>
    <?php endif; ?>
</header>

<?php include $coreViews . '/partials/thread_list.php'; ?>
<?php \render_footer(); ?>
