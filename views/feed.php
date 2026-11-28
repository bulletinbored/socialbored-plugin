<?php
$coreViews = dirname(__DIR__, 3) . '/views';
$feedHeading = $feedHeading ?? \pt('socialbored', 'feed');
$feedEmpty = $feedEmpty ?? \pt('socialbored', 'no_posts');
$showTabs = $showTabs ?? true;
$showComposer = $showComposer ?? true;
$feedBase = $feedBase ?? \SocialBored\Http::url('');
$loggedIn = \SocialBored\Http::loggedIn();
$viewerId = $viewerId ?? \SocialBored\Http::userId();
$pm = \App::getInstance()->pluginManager;
$features = \SocialBored\Settings::featuresFor($pm);
$pdo = \App::getInstance()->pdo;
$sidebarData = $pdo instanceof \PDO ? \SocialBored\Feed::sidebarData($pdo, $viewerId) : [];
$viewerProfile = $sidebarData['profile'] ?? null;
$viewerStats = $sidebarData['stats'] ?? null;
$trendingTags = $sidebarData['trending'] ?? [];

$reportSuccess = \SocialBored\Util::flash('report_success');
$reportError = \SocialBored\Util::flash('report_error');

include $coreViews . '/header.php';
\render_header($feedHeading, ['sidebar' => false, 'wide' => true]);
?>
<div class="social-shell">
    <div class="social-layout">
        <section class="social-main">
            <header class="social-page-head">
                <div>
                    <h1 class="social-page-title"><?= \escape($feedHeading) ?></h1>
                    <p class="social-page-subtitle"><?= \escape(\pt('socialbored', 'feed_subtitle')) ?></p>
                </div>
                <?php if ($loggedIn): ?>
                    <a class="btn btn-brand social-compose-btn" href="<?= \escape(\SocialBored\Http::url('compose')) ?>">
                        <i class="fas fa-pen-to-square"></i><span><?= \escape(\pt('socialbored', 'compose')) ?></span>
                    </a>
                <?php endif; ?>
            </header>

            <?php if ($reportSuccess !== ''): ?>
                <div class="alert alert-success social-alert"><i class="fas fa-check-circle"></i><?= \escape($reportSuccess) ?></div>
            <?php endif; ?>
            <?php if ($reportError !== ''): ?>
                <div class="alert alert-danger social-alert"><i class="fas fa-exclamation-circle"></i><?= \escape($reportError) ?></div>
            <?php endif; ?>

            <?php if ($showComposer): ?>
                <?php if ($loggedIn): ?>
                    <a class="social-compose-prompt" href="<?= \escape(\SocialBored\Http::url('compose')) ?>">
                        <?= \SocialBored\Util::avatar((string)($_SESSION['username'] ?? ''), (string)($_SESSION['avatar'] ?? ''), 42) ?>
                        <span><?= \escape(\pt('socialbored', 'compose_prompt')) ?></span>
                        <i class="fas fa-image"></i>
                    </a>
                <?php else: ?>
                    <div class="social-guest-cta">
                        <div>
                            <strong><?= \escape(\pt('socialbored', 'guest_title')) ?></strong>
                            <p><?= \escape(\pt('socialbored', 'guest_body')) ?></p>
                        </div>
                        <div class="social-guest-actions">
                            <a class="btn btn-brand btn-sm" href="<?= \escape(\url('login')) ?>"><?= \escape(\t('login')) ?></a>
                            <a class="btn btn-outline-soft btn-sm" href="<?= \escape(\url('register')) ?>"><?= \escape(\t('register')) ?></a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($showTabs): ?>
                <nav class="social-tabs" aria-label="<?= \escape(\pt('socialbored', 'feed')) ?>">
                    <?php
                    $tabs = [
                        'following' => \pt('socialbored', 'tab_following'),
                        'discover' => \pt('socialbored', 'tab_discover'),
                        'latest' => \pt('socialbored', 'tab_latest'),
                    ];
                    if (!$loggedIn || empty($features['following_feed'])) {
                        unset($tabs['following']);
                    }
                    foreach ($tabs as $key => $label):
                        $href = $feedBase . '?tab=' . $key;
                        ?>
                        <a class="social-tab<?= $tab === $key ? ' is-active' : '' ?>" href="<?= \escape($href) ?>"><?= \escape($label) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <?php if (empty($threads)): ?>
                <div class="social-empty">
                    <i class="fas fa-comments"></i>
                    <p><?= \escape($feedEmpty) ?></p>
                    <?php if ($loggedIn): ?>
                        <a class="btn btn-brand btn-sm" href="<?= \escape(\SocialBored\Http::url('compose')) ?>"><?= \escape(\pt('socialbored', 'compose_first')) ?></a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="social-feed">
                    <?php foreach ($threads as $thread): ?>
                        <?= \SocialBored\View::postCard($thread) ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($pages > 1): ?>
                    <nav class="social-pager" aria-label="<?= \escape(\t('pagination')) ?>">
                        <?php
                        $baseParams = [];
                        if ($showTabs) {
                            $baseParams['tab'] = $tab;
                        }
                        $prev = max(1, $page - 1);
                        $next = min($pages, $page + 1);
                        ?>
                        <a class="social-page-link<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= \escape($feedBase . '?' . http_build_query($baseParams + ['page' => $prev])) ?>">&laquo;</a>
                        <span><?= (int)$page ?> / <?= (int)$pages ?></span>
                        <a class="social-page-link<?= $page >= $pages ? ' is-disabled' : '' ?>" href="<?= \escape($feedBase . '?' . http_build_query($baseParams + ['page' => $next])) ?>">&raquo;</a>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <aside class="social-aside">
            <?php if (is_array($viewerProfile)): ?>
                <?php
                $viewerUsername = (string)($viewerProfile['username'] ?? '');
                $viewerUrl = \SocialBored\Util::profileUrl($viewerUsername);
                $viewerCover = (string)($viewerProfile['cover'] ?? '');
                $viewerHasCover = \SocialBored\Util::hasCover($viewerCover);
                $viewerDisplay = (string)($viewerProfile['display_name'] ?? $viewerUsername);
                ?>
                <div class="social-card social-viewer-card">
                    <a class="social-viewer-cover<?= $viewerHasCover ? ' has-image' : '' ?>"
                       href="<?= \escape($viewerUrl) ?>"
                       <?= $viewerHasCover ? 'style="background-image:url(\'' . \escape(\SocialBored\Util::coverUrl($viewerCover)) . '\')"' : '' ?>>
                    </a>
                    <div class="social-viewer-body">
                        <a class="social-viewer-avatar" href="<?= \escape($viewerUrl) ?>">
                            <?= \SocialBored\Util::avatar($viewerUsername, (string)($viewerProfile['avatar'] ?? ''), 64) ?>
                        </a>
                        <a class="social-viewer-name" href="<?= \escape($viewerUrl) ?>">
                            <strong><?= \escape($viewerDisplay !== '' ? $viewerDisplay : $viewerUsername) ?></strong>
                            <span>@<?= \escape($viewerUsername) ?></span>
                        </a>
                        <?php if (!empty($viewerProfile['bio'])): ?>
                            <p class="social-viewer-bio"><?= \escape(\SocialBored\Util::truncate((string)$viewerProfile['bio'], 120)) ?></p>
                        <?php endif; ?>
                        <div class="social-viewer-stats">
                            <a href="<?= \escape($viewerUrl) ?>">
                                <strong><?= \SocialBored\Util::compact((int)($viewerStats['posts'] ?? 0)) ?></strong>
                                <span><?= \escape(\pt('socialbored', 'stat_posts')) ?></span>
                            </a>
                            <a href="<?= \escape($viewerUrl) ?>/followers">
                                <strong><?= \SocialBored\Util::compact((int)($viewerStats['followers'] ?? 0)) ?></strong>
                                <span><?= \escape(\pt('socialbored', 'followers')) ?></span>
                            </a>
                            <a href="<?= \escape($viewerUrl) ?>/following">
                                <strong><?= \SocialBored\Util::compact((int)($viewerStats['following'] ?? 0)) ?></strong>
                                <span><?= \escape(\pt('socialbored', 'following')) ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="social-card social-stats">
                <div><strong><?= \SocialBored\Util::compact($stats['posts'] ?? 0) ?></strong><span><?= \escape(\pt('socialbored', 'stat_posts')) ?></span></div>
                <div><strong><?= \SocialBored\Util::compact($stats['members'] ?? 0) ?></strong><span><?= \escape(\pt('socialbored', 'stat_members')) ?></span></div>
                <div><strong><?= \SocialBored\Util::compact($stats['follows'] ?? 0) ?></strong><span><?= \escape(\pt('socialbored', 'stat_follows')) ?></span></div>
            </div>

            <?php if ($viewerId > 0 && !empty($suggestions)): ?>
                <div class="social-card">
                    <h2 class="social-card-title"><?= \escape(\pt('socialbored', 'who_to_follow')) ?></h2>
                    <div class="social-user-list">
                        <?php foreach ($suggestions as $user): ?>
                            <?= \SocialBored\View::userCard($user) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($trendingTags)): ?>
                <div class="social-card social-trending">
                    <h2 class="social-card-title"><?= \escape(\pt('socialbored', 'trending')) ?></h2>
                    <div class="social-trending-list">
                        <?php foreach ($trendingTags as $tag): ?>
                            <a class="social-trending-item" href="<?= \escape(\SocialBored\Util::hashtagUrl($tag['tag'])) ?>">
                                <span>#<?= \escape($tag['tag']) ?></span>
                                <small><?= \SocialBored\Util::compact($tag['total']) ?> <?= \escape(\pt('socialbored', 'stat_posts')) ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($loggedIn): ?>
                <div class="social-card social-shortcuts">
                    <h2 class="social-card-title"><?= \escape(\pt('socialbored', 'shortcuts')) ?></h2>
                    <a href="<?= \escape(\SocialBored\Http::url('compose')) ?>"><i class="fas fa-pen-to-square"></i><?= \escape(\pt('socialbored', 'new_post')) ?></a>
                    <a href="<?= \escape(\SocialBored\Http::url('messages')) ?>"><i class="fas fa-envelope"></i><?= \escape(\t('messages')) ?></a>
                    <a href="<?= \escape(\SocialBored\Http::url('notifications')) ?>"><i class="fas fa-bell"></i><?= \escape(\t('notifications')) ?></a>
                    <a href="<?= \escape(\SocialBored\Util::profileUrl((string)($_SESSION['username'] ?? ''))) ?>"><i class="fas fa-user"></i><?= \escape(\pt('socialbored', 'my_profile')) ?></a>
                </div>
            <?php endif; ?>

        </aside>
    </div>
</div>
<?php \render_footer(); ?>
