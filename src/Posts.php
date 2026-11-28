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
            'image_url' => '',
            'video_url' => '',
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
                $draft['image_url'] = trim((string)($_POST['image_url'] ?? ''));
                $draft['video_url'] = trim((string)($_POST['video_url'] ?? ''));

                $file = $_FILES['image'] ?? null;
                $hasFile = is_array($file) && !empty($file['name']);
                $hasImageUrl = $draft['image_url'] !== '';
                $hasVideoUrl = $draft['video_url'] !== '';
                $mediaCount = ($hasFile ? 1 : 0) + ($hasImageUrl ? 1 : 0) + ($hasVideoUrl ? 1 : 0);

                if ($hasImageUrl && Util::safeHttpUrl($draft['image_url']) === null) {
                    $error = \pt('socialbored', 'image_url_invalid');
                } elseif ($hasVideoUrl && Util::videoEmbed($draft['video_url']) === null) {
                    $error = \pt('socialbored', 'video_url_invalid');
                } elseif ($mediaCount > 1) {
                    $error = \pt('socialbored', 'compose_media_conflict');
                } elseif (\mb_strlen($draft['content']) < 2 && $mediaCount === 0) {
                    $error = \pt('socialbored', 'compose_empty');
                } elseif (\mb_strlen($draft['content']) > 20000) {
                    $error = \pt('socialbored', 'compose_too_long');
                } else {
                    $uploaded = null;
                    if ($hasFile) {
                        $stored = Util::storeSocialImage($file, $me);
                        if (is_string($stored)) {
                            $error = \pt('socialbored', $stored);
                        } else {
                            $uploaded = $stored;
                        }
                    }

                    if ($error === '') {
                        $threadId = self::createPost($pdo, $pm, $me, $draft);
                        if ($threadId <= 0) {
                            $error = \pt('socialbored', 'compose_failed');
                            $uploaded = self::removeStoredUpload($uploaded);
                        } else {
                            $mediaError = self::attachComposeMedia($pdo, $threadId, $me, $uploaded, $draft['image_url'], $draft['video_url']);
                            if ($mediaError !== '') {
                                self::deleteComposeThread($pdo, $threadId);
                                $uploaded = self::removeStoredUpload($uploaded);
                                $error = \pt('socialbored', $mediaError);
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

    /**
     * @param array{filename:string,original:string,mime:string,size:int}|null $uploaded
     */
    private static function attachComposeMedia(
        \PDO $pdo,
        int $threadId,
        int $userId,
        ?array $uploaded,
        string $imageUrl,
        string $videoUrl
    ): string {
        try {
            if ($uploaded !== null) {
                $pdo->prepare("
                    INSERT INTO uploads (thread_id, post_id, user_id, filename, original_name, size, mime_type, created_at)
                    VALUES (?, NULL, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $threadId,
                    $userId,
                    $uploaded['filename'],
                    $uploaded['original'],
                    $uploaded['size'],
                    $uploaded['mime'],
                    date('Y-m-d H:i:s'),
                ]);
            }
            if ($imageUrl !== '') {
                $pdo->prepare("INSERT INTO social_media (thread_id, kind, url, created_at) VALUES (?, 'image', ?, ?)")
                    ->execute([$threadId, $imageUrl, date('Y-m-d H:i:s')]);
            } elseif ($videoUrl !== '') {
                $pdo->prepare("INSERT INTO social_media (thread_id, kind, url, created_at) VALUES (?, 'video', ?, ?)")
                    ->execute([$threadId, $videoUrl, date('Y-m-d H:i:s')]);
            }
        } catch (\Throwable $e) {
            return 'compose_failed';
        }
        return '';
    }

    private static function removeStoredUpload(?array $uploaded): ?array
    {
        if ($uploaded === null || empty($uploaded['filename'])) {
            return null;
        }
        $path = \dirname(__DIR__, 3) . '/uploads/private/' . basename((string)$uploaded['filename']);
        if (is_file($path)) {
            @unlink($path);
        }
        return null;
    }

    private static function deleteComposeThread(\PDO $pdo, int $threadId): void
    {
        if ($threadId <= 0) {
            return;
        }
        self::cleanupThreadBeforeDelete($threadId, []);
        self::cleanupThreadAfterDelete($threadId, []);
        foreach ([
            'DELETE FROM uploads WHERE thread_id = ?',
            'DELETE FROM social_media WHERE thread_id = ?',
            'DELETE FROM posts WHERE thread_id = ?',
            'DELETE FROM threads WHERE id = ?',
        ] as $sql) {
            try {
                $pdo->prepare($sql)->execute([$threadId]);
            } catch (\Throwable $e) {
            }
        }
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
     * Social post detail page. Registered only in full social mode; forum mode
     * keeps the core thread view with its sidebar and threaded comments.
     */
    public static function handleThread(array $params = []): bool|\Bulletin\Response
    {
        $app = App::getInstance();
        $pdo = $app->pdo;
        $viewerId = Http::userId();
        $threadId = (int)($params['id'] ?? 0);
        if ($threadId <= 0) {
            throw new \Bulletin\NotFoundException('Thread not found');
        }

        $profileVisibility = \bb_profile_visibility_select('u');
        $stmt = $pdo->prepare("
            SELECT t.*, u.username AS author, u.avatar AS author_avatar, u.role AS author_role,
                   {$profileVisibility},
                   COALESCE(st.visibility, t.visibility, 'public') AS visibility,
                   st.kind,
                   COALESCE(t.views, 0) AS view_count,
                   (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id AND p.status = 'visible') AS reply_count,
                   (SELECT COUNT(*) FROM social_reactions r WHERE r.target_type = 'thread' AND r.target_id = t.id) AS reaction_count,
                   (SELECT COUNT(*) FROM social_reposts rp WHERE rp.thread_id = t.id) AS repost_count,
                   (SELECT COUNT(*) FROM social_bookmarks b WHERE b.thread_id = t.id) AS bookmark_count
            FROM threads t
            JOIN users u ON u.id = t.user_id
            LEFT JOIN social_threads st ON st.thread_id = t.id
            WHERE t.id = ? AND t.status IN ('visible', 'sticky', 'locked')
        ");
        $stmt->execute([$threadId]);
        $thread = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$thread) {
            throw new \Bulletin\NotFoundException('Thread not found');
        }
        if (!\can_view_thread((string)($thread['status'] ?? 'visible'))) {
            throw new \Bulletin\ForbiddenException('Thread not available');
        }
        Privacy::assertThreadViewable($pdo, $thread);

        $thread = Feed::attachState($pdo, [$thread])[0] ?? $thread;
        $replies = self::threadReplies($pdo, $threadId, $viewerId);

        $byId = [];
        $childrenMap = [];
        $topLevel = [];
        foreach ($replies as $reply) {
            $byId[(int)$reply['id']] = $reply;
        }
        foreach ($replies as $reply) {
            $id = (int)$reply['id'];
            $parentId = (int)($reply['parent_id'] ?? 0);
            if ($parentId > 0 && isset($byId[$parentId])) {
                $childrenMap[$parentId][] = $id;
            } else {
                $topLevel[] = $id;
            }
        }

        try {
            $pdo->prepare('UPDATE threads SET views = COALESCE(views, 0) + 1 WHERE id = ?')->execute([$threadId]);
        } catch (\Throwable $e) {
        }

        $replyError = (string)($_SESSION['reply_error'] ?? '');
        $replyContent = (string)($_SESSION['reply_content'] ?? '');
        unset($_SESSION['reply_error'], $_SESSION['reply_content']);

        $loggedIn = Http::loggedIn();
        $isLocked = ($thread['status'] ?? '') === 'locked';
        $canReply = $loggedIn && !$isLocked;

        $isWatching = false;
        if ($viewerId > 0) {
            try {
                $watch = $pdo->prepare('SELECT 1 FROM thread_watchers WHERE thread_id = ? AND user_id = ?');
                $watch->execute([$threadId, $viewerId]);
                $isWatching = (bool)$watch->fetchColumn();
            } catch (\Throwable $e) {
            }
        }

        include dirname(__DIR__) . '/views/thread.php';
        return true;
    }

    /**
     * @return array<int,array>
     */
    private static function threadReplies(\PDO $pdo, int $threadId, int $viewerId): array
    {
        $stmt = $pdo->prepare("
            SELECT p.*, u.username AS author, u.avatar AS author_avatar, u.role AS author_role,
                   (SELECT COUNT(*) FROM social_reactions r
                    WHERE r.target_type = 'post' AND r.target_id = p.id) AS reaction_count,
                   EXISTS(SELECT 1 FROM social_reactions r2
                    WHERE r2.target_type = 'post' AND r2.target_id = p.id AND r2.user_id = ?) AS liked
            FROM posts p
            JOIN users u ON u.id = p.user_id
            WHERE p.thread_id = ? AND p.status = 'visible'
            ORDER BY p.created_at ASC, p.id ASC
        ");
        $stmt->execute([$viewerId, $threadId]);
        $replies = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($replies === []) {
            return [];
        }

        $ids = array_map(static fn(array $row): int => (int)$row['id'], $replies);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $uploads = $pdo->prepare("
                SELECT * FROM uploads
                WHERE post_id IN ({$placeholders})
                ORDER BY created_at ASC, id ASC
            ");
            $uploads->execute($ids);
            $byPost = [];
            foreach ($uploads->fetchAll(\PDO::FETCH_ASSOC) as $upload) {
                $byPost[(int)$upload['post_id']][] = $upload;
            }
            foreach ($replies as &$reply) {
                $reply['attachments'] = $byPost[(int)$reply['id']] ?? [];
            }
            unset($reply);
        } catch (\Throwable $e) {
        }

        return $replies;
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
