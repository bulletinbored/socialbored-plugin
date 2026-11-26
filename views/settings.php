<?php
$coreViews = dirname(__DIR__, 3) . '/views';
$cover = (string)($profile['cover'] ?? '');
$hasCover = \SocialBored\Util::hasCover($cover);
$coverUrl = \SocialBored\Util::coverUrl($cover);
$privacy = (string)($profile['privacy'] ?? 'public');
$pm = \App::getInstance()->pluginManager;
$hasMessages = $pm !== null && $pm->isEnabled('textmebored');

include $coreViews . '/header.php';
\render_header(\pt('socialbored', 'social_settings'), ['sidebar' => false, 'wide' => true]);
?>
<div class="social-shell social-shell-narrow">
    <nav class="social-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= \escape(\SocialBored\Util::profileUrl((string)($_SESSION['username'] ?? ''))) ?>"><i class="fas fa-arrow-left"></i><?= \escape(\t('profile')) ?></a>
    </nav>

    <header class="social-page-head">
        <div>
            <h1 class="social-page-title"><?= \escape(\pt('socialbored', 'social_settings')) ?></h1>
            <p class="social-page-subtitle"><?= \escape(\pt('socialbored', 'social_settings_hint')) ?></p>
        </div>
    </header>

    <?php if (!empty($settingsError)): ?>
        <div class="alert alert-danger social-alert"><i class="fas fa-exclamation-circle"></i><?= \escape($settingsError) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= \escape(\SocialBored\Http::url('settings/social')) ?>" enctype="multipart/form-data" class="social-card social-settings-form">
        <?= \SocialBored\Http::csrfField() ?>

        <div class="social-field">
            <label class="form-label" for="social-display-name"><?= \escape(\pt('socialbored', 'display_name')) ?></label>
            <input id="social-display-name" type="text" name="display_name" class="form-control" maxlength="100"
                   value="<?= \escape((string)($profile['display_name'] ?? $_SESSION['username'] ?? '')) ?>">
            <div class="form-text"><?= \escape(\pt('socialbored', 'display_name_hint')) ?></div>
        </div>

        <div class="social-field">
            <label class="form-label" for="social-bio"><?= \escape(\pt('socialbored', 'bio')) ?></label>
            <textarea id="social-bio" name="bio" class="form-control" rows="4" maxlength="500"><?= \escape((string)($profile['bio'] ?? '')) ?></textarea>
            <div class="form-text"><?= \escape(\pt('socialbored', 'bio_hint')) ?></div>
        </div>

        <div class="social-settings-grid">
            <div class="social-field">
                <label class="form-label" for="social-location"><?= \escape(\pt('socialbored', 'location')) ?></label>
                <input id="social-location" type="text" name="location" class="form-control" maxlength="100"
                       value="<?= \escape((string)($profile['location'] ?? '')) ?>">
            </div>
            <div class="social-field">
                <label class="form-label" for="social-website"><?= \escape(\pt('socialbored', 'website')) ?></label>
                <input id="social-website" type="url" name="website" class="form-control" maxlength="255"
                       value="<?= \escape((string)($profile['website'] ?? '')) ?>" placeholder="https://">
            </div>
        </div>

        <div class="social-field">
            <label class="form-label" for="social-privacy"><?= \escape(\pt('socialbored', 'profile_privacy')) ?></label>
            <select id="social-privacy" name="privacy" class="form-select">
                <?php foreach (['public', 'followers', 'private'] as $option): ?>
                    <option value="<?= $option ?>" <?= $privacy === $option ? 'selected' : '' ?>><?= \escape(\SocialBored\Util::visibilityLabel($option)) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text"><?= \escape(\pt('socialbored', 'profile_privacy_hint')) ?></div>
        </div>

        <div class="social-field">
            <label class="form-label" for="social-cover"><?= \escape(\pt('socialbored', 'cover')) ?></label>
            <?php if ($hasCover): ?>
                <div class="social-cover-preview" style="background-image:url('<?= \escape($coverUrl) ?>')"></div>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="remove_cover" value="1" id="social-remove-cover">
                    <label class="form-check-label" for="social-remove-cover"><?= \escape(\pt('socialbored', 'remove_cover')) ?></label>
                </div>
            <?php endif; ?>
            <input id="social-cover" type="file" name="cover" class="form-control" accept="image/jpeg,image/png,image/webp">
            <div class="form-text"><?= \escape(\pt('socialbored', 'cover_hint')) ?></div>
        </div>

        <div class="social-settings-actions">
            <a class="btn btn-outline-soft" href="<?= \escape(\SocialBored\Util::profileUrl((string)($_SESSION['username'] ?? ''))) ?>"><?= \escape(\t('cancel')) ?></a>
            <button type="submit" class="btn btn-brand"><i class="fas fa-save"></i><?= \escape(\t('save_changes')) ?></button>
        </div>
    </form>

    <div class="social-card social-settings-links">
        <h2 class="social-card-title"><?= \escape(\pt('socialbored', 'account_settings')) ?></h2>
        <p><?= \escape(\pt('socialbored', 'account_settings_hint')) ?></p>
        <a class="btn btn-outline-soft btn-sm" href="<?= \escape(\url('edit_profile')) ?>"><i class="fas fa-user-gear"></i><?= \escape(\t('edit_profile')) ?></a>
        <?php if ($hasMessages): ?>
            <a class="btn btn-outline-soft btn-sm" href="<?= \escape(\SocialBored\Http::url('messages')) ?>"><i class="fas fa-envelope"></i><?= \escape(\t('messages')) ?></a>
        <?php endif; ?>
    </div>
</div>
<?php \render_footer(); ?>
