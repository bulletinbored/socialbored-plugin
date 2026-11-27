<?php
/**
 * Social post detail page.
 *
 * @var array $thread
 * @var array $replies
 * @var array $byId
 * @var array $childrenMap
 * @var array $topLevel
 * @var string $replyError
 * @var string $replyContent
 * @var bool $loggedIn
 * @var bool $isLocked
 * @var bool $canReply
 */
$coreViews = dirname(__DIR__, 3) . '/views';
$thread = $thread ?? [];
$replies = $replies ?? [];
$byId = $byId ?? [];
$childrenMap = $childrenMap ?? [];
$topLevel = $topLevel ?? [];
$replyError = $replyError ?? '';
$replyContent = $replyContent ?? '';
$loggedIn = $loggedIn ?? \SocialBored\Http::loggedIn();
$isLocked = $isLocked ?? false;
$canReply = $canReply ?? false;
$threadId = (int)($thread['id'] ?? 0);
$threadUrl = \SocialBored\Util::threadUrl($thread);
$commentCount = count($replies);
$viewerName = (string)($_SESSION['username'] ?? '');
$viewerAvatar = (string)($_SESSION['avatar'] ?? '');

include $coreViews . '/header.php';
\render_header((string)($thread['title'] ?? \pt('socialbored', 'post')), ['sidebar' => false, 'wide' => true]);
?>
<div class="social-shell social-thread-shell">
    <div class="social-thread-head">
        <a class="social-thread-back" href="<?= \escape(\SocialBored\Http::url('social')) ?>">
            <i class="fas fa-arrow-left"></i><?= \escape(\pt('socialbored', 'back_to_feed')) ?>
        </a>
    </div>

    <div class="social-thread-post">
        <?= \SocialBored\View::postCard($thread, ['show_actions' => true, 'is_thread' => true]) ?>
    </div>

    <section class="social-comments-card" id="comments">
        <h2 class="social-comments-title">
            <?= \escape(\pt('socialbored', 'comments')) ?>
            <span class="social-comments-count"><?= $commentCount ?></span>
        </h2>

        <?php if ($replyError !== ''): ?>
            <div class="alert alert-danger social-alert">
                <i class="fas fa-circle-exclamation"></i><?= \escape($replyError) ?>
            </div>
        <?php endif; ?>

        <?php if ($canReply): ?>
            <form method="POST" action="<?= \escape(\url('reply')) ?>" class="social-comment-composer">
                <?= \SocialBored\Http::csrfField() ?>
                <input type="hidden" name="thread_id" value="<?= $threadId ?>">
                <div class="social-comment-composer-avatar">
                    <?= \SocialBored\Util::avatar($viewerName, $viewerAvatar, 38) ?>
                </div>
                <div class="social-comment-composer-body">
                    <textarea name="content" class="form-control" rows="2" data-no-editbored placeholder="<?= \escape(\pt('socialbored', 'comment_placeholder')) ?>" required><?= \escape($replyContent) ?></textarea>
                    <div class="social-comment-composer-actions">
                        <button type="submit" class="btn btn-brand btn-sm"><?= \escape(\pt('socialbored', 'comment_submit')) ?></button>
                    </div>
                </div>
            </form>
        <?php elseif ($isLocked): ?>
            <div class="social-comments-locked">
                <i class="fas fa-lock"></i><?= \escape(\pt('socialbored', 'comments_locked')) ?>
            </div>
        <?php else: ?>
            <div class="social-comments-locked">
                <a href="<?= \escape(\url('login')) ?>"><?= \escape(\pt('socialbored', 'login_to_comment')) ?></a>
            </div>
        <?php endif; ?>

        <?php if ($commentCount === 0): ?>
            <div class="social-comments-empty"><?= \escape(\pt('socialbored', 'no_comments')) ?></div>
        <?php else: ?>
            <?php
            $renderComment = function (int $commentId, int $depth = 0) use (&$renderComment, $byId, $childrenMap, $threadId, $loggedIn, $isLocked): void {
                if (!isset($byId[$commentId])) {
                    return;
                }
                $comment = $byId[$commentId];
                $cid = (int)$comment['id'];
                $author = (string)($comment['author'] ?? '');
                $authorAvatar = (string)($comment['author_avatar'] ?? '');
                $content = (string)($comment['content'] ?? '');
                $createdAt = (string)($comment['created_at'] ?? '');
                $reactionCount = (int)($comment['reaction_count'] ?? 0);
                $liked = !empty($comment['liked']);
                $attachments = is_array($comment['attachments'] ?? null) ? $comment['attachments'] : [];
                $profileUrl = \SocialBored\Util::profileUrl($author);
                ?>
                <article class="social-comment" id="post-<?= $cid ?>" data-comment-id="<?= $cid ?>">
                    <a class="social-comment-avatar" href="<?= \escape($profileUrl) ?>" tabindex="-1" aria-hidden="true">
                        <?= \SocialBored\Util::avatar($author, $authorAvatar, 34) ?>
                    </a>
                    <div class="social-comment-main">
                        <div class="social-comment-bubble">
                            <a class="social-comment-author" href="<?= \escape($profileUrl) ?>"><?= \escape($author ?: '—') ?></a>
                            <div class="social-comment-text"><?= \SocialBored\Util::content($content) ?></div>
                            <?php if ($attachments !== []): ?>
                                <div class="social-comment-attachments">
                                    <?php foreach ($attachments as $upload): ?>
                                        <?php
                                        $mime = (string)($upload['mime_type'] ?? '');
                                        $downloadUrl = \url('download', ['id' => (int)($upload['id'] ?? 0)]);
                                        ?>
                                        <?php if (str_starts_with($mime, 'image/')): ?>
                                            <a href="<?= \escape($downloadUrl) ?>"
                                               data-lightbox-src="<?= \escape($downloadUrl) ?>"
                                               data-lightbox-alt="<?= \escape((string)($upload['original_name'] ?? 'image')) ?>">
                                                <img src="<?= \escape($downloadUrl) ?>" alt="<?= \escape((string)($upload['original_name'] ?? 'image')) ?>" loading="lazy">
                                            </a>
                                        <?php else: ?>
                                            <a class="social-comment-file" href="<?= \escape($downloadUrl) ?>">
                                                <i class="fas fa-paperclip"></i><?= \escape((string)($upload['original_name'] ?? 'file')) ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="social-comment-meta">
                            <time datetime="<?= \escape($createdAt) ?>"><?= \escape(\SocialBored\Util::timeAgo($createdAt)) ?></time>

                            <?php if ($loggedIn && !$isLocked): ?>
                                <form method="POST" action="<?= \escape(\SocialBored\Http::url('react')) ?>" class="social-comment-like" data-social-action="react">
                                    <?= \SocialBored\Http::csrfField() ?>
                                    <input type="hidden" name="target_type" value="post">
                                    <input type="hidden" name="target_id" value="<?= $cid ?>">
                                    <input type="hidden" name="kind" value="like">
                                    <button type="submit" class="social-comment-action<?= $liked ? ' is-active' : '' ?>" data-social-button="like" aria-pressed="<?= $liked ? 'true' : 'false' ?>">
                                        <i class="<?= $liked ? 'fas' : 'far' ?> fa-heart"></i>
                                        <span data-social-count="like"><?= \SocialBored\Util::compact($reactionCount) ?></span>
                                    </button>
                                </form>
                            <?php elseif ($reactionCount > 0): ?>
                                <span class="social-comment-action"><i class="far fa-heart"></i> <?= \SocialBored\Util::compact($reactionCount) ?></span>
                            <?php endif; ?>

                            <?php if ($loggedIn && !$isLocked): ?>
                                <details class="social-comment-reply">
                                    <summary><?= \escape(\pt('socialbored', 'action_reply')) ?></summary>
                                    <form method="POST" action="<?= \escape(\url('reply')) ?>" class="social-comment-reply-form">
                                        <?= \SocialBored\Http::csrfField() ?>
                                        <input type="hidden" name="thread_id" value="<?= $threadId ?>">
                                        <input type="hidden" name="parent_id" value="<?= $cid ?>">
                                        <textarea name="content" class="form-control" rows="2" data-no-editbored placeholder="<?= \escape(\pt('socialbored', 'comment_placeholder')) ?>" required></textarea>
                                        <button type="submit" class="btn btn-brand btn-sm"><?= \escape(\pt('socialbored', 'action_reply')) ?></button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($childrenMap[$cid])): ?>
                            <div class="social-comment-children">
                                <?php foreach ($childrenMap[$cid] as $childId) { $renderComment((int)$childId, $depth + 1); } ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php
            };
            ?>
            <div class="social-comments">
                <?php foreach ($topLevel as $commentId) { $renderComment((int)$commentId); } ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<div class="social-lightbox" id="socialLightbox" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="<?= \escape(\t('close')) ?>">
    <button type="button" class="social-lightbox-close" data-lightbox-close aria-label="<?= \escape(\t('close')) ?>">
        <i class="fas fa-xmark"></i>
    </button>
    <img src="" alt="" data-lightbox-image>
</div>
<?php \render_footer(); ?>
