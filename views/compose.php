<?php
$coreViews = dirname(__DIR__, 3) . '/views';
include $coreViews . '/header.php';
\render_header(\pt('socialbored', 'compose'), ['sidebar' => false, 'wide' => true]);
$back = $back ?? '/';
$visibility = (string)($draft['visibility'] ?? 'public');
?>
<div class="social-shell social-shell-narrow">
    <nav class="social-breadcrumb" aria-label="Breadcrumb">
        <a href="<?= \escape($back) ?>"><i class="fas fa-arrow-left"></i><?= \escape(\pt('socialbored', 'back_to_feed')) ?></a>
    </nav>

    <section class="social-card social-compose-card">
        <header class="social-compose-head">
            <?= \SocialBored\Util::avatar((string)($_SESSION['username'] ?? ''), (string)($_SESSION['avatar'] ?? ''), 48) ?>
            <div>
                <strong><?= \escape($_SESSION['username'] ?? '') ?></strong>
                <span><?= \escape(\pt('socialbored', 'compose_visibility_hint')) ?></span>
            </div>
        </header>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger social-alert"><i class="fas fa-exclamation-circle"></i><?= \escape($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= \escape(\SocialBored\Http::url('compose')) ?>" class="social-compose-form">
            <?= \SocialBored\Http::csrfField() ?>
            <div class="social-field">
                <label class="form-label" for="social-title"><?= \escape(\pt('socialbored', 'optional_title')) ?></label>
                <input id="social-title" type="text" name="title" class="form-control" maxlength="120"
                       value="<?= \escape((string)($draft['title'] ?? '')) ?>"
                       placeholder="<?= \escape(\pt('socialbored', 'optional_title_placeholder')) ?>">
            </div>

            <div class="social-field">
                <label class="form-label" for="editbored-content"><?= \escape(\pt('socialbored', 'post_content')) ?></label>
                <textarea id="editbored-content" name="content" class="form-control editbored-wrap" rows="8" required
                          placeholder="<?= \escape(\pt('socialbored', 'compose_placeholder')) ?>"><?= \escape((string)($draft['content'] ?? '')) ?></textarea>
                <div class="form-text"><?= \escape(\pt('socialbored', 'compose_hint')) ?></div>
            </div>

            <div class="social-compose-row">
                <div class="social-field social-field-inline">
                    <label class="form-label" for="social-visibility"><?= \escape(\pt('socialbored', 'visibility')) ?></label>
                    <select id="social-visibility" name="visibility" class="form-select">
                        <?php foreach (['public', 'followers', 'private'] as $option): ?>
                            <option value="<?= $option ?>" <?= $visibility === $option ? 'selected' : '' ?>><?= \escape(\SocialBored\Util::visibilityLabel($option)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="social-compose-actions">
                    <a class="btn btn-outline-soft" href="<?= \escape($back) ?>"><?= \escape(\t('cancel')) ?></a>
                    <button type="submit" class="btn btn-brand">
                        <i class="fas fa-paper-plane"></i><?= \escape(\pt('socialbored', 'publish')) ?>
                    </button>
                </div>
            </div>
        </form>
    </section>
</div>
<?php \render_footer(); ?>
