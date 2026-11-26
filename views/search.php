<?php
$coreViews = dirname(__DIR__, 3) . '/views';
$q = $q ?? '';
$type = $type ?? 'posts';
$page = (int)($page ?? 1);
$pages = (int)($pages ?? 1);
$total = (int)($total ?? 0);
$threads = $threads ?? [];
$people = $people ?? [];
$tags = $tags ?? [];
$reportSuccess = \SocialBored\Util::flash('report_success');
$reportError = \SocialBored\Util::flash('report_error');
$pm = \App::getInstance()->pluginManager;
$features = \SocialBored\Settings::featuresFor($pm);

include $coreViews . '/header.php';
\render_header(\t('search'), ['sidebar' => false, 'wide' => true]);
?>
<div class="social-shell social-shell-narrow">
    <header class="social-page-head">
        <div>
            <h1 class="social-page-title"><?= \escape(\pt('socialbored', 'search_title')) ?></h1>
            <p class="social-page-subtitle"><?= \escape(\pt('socialbored', 'search_subtitle')) ?></p>
        </div>
    </header>

    <?php if ($reportSuccess !== ''): ?>
        <div class="alert alert-success social-alert"><i class="fas fa-check-circle"></i><?= \escape($reportSuccess) ?></div>
    <?php endif; ?>
    <?php if ($reportError !== ''): ?>
        <div class="alert alert-danger social-alert"><i class="fas fa-exclamation-circle"></i><?= \escape($reportError) ?></div>
    <?php endif; ?>

    <form class="social-search-form" method="GET" action="<?= \escape(\SocialBored\Http::url('search')) ?>" role="search">
        <i class="fas fa-search"></i>
        <input type="search" name="q" value="<?= \escape($q) ?>" placeholder="<?= \escape(\pt('socialbored', 'search_placeholder')) ?>" required>
        <input type="hidden" name="type" value="<?= \escape($type) ?>">
        <button type="submit" class="btn btn-brand btn-sm"><?= \escape(\t('search')) ?></button>
    </form>

    <nav class="social-tabs social-search-tabs" aria-label="<?= \escape(\pt('socialbored', 'search_title')) ?>">
        <?php
        $searchTabs = ['posts' => 'search_posts', 'people' => 'search_people'];
        if (!empty($features['hashtags'])) {
            $searchTabs['tags'] = 'search_tags';
        }
        foreach ($searchTabs as $key => $labelKey):
            ?>
            <a class="social-tab<?= $type === $key ? ' is-active' : '' ?>" href="<?= \escape(\SocialBored\Util::searchUrl($q, $key)) ?>"><?= \escape(\pt('socialbored', $labelKey)) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($q === ''): ?>
        <div class="social-empty"><i class="fas fa-magnifying-glass"></i><p><?= \escape(\pt('socialbored', 'search_empty_query')) ?></p></div>
    <?php elseif ($type === 'people'): ?>
        <?php if (empty($people)): ?>
            <div class="social-empty"><i class="fas fa-user-group"></i><p><?= \escape(\pt('socialbored', 'search_no_people')) ?></p></div>
        <?php else: ?>
            <div class="social-card social-user-list social-user-list-lg">
                <?php foreach ($people as $user): ?>
                    <?= \SocialBored\View::userCard($user) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php elseif ($type === 'tags'): ?>
        <?php if (empty($tags)): ?>
            <div class="social-empty"><i class="fas fa-hashtag"></i><p><?= \escape(\pt('socialbored', 'search_no_tags')) ?></p></div>
        <?php else: ?>
            <div class="social-card social-tag-list">
                <?php foreach ($tags as $tag): ?>
                    <a class="social-tag-chip" href="<?= \escape(\SocialBored\Util::hashtagUrl((string)$tag['tag'])) ?>">
                        <span>#<?= \escape((string)$tag['tag']) ?></span>
                        <small><?= \SocialBored\Util::compact((int)($tag['total'] ?? 0)) ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php if (empty($threads)): ?>
            <div class="social-empty"><i class="fas fa-comments"></i><p><?= \escape(\pt('socialbored', 'search_no_posts')) ?></p></div>
        <?php else: ?>
            <div class="social-feed">
                <?php foreach ($threads as $thread): ?>
                    <?= \SocialBored\View::postCard($thread) ?>
                <?php endforeach; ?>
            </div>
            <?php if ($pages > 1): ?>
                <nav class="social-pager" aria-label="<?= \escape(\t('pagination')) ?>">
                    <?php
                    $prev = max(1, $page - 1);
                    $next = min($pages, $page + 1);
                    $base = \SocialBored\Util::searchUrl($q, 'posts');
                    $sep = str_contains($base, '?') ? '&' : '?';
                    ?>
                    <a class="social-page-link<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= \escape($base . $sep . 'page=' . $prev) ?>">&laquo;</a>
                    <span><?= (int)$page ?> / <?= (int)$pages ?></span>
                    <a class="social-page-link<?= $page >= $pages ? ' is-disabled' : '' ?>" href="<?= \escape($base . $sep . 'page=' . $next) ?>">&raquo;</a>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php \render_footer(); ?>
