<?php
/**
 * Reply row used by the profile "replies" tab.
 *
 * @var array $reply
 */
$postId = (int)($reply['id'] ?? 0);
$threadId = (int)($reply['thread_id'] ?? 0);
$threadTitle = (string)($reply['thread_title'] ?? '');
$threadAuthor = (string)($reply['thread_author'] ?? '');
$content = (string)($reply['content'] ?? '');
$createdAt = (string)($reply['created_at'] ?? '');
$threadUrl = \SocialBored\Util::threadUrl(['id' => $threadId, 'title' => $threadTitle]) . '#post-' . $postId;
?>
<article class="social-reply-card">
    <div class="social-reply-context">
        <i class="fas fa-reply"></i>
        <?= \escape(\pt('socialbored', 'replied_to')) ?>
        <a href="<?= \escape(\SocialBored\Util::profileUrl($threadAuthor)) ?>"><?= \escape($threadAuthor) ?></a>
        <span class="dot">·</span>
        <a href="<?= \escape($threadUrl) ?>"><?= \escape(\SocialBored\Util::truncate($threadTitle, 80)) ?></a>
    </div>
    <div class="social-reply-body">
        <?= \SocialBored\Util::content($content) ?>
    </div>
    <div class="social-reply-meta">
        <time datetime="<?= \escape($createdAt) ?>"><?= \escape(\SocialBored\Util::timeAgo($createdAt)) ?></time>
        <a href="<?= \escape($threadUrl) ?>"><?= \escape(\pt('socialbored', 'open_post')) ?></a>
    </div>
</article>
