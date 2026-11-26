<?php
/**
 * Compact user card used by followers/following lists, search and suggestions.
 *
 * @var array $user
 * @var array $ctx
 */
$userId = (int)($user['id'] ?? 0);
$username = (string)($user['username'] ?? '');
$avatar = (string)($user['avatar'] ?? '');
$role = (string)($user['role'] ?? 'user');
$followers = (int)($user['followers_count'] ?? 0);
$viewerFollows = !empty($user['viewer_follows']);
$isSelf = \SocialBored\Http::userId() === $userId;
$loggedIn = \SocialBored\Http::loggedIn();
$profileUrl = \SocialBored\Util::profileUrl($username);
?>
<div class="social-user-card">
    <a href="<?= \escape($profileUrl) ?>" class="social-user-avatar">
        <?= \SocialBored\Util::avatar($username, $avatar, 46) ?>
    </a>
    <div class="social-user-main">
        <a class="social-user-name" href="<?= \escape($profileUrl) ?>"><?= \escape($username) ?></a>
        <div class="social-user-meta">
            <?php if ($role === 'admin'): ?>
                <span class="social-role social-role-admin"><?= \escape(\t('role_admin')) ?></span>
            <?php elseif ($role === 'moderator'): ?>
                <span class="social-role"><?= \escape(\t('role_moderator')) ?></span>
            <?php endif; ?>
            <span><?= \SocialBored\Util::compact($followers) ?> <?= \escape(\pt('socialbored', 'followers')) ?></span>
        </div>
    </div>
    <?php if ($loggedIn && !$isSelf): ?>
        <?php if ($viewerFollows): ?>
            <form method="POST" action="<?= \escape(\SocialBored\Http::url('unfollow')) ?>" class="social-user-action" data-social-action="unfollow">
                <?= \SocialBored\Http::csrfField() ?>
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                <input type="hidden" name="username" value="<?= \escape($username) ?>">
                <button type="submit" class="btn btn-outline-soft btn-sm" data-social-button="follow">
                    <i class="fas fa-user-check"></i><span><?= \escape(\pt('socialbored', 'action_following')) ?></span>
                </button>
            </form>
        <?php else: ?>
            <form method="POST" action="<?= \escape(\SocialBored\Http::url('follow')) ?>" class="social-user-action" data-social-action="follow">
                <?= \SocialBored\Http::csrfField() ?>
                <input type="hidden" name="user_id" value="<?= $userId ?>">
                <input type="hidden" name="username" value="<?= \escape($username) ?>">
                <button type="submit" class="btn btn-brand btn-sm" data-social-button="follow">
                    <i class="fas fa-user-plus"></i><span><?= \escape(\pt('socialbored', 'action_follow')) ?></span>
                </button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
