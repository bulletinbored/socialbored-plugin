<?php

namespace SocialBored;

use App;

class Admin
{
    public static function handle(string $method): bool|\Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if (!Http::isAdmin()) {
            return \Bulletin\Response::error(403, 'Forbidden');
        }

        $app = App::getInstance();
        $pdo = $app->pdo;
        $pm = $app->pluginManager;
        $message = '';
        $error = '';

        if ($method === 'POST') {
            if ($denied = Http::requireCsrf()) {
                return $denied;
            }
            if (!Http::rateLimit('socialbored_admin', 30, 3600)) {
                $error = \pt('socialbored', 'admin_rate_limited');
            } else {
                $action = (string)($_POST['action'] ?? 'save_settings');
                if ($action === 'save_settings') {
                    $mode = (string)($_POST['mode'] ?? 'forum');
                    if (!in_array($mode, ['forum', 'social', 'both'], true)) {
                        $mode = 'forum';
                    }
                    $deps = self::dependencies($pm);
                    if (($mode === 'social' || $mode === 'both') && (empty($deps['bellbored']) || empty($deps['textmebored']))) {
                        $error = \pt('socialbored', 'deps_required');
                        $mode = 'forum';
                    } else {
                        Settings::set($pm, 'mode', $mode);
                        $feed = (string)($_POST['feed_default'] ?? 'discover');
                        Settings::set($pm, 'feed_default', in_array($feed, ['following', 'discover', 'latest'], true) ? $feed : 'discover');
                        $visibility = Posts::normalizeVisibility($_POST['default_visibility'] ?? 'public');
                        Settings::set($pm, 'default_visibility', $visibility);
                        Settings::set($pm, 'site_label', Util::truncate(trim((string)($_POST['site_label'] ?? 'Social')), 40) ?: 'Social');
                        foreach (['include_forum_threads', 'allow_public_feed', 'social_email_notifications', 'show_nav', 'enable_reactions', 'enable_reposts', 'enable_bookmarks', 'enable_hashtags', 'enable_following_feed'] as $flag) {
                            Settings::set($pm, $flag, !empty($_POST[$flag]));
                        }
                        $message = \pt('socialbored', 'settings_saved');
                    }
                } elseif ($action === 'delete_data') {
                    if (($_POST['confirm'] ?? '') !== 'DELETE') {
                        $error = \pt('socialbored', 'delete_confirm_error');
                    } else {
                        self::deleteSocialData($pdo);
                        $message = \pt('socialbored', 'data_deleted');
                    }
                } elseif ($action === 'cleanup_orphans') {
                    $cleaned = self::cleanupOrphans($pdo);
                    $message = \pt('socialbored', 'orphans_cleaned', ['n' => array_sum($cleaned)]);
                }
            }
        }

        $settings = Settings::all($pm);
        $deps = self::dependencies($pm);
        $stats = self::stats($pdo);
        $schemaVersion = Schema::currentVersion($pdo);
        $themeInfo = self::themeStatus($app);
        $coreViews = dirname(__DIR__, 3) . '/views';

