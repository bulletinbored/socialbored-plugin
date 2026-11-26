<?php

namespace SocialBored;

class Reposts
{
    public static function handleToggle(array $params = []): \Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if ($denied = Http::requireCsrf()) {
            return $denied;
        }
        if (!Http::rateLimit('socialbored_repost', 120, 3600)) {
            return Http::wantsJson()
                ? Http::json(['ok' => false, 'error' => 'rate_limited'], 429)
                : Http::back();
        }

        $pdo = \App::getInstance()->pdo;
        $threadId = (int)($_POST['thread_id'] ?? $_POST['target_id'] ?? 0);
        $target = Reactions::loadTarget($pdo, 'thread', $threadId);
        if ($target === null) {
            return Http::wantsJson() ? Http::json(['ok' => false, 'error' => 'not_found'], 404) : Http::back();
        }
        try {
            Privacy::assertThreadViewable($pdo, $target['thread']);
        } catch (\Throwable $e) {
            return Http::wantsJson() ? Http::json(['ok' => false, 'error' => 'forbidden'], 403) : Http::back();
        }

        $me = Http::userId();
        $existing = self::has($pdo, $me, $threadId);
        if ($existing) {
            $pdo->prepare("DELETE FROM social_reposts WHERE user_id = ? AND thread_id = ?")->execute([$me, $threadId]);
        } else {
            SocialGraph::insertIgnore($pdo, 'social_reposts', [
                'user_id' => $me,
                'thread_id' => $threadId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            Notifications::repost($pdo, $target['thread'], $me);
        }

        if (Http::wantsJson()) {
            return Http::json([
                'ok' => true,
                'active' => !$existing,
                'count' => self::countForThread($pdo, $threadId),
                'csrf' => Http::token(),
            ]);
        }
        return Http::back('/');
    }

    public static function has(\PDO $pdo, int $userId, int $threadId): bool
    {
        if ($userId <= 0 || $threadId <= 0) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM social_reposts WHERE user_id = ? AND thread_id = ?");
            $stmt->execute([$userId, $threadId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function countForThread(\PDO $pdo, int $threadId): int
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM social_reposts WHERE thread_id = ?");
            $stmt->execute([$threadId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

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
            $row['repost_count'] = $counts[$id] ?? 0;
            $row['reposted'] = isset($states[$id]);
        }
        unset($row);

        return $threads;
    }

    public static function countsForThreads(\PDO $pdo, array $threadIds): array
    {
        $threadIds = array_values(array_unique(array_filter(array_map('intval', $threadIds))));
        if ($threadIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        try {
            $stmt = $pdo->prepare("SELECT thread_id, COUNT(*) AS total FROM social_reposts WHERE thread_id IN ({$placeholders}) GROUP BY thread_id");
            $stmt->execute($threadIds);
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $out[(int)$row['thread_id']] = (int)$row['total'];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function statesForThreads(\PDO $pdo, array $threadIds, int $viewerId): array
    {
        $threadIds = array_values(array_unique(array_filter(array_map('intval', $threadIds))));
        if ($threadIds === [] || $viewerId <= 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        try {
            $stmt = $pdo->prepare("SELECT thread_id FROM social_reposts WHERE user_id = ? AND thread_id IN ({$placeholders})");
            $stmt->execute(array_merge([$viewerId], $threadIds));
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $out[(int)$id] = true;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
