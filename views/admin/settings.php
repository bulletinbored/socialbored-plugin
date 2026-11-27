<?php
$coreViews = $coreViews ?? dirname(__DIR__, 4) . '/views';
$settings = $settings ?? [];
$deps = $deps ?? [];
$stats = $stats ?? [];
$schemaVersion = (int)($schemaVersion ?? 0);
$message = $message ?? '';
$error = $error ?? '';

$mode = (string)($settings['mode'] ?? 'forum');
if (!in_array($mode, ['forum', 'social', 'both'], true)) {
    $mode = 'forum';
}
$modeLabel = \pt('socialbored', 'mode_' . $mode);
$feedDefault = (string)($settings['feed_default'] ?? 'discover');
$visibility = (string)($settings['default_visibility'] ?? 'public');
$siteLabel = (string)($settings['site_label'] ?? 'Social');
$openReports = (int)($stats['reports'] ?? 0);

$modes = [
    'forum' => ['label' => 'mode_forum', 'hint' => 'mode_forum_hint', 'icon' => 'fa-comments'],
    'social' => ['label' => 'mode_social', 'hint' => 'mode_social_hint', 'icon' => 'fa-users-rectangle'],
    'both' => ['label' => 'mode_both', 'hint' => 'mode_both_hint', 'icon' => 'fa-layer-group'],
];

$flags = [
    'include_forum_threads' => 'include_forum_threads',
    'allow_public_feed' => 'allow_public_feed',
    'enable_following_feed' => 'enable_following_feed',
    'show_nav' => 'show_nav',
    'enable_reactions' => 'enable_reactions',
    'enable_reposts' => 'enable_reposts',
    'enable_bookmarks' => 'enable_bookmarks',
    'enable_hashtags' => 'enable_hashtags',
    'social_email_notifications' => 'social_email_notifications',
];

