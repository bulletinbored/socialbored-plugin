<?php
$coreViews = dirname(__DIR__, 3) . '/views';
$username = (string)($profileUser['username'] ?? '');
$profileUrl = \SocialBored\Util::profileUrl($username);
$heading = $heading ?? \pt('socialbored', 'followers');
$empty = $empty ?? \pt('socialbored', 'no_followers');
$kind = $kind ?? 'followers';
$listBase = $profileUrl . '/' . ($kind === 'followers' ? 'followers' : 'following');

include $coreViews . '/header.php';
\render_header($heading, ['sidebar' => false, 'wide' => true]);
?>
<div class="social-shell social-shell-narrow">
    <nav class="social-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= \escape($profileUrl) ?>"><i class="fas fa-arrow-left"></i>@<?= \escape($username) ?></a>
    </nav>

    <header class="social-page-head">
        <div>
            <h1 class="social-page-title"><?= \escape($heading) ?></h1>
            <p class="social-page-subtitle">@<?= \escape($username) ?> · <?= \SocialBored\Util::compact($total) ?></p>
        </div>
    </header>

    <?php if (empty($users)): ?>
        <div class="social-empty"><i class="fas fa-users"></i><p><?= \escape($empty) ?></p></div>
    <?php else: ?>
        <div class="social-card social-user-list social-user-list-lg">
            <?php foreach ($users as $user): ?>
                <?= \SocialBored\View::userCard($user) ?>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="social-pager" aria-label="<?= \escape(\t('pagination')) ?>">
                <?php
                $prev = max(1, $page - 1);
                $next = min($pages, $page + 1);
                ?>
                <a class="social-page-link<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= \escape($listBase . '?page=' . $prev) ?>">&laquo;</a>
                <span><?= (int)$page ?> / <?= (int)$pages ?></span>
                <a class="social-page-link<?= $page >= $pages ? ' is-disabled' : '' ?>" href="<?= \escape($listBase . '?page=' . $next) ?>">&raquo;</a>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php \render_footer(); ?>
