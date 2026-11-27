<?php
$coreViews = dirname(__DIR__, 3) . '/views';
$cover = (string)($profile['cover'] ?? '');
$hasCover = \SocialBored\Util::hasCover($cover);
$coverUrl = \SocialBored\Util::coverUrl($cover);
$privacy = (string)($profile['privacy'] ?? 'public');
$pm = \App::getInstance()->pluginManager;
$hasMessages = $pm !== null && $pm->isEnabled('textmebored');

$pdo = \App::getInstance()->pdo;
$userId = (int)($_SESSION['user_id'] ?? 0);
$account = [
    'username' => (string)($_SESSION['username'] ?? ''),
    'email' => (string)($_SESSION['email'] ?? ''),
    'avatar' => (string)($_SESSION['avatar'] ?? ''),
];
if ($pdo instanceof \PDO && $userId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT username, email, avatar FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $account = array_merge($account, $row);
        }
    } catch (\Throwable $e) {
    }
}

$profileError = (string)($_SESSION['profile_error'] ?? '');
$avatarSuccess = (string)($_SESSION['avatar_upload_success'] ?? '');
$avatarError = (string)($_SESSION['avatar_upload_error'] ?? '');
unset($_SESSION['profile_error'], $_SESSION['avatar_upload_success'], $_SESSION['avatar_upload_error']);

include $coreViews . '/header.php';
\render_header(\t('edit_profile'), ['sidebar' => false, 'wide' => true]);
?>
<div class="social-shell social-shell-narrow">
    <nav class="social-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= \escape(\SocialBored\Util::profileUrl((string)($_SESSION['username'] ?? ''))) ?>"><i class="fas fa-arrow-left"></i><?= \escape(\t('profile')) ?></a>
    </nav>

    <header class="social-page-head">
        <div>
            <h1 class="social-page-title"><?= \escape(\t('edit_profile')) ?></h1>
            <p class="social-page-subtitle"><?= \escape(\pt('socialbored', 'edit_profile_merged_hint')) ?></p>
        </div>
    </header>

    <?php if ($profileError !== ''): ?>
        <div class="alert alert-danger social-alert"><i class="fas fa-exclamation-circle"></i><?= \escape($profileError) ?></div>
    <?php endif; ?>
    <?php if ($avatarSuccess !== ''): ?>
        <div class="alert alert-success social-alert"><i class="fas fa-check-circle"></i><?= \escape($avatarSuccess) ?></div>
    <?php endif; ?>
    <?php if ($avatarError !== ''): ?>
        <div class="alert alert-danger social-alert"><i class="fas fa-exclamation-circle"></i><?= \escape($avatarError) ?></div>
    <?php endif; ?>
    <?php if (!empty($settingsError)): ?>
        <div class="alert alert-danger social-alert"><i class="fas fa-exclamation-circle"></i><?= \escape($settingsError) ?></div>
    <?php endif; ?>

    <section class="social-card social-settings-form">
        <h2 class="social-card-title"><?= \escape(\t('account')) ?></h2>
        <form method="POST" action="<?= \escape(\url('update_profile')) ?>">
            <input type="hidden" name="csrf_token" value="<?= \generate_csrf_token() ?>">
            <div class="social-settings-grid">
                <div class="social-field">
                    <label class="form-label" for="profile-username"><?= \escape(\t('username')) ?></label>
                    <input id="profile-username" type="text" name="username" class="form-control" value="<?= \escape((string)$account['username']) ?>">
                </div>
                <div class="social-field">
                    <label class="form-label" for="profile-email"><?= \escape(\t('email')) ?></label>
                    <input id="profile-email" type="email" name="email" class="form-control" value="<?= \escape((string)$account['email']) ?>">
                </div>
            </div>
            <div class="social-field">
                <label class="form-label" for="profile-password"><?= \escape(\t('password')) ?></label>
                <input id="profile-password" type="password" name="password" class="form-control" placeholder="<?= \escape(\t('leave_blank_keep')) ?>">
            </div>
            <div class="social-settings-actions">
                <button type="submit" class="btn btn-brand"><i class="fas fa-save"></i><?= \escape(\t('save_changes')) ?></button>
            </div>
        </form>
    </section>

    <section class="social-card social-settings-form text-center">
        <h2 class="social-card-title"><?= \escape(\t('avatar')) ?></h2>
        <div class="mb-3">
            <?= \render_avatar((string)$account['username'], (string)$account['avatar'], 110) ?>
        </div>
        <form method="POST" action="<?= \escape(\url('upload_avatar')) ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= \generate_csrf_token() ?>">
            <div class="mb-3 text-start">
                <input type="file" name="avatar" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                <div class="form-text"><?= \escape(\t('avatar_hint')) ?></div>
            </div>
            <button type="submit" class="btn btn-outline-soft w-100"><i class="fas fa-upload"></i><?= \escape(\t('upload_avatar')) ?></button>
        </form>
        <?php if ((string)$account['avatar'] !== ''): ?>
            <form method="POST" action="<?= \escape(\url('remove_avatar')) ?>" class="mt-2">
                <input type="hidden" name="csrf_token" value="<?= \generate_csrf_token() ?>">
                <button type="submit" class="btn btn-outline-danger w-100"><i class="fas fa-trash-alt"></i><?= \escape(\t('remove_avatar')) ?></button>
            </form>
        <?php endif; ?>
    </section>

    <form method="POST" action="<?= \escape(\SocialBored\Http::url('settings/social')) ?>" enctype="multipart/form-data" class="social-card social-settings-form">
        <?= \SocialBored\Http::csrfField() ?>

        <h2 class="social-card-title"><?= \escape(\pt('socialbored', 'social_settings')) ?></h2>

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

    <?php if ($hasMessages): ?>
        <div class="social-card social-settings-links">
            <a class="btn btn-outline-soft btn-sm" href="<?= \escape(\SocialBored\Http::url('messages')) ?>"><i class="fas fa-envelope"></i><?= \escape(\t('messages')) ?></a>
        </div>
    <?php endif; ?>
</div>
<?php \render_footer(); ?>