$dependencies = [
    'editbored' => true,
    'bellbored' => true,
    'textmebored' => false,
];

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
            <a class="btn btn-outline-secondary" href="<?= \escape(\SocialBored\Http::url('social')) ?>">
                <i class="fas fa-arrow-up-right-from-square"></i><?= \escape(\pt('socialbored', 'open_feed')) ?>
            </a>
            <a class="btn btn-outline-secondary" href="<?= \escape(\SocialBored\Http::url('admin/social/reports')) ?>">
                <i class="fas fa-flag"></i><?= \escape(\pt('socialbored', 'reports')) ?>
                <?php if ($openReports > 0): ?>
                    <span class="badge text-bg-warning ms-1"><?= $openReports ?></span>
                <?php endif; ?>
            </a>
            <button type="submit" form="socialSettingsForm" class="btn btn-primary">
                <i class="fas fa-save"></i><?= \escape(\t('save_changes')) ?>
            </button>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-circle-check me-1"></i><?= \escape($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-circle-exclamation me-1"></i><?= \escape($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-chart-simple me-2"></i><?= \escape(\pt('socialbored', 'admin_status')) ?></h5>
        </div>
        <div class="card-body">
            <table class="table table-sm align-middle mb-0">
                <tbody>
                    <tr>
                        <th scope="row" class="text-muted fw-normal"><?= \escape(\pt('socialbored', 'mode')) ?></th>
                        <td class="text-end fw-semibold"><?= \escape($modeLabel) ?></td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-muted fw-normal"><?= \escape(\pt('socialbored', 'schema_version')) ?></th>
                        <td class="text-end fw-semibold"><?= $schemaVersion ?></td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-muted fw-normal"><?= \escape(\pt('socialbored', 'stat_posts')) ?></th>
                        <td class="text-end fw-semibold"><?= (int)($stats['posts'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-muted fw-normal"><?= \escape(\pt('socialbored', 'profiles')) ?></th>
                        <td class="text-end fw-semibold"><?= (int)($stats['profiles'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-muted fw-normal"><?= \escape(\pt('socialbored', 'stat_follows')) ?></th>
                        <td class="text-end fw-semibold"><?= (int)($stats['follows'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-muted fw-normal"><?= \escape(\pt('socialbored', 'stat_reactions')) ?></th>
                        <td class="text-end fw-semibold"><?= (int)($stats['reactions'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <th scope="row" class="text-muted fw-normal"><?= \escape(\pt('socialbored', 'stat_reports_open')) ?></th>
                        <td class="text-end fw-semibold"><?= $openReports ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <form id="socialSettingsForm" method="POST">
        <?= \SocialBored\Http::csrfField() ?>
        <input type="hidden" name="action" value="save_settings">

        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-sliders me-2"></i><?= \escape(\pt('socialbored', 'mode')) ?></h5>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php foreach ($modes as $value => $meta): ?>
                        <?php $active = $mode === $value; ?>
                        <label class="list-group-item d-flex align-items-start gap-3 <?= $active ? 'active' : '' ?>" style="cursor:pointer;">
                            <input class="form-check-input mt-1" type="radio" name="mode" value="<?= $value ?>" <?= $active ? 'checked' : '' ?>>
                            <span class="flex-grow-1">
                                <span class="d-block fw-semibold"><i class="fas <?= \escape($meta['icon']) ?> me-1"></i><?= \escape(\pt('socialbored', $meta['label'])) ?></span>
                                <span class="d-block small <?= $active ? 'text-white-50' : 'text-muted' ?>"><?= \escape(\pt('socialbored', $meta['hint'])) ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-rss me-2"></i><?= \escape(\pt('socialbored', 'admin_feed')) ?></h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="social-feed-default"><?= \escape(\pt('socialbored', 'feed_default')) ?></label>
                    <select id="social-feed-default" name="feed_default" class="form-select">
                        <?php foreach (['following' => 'tab_following', 'discover' => 'tab_discover', 'latest' => 'tab_latest'] as $value => $labelKey): ?>
                            <option value="<?= $value ?>" <?= $feedDefault === $value ? 'selected' : '' ?>><?= \escape(\pt('socialbored', $labelKey)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="social-default-visibility"><?= \escape(\pt('socialbored', 'default_visibility')) ?></label>
                    <select id="social-default-visibility" name="default_visibility" class="form-select">
                        <?php foreach (['public', 'followers', 'private'] as $value): ?>
                            <option value="<?= $value ?>" <?= $visibility === $value ? 'selected' : '' ?>><?= \escape(\SocialBored\Util::visibilityLabel($value)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="social-label"><?= \escape(\pt('socialbored', 'site_label')) ?></label>
                    <input id="social-label" type="text" name="site_label" class="form-control" maxlength="40" value="<?= \escape($siteLabel) ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="fas fa-toggle-on me-2"></i><?= \escape(\pt('socialbored', 'admin_features')) ?></h5>
            </div>
            <div class="card-body">
                <?php foreach ($flags as $flag => $labelKey): ?>
                    <input type="hidden" name="<?= $flag ?>" value="0">
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <label class="form-check-label mb-0" for="flag-<?= $flag ?>"><?= \escape(\pt('socialbored', $labelKey)) ?></label>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" name="<?= $flag ?>" value="1" id="flag-<?= $flag ?>" <?= !empty($settings[$flag]) ? 'checked' : '' ?>>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-puzzle-piece me-2"></i><?= \escape(\pt('socialbored', 'dependencies')) ?></h5>
        </div>
        <div class="card-body">
            <table class="table table-sm align-middle mb-0">
                <tbody>
                    <?php foreach ($dependencies as $dep => $required): ?>
                        <tr>
                            <th scope="row" class="fw-normal">
                                <strong><?= \escape($dep) ?></strong>
                                <span class="d-block small text-muted"><?= $required ? \escape(\pt('socialbored', 'required')) : \escape(\pt('socialbored', 'optional')) ?></span>
                            </th>
                            <td class="text-end">
                                <?php if (!empty($deps[$dep])): ?>
                                    <span class="badge text-bg-success"><?= \escape(\pt('socialbored', 'enabled')) ?></span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary"><?= \escape(\pt('socialbored', 'disabled')) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="small text-muted mt-3 mb-0"><?= \escape(\pt('socialbored', 'dependencies_hint')) ?></p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-palette me-2"></i><?= \escape(\pt('socialbored', 'admin_core_identity')) ?></h5>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-0"><?= \escape(\pt('socialbored', 'admin_core_identity_body')) ?></p>
        </div>
    </div>

    <div class="card border-danger">
        <div class="card-header">
            <h5 class="card-title mb-0 text-danger"><i class="fas fa-triangle-exclamation me-2"></i><?= \escape(\pt('socialbored', 'danger_zone')) ?></h5>
        </div>
        <div class="card-body">
            <p class="small text-muted"><?= \escape(\pt('socialbored', 'delete_data_hint')) ?></p>
            <form method="POST" class="d-flex gap-2 flex-wrap align-items-center">
                <?= \SocialBored\Http::csrfField() ?>
                <input type="hidden" name="action" value="delete_data">
                <input type="text" name="confirm" class="form-control form-control-sm" style="max-width:140px;" placeholder="DELETE" autocomplete="off" aria-label="DELETE">
                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="<?= \escape(\pt('socialbored', 'delete_data')) ?>">
                    <i class="fas fa-trash me-1"></i><?= \escape(\pt('socialbored', 'delete_data')) ?>
                </button>
            </form>
            <form method="POST" class="mt-3">
                <?= \SocialBored\Http::csrfField() ?>
                <input type="hidden" name="action" value="cleanup_orphans">
                <button type="submit" class="btn btn-outline-secondary btn-sm" data-confirm="<?= \escape(\pt('socialbored', 'cleanup_confirm')) ?>">
                    <i class="fas fa-broom me-1"></i><?= \escape(\pt('socialbored', 'cleanup_orphans')) ?>
                </button>
            </form>
        </div>
    </div>
</div>
<?php include $coreViews . '/admin_footer.php'; ?>
