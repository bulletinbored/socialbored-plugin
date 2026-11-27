<?php

namespace SocialBored;

use App;

class Posts
{
    public static function handleCompose(string $method): bool|\Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }

        $app = App::getInstance();
        $pdo = $app->pdo;
        $pm = $app->pluginManager;
        $authz = $app->authz;
        $me = Http::userId();

        if ($authz !== null && !$authz->can($me, 'threads.create')) {
            throw new \Bulletin\ForbiddenException('Not authorized to create posts');
        }

        $error = '';
        $draft = [
            'title' => '',
            'content' => '',
            'visibility' => Settings::defaultVisibility($pm),
        ];

        if ($method === 'POST') {
            if ($denied = Http::requireCsrf()) {
                return $denied;
            }
            if (!Http::rateLimit('socialbored_compose', 15, 3600)) {
                $error = \pt('socialbored', 'compose_rate_limited');
            } else {
                $draft['title'] = trim((string)($_POST['title'] ?? ''));
                $draft['content'] = trim((string)($_POST['content'] ?? ''));
                $draft['visibility'] = self::normalizeVisibility($_POST['visibility'] ?? Settings::defaultVisibility($pm));

                if (\mb_strlen($draft['content']) < 2) {
                    $error = \pt('socialbored', 'compose_empty');
                } elseif (\mb_strlen($draft['content']) > 20000) {
                    $error = \pt('socialbored', 'compose_too_long');
                } else {
                    $threadId = self::createPost($pdo, $pm, $me, $draft);
                    if ($threadId <= 0) {
                        $error = \pt('socialbored', 'compose_failed');
                    } else {
                        $finalTitle = (string)$draft['title'];
                        try {
                            $titleStmt = $pdo->prepare("SELECT title FROM threads WHERE id = ?");
                            $titleStmt->execute([$threadId]);
                            $finalTitle = (string)($titleStmt->fetchColumn() ?: $finalTitle);
                        } catch (\Throwable $e) {
                        }
                        $url = Util::threadUrl(['id' => $threadId, 'title' => $finalTitle]);
                        if (Http::wantsJson()) {
                            return Http::json(['ok' => true, 'thread_id' => $threadId, 'url' => $url, 'csrf' => Http::token()], 201);
                        }
                        return \Bulletin\Response::redirect($url);
                    }
                }
            }

            $_SESSION['socialbored_compose_draft'] = $draft;
        } elseif (!empty($_SESSION['socialbored_compose_draft'])) {
            $draft = array_merge($draft, (array)$_SESSION['socialbored_compose_draft']);
            unset($_SESSION['socialbored_compose_draft']);
        }

        $back = (string)($_GET['back'] ?? '/');
        if (!str_starts_with($back, '/') || str_starts_with($back, '//')) {
            $back = '/';
        }

        include dirname(__DIR__) . '/views/compose.php';
        return true;
    }

    public static function createPost(\PDO $pdo, \PluginManager $pm, int $userId, array $draft): int
    {
        $content = \validate_input((string)($draft['content'] ?? ''));
        $title = trim((string)($draft['title'] ?? ''));
        if ($title === '') {
            $title = Util::firstLine($content);
        }
        if ($title === '') {
            $title = \pt('socialbored', 'untitled_post');
        }
        $title = Util::truncate($title, 120);
        $visibility = self::normalizeVisibility($draft['visibility'] ?? 'public');

        $threadData = [
            'category_id' => null,
            'user_id' => $userId,
            'title' => $title,
            'content' => $content,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $threadData = $pm->filter('thread_before_create', $threadData);
        if ($pm->checkHook('thread_create_block', $threadData)) {
            return 0;
        }

        // Core owns threads.visibility; social_threads.visibility is kept in
        // sync for backward compatibility with the plugin's own feed queries.
        $stmt = $pdo->prepare("
            INSERT INTO threads (category_id, user_id, title, content, status, visibility, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'visible', ?, ?, ?)
        ");
        $stmt->execute([
            $threadData['category_id'] ?? null,
            (int)$threadData['user_id'],
            (string)$threadData['title'],
            (string)$threadData['content'],
            $visibility,
            (string)$threadData['created_at'],
            (string)$threadData['updated_at'],
        ]);
        $threadId = (int)$pdo->lastInsertId();
        if ($threadId <= 0) {
            return 0;
        }

        SocialGraph::insertIgnore($pdo, 'social_threads', [
            'thread_id' => $threadId,
            'visibility' => $visibility,
            'kind' => 'post',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        self::syncHashtags($pdo, $threadId, $content);
        $pm->runHook('thread_after_create', $threadId, $threadData);

        if (function_exists('notify_mentioned_users')) {
            \notify_mentioned_users(
                $pdo,
                $content,
                $threadId,
                $title,
                (string)($_SESSION['username'] ?? ''),
                $userId
            );
        }

        return $threadId;
    }

    public static function syncHashtags(\PDO $pdo, int $threadId, string $content): void
    {
        if ($threadId <= 0) {
            return;
        }
        $pm = App::getInstance()->pluginManager;
        if ($pm instanceof \PluginManager && !Settings::bool($pm, 'enable_hashtags')) {
            return;
        }
        try {
            $pdo->prepare("DELETE FROM social_thread_hashtags WHERE thread_id = ?")->execute([$threadId]);
        } catch (\Throwable $e) {
            return;
        }

        if (!preg_match_all('/(?<![\w#])#([\p{L}\p{N}_]{2,50})/u', $content, $matches)) {
            return;
        }

        $tags = array_values(array_unique(array_map(
            static fn($tag) => \mb_strtolower((string)$tag),
            $matches[1] ?? []
        )));

        foreach (array_slice($tags, 0, 30) as $tag) {
            try {
                SocialGraph::insertIgnore($pdo, 'social_hashtags', ['tag' => $tag]);
                $stmt = $pdo->prepare("SELECT id FROM social_hashtags WHERE tag = ?");
                $stmt->execute([$tag]);
                $hashtagId = (int)($stmt->fetchColumn() ?: 0);
                if ($hashtagId > 0) {
                    SocialGraph::insertIgnore($pdo, 'social_thread_hashtags', [
                        'thread_id' => $threadId,
                        'hashtag_id' => $hashtagId,
                    ]);
                }
            } catch (\Throwable $e) {
            }
        }
    }

    public static function handleVisibility(array $params = []): \Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if ($denied = Http::requireCsrf()) {
            return $denied;
        }
        if (!Http::rateLimit('socialbored_visibility', 60, 3600)) {
            return Http::back('/');
        }

        $pdo = App::getInstance()->pdo;
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $visibility = self::normalizeVisibility($_POST['visibility'] ?? 'public');

        $stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
        $stmt->execute([$threadId]);
        $thread = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$thread) {
            return Http::back('/');
        }

        if (!empty($thread['category_id'])) {
            if (Http::wantsJson()) {
                return Http::json(['ok' => false, 'error' => 'not_social_post'], 422);
            }
            return Http::back('/');
        }

        $me = Http::userId();
        if ((int)$thread['user_id'] !== $me && !Privacy::isPrivileged($me)) {
            return \Bulletin\Response::error(403, 'Forbidden');
        }

        SocialGraph::insertIgnore($pdo, 'social_threads', [
            'thread_id' => $threadId,
            'visibility' => $visibility,
            'kind' => 'post',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $pdo->prepare("UPDATE social_threads SET visibility = ? WHERE thread_id = ?")
            ->execute([$visibility, $threadId]);

        // Keep the core column authoritative so privacy survives even if this
        // plugin is later disabled.
        try {
            $pdo->prepare("UPDATE threads SET visibility = ? WHERE id = ?")
                ->execute([$visibility, $threadId]);
        } catch (\Throwable $e) {
        }

        if (Http::wantsJson()) {
            return Http::json(['ok' => true, 'visibility' => $visibility, 'csrf' => Http::token()]);
        }
        return Http::back('/');
    }

    public static function handleThreadHook(array $thread): array
    {
        $pdo = App::getInstance()->pdo;
        if ($pdo instanceof \PDO) {
            Privacy::assertThreadViewable($pdo, $thread);
        }
        return $thread;
    }

    public static function handlePostCreateHook(array $postData, array $thread = []): array
    {
        $pdo = App::getInstance()->pdo;
        if ($pdo instanceof \PDO && !empty($thread['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
            $stmt->execute([(int)$thread['id']]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                Privacy::assertThreadViewable($pdo, $row);
            }
        }
        return $postData;
    }

    public static function handleThreadUpdate(int $threadId, array $updateData, array $thread = []): void
    {
        $pdo = App::getInstance()->pdo;
        if ($pdo instanceof \PDO && isset($updateData['content'])) {
            self::syncHashtags($pdo, $threadId, (string)$updateData['content']);
        }
    }

    public static function cleanupThreadBeforeDelete(int $threadId, array $thread = []): void
    {
        $pdo = App::getInstance()->pdo;
        if (!$pdo instanceof \PDO) {
            return;
        }
        try {
            $pdo->prepare("
                DELETE FROM social_reactions
                WHERE target_type = 'post'
                  AND target_id IN (SELECT id FROM posts WHERE thread_id = ?)
            ")->execute([$threadId]);
            $pdo->prepare("
                DELETE FROM social_reports
                WHERE target_type = 'post'
                  AND target_id IN (SELECT id FROM posts WHERE thread_id = ?)
            ")->execute([$threadId]);
        } catch (\Throwable $e) {
        }
    }

    public static function cleanupThreadAfterDelete(int $threadId, array $thread = []): void
    {
        $pdo = App::getInstance()->pdo;
        if (!$pdo instanceof \PDO) {
            return;
        }
        $statements = [
            "DELETE FROM social_reactions WHERE target_type = 'thread' AND target_id = ?",
            "DELETE FROM social_threads WHERE thread_id = ?",
            "DELETE FROM social_thread_hashtags WHERE thread_id = ?",
            "DELETE FROM social_bookmarks WHERE thread_id = ?",
            "DELETE FROM social_reposts WHERE thread_id = ?",
            "DELETE FROM social_reports WHERE target_type = 'thread' AND target_id = ?",
            "UPDATE social_profiles SET pinned_thread_id = NULL WHERE pinned_thread_id = ?",
        ];
        foreach ($statements as $sql) {
            try {
                $pdo->prepare($sql)->execute([$threadId]);
            } catch (\Throwable $e) {
            }
        }
    }

    public static function cleanupPostAfterDelete(int $postId, int $threadId = 0): void
    {
        $pdo = App::getInstance()->pdo;
        if (!$pdo instanceof \PDO) {
            return;
        }
        try {
            $pdo->prepare("DELETE FROM social_reactions WHERE target_type = 'post' AND target_id = ?")->execute([$postId]);
            $pdo->prepare("DELETE FROM social_reports WHERE target_type = 'post' AND target_id = ?")->execute([$postId]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Remove the social rows owned by a deleted user. The core fires the
     * user_after_delete hook after the users row is removed; threads/posts are
     * left to the core so this method only touches the social tables.
     */
    public static function cleanupUserAfterDelete(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $pdo = App::getInstance()->pdo;
        if (!$pdo instanceof \PDO) {
            return;
        }

        $statements = [
            ['DELETE FROM social_profiles WHERE user_id = ?', [$userId]],
            ['DELETE FROM social_follows WHERE follower_id = ?', [$userId]],
            ['DELETE FROM social_follows WHERE following_id = ?', [$userId]],
            ['DELETE FROM social_blocks WHERE blocker_id = ?', [$userId]],
            ['DELETE FROM social_blocks WHERE blocked_id = ?', [$userId]],
            ['DELETE FROM social_mutes WHERE user_id = ?', [$userId]],
            ['DELETE FROM social_mutes WHERE muted_id = ?', [$userId]],
            ['DELETE FROM social_reactions WHERE user_id = ?', [$userId]],
            ['DELETE FROM social_bookmarks WHERE user_id = ?', [$userId]],
            ['DELETE FROM social_reposts WHERE user_id = ?', [$userId]],
            ['DELETE FROM social_reports WHERE reporter_id = ?', [$userId]],
        ];

        foreach ($statements as [$sql, $params]) {
            try {
                $pdo->prepare($sql)->execute($params);
            } catch (\Throwable $e) {
            }
        }
    }

    public static function normalizeVisibility(mixed $visibility): string
    {
        $visibility = (string)$visibility;
        return in_array($visibility, ['public', 'followers', 'private'], true) ? $visibility : 'public';
    }
}
