<?php

/**
 * socialbored schema — idempotent table creation for the social layer.
 *
 * The plugin reuses the core `users`, `threads` and `posts` tables and adds
 * only the social metadata it needs. Every statement is safe to run repeatedly
 * so the plugin can recover from an interrupted install/update.
 */

namespace SocialBored;

class Schema
{
    public const VERSION = 2;

    public static function ensure(\PDO $pdo): void
    {
        if (self::currentVersion($pdo) >= self::VERSION && self::hasCoreTables($pdo) && self::hasSocialTables($pdo)) {
            return;
        }

        self::ensureTables($pdo);
        self::setVersion($pdo, self::VERSION);
    }

    public static function ensureTables(\PDO $pdo): void
    {
        if (!self::hasCoreTables($pdo)) {
            throw new \RuntimeException('socialbored requires the core users/threads/posts tables');
        }

        $driver = (string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $mysql = $driver === 'mysql';
        $charset = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $int = $mysql ? 'INT' : 'INTEGER';
        $auto = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text = 'TEXT';
        $varchar = static fn(int $n): string => $mysql ? 'VARCHAR(' . $n . ')' : 'TEXT';
        $now = 'DATETIME DEFAULT CURRENT_TIMESTAMP';

        $tables = [
            "CREATE TABLE IF NOT EXISTS social_meta (
                k {$varchar(64)} PRIMARY KEY,
                v {$text}
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_profiles (
                user_id {$int} PRIMARY KEY,
                display_name {$varchar(100)},
                bio {$text},
                location {$varchar(100)},
                website {$varchar(255)},
                cover {$varchar(255)},
                privacy {$varchar(20)} DEFAULT 'public',
                pinned_thread_id {$int} NULL,
                created_at {$now},
                updated_at {$now}
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_follows (
                follower_id {$int} NOT NULL,
                following_id {$int} NOT NULL,
                created_at {$now},
                PRIMARY KEY (follower_id, following_id)
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_blocks (
                blocker_id {$int} NOT NULL,
                blocked_id {$int} NOT NULL,
                created_at {$now},
                PRIMARY KEY (blocker_id, blocked_id)
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_mutes (
                user_id {$int} NOT NULL,
                muted_id {$int} NOT NULL,
                created_at {$now},
                PRIMARY KEY (user_id, muted_id)
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_threads (
                thread_id {$int} PRIMARY KEY,
                visibility {$varchar(20)} DEFAULT 'public',
                kind {$varchar(20)} DEFAULT 'post',
                repost_of {$int} NULL,
                created_at {$now}
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_reactions (
                user_id {$int} NOT NULL,
                target_type {$varchar(16)} NOT NULL,
                target_id {$int} NOT NULL,
                kind {$varchar(20)} NOT NULL DEFAULT 'like',
                created_at {$now},
                PRIMARY KEY (user_id, target_type, target_id, kind)
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_bookmarks (
                user_id {$int} NOT NULL,
                thread_id {$int} NOT NULL,
                created_at {$now},
                PRIMARY KEY (user_id, thread_id)
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_reposts (
                user_id {$int} NOT NULL,
                thread_id {$int} NOT NULL,
                created_at {$now},
                PRIMARY KEY (user_id, thread_id)
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_hashtags (
                id {$auto},
                tag {$varchar(191)} NOT NULL UNIQUE
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_thread_hashtags (
                thread_id {$int} NOT NULL,
                hashtag_id {$int} NOT NULL,
                PRIMARY KEY (thread_id, hashtag_id)
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_reports (
                id {$auto},
                reporter_id {$int} NOT NULL,
                target_type {$varchar(16)} NOT NULL,
                target_id {$int} NOT NULL,
                reason {$text} NOT NULL,
                status {$varchar(20)} DEFAULT 'open',
                created_at {$now},
                resolved_at DATETIME NULL,
                resolved_by {$int} NULL
            ){$charset}",

            "CREATE TABLE IF NOT EXISTS social_media (
                id {$auto},
                thread_id {$int} NOT NULL,
                kind {$varchar(10)} NOT NULL DEFAULT 'image',
                url {$varchar(1000)} NOT NULL,
                created_at {$now}
            ){$charset}",
        ];

        foreach ($tables as $sql) {
            $pdo->exec($sql);
        }

        self::index($pdo, $mysql, 'social_follows', 'idx_social_follows_following', 'following_id');
        self::index($pdo, $mysql, 'social_blocks', 'idx_social_blocks_blocked', 'blocked_id');
        self::index($pdo, $mysql, 'social_mutes', 'idx_social_mutes_muted', 'muted_id');
        self::index($pdo, $mysql, 'social_threads', 'idx_social_threads_visibility', 'visibility');
        self::index($pdo, $mysql, 'social_reactions', 'idx_social_reactions_target', 'target_type, target_id');
        self::index($pdo, $mysql, 'social_reposts', 'idx_social_reposts_thread', 'thread_id');
        self::index($pdo, $mysql, 'social_bookmarks', 'idx_social_bookmarks_thread', 'thread_id');
        self::index($pdo, $mysql, 'social_thread_hashtags', 'idx_social_thread_hashtags_tag', 'hashtag_id');
        self::index($pdo, $mysql, 'social_reports', 'idx_social_reports_status', 'status, created_at');
        self::index($pdo, $mysql, 'social_media', 'idx_social_media_thread', 'thread_id');
        self::ensureReactionUniqueness($pdo, $mysql);
    }

    /**
     * A reaction is one row per user/target; the kind may change but it may
     * not coexist with another kind. Clean up legacy duplicates before adding
     * the unique index.
     */
    private static function ensureReactionUniqueness(\PDO $pdo, bool $mysql): void
    {
        if (self::hasIndex($pdo, $mysql, 'social_reactions', 'uniq_social_reactions_user_target')) {
            return;
        }

        try {
            $groups = $pdo->query("
                SELECT user_id, target_type, target_id
                FROM social_reactions
                GROUP BY user_id, target_type, target_id
                HAVING COUNT(*) > 1
            ")->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($groups as $group) {
                $params = [
                    (int)$group['user_id'],
                    (string)$group['target_type'],
                    (int)$group['target_id'],
                ];
                $keep = $pdo->prepare("
                    SELECT kind FROM social_reactions
                    WHERE user_id = ? AND target_type = ? AND target_id = ?
                    ORDER BY CASE WHEN kind = 'like' THEN 0 ELSE 1 END, created_at DESC
                    LIMIT 1
                ");
                $keep->execute($params);
                $kind = (string)($keep->fetchColumn() ?: 'like');
                $pdo->prepare("
                    DELETE FROM social_reactions
                    WHERE user_id = ? AND target_type = ? AND target_id = ? AND kind <> ?
                ")->execute(array_merge($params, [$kind]));
            }
        } catch (\Throwable $e) {
            return;
        }

        if ($mysql) {
            try {
                $pdo->exec("ALTER TABLE social_reactions ADD UNIQUE INDEX uniq_social_reactions_user_target (user_id, target_type, target_id)");
            } catch (\Throwable $e) {
            }
            return;
        }

        try {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uniq_social_reactions_user_target ON social_reactions (user_id, target_type, target_id)");
        } catch (\Throwable $e) {
        }
    }

    private static function hasIndex(\PDO $pdo, bool $mysql, string $table, string $name): bool
    {
        try {
            if ($mysql) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
                ");
                $stmt->execute([$table, $name]);
                return (int)$stmt->fetchColumn() > 0;
            }
            $stmt = $pdo->prepare("PRAGMA index_list({$table})");
            $stmt->execute();
            return in_array($name, $stmt->fetchAll(\PDO::FETCH_COLUMN, 1), true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function currentVersion(\PDO $pdo): int
    {
        try {
            $stmt = $pdo->query("SELECT v FROM social_meta WHERE k = 'schema_version'");
            $value = $stmt ? $stmt->fetchColumn() : false;
            return $value === false ? 0 : (int)$value;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function setVersion(\PDO $pdo, int $version): void
    {
        try {
            $pdo->prepare("DELETE FROM social_meta WHERE k = 'schema_version'")->execute();
            $pdo->prepare("INSERT INTO social_meta (k, v) VALUES ('schema_version', ?)")->execute([(string)$version]);
        } catch (\Throwable $e) {
            // Non-fatal: the version is an optimisation, the tables already exist.
        }
    }

    private static function hasCoreTables(\PDO $pdo): bool
    {
        foreach (['users', 'threads', 'posts'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            } catch (\Throwable $e) {
                return false;
            }
        }
        return true;
    }

    private static function hasSocialTables(\PDO $pdo): bool
    {
        try {
            $pdo->query("SELECT 1 FROM social_threads LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function index(\PDO $pdo, bool $mysql, string $table, string $name, string $columns): void
    {
        if ($mysql) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
                ");
                $stmt->execute([$table, $name]);
                if ((int)$stmt->fetchColumn() > 0) {
                    return;
                }
                $pdo->exec("ALTER TABLE {$table} ADD INDEX {$name} ({$columns})");
            } catch (\Throwable $e) {
                // A duplicate index from a concurrent request is harmless.
            }
            return;
        }

        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
        } catch (\Throwable $e) {
            // A duplicate index from a concurrent request is harmless.
        }
    }
}
