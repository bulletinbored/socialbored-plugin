<?php
$coreViews = dirname(__DIR__, 3) . '/views';
$username = (string)($profileUser['username'] ?? '');
$displayName = trim((string)($profile['display_name'] ?? ''));
if ($displayName === '') {
    $displayName = $username;
}
$cover = (string)($profile['cover'] ?? '');
$hasCover = \SocialBored\Util::hasCover($cover);
$coverUrl = \SocialBored\Util::coverUrl($cover);
$joined = (string)($profileUser['created_at'] ?? '');
$bio = (string)($profile['bio'] ?? '');
$location = (string)($profile['location'] ?? '');
$website = (string)($profile['website'] ?? '');
$websiteSafe = $website !== '' && preg_match('#^https?://#i', $website);
$privacy = (string)($profile['privacy'] ?? 'public');
$profileUrl = \SocialBored\Util::profileUrl($username);
$viewerId = \SocialBored\Http::userId();
$loggedIn = \SocialBored\Http::loggedIn();
$isAdmin = \SocialBored\Http::isAdmin();
$pm = \App::getInstance()->pluginManager;
$hasMessages = $pm !== null && $pm->isEnabled('textmebored');
$settingsSaved = \SocialBored\Util::flash('settings');
$reportSuccess = \SocialBored\Util::flash('report_success');
$reportError = \SocialBored\Util::flash('report_error');
$canView = $canView ?? true;
$isSelf = $isSelf ?? false;
$isFollowing = $isFollowing ?? false;
$isBlocked = $isBlocked ?? false;
$viewerBlockedTarget = $viewerBlockedTarget ?? $isBlocked;
$isMuted = $isMuted ?? false;
$stats = $stats ?? [];
$items = $items ?? [];
$tab = $tab ?? 'posts';
$page = (int)($page ?? 1);
$pages = (int)($pages ?? 1);
$total = (int)($total ?? 0);
$isReplyTab = !empty($isReplyTab);
$redirectPath = '/u/' . rawurlencode($username);

