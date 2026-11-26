<?php
$coreViews = $coreViews ?? dirname(__DIR__, 4) . '/views';
$settings = $settings ?? [];
$deps = $deps ?? [];
$stats = $stats ?? [];
$theme = $theme ?? ['installed' => false, 'active' => ''];
$schemaVersion = $schemaVersion ?? 0;
$message = $message ?? '';
$error = $error ?? '';

include $coreViews . '/admin_header.php';
\render_admin_header(\pt('socialbored', 'social'));
?>
<div class="container-fluid">
    <div class="page-head">
        <div>
            <h1 class="page-title"><?= \escape(\pt('socialbored', 'social')) ?></h1>
            <p class="page-subtitle"><?= \escape(\pt('socialbored', 'admin_subtitle')) ?></p>
        </div>
        <div class="page-actions">
            <a class="btn btn-outline-primary" href="<?= \escape(\SocialBored\Http::url('admin/social/reports')) ?>">
                <i class="fas fa-flag"></i><?= \escape(\pt('socialbored', 'reports_open', ['n' => (int)($stats['reports'] ?? 0)])) ?>
            </a>
            <a class="btn btn-outline-secondary" href="<?= \escape(\SocialBored\Http::url('social')) ?>">
                <i class="fas fa-up-right-from-square"></i><?= \escape(\pt('socialbored', 'open_feed')) ?>
            </a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-1"></i><?= \escape($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i><?= \escape($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="text-muted small"><?= \escape(\pt('socialbored', 'stat_posts')) ?></div>
                <div class="fs-3 fw-bold"><?= (int)($stats['posts'] ?? 0) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="text-muted small"><?= \escape(\pt('socialbored', 'stat_follows')) ?></div>
                <div class="fs-3 fw-bold"><?= (int)($stats['follows'] ?? 0) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="text-muted small"><?= \escape(\pt('socialbored', 'stat_reactions')) ?></div>
                <div class="fs-3 fw-bold"><?= (int)($stats['reactions'] ?? 0) ?></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="text-muted small"><?= \escape(\pt('socialbored', 'stat_reports_open')) ?></div>
                <div class="fs-3 fw-bold"><?= (int)($stats['reports'] ?? 0) ?></div>
            </div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <form method="POST" class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-sliders me-2"></i><?= \escape(\pt('socialbored', 'settings')) ?></h5>
                </div>
                <div class="card-body">
                    <?= \SocialBored\Http::csrfField() ?>
                    <input type="hidden" name="action" value="save_settings">

                    <div class="mb-4">
                        <label class="form-label fw-semibold"><?= \escape(\pt('socialbored', 'mode')) ?></label>
                        <div class="row g-2">
                            <?php foreach (['forum' => 'mode_forum', 'social' => 'mode_social', 'both' => 'mode_both'] as $value => $labelKey): ?>
                                <div class="col-md-4">
                                    <label class="border rounded-3 p-3 d-block h-100 <?= ($settings['mode'] ?? 'forum') === $value ? 'border-primary bg-light' : '' ?>">
                                        <input class="form-check-input me-2" type="radio" name="mode" value="<?= $value ?>" <?= ($settings['mode'] ?? 'forum') === $value ? 'checked' : '' ?>>
                                        <strong><?= \escape(\pt('socialbored', $labelKey)) ?></strong>
                                        <span class="d-block small text-muted mt-1"><?= \escape(\pt('socialbored', $labelKey . '_hint')) ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="social-feed-default"><?= \escape(\pt('socialbored', 'feed_default')) ?></label>
                            <select id="social-feed-default" name="feed_default" class="form-select">
                                <?php foreach (['following' => 'tab_following', 'discover' => 'tab_discover', 'latest' => 'tab_latest'] as $value => $labelKey): ?>
                                    <option value="<?= $value ?>" <?= ($settings['feed_default'] ?? 'discover') === $value ? 'selected' : '' ?>><?= \escape(\pt('socialbored', $labelKey)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="social-default-visibility"><?= \escape(\pt('socialbored', 'default_visibility')) ?></label>
                            <select id="social-default-visibility" name="default_visibility" class="form-select">
                                <?php foreach (['public', 'followers', 'private'] as $value): ?>
                                    <option value="<?= $value ?>" <?= ($settings['default_visibility'] ?? 'public') === $value ? 'selected' : '' ?>><?= \escape(\SocialBored\Util::visibilityLabel($value)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="social-label"><?= \escape(\pt('socialbored', 'site_label')) ?></label>
                            <input id="social-label" type="text" name="site_label" class="form-control" maxlength="40" value="<?= \escape((string)($settings['site_label'] ?? 'Social')) ?>">
                        </div>
                    </div>

                    <hr class="my-4">

                    <div class="row g-2">
                        <?php
                        $flags = [
                            'include_forum_threads' => 'include_forum_threads',
                            'allow_public_feed' => 'allow_public_feed',
                            'show_nav' => 'show_nav',
                            'enable_following_feed' => 'enable_following_feed',
                            'enable_reactions' => 'enable_reactions',
                            'enable_reposts' => 'enable_reposts',
                            'enable_bookmarks' => 'enable_bookmarks',
                            'enable_hashtags' => 'enable_hashtags',
                            'social_email_notifications' => 'social_email_notifications',
                        ];
                        foreach ($flags as $flag => $labelKey): ?>
                            <div class="col-md-6">
                                <input type="hidden" name="<?= $flag ?>" value="0">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="<?= $flag ?>" value="1" id="flag-<?= $flag ?>" <?= !empty($settings[$flag]) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="flag-<?= $flag ?>"><?= \escape(\pt('socialbored', $labelKey)) ?></label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= \escape(\t('save_changes')) ?></button>
                    </div>
                </div>
            </form>

            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-palette me-2"></i><?= \escape(\pt('socialbored', 'theme')) ?></h5>
                </div>
                <div class="card-body">
                    <p class="mb-2">
                        <?= \escape(\pt('socialbored', 'theme_active', ['theme' => (string)($theme['active'] ?? '')])) ?>
                    </p>
                    <?php if (empty($theme['installed'])): ?>
                        <div class="alert alert-warning py-2 px-3 small mb-2">
                            <?= \escape(\pt('socialbored', 'theme_missing')) ?>
                        </div>
                    <?php else: ?>
                        <form method="POST" class="d-inline-flex gap-2 flex-wrap">
                            <?= \SocialBored\Http::csrfField() ?>
                            <input type="hidden" name="action" value="activate_theme">
                            <button type="submit" name="theme" value="socialbored" class="btn btn-outline-primary btn-sm"><?= \escape(\pt('socialbored', 'activate_social_theme')) ?></button>
                            <button type="submit" name="theme" value="freshbored" class="btn btn-outline-secondary btn-sm"><?= \escape(\pt('socialbored', 'activate_forum_theme')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-puzzle-piece me-2"></i><?= \escape(\pt('socialbored', 'dependencies')) ?></h5>
                </div>
                <div class="card-body">
                    <?php foreach (['editbored' => true, 'bellbored' => true, 'textmebored' => false] as $dep => $required): ?>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <div>
                                <strong><?= \escape($dep) ?></strong>
                                <span class="d-block small text-muted"><?= $required ? \escape(\pt('socialbored', 'required')) : \escape(\pt('socialbored', 'optional')) ?></span>
                            </div>
                            <?php if (!empty($deps[$dep])): ?>
                                <span class="badge bg-success"><?= \escape(\pt('socialbored', 'enabled')) ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><?= \escape(\pt('socialbored', 'disabled')) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <p class="small text-muted mt-3 mb-0"><?= \escape(\pt('socialbored', 'dependencies_hint')) ?></p>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-circle-info me-2"></i><?= \escape(\pt('socialbored', 'system')) ?></h5>
                </div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between py-1"><span><?= \escape(\pt('socialbored', 'schema_version')) ?></span><strong><?= (int)$schemaVersion ?></strong></div>
                    <div class="d-flex justify-content-between py-1"><span><?= \escape(\pt('socialbored', 'profiles')) ?></span><strong><?= (int)($stats['profiles'] ?? 0) ?></strong></div>
                    <div class="d-flex justify-content-between py-1"><span><?= \escape(\pt('socialbored', 'mode')) ?></span><strong><?= \escape((string)($settings['mode'] ?? 'forum')) ?></strong></div>
                </div>
            </div>

            <div class="card shadow-sm border-danger">
                <div class="card-header bg-danger-subtle">
                    <h5 class="card-title mb-0 text-danger"><i class="fas fa-triangle-exclamation me-2"></i><?= \escape(\pt('socialbored', 'danger_zone')) ?></h5>
                </div>
                <div class="card-body">
                    <p class="small"><?= \escape(\pt('socialbored', 'delete_data_hint')) ?></p>
                    <form method="POST" class="mb-2">
                        <?= \SocialBored\Http::csrfField() ?>
                        <input type="hidden" name="action" value="delete_data">
                        <div class="mb-2">
                            <input type="text" name="confirm" class="form-control form-control-sm" placeholder="DELETE" autocomplete="off">
                        </div>
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="fas fa-trash me-1"></i><?= \escape(\pt('socialbored', 'delete_data')) ?></button>
                    </form>
                    <form method="POST">
                        <?= \SocialBored\Http::csrfField() ?>
                        <input type="hidden" name="action" value="cleanup_orphans">
                        <button type="submit" class="btn btn-outline-secondary btn-sm w-100" data-confirm="<?= \escape(\pt('socialbored', 'cleanup_confirm')) ?>"><i class="fas fa-broom me-1"></i><?= \escape(\pt('socialbored', 'cleanup_orphans')) ?></button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $coreViews . '/admin_footer.php'; ?>
