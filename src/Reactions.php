<?php

namespace SocialBored;

class Reactions
{
    public const KINDS = ['like', 'love', 'haha', 'wow', 'sad', 'angry'];

    public static function handleToggle(array $params = []): \Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if ($denied = Http::requireCsrf()) {
            return $denied;
        }
        if (!Http::rateLimit('socialbored_react', 240, 3600)) {
            return Http::wantsJson()
                ? Http::json(['ok' => false, 'error' => 'rate_limited'], 429)
                : Http::back();
        }

        $pdo = \App::getInstance()->pdo;
        $targetType = (string)($_POST['target_type'] ?? 'thread');
        $targetId = (int)($_POST['target_id'] ?? 0);
        $kind = strtolower((string)($_POST['kind'] ?? 'like'));
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'like';
        }

        $target = self::loadTarget($pdo, $targetType, $targetId);
        if ($target === null) {
            return Http::wantsJson() ? Http::json(['ok' => false, 'error' => 'not_found'], 404) : Http::back();
        }
        try {
            Privacy::assertThreadViewable($pdo, $target['thread']);
        } catch (\Throwable $e) {
            return Http::wantsJson() ? Http::json(['ok' => false, 'error' => 'forbidden'], 403) : Http::back();
        }

        $me = Http::userId();
        $existing = self::userKind($pdo, $me, $targetType, $targetId);
        $active = false;

        $pdo->prepare("DELETE FROM social_reactions WHERE user_id = ? AND target_type = ? AND target_id = ?")
            ->execute([$me, $targetType, $targetId]);

        if ($existing !== $kind) {
            $pdo->prepare("INSERT INTO social_reactions (user_id, target_type, target_id, kind, created_at) VALUES (?, ?, ?, ?, ?)")
                ->execute([$me, $targetType, $targetId, $kind, date('Y-m-d H:i:s')]);
            $active = true;
            if ($targetType === 'thread') {
                Notifications::like($pdo, $target['thread'], $me);
            }
        }

        $count = self::countForTarget($pdo, $targetType, $targetId);
        $counts = self::countsByKind($pdo, $targetType, $targetId);

        if (Http::wantsJson()) {
            return Http::json([
                'ok' => true,
                'active' => $active,
                'kind' => $active ? $kind : null,
                'count' => $count,
                'counts' => $counts,
                'csrf' => Http::token(),
            ]);
        }
        return Http::back('/');
    }

    public static function loadTarget(\PDO $pdo, string $targetType, int $targetId): ?array
    {
        if ($targetId <= 0) {
            return null;
        }
        try {
            if ($targetType === 'thread') {
                $stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
                $stmt->execute([$targetId]);
                $thread = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $thread ? ['thread' => $thread, 'post' => null] : null;
            }
            if ($targetType === 'post') {
                $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
                $stmt->execute([$targetId]);
                $post = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$post) {
                    return null;
                }
                $stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
                $stmt->execute([(int)$post['thread_id']]);
                $thread = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $thread ? ['thread' => $thread, 'post' => $post] : null;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    public static function userKind(\PDO $pdo, int $userId, string $targetType, int $targetId): ?string
    {
        try {
            $stmt = $pdo->prepare("SELECT kind FROM social_reactions WHERE user_id = ? AND target_type = ? AND target_id = ? LIMIT 1");
            $stmt->execute([$userId, $targetType, $targetId]);
            $kind = $stmt->fetchColumn();
            return $kind === false ? null : (string)$kind;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function countForTarget(\PDO $pdo, string $targetType, int $targetId): int
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM social_reactions WHERE target_type = ? AND target_id = ?");
            $stmt->execute([$targetType, $targetId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function countsByKind(\PDO $pdo, string $targetType, int $targetId): array
    {
        $counts = array_fill_keys(self::KINDS, 0);
        try {
            $stmt = $pdo->prepare("SELECT kind, COUNT(*) AS total FROM social_reactions WHERE target_type = ? AND target_id = ? GROUP BY kind");
            $stmt->execute([$targetType, $targetId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $counts[(string)$row['kind']] = (int)$row['total'];
            }
        } catch (\Throwable $e) {
        }
        return $counts;
    }

    /**
     * Attach reaction count and viewer state to a list of thread rows.
     *
     * @param array<int,array> $threads
     * @return array<int,array>
     */
    public static function attachToThreads(\PDO $pdo, array $threads, int $viewerId): array
    {
        if ($threads === []) {
            return $threads;
        }
        $ids = array_map(static fn($row) => (int)$row['id'], $threads);
        $counts = self::countsForThreads($pdo, $ids);
        $states = $viewerId > 0 ? self::statesForThreads($pdo, $ids, $viewerId) : [];

        foreach ($threads as &$row) {
            $id = (int)$row['id'];
            $row['reaction_count'] = $counts[$id] ?? 0;
            $row['reaction_kind'] = $states[$id] ?? null;
            $row['liked'] = isset($states[$id]);
        }
        unset($row);

        return $threads;
    }

    /**
     * @param int[] $threadIds
     * @return array<int,int>
     */
    public static function countsForThreads(\PDO $pdo, array $threadIds): array
    {
        $threadIds = array_values(array_unique(array_filter(array_map('intval', $threadIds))));
        if ($threadIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        try {
            $stmt = $pdo->prepare("
                SELECT target_id, COUNT(*) AS total
                FROM social_reactions
                WHERE target_type = 'thread' AND target_id IN ({$placeholders})
                GROUP BY target_id
            ");
            $stmt->execute($threadIds);
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $out[(int)$row['target_id']] = (int)$row['total'];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param int[] $threadIds
     * @return array<int,string>
     */
    public static function statesForThreads(\PDO $pdo, array $threadIds, int $viewerId): array
    {
        $threadIds = array_values(array_unique(array_filter(array_map('intval', $threadIds))));
        if ($threadIds === [] || $viewerId <= 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        $params = array_merge([$viewerId], $threadIds);
        try {
            $stmt = $pdo->prepare("
                SELECT target_id, kind
                FROM social_reactions
                WHERE user_id = ? AND target_type = 'thread' AND target_id IN ({$placeholders})
            ");
            $stmt->execute($params);
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $out[(int)$row['target_id']] = (string)$row['kind'];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function receivedCount(\PDO $pdo, int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM social_reactions r
                JOIN threads t ON r.target_type = 'thread' AND r.target_id = t.id
                WHERE t.user_id = ? AND r.user_id <> ?
            ");
            $stmt->execute([$userId, $userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