        include dirname(__DIR__) . '/views/admin/settings.php';
        return true;
    }

    public static function handleReports(string $method): bool|\Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if (!Http::canModerate()) {
            return \Bulletin\Response::error(403, 'Forbidden');
        }

        $pdo = App::getInstance()->pdo;
        $message = '';
        $error = '';

        if ($method === 'POST') {
            if ($denied = Http::requireCsrf()) {
                return $denied;
            }
            if (!Http::rateLimit('socialbored_reports_admin', 60, 3600)) {
                $error = \pt('socialbored', 'admin_rate_limited');
            } else {
                $reportId = (int)($_POST['report_id'] ?? 0);
                $action = (string)($_POST['report_action'] ?? '');
                if ($reportId > 0 && in_array($action, ['resolve', 'dismiss'], true)) {
                    $pdo->prepare("UPDATE social_reports SET status = ?, resolved_at = ?, resolved_by = ? WHERE id = ?")
                        ->execute([$action === 'resolve' ? 'resolved' : 'dismissed', date('Y-m-d H:i:s'), Http::userId(), $reportId]);
                    $message = \pt('socialbored', 'report_updated');
                }
            }
        }

        $status = (string)($_GET['status'] ?? 'open');
        if (!in_array($status, ['open', 'resolved', 'dismissed', 'all'], true)) {
            $status = 'open';
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $where = $status === 'all' ? '1=1' : 'r.status = :report_status';
        $params = $status === 'all' ? [] : [':report_status' => $status];

        $reports = [];
        $total = 0;
        try {
            $stmt = $pdo->prepare("
                SELECT r.*, u.username AS reporter
                FROM social_reports r
                LEFT JOIN users u ON u.id = r.reporter_id
                WHERE {$where}
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT :limit OFFSET :offset
            ");
            if (isset($params[':report_status'])) {
                $stmt->bindValue(':report_status', (string)$params[':report_status'], \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $reports = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM social_reports r WHERE {$where}");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('socialbored reports: ' . $e->getMessage());
        }

        foreach ($reports as &$report) {
            $report['target'] = self::reportTarget($pdo, (string)$report['target_type'], (int)$report['target_id']);
        }
        unset($report);

        $pages = max(1, (int)ceil($total / $perPage));
        $coreViews = dirname(__DIR__, 3) . '/views';

        include dirname(__DIR__) . '/views/admin/reports.php';
        return true;
    }

    /**
     * Report the active theme and whether it provides the socialbored identity.
     *
     * The social components consume the core `--fb-*` design tokens. freshbored
     * ships the social-mode values; another theme is compatible when it defines
     * those tokens too. When the active theme cannot be determined we return
     * null so the admin page simply omits the row.
     */
    public static function themeStatus(object $app): ?array
    {
        $root = dirname(__DIR__, 3);
        $manifestPath = (string)($app->config['theme_manifest'] ?? $root . '/data/themes.json');
        $themes = [];
        if (is_file($manifestPath)) {
            $data = json_decode((string)file_get_contents($manifestPath), true);
            if (is_array($data)) {
                $themes = $data;
            }
        }

        $active = (string)($themes['active'] ?? $app->config['theme'] ?? '');
        if ($active === '') {
            return null;
        }

        $cssPath = $root . '/themes/' . $active . '/style.css';
        $compatible = $active === 'freshbored';
        if (!$compatible && is_file($cssPath)) {
            $css = (string)file_get_contents($cssPath);
            $compatible = str_contains($css, '--fb-brand') && str_contains($css, '--fb-bg');
        }

        return ['name' => $active, 'compatible' => $compatible];
    }

    public static function stats(\PDO $pdo): array
    {
        $stats = [
            'posts' => 0,
            'follows' => 0,
            'reactions' => 0,
            'reports' => 0,
            'profiles' => 0,
        ];
        foreach ($stats as $key => $_) {
            try {
                $sql = match ($key) {
                    'posts' => 'SELECT COUNT(*) FROM social_threads',
                    'follows' => 'SELECT COUNT(*) FROM social_follows',
                    'reactions' => 'SELECT COUNT(*) FROM social_reactions',
                    'reports' => "SELECT COUNT(*) FROM social_reports WHERE status = 'open'",
                    'profiles' => 'SELECT COUNT(*) FROM social_profiles',
                };
                $stats[$key] = (int)$pdo->query($sql)->fetchColumn();
            } catch (\Throwable $e) {
            }
        }
        return $stats;
    }

    public static function dependencies(\PluginManager $pm): array
    {
        return [
            'bellbored' => $pm->isEnabled('bellbored'),
            'textmebored' => $pm->isEnabled('textmebored'),
        ];
    }

    /**
     * Remove social rows whose user/thread/post target no longer exists.
     * Core user deletion has no plugin hook, so this keeps the social tables
     * tidy without adding fragile foreign keys to the core schema.
     *
     * @return array<string,int>
     */
    public static function cleanupOrphans(\PDO $pdo): array
    {
        $queries = [
            'reactions' => "DELETE FROM social_reactions
                WHERE user_id NOT IN (SELECT id FROM users)
                   OR (target_type = 'thread' AND target_id NOT IN (SELECT id FROM threads))
                   OR (target_type = 'post' AND target_id NOT IN (SELECT id FROM posts))",
            'reports' => "DELETE FROM social_reports
                WHERE reporter_id NOT IN (SELECT id FROM users)
                   OR (target_type = 'thread' AND target_id NOT IN (SELECT id FROM threads))
                   OR (target_type = 'post' AND target_id NOT IN (SELECT id FROM posts))
                   OR (target_type = 'user' AND target_id NOT IN (SELECT id FROM users))",
            'bookmarks' => "DELETE FROM social_bookmarks
                WHERE user_id NOT IN (SELECT id FROM users) OR thread_id NOT IN (SELECT id FROM threads)",
            'reposts' => "DELETE FROM social_reposts
                WHERE user_id NOT IN (SELECT id FROM users) OR thread_id NOT IN (SELECT id FROM threads)",
            'thread_hashtags' => "DELETE FROM social_thread_hashtags
                WHERE thread_id NOT IN (SELECT id FROM threads)
                   OR hashtag_id NOT IN (SELECT id FROM social_hashtags)",
            'threads' => "DELETE FROM social_threads WHERE thread_id NOT IN (SELECT id FROM threads)",
            'hashtags' => "DELETE FROM social_hashtags
                WHERE id NOT IN (SELECT hashtag_id FROM social_thread_hashtags)",
            'follows' => "DELETE FROM social_follows
                WHERE follower_id NOT IN (SELECT id FROM users) OR following_id NOT IN (SELECT id FROM users)",
            'blocks' => "DELETE FROM social_blocks
                WHERE blocker_id NOT IN (SELECT id FROM users) OR blocked_id NOT IN (SELECT id FROM users)",
            'mutes' => "DELETE FROM social_mutes
                WHERE user_id NOT IN (SELECT id FROM users) OR muted_id NOT IN (SELECT id FROM users)",
            'profiles' => "DELETE FROM social_profiles WHERE user_id NOT IN (SELECT id FROM users)",
        ];

        try {
            $orphanCovers = $pdo->query("
                SELECT cover FROM social_profiles
                WHERE user_id NOT IN (SELECT id FROM users) AND cover IS NOT NULL AND cover <> ''
            ")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($orphanCovers as $cover) {
                Media::deleteCover((string)$cover);
            }
        } catch (\Throwable $e) {
        }

        $counts = [];
        foreach ($queries as $key => $sql) {
            $counts[$key] = 0;
            try {
                $counts[$key] = (int)$pdo->exec($sql);
            } catch (\Throwable $e) {
            }
        }
        return $counts;
    }

    public static function deleteSocialData(\PDO $pdo): void
    {
        try {
            $covers = $pdo->query("SELECT cover FROM social_profiles WHERE cover IS NOT NULL AND cover <> ''")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($covers as $cover) {
                Media::deleteCover((string)$cover);
            }
        } catch (\Throwable $e) {
        }

        $tables = [
            'social_thread_hashtags',
            'social_hashtags',
            'social_reactions',
            'social_bookmarks',
            'social_reposts',
            'social_reports',
            'social_threads',
            'social_follows',
            'social_blocks',
            'social_mutes',
            'social_profiles',
        ];
        foreach ($tables as $table) {
            try {
                $pdo->exec("DELETE FROM {$table}");
            } catch (\Throwable $e) {
            }
        }
        try {
            $pdo->exec("DELETE FROM social_meta WHERE k = 'schema_version'");
        } catch (\Throwable $e) {
        }
    }

    public static function reportTarget(\PDO $pdo, string $type, int $id): ?array
    {
        try {
            if ($type === 'thread') {
                $stmt = $pdo->prepare("SELECT t.id, t.title, t.user_id, u.username AS author FROM threads t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $row ? ['type' => 'thread', 'id' => $id, 'label' => (string)$row['title'], 'author' => (string)($row['author'] ?? ''), 'url' => Util::threadUrl($row)] : null;
            }
            if ($type === 'post') {
                $stmt = $pdo->prepare("SELECT p.id, p.content, p.thread_id, t.title, u.username AS author FROM posts p JOIN threads t ON t.id = p.thread_id LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $row ? ['type' => 'post', 'id' => $id, 'label' => Util::truncate((string)$row['content'], 120), 'author' => (string)($row['author'] ?? ''), 'url' => Util::threadUrl(['id' => (int)$row['thread_id'], 'title' => (string)$row['title']]) . '#post-' . $id] : null;
            }
            if ($type === 'user') {
                $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $row ? ['type' => 'user', 'id' => $id, 'label' => (string)$row['username'], 'author' => (string)$row['username'], 'url' => Util::profileUrl((string)$row['username'])] : null;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }
}
