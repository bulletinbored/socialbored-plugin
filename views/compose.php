<?php
$coreViews = dirname(__DIR__, 3) . '/views';
include $coreViews . '/header.php';
\render_header(\pt('socialbored', 'compose'), ['sidebar' => false, 'wide' => true]);
$back = $back ?? '/';
$visibility = (string)($draft['visibility'] ?? 'public');
$viewerName = (string)($_SESSION['username'] ?? '');
$viewerAvatar = (string)($_SESSION['avatar'] ?? '');
$imagePanelOpen = (string)($draft['image_url'] ?? '') !== '';
$videoPanelOpen = (string)($draft['video_url'] ?? '') !== '';
$visibilityIcon = match ($visibility) {
    'followers' => 'fa-user-group',
    'private' => 'fa-lock',
    default => 'fa-globe',
};
?>
<div class="social-shell social-compose-shell">
    <nav class="social-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= \escape($back) ?>"><i class="fas fa-arrow-left"></i><?= \escape(\pt('socialbored', 'back_to_feed')) ?></a>
    </nav>

    <header class="social-page-head social-compose-page-head">
        <div>
            <h1 class="social-page-title"><?= \escape(\pt('socialbored', 'create_post')) ?></h1>
            <p class="social-page-subtitle"><?= \escape(\pt('socialbored', 'create_post_subtitle')) ?></p>
        </div>
    </header>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger social-alert"><i class="fas fa-exclamation-circle"></i><?= \escape($error) ?></div>
    <?php endif; ?>

    <div class="social-compose-grid">
        <section class="social-card composer-card">
            <form method="POST" action="<?= \escape(\SocialBored\Http::url('compose')) ?>" enctype="multipart/form-data" class="composer-form">
                <?= \SocialBored\Http::csrfField() ?>

                <div class="composer-head">
                    <?= \SocialBored\Util::avatar($viewerName, $viewerAvatar, 52) ?>
                    <div class="composer-identity">
                        <strong><?= \escape($viewerName) ?></strong>
                        <label class="composer-audience" for="social-visibility">
                            <i class="fas <?= \escape($visibilityIcon) ?>" data-visibility-icon></i>
                            <select id="social-visibility" name="visibility" class="composer-audience-select" data-composer-visibility>
                                <?php foreach (['public', 'followers', 'private'] as $option): ?>
                                    <option value="<?= $option ?>" <?= $visibility === $option ? 'selected' : '' ?>><?= \escape(\SocialBored\Util::visibilityLabel($option)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                </div>

                <input class="composer-headline" type="text" name="title" maxlength="120"
                       value="<?= \escape((string)($draft['title'] ?? '')) ?>"
                       placeholder="<?= \escape(\pt('socialbored', 'optional_title_placeholder')) ?>"
                       aria-label="<?= \escape(\pt('socialbored', 'optional_title')) ?>">

                <textarea class="composer-textarea" id="social-content" name="content" rows="6" data-plain-editor
                          placeholder="<?= \escape(\pt('socialbored', 'composer_placeholder')) ?>"><?= \escape((string)($draft['content'] ?? '')) ?></textarea>

                <div class="composer-toolbar" role="toolbar" aria-label="<?= \escape(\pt('socialbored', 'composer_media')) ?>">
                    <label class="composer-tool">
                        <i class="fas fa-image"></i><span><?= \escape(\pt('socialbored', 'composer_photo')) ?></span>
                        <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" data-composer-file-input>
                    </label>
                    <button type="button" class="composer-tool" data-composer-panel="image-url">
                        <i class="fas fa-link"></i><span><?= \escape(\pt('socialbored', 'composer_image_link')) ?></span>
                    </button>
                    <button type="button" class="composer-tool" data-composer-panel="video-url">
                        <i class="fas fa-clapperboard"></i><span><?= \escape(\pt('socialbored', 'composer_video_link')) ?></span>
                    </button>
                    <span class="composer-file-chip" data-composer-file hidden></span>
                </div>

                <div class="composer-panels">
                    <div class="composer-panel" data-composer-panel-body="image-url" <?= $imagePanelOpen ? '' : 'hidden' ?>>
                        <label class="form-label" for="social-image-url"><?= \escape(\pt('socialbored', 'media_image_url')) ?></label>
                        <input id="social-image-url" type="url" name="image_url" class="form-control" placeholder="https://"
                               value="<?= \escape((string)($draft['image_url'] ?? '')) ?>">
                    </div>
                    <div class="composer-panel" data-composer-panel-body="video-url" <?= $videoPanelOpen ? '' : 'hidden' ?>>
                        <label class="form-label" for="social-video-url"><?= \escape(\pt('socialbored', 'media_video_url')) ?></label>
                        <input id="social-video-url" type="url" name="video_url" class="form-control" placeholder="https://"
                               value="<?= \escape((string)($draft['video_url'] ?? '')) ?>">
                    </div>
                    <p class="composer-hint"><?= \escape(\pt('socialbored', 'media_hint')) ?></p>
                </div>

                <footer class="composer-footer">
                    <span class="composer-footer-note"><i class="fas fa-shield-halved"></i><?= \escape(\pt('socialbored', 'composer_privacy_note')) ?></span>
                    <div class="composer-actions">
                        <a class="btn btn-outline-soft" href="<?= \escape($back) ?>"><?= \escape(\t('cancel')) ?></a>
                        <button type="submit" class="btn btn-brand">
                            <i class="fas fa-paper-plane"></i><?= \escape(\pt('socialbored', 'publish')) ?>
                        </button>
                    </div>
                </footer>
            </form>
        </section>

        <aside class="social-compose-aside">
            <div class="social-card composer-tip-card">
                <h2 class="social-card-title"><?= \escape(\pt('socialbored', 'composer_tips_title')) ?></h2>
                <ul class="composer-tips">
                    <li><i class="fas fa-lightbulb"></i><span><?= \escape(\pt('socialbored', 'composer_tip_text')) ?></span></li>
                    <li><i class="fas fa-image"></i><span><?= \escape(\pt('socialbored', 'composer_tip_media')) ?></span></li>
                    <li><i class="fas fa-lock"></i><span><?= \escape(\pt('socialbored', 'composer_tip_visibility')) ?></span></li>
                </ul>
            </div>
        </aside>
    </div>
</div>
<?php \render_footer(); ?>