include $coreViews . '/header.php';
\render_header($displayName, ['sidebar' => false, 'wide' => true]);
?>
<div class="social-shell">
    <div class="social-profile">
        <header class="social-profile-head<?= $hasCover ? ' has-cover' : '' ?>">
            <?php if ($hasCover): ?>
                <div class="social-cover" style="background-image:url('<?= \escape($coverUrl) ?>')"></div>
            <?php endif; ?>
            <div class="social-profile-main">
                <div class="social-profile-avatar">
                    <?= \SocialBored\Util::avatar($username, (string)($profileUser['avatar'] ?? ''), 96) ?>
                </div>
                <div class="social-profile-identity">
                    <h1 class="social-profile-name"><?= \escape($displayName) ?></h1>
                    <div class="social-profile-handle">
                        <a href="<?= \escape($profileUrl) ?>">@<?= \escape($username) ?></a>
                        <?php if (($profileUser['role'] ?? 'user') === 'admin'): ?>
                            <span class="social-role social-role-admin"><?= \escape(\t('role_admin')) ?></span>
                        <?php elseif (($profileUser['role'] ?? 'user') === 'moderator'): ?>
                            <span class="social-role"><?= \escape(\t('role_moderator')) ?></span>
                        <?php endif; ?>
                        <?php if ($privacy !== 'public'): ?>
                            <span class="social-visibility"><i class="fas fa-<?= $privacy === 'private' ? 'lock' : 'user-group' ?>"></i><?= \escape(\SocialBored\Util::visibilityLabel($privacy)) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($bio !== '' && (!$isBlocked || $isSelf || \SocialBored\Privacy::isPrivileged($viewerId))): ?>
                        <p class="social-profile-bio"><?= nl2br(\escape($bio)) ?></p>
                    <?php endif; ?>

                    <?php if (!$isBlocked || $isSelf || \SocialBored\Privacy::isPrivileged($viewerId)): ?>
                    <div class="social-profile-facts">
                        <?php if ($location !== ''): ?>
                            <span><i class="fas fa-location-dot"></i><?= \escape($location) ?></span>
                        <?php endif; ?>
                        <?php if ($website !== ''): ?>
                            <?php $websiteHost = (string)parse_url($website, PHP_URL_HOST); ?>
                            <span><i class="fas fa-link"></i>
                                <?php if ($websiteSafe): ?>
                                    <a href="<?= \escape($website) ?>" rel="nofollow noopener" target="_blank"><?= \escape($websiteHost !== '' ? $websiteHost : $website) ?></a>
                                <?php else: ?>
                                    <?= \escape($website) ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <span><i class="fas fa-calendar"></i><?= \escape(\t('joined')) ?> <?= \escape(\SocialBored\Util::timeAgo($joined)) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!$isBlocked || $isSelf || \SocialBored\Privacy::isPrivileged($viewerId)): ?>
                    <div class="social-profile-stats">
                        <a href="<?= \escape($profileUrl . '?tab=posts') ?>"><strong><?= \SocialBored\Util::compact($stats['posts'] ?? 0) ?></strong><span><?= \escape(\pt('socialbored', 'stat_posts')) ?></span></a>
                        <a href="<?= \escape($profileUrl . '?tab=replies') ?>"><strong><?= \SocialBored\Util::compact($stats['replies'] ?? 0) ?></strong><span><?= \escape(\pt('socialbored', 'stat_replies')) ?></span></a>
                        <a href="<?= \escape($profileUrl . '/followers') ?>"><strong><?= \SocialBored\Util::compact($stats['followers'] ?? 0) ?></strong><span><?= \escape(\pt('socialbored', 'followers')) ?></span></a>
                        <a href="<?= \escape($profileUrl . '/following') ?>"><strong><?= \SocialBored\Util::compact($stats['following'] ?? 0) ?></strong><span><?= \escape(\pt('socialbored', 'following')) ?></span></a>
                        <span class="social-stat"><strong><?= \SocialBored\Util::compact($stats['reactions'] ?? 0) ?></strong><span><?= \escape(\pt('socialbored', 'stat_reactions')) ?></span></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="social-profile-actions">
                    <?php if ($isSelf): ?>
                        <a class="btn btn-brand btn-sm" href="<?= \escape(\url('edit_profile')) ?>"><i class="fas fa-pen"></i><?= \escape(\t('edit_profile')) ?></a>
                        <a class="btn btn-outline-soft btn-sm" href="<?= \escape(\SocialBored\Http::url('settings/social')) ?>"><i class="fas fa-user-gear"></i><?= \escape(\pt('socialbored', 'social_settings')) ?></a>
                        <?php if ($hasMessages): ?>
                            <a class="btn btn-outline-soft btn-sm" href="<?= \escape(\SocialBored\Http::url('messages')) ?>"><i class="fas fa-envelope"></i><?= \escape(\t('messages')) ?></a>
                        <?php endif; ?>
                    <?php elseif ($loggedIn): ?>
                        <?php if (!$isBlocked): ?>
                        <?php if ($isFollowing): ?>
                            <form method="POST" action="<?= \escape(\SocialBored\Http::url('unfollow')) ?>" data-social-action="unfollow">
                                <?= \SocialBored\Http::csrfField() ?>
                                <input type="hidden" name="user_id" value="<?= (int)$profileUser['id'] ?>">
                                <input type="hidden" name="username" value="<?= \escape($username) ?>">
                                <button type="submit" class="btn btn-outline-soft btn-sm" data-social-button="follow"><i class="fas fa-user-check"></i><?= \escape(\pt('socialbored', 'action_following')) ?></button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="<?= \escape(\SocialBored\Http::url('follow')) ?>" data-social-action="follow">
                                <?= \SocialBored\Http::csrfField() ?>
                                <input type="hidden" name="user_id" value="<?= (int)$profileUser['id'] ?>">
                                <input type="hidden" name="username" value="<?= \escape($username) ?>">
                                <button type="submit" class="btn btn-brand btn-sm" data-social-button="follow"><i class="fas fa-user-plus"></i><?= \escape(\pt('socialbored', 'action_follow')) ?></button>
                            </form>
                        <?php endif; ?>

                        <?php if ($hasMessages): ?>
                            <button type="button" class="btn btn-outline-soft btn-sm textmebored-send-msg-btn"
                                    data-user-id="<?= (int)$profileUser['id'] ?>"
                                    data-username="<?= \escape($username) ?>">
                                <i class="fas fa-envelope"></i><?= \escape(\t('send_message')) ?>
                            </button>
                        <?php endif; ?>
                        <?php endif; ?>

                        <div class="dropdown">
                            <button class="social-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= \escape(\pt('socialbored', 'actions')) ?>">
                                <i class="fas fa-ellipsis"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($isMuted): ?>
                                    <li>
                                        <form method="POST" action="<?= \escape(\SocialBored\Http::url('unmute')) ?>" class="social-menu-form">
                                            <?= \SocialBored\Http::csrfField() ?>
                                            <input type="hidden" name="user_id" value="<?= (int)$profileUser['id'] ?>">
                                            <button class="dropdown-item" type="submit"><i class="fas fa-bell"></i><?= \escape(\pt('socialbored', 'unmute')) ?></button>
                                        </form>
                                    </li>
                                <?php else: ?>
                                    <li>
                                        <form method="POST" action="<?= \escape(\SocialBored\Http::url('mute')) ?>" class="social-menu-form">
                                            <?= \SocialBored\Http::csrfField() ?>
                                            <input type="hidden" name="user_id" value="<?= (int)$profileUser['id'] ?>">
                                            <button class="dropdown-item" type="submit"><i class="fas fa-bell-slash"></i><?= \escape(\pt('socialbored', 'mute')) ?></button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                                <?php if ($viewerBlockedTarget): ?>
                                    <li>
                                        <form method="POST" action="<?= \escape(\SocialBored\Http::url('unblock')) ?>" class="social-menu-form">
                                            <?= \SocialBored\Http::csrfField() ?>
                                            <input type="hidden" name="user_id" value="<?= (int)$profileUser['id'] ?>">
                                            <input type="hidden" name="username" value="<?= \escape($username) ?>">
                                            <button class="dropdown-item" type="submit"><i class="fas fa-unlock"></i><?= \escape(\pt('socialbored', 'unblock')) ?></button>
                                        </form>
                                    </li>
                                <?php else: ?>
                                    <li>
                                        <form method="POST" action="<?= \escape(\SocialBored\Http::url('block')) ?>" class="social-menu-form" data-confirm="<?= \escape(\pt('socialbored', 'block_confirm')) ?>">
                                            <?= \SocialBored\Http::csrfField() ?>
                                            <input type="hidden" name="user_id" value="<?= (int)$profileUser['id'] ?>">
                                            <input type="hidden" name="username" value="<?= \escape($username) ?>">
                                            <button class="dropdown-item text-danger" type="submit"><i class="fas fa-ban"></i><?= \escape(\pt('socialbored', 'block')) ?></button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="<?= \escape(\SocialBored\Http::url('report')) ?>" class="social-menu-form">
                                        <?= \SocialBored\Http::csrfField() ?>
                                        <input type="hidden" name="target_type" value="user">
                                        <input type="hidden" name="target_id" value="<?= (int)$profileUser['id'] ?>">
                                        <label class="social-menu-label"><i class="fas fa-flag"></i><?= \escape(\pt('socialbored', 'report')) ?></label>
                                        <select name="reason_type" class="form-select form-select-sm">
                                            <option value="spam"><?= \escape(\pt('socialbored', 'report_spam')) ?></option>
                                            <option value="harassment"><?= \escape(\pt('socialbored', 'report_harassment')) ?></option>
                                            <option value="hate"><?= \escape(\pt('socialbored', 'report_hate')) ?></option>
                                            <option value="other"><?= \escape(\pt('socialbored', 'report_other')) ?></option>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-danger w-100 mt-2"><?= \escape(\pt('socialbored', 'report_send')) ?></button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isAdmin && !$isSelf): ?>
                <div class="social-admin-bar">
                    <span><i class="fas fa-shield-halved"></i><?= \escape(\t('moderation')) ?></span>
                    <a class="btn btn-outline-soft btn-sm" href="<?= \escape(\url('admin_user_edit', ['id' => (int)$profileUser['id']])) ?>"><i class="fas fa-user-pen"></i><?= \escape(\t('edit')) ?></a>
                    <?php if (in_array((string)($profileUser['status'] ?? 'active'), ['banned', 'suspended'], true)): ?>
                        <form method="POST" action="<?= \escape(\SocialBored\Http::url('admin/unban-user')) ?>" class="d-inline" data-confirm="<?= \escape(\t('unban_user')) ?>?">
                            <?= \SocialBored\Http::csrfField() ?>
                            <input type="hidden" name="id" value="<?= (int)$profileUser['id'] ?>">
                            <button class="btn btn-outline-soft btn-sm" type="submit"><i class="fas fa-unlock"></i><?= \escape(\t('unban_user')) ?></button>
                        </form>
                    <?php else: ?>
                        <form method="POST" action="<?= \escape(\SocialBored\Http::url('admin/ban-user')) ?>" class="d-inline" data-confirm="<?= \escape(\t('ban_user')) ?>?">
                            <?= \SocialBored\Http::csrfField() ?>
                            <input type="hidden" name="id" value="<?= (int)$profileUser['id'] ?>">
                            <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fas fa-ban"></i><?= \escape(\t('ban_user')) ?></button>
                        </form>
                        <form method="POST" action="<?= \escape(\SocialBored\Http::url('admin/suspend-user')) ?>" class="d-inline" data-suspend-form data-suspend-prompt="<?= \escape(\t('suspend_days')) ?>">
                            <?= \SocialBored\Http::csrfField() ?>
                            <input type="hidden" name="id" value="<?= (int)$profileUser['id'] ?>">
                            <input type="hidden" name="days" value="7">
                            <button class="btn btn-outline-soft btn-sm" type="submit"><i class="fas fa-clock"></i><?= \escape(\t('suspend')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </header>

        <?php if ($settingsSaved !== ''): ?>
            <div class="alert alert-success social-alert"><i class="fas fa-check-circle"></i><?= \escape($settingsSaved) ?></div>
        <?php endif; ?>
        <?php if ($reportSuccess !== ''): ?>
            <div class="alert alert-success social-alert"><i class="fas fa-check-circle"></i><?= \escape($reportSuccess) ?></div>
        <?php endif; ?>
        <?php if ($reportError !== ''): ?>
            <div class="alert alert-danger social-alert"><i class="fas fa-exclamation-circle"></i><?= \escape($reportError) ?></div>
        <?php endif; ?>

        <?php if (!$canView): ?>
            <div class="social-locked">
                <i class="fas fa-<?= $isBlocked ? 'ban' : 'lock' ?>"></i>
                <?php if ($isBlocked): ?>
                    <h2><?= \escape(\pt('socialbored', 'blocked_profile_title')) ?></h2>
                    <p><?= \escape(\pt('socialbored', 'blocked_profile_body')) ?></p>
                <?php elseif ($privacy === 'private'): ?>
                    <h2><?= \escape(\pt('socialbored', 'private_profile_title')) ?></h2>
                    <p><?= \escape(\pt('socialbored', 'private_profile_body')) ?></p>
                <?php else: ?>
                    <h2><?= \escape(\pt('socialbored', 'followers_profile_title')) ?></h2>
                    <p><?= \escape(\pt('socialbored', 'followers_profile_body')) ?></p>
                <?php endif; ?>
                <?php if ($loggedIn && !$isFollowing && !$isBlocked && $privacy !== 'private'): ?>
                    <form method="POST" action="<?= \escape(\SocialBored\Http::url('follow')) ?>">
                        <?= \SocialBored\Http::csrfField() ?>
                        <input type="hidden" name="user_id" value="<?= (int)$profileUser['id'] ?>">
                        <input type="hidden" name="username" value="<?= \escape($username) ?>">
                        <button type="submit" class="btn btn-brand"><i class="fas fa-user-plus"></i><?= \escape(\pt('socialbored', 'action_follow')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($pinned !== null): ?>
                <div class="social-pinned">
                    <span class="social-pinned-label"><i class="fas fa-thumbtack"></i><?= \escape(\pt('socialbored', 'pinned')) ?></span>
                    <?= \SocialBored\View::postCard($pinned) ?>
                </div>
            <?php endif; ?>

            <nav class="social-tabs social-profile-tabs" aria-label="<?= \escape(\pt('socialbored', 'profile_tabs')) ?>">
                <?php
                $tabs = [
                    'posts' => \pt('socialbored', 'tab_posts'),
                    'replies' => \pt('socialbored', 'tab_replies'),
                    'media' => \pt('socialbored', 'tab_media'),
                ];
                if (!empty($features['reactions'])) {
                    $tabs['likes'] = \pt('socialbored', 'tab_likes');
                }
                if (!empty($features['reposts'])) {
                    $tabs['reposts'] = \pt('socialbored', 'tab_reposts');
                }
                if ($isSelf && !empty($features['bookmarks'])) {
                    $tabs['bookmarks'] = \pt('socialbored', 'tab_bookmarks');
                }
                foreach ($tabs as $key => $label):
                    $href = $profileUrl . '?tab=' . $key;
                    ?>
                    <a class="social-tab<?= $tab === $key ? ' is-active' : '' ?>" href="<?= \escape($href) ?>"><?= \escape($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tab === 'media'): ?>
                <?php if (empty($items)): ?>
                    <div class="social-empty"><i class="fas fa-image"></i><p><?= \escape(\pt('socialbored', 'no_media')) ?></p></div>
                <?php else: ?>
                    <div class="social-media-gallery">
                        <?php foreach ($items as $upload):
                            $downloadUrl = \url('download', ['id' => (int)($upload['id'] ?? 0)]);
                            $mime = (string)($upload['mime_type'] ?? '');
                            $threadUrl = \SocialBored\Util::threadUrl(['id' => (int)($upload['thread_id'] ?? 0), 'title' => (string)($upload['thread_title'] ?? '')]);
                            ?>
                            <a class="social-gallery-item" href="<?= \escape($threadUrl) ?>">
                                <?php if (str_starts_with($mime, 'image/')): ?>
                                    <img src="<?= \escape($downloadUrl) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span><i class="fas fa-paperclip"></i><?= \escape((string)($upload['original_name'] ?? 'file')) ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php elseif (empty($items)): ?>
                <div class="social-empty">
                    <i class="fas fa-comments"></i>
                    <p><?= \escape(\pt('socialbored', 'profile_empty_' . $tab)) ?></p>
                </div>
            <?php elseif ($isReplyTab): ?>
                <div class="social-replies">
                    <?php foreach ($items as $reply): ?>
                        <?= \SocialBored\View::partial('reply-card', ['reply' => $reply]) ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="social-feed">
                    <?php foreach ($items as $thread): ?>
                        <?= \SocialBored\View::postCard($thread) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($tab !== 'media' && $pages > 1): ?>
                <nav class="social-pager" aria-label="<?= \escape(\t('pagination')) ?>">
                    <?php
                    $prev = max(1, $page - 1);
                    $next = min($pages, $page + 1);
                    $base = $profileUrl . '?tab=' . rawurlencode($tab);
                    ?>
                    <a class="social-page-link<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= \escape($base . '&page=' . $prev) ?>">&laquo;</a>
                    <span><?= (int)$page ?> / <?= (int)$pages ?></span>
                    <a class="social-page-link<?= $page >= $pages ? ' is-disabled' : '' ?>" href="<?= \escape($base . '&page=' . $next) ?>">&raquo;</a>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php \render_footer(); ?>
<?php if ($hasMessages && $loggedIn && !$isSelf): ?>
    <script nonce="<?= \htmlspecialchars(\csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
        document.querySelectorAll('.textmebored-send-msg-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (window.textmebored && window.textmebored.openConversation) {
                    window.textmebored.openConversation(
                        parseInt(btn.getAttribute('data-user-id'), 10),
                        btn.getAttribute('data-username')
                    );
                }
            });
        });
    </script>
<?php endif; ?>
