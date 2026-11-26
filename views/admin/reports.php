<?php
$coreViews = $coreViews ?? dirname(__DIR__, 4) . '/views';
$reports = $reports ?? [];
$status = $status ?? 'open';
$page = (int)($page ?? 1);
$pages = (int)($pages ?? 1);
$total = (int)($total ?? 0);
$message = $message ?? '';
$error = $error ?? '';

include $coreViews . '/admin_header.php';
\render_admin_header(\pt('socialbored', 'reports'));
?>
<div class="container-fluid">
    <div class="page-head">
        <div>
            <h1 class="page-title"><?= \escape(\pt('socialbored', 'reports')) ?></h1>
            <p class="page-subtitle"><?= \escape(\pt('socialbored', 'reports_subtitle')) ?></p>
        </div>
        <div class="page-actions">
            <a class="btn btn-outline-secondary" href="<?= \escape(\SocialBored\Http::url('admin/social')) ?>">
                <i class="fas fa-arrow-left"></i><?= \escape(\pt('socialbored', 'social')) ?>
            </a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle me-1"></i><?= \escape($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-1"></i><?= \escape($error) ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-3">
        <?php foreach (['open' => 'reports_open_tab', 'resolved' => 'reports_resolved', 'dismissed' => 'reports_dismissed', 'all' => 'reports_all'] as $key => $labelKey): ?>
            <li class="nav-item">
                <a class="nav-link<?= $status === $key ? ' active' : '' ?>" href="<?= \escape(\SocialBored\Http::url('admin/social/reports') . '?status=' . $key) ?>"><?= \escape(\pt('socialbored', $labelKey)) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if (empty($reports)): ?>
        <div class="card shadow-sm"><div class="card-body text-center py-5 text-muted">
            <i class="fas fa-flag fa-2x mb-3"></i>
            <p class="mb-0"><?= \escape(\pt('socialbored', 'no_reports')) ?></p>
        </div></div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= \escape(\pt('socialbored', 'report_reporter')) ?></th>
                            <th><?= \escape(\pt('socialbored', 'report_target')) ?></th>
                            <th><?= \escape(\pt('socialbored', 'report_reason')) ?></th>
                            <th><?= \escape(\pt('socialbored', 'report_date')) ?></th>
                            <th class="text-end"><?= \escape(\pt('socialbored', 'actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $report):
                            $target = $report['target'] ?? null;
                            ?>
                            <tr>
                                <td><?= (int)$report['id'] ?></td>
                                <td>
                                    <?= \escape((string)($report['reporter'] ?? '—')) ?>
                                    <span class="d-block small text-muted">#<?= (int)$report['reporter_id'] ?></span>
                                </td>
                                <td>
                                    <?php if ($target !== null): ?>
                                        <span class="badge bg-light text-dark"><?= \escape((string)($target['type'] ?? '')) ?></span>
                                        <a href="<?= \escape((string)($target['url'] ?? '#')) ?>"><?= \escape((string)($target['label'] ?? '')) ?></a>
                                        <span class="d-block small text-muted"><?= \escape((string)($target['author'] ?? '')) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted"><?= \escape((string)$report['target_type']) ?> #<?= (int)$report['target_id'] ?> (<?= \escape(\pt('socialbored', 'deleted')) ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="small"><?= \escape((string)($report['reason'] ?? '')) ?></span></td>
                                <td class="small text-muted"><?= \escape(\SocialBored\Util::timeAgo((string)($report['created_at'] ?? ''))) ?></td>
                                <td class="text-end">
                                    <?php if (($report['status'] ?? '') === 'open'): ?>
                                        <form method="POST" class="d-inline">
                                            <?= \SocialBored\Http::csrfField() ?>
                                            <input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
                                            <input type="hidden" name="report_action" value="resolve">
                                            <button class="btn btn-sm btn-outline-success" type="submit"><i class="fas fa-check"></i><?= \escape(\pt('socialbored', 'resolve')) ?></button>
                                        </form>
                                        <form method="POST" class="d-inline">
                                            <?= \SocialBored\Http::csrfField() ?>
                                            <input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
                                            <input type="hidden" name="report_action" value="dismiss">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="fas fa-xmark"></i><?= \escape(\pt('socialbored', 'dismiss')) ?></button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-<?= ($report['status'] ?? '') === 'resolved' ? 'success' : 'secondary' ?>"><?= \escape((string)$report['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="mt-3" aria-label="<?= \escape(\t('pagination')) ?>">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <li class="page-item<?= $i === $page ? ' active' : '' ?>">
                            <a class="page-link" href="<?= \escape(\SocialBored\Http::url('admin/social/reports') . '?status=' . rawurlencode($status) . '&page=' . $i) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php include $coreViews . '/admin_footer.php'; ?>
