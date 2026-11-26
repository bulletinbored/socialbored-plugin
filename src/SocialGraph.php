<?php

namespace SocialBored;

class SocialGraph
{
    public static function handleFollow(array $params = []): \Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if ($denied = Http::requireCsrf()) {
            return $denied;
        }
        if (!Http::rateLimit('socialbored_follow', 120, 3600)) {
            return Http::wantsJson()
                ? Http::json(['ok' => false, 'error' => 'rate_limited'], 429)
                : Http::back();
        }

        $pdo = \App::getInstance()->pdo;
        $targetId = self::targetUserId($pdo, $_POST['user_id'] ?? null, $_POST['username'] ?? null);
        $me = Http::userId();

        if ($targetId <= 0 || $targetId === $me) {
            return Http::wantsJson() ? Http::json(['ok' => false, 'error' => 'invalid_user'], 422) : Http::back();
        }
        if (Privacy::isBlockedBetween($pdo, $me, $targetId)) {
            return Http::wantsJson() ? Http::json(['ok' => false, 'error' => 'blocked'], 403) : Http::back();
        }

        $inserted = self::insertIgnore($pdo, 'social_follows', ['follower_id' => $me, 'following_id' => $targetId, 'created_at' => date('Y-m-d H:i:s')]);
        if ($inserted) {
            Notifications::follow($pdo, $targetId, $me);
        }

