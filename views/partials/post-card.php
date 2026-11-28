<?php
/**
 * Social post card.
 *
 * @var array $post
 * @var array $ctx
 */
$postId = (int)($post['id'] ?? 0);
$author = (string)($post['author'] ?? '');
$avatar = (string)($post['author_avatar'] ?? '');
$content = (string)($post['content'] ?? '');
$title = (string)($post['title'] ?? '');
$createdAt = (string)($post['created_at'] ?? '');
$visibility = (string)($post['visibility'] ?? 'public');
$threadUrl = \SocialBored\Util::threadUrl($post);
$profileUrl = \SocialBored\Util::profileUrl($author);
$viewerId = \SocialBored\Http::userId();
$loggedIn = \SocialBored\Http::loggedIn();
$isOwner = $viewerId > 0 && $viewerId === (int)($post['user_id'] ?? 0);
$isPrivileged = \SocialBored\Privacy::isPrivileged($viewerId);
$reactionCount = (int)($post['reaction_count'] ?? 0);
$liked = !empty($post['liked']);
$repostCount = (int)($post['repost_count'] ?? 0);
$reposted = !empty($post['reposted']);
$bookmarked = !empty($post['bookmarked']);
$replyCount = (int)($post['reply_count'] ?? 0);
$attachments = is_array($post['attachments'] ?? null) ? $post['attachments'] : [];
$urlMedia = is_array($post['url_media'] ?? null) ? $post['url_media'] : [];
$hashtags = \SocialBored\Util::extractHashtags($content);
$isThread = !empty($ctx['is_thread']);
$longPost = !$isThread && \mb_strlen(trim($content)) > 520;
$showActions = $ctx['show_actions'] ?? true;
$pm = \App::getInstance()->pluginManager;
$features = \SocialBored\Settings::featuresFor($pm);
$showReactions = $showActions && !empty($features['reactions']);
$showReposts = $showActions && !empty($features['reposts']);
$showBookmarks = $showActions && !empty($features['bookmarks']);
$showHashtags = !empty($features['hashtags']);
?>
<article class="social-post" data-post-id="<?= $postId ?>" data-thread-id="<?= $postId ?>">
    <header class="social-post-head">
        <a href="<?= \escape($profileUrl) ?>" class="social-avatar" aria-hidden="true" tabindex="-1">
            <?= \SocialBored\Util::avatar($author, $avatar, 46) ?>
        </a>
        <div class="social-post-meta">
            <div class="social-post-authorline">
                <a class="social-author" href="<?= \escape($profileUrl) ?>"><?= \escape($author ?: '—') ?></a>
                <?php if (($post['author_role'] ?? 'user') === 'admin'): ?>
                    <span class="social-role social-role-admin"><?= \escape(\t('role_admin')) ?></span>
                <?php elseif (($post['author_role'] ?? 'user') === 'moderator'): ?>
                    <span class="social-role"><?= \escape(\t('role_moderator')) ?></span>
                <?php endif; ?>
            </div>
            <div class="social-submeta">
                <a href="<?= \escape($threadUrl) ?>"><time datetime="<?= \escape($createdAt) ?>"><?= \escape(\SocialBored\Util::timeAgo($createdAt)) ?></time></a>
                <?php if ($visibility !== 'public'): ?>
                    <span class="social-visibility">
                        <i class="fas fa-<?= $visibility === 'private' ? 'lock' : 'user-group' ?>"></i>
                        <?= \escape(\SocialBored\Util::visibilityLabel($visibility)) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="dropdown social-post-menu">
            <button class="social-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= \escape(\pt('socialbored', 'actions')) ?>">
                <i class="fas fa-ellipsis"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php if (!$isThread): ?>
                    <li><a class="dropdown-item" href="<?= \escape($threadUrl) ?>"><i class="fas fa-up-right-from-square"></i><?= \escape(\pt('socialbored', 'open_post')) ?></a></li>
                <?php endif; ?>
                <li><button class="dropdown-item" type="button" data-social-copy="<?= \escape($threadUrl) ?>"><i class="fas fa-link"></i><?= \escape(\pt('socialbored', 'copy_link')) ?></button></li>
                <?php if ($isOwner): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= \escape(\url('edit_thread', ['id' => $postId])) ?>"><i class="fas fa-pen"></i><?= \escape(\t('edit')) ?></a></li>
                    <?php if (empty($post['category_id'])): ?>
                    <li>
                        <form method="POST" action="<?= \escape(\SocialBored\Http::url('social/visibility')) ?>" class="social-menu-form">
                            <?= \SocialBored\Http::csrfField() ?>
                            <input type="hidden" name="thread_id" value="<?= $postId ?>">
                            <label class="social-menu-label"><i class="fas fa-eye"></i><?= \escape(\pt('socialbored', 'visibility')) ?></label>
                            <select name="visibility" class="form-select form-select-sm">
                                <?php foreach (['public', 'followers', 'private'] as $option): ?>
                                    <option value="<?= $option ?>" <?= $visibility === $option ? 'selected' : '' ?>><?= \escape(\SocialBored\Util::visibilityLabel($option)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-brand w-100 mt-2"><?= \escape(\pt('socialbored', 'save')) ?></button>
                        </form>
                    </li>
                    <?php endif; ?>
                    <li>
                        <form method="POST" action="<?= \escape(\url('delete_thread', ['id' => $postId])) ?>" class="social-menu-form" data-confirm="<?= \escape(\t('delete_confirm')) ?>">
                            <?= \SocialBored\Http::csrfField() ?>
                            <button type="submit" class="dropdown-item text-danger"><i class="fas fa-trash"></i><?= \escape(\t('delete')) ?></button>
                        </form>
                    </li>
                <?php elseif ($loggedIn): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="<?= \escape(\SocialBored\Http::url('report')) ?>" class="social-menu-form">
                            <?= \SocialBored\Http::csrfField() ?>
                            <input type="hidden" name="target_type" value="thread">
                            <input type="hidden" name="target_id" value="<?= $postId ?>">
                            <label class="social-menu-label"><i class="fas fa-flag"></i><?= \escape(\pt('socialbored', 'report')) ?></label>
                            <select name="reason_type" class="form-select form-select-sm">
                                <option value="spam"><?= \escape(\pt('socialbored', 'report_spam')) ?></option>
                                <option value="harassment"><?= \escape(\pt('socialbored', 'report_harassment')) ?></option>
                                <option value="hate"><?= \escape(\pt('socialbored', 'report_hate')) ?></option>
                                <option value="misinformation"><?= \escape(\pt('socialbored', 'report_misinformation')) ?></option>
                                <option value="other"><?= \escape(\pt('socialbored', 'report_other')) ?></option>
                            </select>
                            <input type="text" name="reason_details" class="form-control form-control-sm mt-2" maxlength="300" placeholder="<?= \escape(\pt('socialbored', 'report_details')) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100 mt-2"><?= \escape(\pt('socialbored', 'report_send')) ?></button>
                        </form>
                    </li>
                <?php endif; ?>
                <?php if ($isPrivileged && !$isOwner): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?= \escape(\url('admin_moderation')) ?>"><i class="fas fa-shield-halved"></i><?= \escape(\pt('socialbored', 'moderate')) ?></a></li>
                <?php endif; ?>
            </ul>
        </div>
    </header>

    <div class="social-post-body<?= $longPost ? ' is-clamped' : '' ?>">
        <?= \SocialBored\Util::content($content) ?>
    </div>
    <?php if ($longPost): ?>
        <a class="social-readmore" href="<?= \escape($threadUrl) ?>"><?= \escape(\pt('socialbored', 'read_more')) ?></a>
    <?php endif; ?>

    <?php if ($attachments !== []): ?>
        <div class="social-media-grid">
            <?php foreach (array_slice($attachments, 0, 4) as $upload):
                $mime = (string)($upload['mime_type'] ?? '');
                $downloadUrl = \url('download', ['id' => (int)($upload['id'] ?? 0)]);
                $original = (string)($upload['original_name'] ?? 'file');
                ?>
                <?php if (str_starts_with($mime, 'image/')): ?>
                    <?php if ($isThread): ?>
                        <a class="social-media-item" href="<?= \escape($downloadUrl) ?>"
                           data-lightbox-src="<?= \escape($downloadUrl) ?>"
                           data-lightbox-alt="<?= \escape($original) ?>">
                            <img src="<?= \escape($downloadUrl) ?>" alt="<?= \escape($original) ?>" loading="lazy">
                        </a>
                    <?php else: ?>
                        <a class="social-media-item" href="<?= \escape($threadUrl) ?>">
                            <img src="<?= \escape($downloadUrl) ?>" alt="<?= \escape($original) ?>" loading="lazy">
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <a class="social-media-file" href="<?= \escape($downloadUrl) ?>">
                        <i class="fas fa-paperclip"></i><?= \escape($original) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($urlMedia !== []): ?>
        <div class="social-media-grid">
            <?php foreach ($urlMedia as $item):
                $mediaKind = (string)($item['kind'] ?? 'image');
                $mediaUrl = (string)($item['url'] ?? '');
                ?>
                <?php if ($mediaKind === 'image' && $mediaUrl !== ''): ?>
                    <?php if ($isThread): ?>
                        <a class="social-media-item" href="<?= \escape($mediaUrl) ?>"
                           data-lightbox-src="<?= \escape($mediaUrl) ?>"
                           data-lightbox-alt="image">
                            <img src="<?= \escape($mediaUrl) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                        </a>
                    <?php else: ?>
                        <a class="social-media-item" href="<?= \escape($threadUrl) ?>">
                            <img src="<?= \escape($mediaUrl) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                        </a>
                    <?php endif; ?>
                <?php elseif ($mediaKind === 'video'):
                    $embed = \SocialBored\Util::videoEmbed($mediaUrl);
                    ?>
                    <?php if ($embed !== null): ?>
                        <div class="social-media-item social-media-video">
                            <?php if ($embed['type'] === 'iframe'): ?>
                                <iframe src="<?= \escape($embed['src']) ?>" loading="lazy"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                        allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
                            <?php else: ?>
                                <video src="<?= \escape($embed['src']) ?>" controls preload="metadata" playsinline></video>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($showHashtags && $hashtags !== []): ?>
        <div class="social-tags">
            <?php foreach ($hashtags as $tag): ?>
                <a class="social-tag" href="<?= \escape(\SocialBored\Util::hashtagUrl($tag)) ?>">#<?= \escape($tag) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($showActions): ?>
        <footer class="social-actions">
            <?php if ($showReactions): ?>
                <form method="POST" action="<?= \escape(\SocialBored\Http::url('react')) ?>" class="social-action-form" data-social-action="react">
                    <?= \SocialBored\Http::csrfField() ?>
                    <input type="hidden" name="target_type" value="thread">
                    <input type="hidden" name="target_id" value="<?= $postId ?>">
                    <input type="hidden" name="kind" value="like">
                    <button type="submit" class="social-action<?= $liked ? ' is-active' : '' ?>" data-social-button="like" aria-pressed="<?= $liked ? 'true' : 'false' ?>" <?= $loggedIn ? '' : 'disabled' ?>>
                        <i class="<?= $liked ? 'fas' : 'far' ?> fa-heart"></i>
                        <span data-social-count="like"><?= \SocialBored\Util::compact($reactionCount) ?></span>
                    </button>
                </form>
            <?php endif; ?>

            <a class="social-action" href="<?= \escape($isThread ? $threadUrl . '#comments' : $threadUrl) ?>" title="<?= \escape(\pt('socialbored', 'replies')) ?>">
                <i class="far fa-comment"></i>
                <span><?= \SocialBored\Util::compact($replyCount) ?></span>
            </a>

            <?php if ($showReposts): ?>
                <form method="POST" action="<?= \escape(\SocialBored\Http::url('repost')) ?>" class="social-action-form" data-social-action="repost">
                    <?= \SocialBored\Http::csrfField() ?>
                    <input type="hidden" name="thread_id" value="<?= $postId ?>">
                    <button type="submit" class="social-action<?= $reposted ? ' is-active' : '' ?>" data-social-button="repost" aria-pressed="<?= $reposted ? 'true' : 'false' ?>" <?= $loggedIn ? '' : 'disabled' ?>>
                        <i class="fas fa-retweet"></i>
                        <span data-social-count="repost"><?= \SocialBored\Util::compact($repostCount) ?></span>
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($showBookmarks): ?>
                <form method="POST" action="<?= \escape(\SocialBored\Http::url('bookmark')) ?>" class="social-action-form" data-social-action="bookmark">
                    <?= \SocialBored\Http::csrfField() ?>
                    <input type="hidden" name="thread_id" value="<?= $postId ?>">
                    <button type="submit" class="social-action<?= $bookmarked ? ' is-active' : '' ?>" data-social-button="bookmark" aria-pressed="<?= $bookmarked ? 'true' : 'false' ?>" <?= $loggedIn ? '' : 'disabled' ?> title="<?= \escape(\pt('socialbored', 'action_bookmark')) ?>">
                        <i class="<?= $bookmarked ? 'fas' : 'far' ?> fa-bookmark"></i>
                    </button>
                </form>
            <?php endif; ?>

            <button type="button" class="social-action" data-social-copy="<?= \escape($threadUrl) ?>" title="<?= \escape(\pt('socialbored', 'copy_link')) ?>">
                <i class="fas fa-link"></i>
            </button>
        </footer>
    <?php endif; ?>
</article>