        if (Http::wantsJson()) {
            return Http::json([
                'ok' => true,
                'following' => true,
                'followers' => self::followerCount($pdo, $targetId),
                'csrf' => Http::token(),
            ]);
        }
        return Http::back(Util::profileUrl((string)($_POST['username'] ?? '')));
    }

    public static function handleUnfollow(array $params = []): \Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if ($denied = Http::requireCsrf()) {
            return $denied;
        }
        if (!Http::rateLimit('socialbored_unfollow', 120, 3600)) {
            return Http::wantsJson()
                ? Http::json(['ok' => false, 'error' => 'rate_limited'], 429)
                : Http::back();
        }

        $pdo = \App::getInstance()->pdo;
        $targetId = self::targetUserId($pdo, $_POST['user_id'] ?? null, $_POST['username'] ?? null);
        $me = Http::userId();

        if ($targetId <= 0 || $targetId === $me) {
            return Http::wantsJson() ? Http::json(['ok' => false, 'error' => 'invalid_user'], 422) : Http::back();
        }

        $pdo->prepare("DELETE FROM social_follows WHERE follower_id = ? AND following_id = ?")
            ->execute([$me, $targetId]);

        if (Http::wantsJson()) {
            return Http::json([
                'ok' => true,
                'following' => false,
                'followers' => self::followerCount($pdo, $targetId),
                'csrf' => Http::token(),
            ]);
        }
        return Http::back(Util::profileUrl((string)($_POST['username'] ?? '')));
    }

    public static function handleBlock(array $params = []): \Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if ($denied = Http::requireCsrf()) {
            return $denied;
        }
        if (!Http::rateLimit('socialbored_block', 60, 3600)) {
            return Http::back();
        }

        $pdo = \App::getInstance()->pdo;
        $targetId = self::targetUserId($pdo, $_POST['user_id'] ?? null, $_POST['username'] ?? null);
        $me = Http::userId();
        if ($targetId <= 0 || $targetId === $me) {
            return Http::back();
        }

        // Blocking removes both follow directions so the graph stays coherent.
        $pdo->prepare("DELETE FROM social_follows WHERE (follower_id = ? AND following_id = ?) OR (follower_id = ? AND following_id = ?)")
            ->execute([$me, $targetId, $targetId, $me]);
        self::insertIgnore($pdo, 'social_blocks', ['blocker_id' => $me, 'blocked_id' => $targetId, 'created_at' => date('Y-m-d H:i:s')]);

        if (Http::wantsJson()) {
            return Http::json(['ok' => true, 'blocked' => true, 'csrf' => Http::token()]);
        }
        return Http::back('/');
    }

    public static function handleUnblock(array $params = []): \Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if ($denied = Http::requireCsrf()) {
            return $denied;
        }

        $pdo = \App::getInstance()->pdo;
        $targetId = self::targetUserId($pdo, $_POST['user_id'] ?? null, $_POST['username'] ?? null);
        $me = Http::userId();
        if ($targetId <= 0) {
            return Http::back();
        }

        $pdo->prepare("DELETE FROM social_blocks WHERE blocker_id = ? AND blocked_id = ?")
            ->execute([$me, $targetId]);

        if (Http::wantsJson()) {
            return Http::json(['ok' => true, 'blocked' => false, 'csrf' => Http::token()]);
        }
        return Http::back(Util::profileUrl((string)($_POST['username'] ?? '')));
    }

    public static function handleMute(array $params = []): \Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if ($denied = Http::requireCsrf()) {
            return $denied;
        }
        if (!Http::rateLimit('socialbored_mute', 120, 3600)) {
            return Http::back();
        }

        $pdo = \App::getInstance()->pdo;
        $targetId = self::targetUserId($pdo, $_POST['user_id'] ?? null, $_POST['username'] ?? null);
        $me = Http::userId();
        if ($targetId <= 0 || $targetId === $me) {
            return Http::back();
        }

        self::insertIgnore($pdo, 'social_mutes', ['user_id' => $me, 'muted_id' => $targetId, 'created_at' => date('Y-m-d H:i:s')]);

        if (Http::wantsJson()) {
            return Http::json(['ok' => true, 'muted' => true, 'csrf' => Http::token()]);
        }
        return Http::back();
    }

    public static function handleUnmute(array $params = []): \Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if ($denied = Http::requireCsrf()) {
            return $denied;
        }

        $pdo = \App::getInstance()->pdo;
        $targetId = self::targetUserId($pdo, $_POST['user_id'] ?? null, $_POST['username'] ?? null);
        if ($targetId <= 0) {
            return Http::back();
        }
        $pdo->prepare("DELETE FROM social_mutes WHERE user_id = ? AND muted_id = ?")
            ->execute([Http::userId(), $targetId]);

        if (Http::wantsJson()) {
            return Http::json(['ok' => true, 'muted' => false, 'csrf' => Http::token()]);
        }
        return Http::back();
    }

    public static function followerCount(\PDO $pdo, int $userId): int
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM social_follows WHERE following_id = ?");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function followingCount(\PDO $pdo, int $userId): int
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM social_follows WHERE follower_id = ?");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array{users:array<int,array>, total:int}
     */
    public static function followers(\PDO $pdo, int $userId, int $viewerId, int $page = 1, int $perPage = 30): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $users = [];
        $total = 0;

        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.avatar, u.role, u.created_at, f.created_at AS followed_at,
                       (SELECT COUNT(*) FROM social_follows f2 WHERE f2.following_id = u.id) AS followers_count,
                       EXISTS(SELECT 1 FROM social_follows f3 WHERE f3.follower_id = :viewer AND f3.following_id = u.id) AS viewer_follows
                FROM social_follows f
                JOIN users u ON u.id = f.follower_id
                WHERE f.following_id = :target
                  AND NOT EXISTS (
                      SELECT 1 FROM social_blocks b
                      WHERE (b.blocker_id = :viewer_blocker AND b.blocked_id = u.id)
                         OR (b.blocker_id = u.id AND b.blocked_id = :viewer_blocked)
                  )
                ORDER BY f.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':viewer', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':viewer_blocker', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':viewer_blocked', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':target', $userId, \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $count = $pdo->prepare("SELECT COUNT(*) FROM social_follows WHERE following_id = ?");
            $count->execute([$userId]);
            $total = (int)$count->fetchColumn();
        } catch (\Throwable $e) {
        }

        return ['users' => $users, 'total' => $total];
    }

    /**
     * @return array{users:array<int,array>, total:int}
     */
    public static function following(\PDO $pdo, int $userId, int $viewerId, int $page = 1, int $perPage = 30): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $users = [];
        $total = 0;

        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.avatar, u.role, u.created_at, f.created_at AS followed_at,
                       (SELECT COUNT(*) FROM social_follows f2 WHERE f2.following_id = u.id) AS followers_count,
                       EXISTS(SELECT 1 FROM social_follows f3 WHERE f3.follower_id = :viewer AND f3.following_id = u.id) AS viewer_follows
                FROM social_follows f
                JOIN users u ON u.id = f.following_id
                WHERE f.follower_id = :target
                  AND NOT EXISTS (
                      SELECT 1 FROM social_blocks b
                      WHERE (b.blocker_id = :viewer_blocker AND b.blocked_id = u.id)
                         OR (b.blocker_id = u.id AND b.blocked_id = :viewer_blocked)
                  )
                ORDER BY f.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':viewer', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':viewer_blocker', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':viewer_blocked', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':target', $userId, \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $count = $pdo->prepare("SELECT COUNT(*) FROM social_follows WHERE follower_id = ?");
            $count->execute([$userId]);
            $total = (int)$count->fetchColumn();
        } catch (\Throwable $e) {
        }

        return ['users' => $users, 'total' => $total];
    }

    /**
     * Suggested accounts for the current viewer.
     *
     * @return array<int,array>
     */
    public static function suggestions(\PDO $pdo, int $viewerId, int $limit = 5): array
    {
        if ($viewerId <= 0) {
            return [];
        }
        $limit = max(1, min(20, $limit));

        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.avatar, u.role,
                       (SELECT COUNT(*) FROM social_follows f2 WHERE f2.following_id = u.id) AS followers_count
                FROM users u
                WHERE u.id <> :viewer_self
                  AND u.status = 'active'
                  AND NOT EXISTS (SELECT 1 FROM social_follows f WHERE f.follower_id = :viewer_follow AND f.following_id = u.id)
                  AND NOT EXISTS (
                      SELECT 1 FROM social_blocks b
                      WHERE (b.blocker_id = :viewer_blocker AND b.blocked_id = u.id)
                         OR (b.blocker_id = u.id AND b.blocked_id = :viewer_blocked)
                  )
                ORDER BY followers_count DESC, u.created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':viewer_self', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':viewer_follow', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':viewer_blocker', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':viewer_blocked', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function targetUserId(\PDO $pdo, mixed $id, mixed $username): int
    {
        $id = (int)$id;
        if ($id > 0) {
            return $id;
        }
        $username = trim((string)$username);
        if ($username === '') {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            return (int)($stmt->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function insertIgnore(\PDO $pdo, string $table, array $row): bool
    {
        $driver = (string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $columns = array_keys($row);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $verb = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $sql = "{$verb} INTO {$table} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($row));
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
