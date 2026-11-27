<?php

namespace SocialBored;

use App;

class Privacy
{
    public static function threadMeta(\PDO $pdo, int $threadId): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM social_threads WHERE thread_id = ?");
            $stmt->execute([$threadId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function canViewThread(\PDO $pdo, array $thread, ?int $viewerId = null): bool
    {
        $viewerId = $viewerId ?? Http::userId();
        $authorId = (int)($thread['user_id'] ?? 0);

        if ($authorId > 0 && $viewerId === $authorId) {
            return true;
        }
        if (self::isPrivileged($viewerId)) {
            return true;
        }
        if ($viewerId > 0 && $authorId > 0 && self::isBlockedBetween($pdo, $viewerId, $authorId)) {
            return false;
        }

        $meta = self::threadMeta($pdo, (int)($thread['id'] ?? 0));
        // The core column is authoritative; the social column is kept for
        // backward compatibility, so combine both and take the stricter value.
        $visibility = self::mostRestrictiveVisibility(
            (string)($thread['visibility'] ?? 'public'),
            (string)($meta['visibility'] ?? 'public')
        );
        // The social profile privacy governs social posts (no category). Classic
        // forum threads keep their own forum visibility.
        $profilePrivacy = empty($thread['category_id'])
            ? self::mostRestrictiveVisibility(
                (string)($thread['author_profile_visibility'] ?? 'public'),
                self::profilePrivacy($pdo, $authorId)
            )
            : 'public';

        if ($viewerId <= 0) {
            return $profilePrivacy === 'public' && $visibility === 'public';
        }
        if ($profilePrivacy === 'private' || $visibility === 'private') {
            return false;
        }
        if ($profilePrivacy === 'followers' || $visibility === 'followers') {
            return self::isFollowing($pdo, $viewerId, $authorId);
        }

        return true;
    }

    public static function assertThreadViewable(\PDO $pdo, array $thread): void
    {
        if (!self::canViewThread($pdo, $thread)) {
            throw new \Bulletin\ForbiddenException('This post is not available');
        }
    }

    public static function profilePrivacy(\PDO $pdo, int $userId): string
    {
        if ($userId <= 0) {
            return 'public';
        }

        $core = 'public';
        try {
            $stmt = $pdo->prepare("SELECT profile_visibility FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $value = (string)($stmt->fetchColumn() ?: 'public');
            $core = in_array($value, ['public', 'followers', 'private'], true) ? $value : 'public';
        } catch (\Throwable $e) {
        }

        $social = 'public';
        try {
            $stmt = $pdo->prepare("SELECT privacy FROM social_profiles WHERE user_id = ?");
            $stmt->execute([$userId]);
            $value = (string)($stmt->fetchColumn() ?: 'public');
            $social = in_array($value, ['public', 'followers', 'private'], true) ? $value : 'public';
        } catch (\Throwable $e) {
        }

        return self::mostRestrictiveVisibility($core, $social);
    }

    private static function mostRestrictiveVisibility(string $a, string $b): string
    {
        $rank = static fn(string $v): int => match ($v) {
            'private' => 2,
            'followers' => 1,
            default => 0,
        };
        return $rank($a) >= $rank($b) ? $a : $b;
    }

    public static function canViewProfile(\PDO $pdo, array $user, ?int $viewerId = null): bool
    {
        $viewerId = $viewerId ?? Http::userId();
        $userId = (int)($user['id'] ?? 0);

        if ($viewerId > 0 && $viewerId === $userId) {
            return true;
        }
        if (self::isPrivileged($viewerId)) {
            return true;
        }
        if ($viewerId > 0 && $userId > 0 && self::isBlockedBetween($pdo, $viewerId, $userId)) {
            return false;
        }

        $privacy = self::profilePrivacy($pdo, $userId);

        return match ($privacy) {
            'private' => false,
            'followers' => $viewerId > 0 && self::isFollowing($pdo, $viewerId, $userId),
            default => true,
        };
    }

    public static function isPrivileged(?int $userId = null): bool
    {
        $userId = $userId ?? Http::userId();
        if ($userId <= 0) {
            return false;
        }
        $authz = App::getInstance()->authz;
        if ($authz === null) {
            return false;
        }
        return $authz->can($userId, 'admin.access')
            || $authz->can($userId, 'moderation.manage')
            || $authz->can($userId, 'threads.approve');
    }

    public static function isFollowing(\PDO $pdo, int $followerId, int $followingId): bool
    {
        if ($followerId <= 0 || $followingId <= 0) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM social_follows WHERE follower_id = ? AND following_id = ?");
            $stmt->execute([$followerId, $followingId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function isBlockedBetween(\PDO $pdo, int $a, int $b): bool
    {
        if ($a <= 0 || $b <= 0) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT 1 FROM social_blocks
                WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)
                LIMIT 1
            ");
            $stmt->execute([$a, $b, $b, $a]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function hasBlock(\PDO $pdo, int $blockerId, int $blockedId): bool
    {
        if ($blockerId <= 0 || $blockedId <= 0) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM social_blocks WHERE blocker_id = ? AND blocked_id = ?");
            $stmt->execute([$blockerId, $blockedId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function isMuted(\PDO $pdo, int $userId, int $mutedId): bool
    {
        if ($userId <= 0 || $mutedId <= 0) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM social_mutes WHERE user_id = ? AND muted_id = ?");
            $stmt->execute([$userId, $mutedId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * SQL condition that only leaves visible threads in a feed query.
     *
     * @return array{sql:string, params:array<string,int>}
     */
    public static function threadVisibilityCondition(int $viewerId, string $prefix = 'sv'): array
    {
        $p = ':' . $prefix;

        if ($viewerId <= 0) {
            return [
                'sql' => "(
                    (st.visibility IS NULL OR st.visibility = 'public')
                    AND (t.category_id IS NOT NULL OR sp.privacy IS NULL OR sp.privacy = 'public')
                )",
                'params' => [],
            ];
        }

        return [
            'sql' => "(
                t.user_id = {$p}_author_viewer
                OR (
                    (t.category_id IS NOT NULL OR sp.privacy IS NULL OR sp.privacy = 'public')
                    AND (st.visibility IS NULL OR st.visibility = 'public')
                )
                OR (
                    EXISTS (
                        SELECT 1 FROM social_follows {$prefix}_f
                        WHERE {$prefix}_f.follower_id = {$p}_follower_viewer
                          AND {$prefix}_f.following_id = t.user_id
                    )
                    AND (t.category_id IS NOT NULL OR sp.privacy IS NULL OR sp.privacy IN ('public', 'followers'))
                    AND (st.visibility IS NULL OR st.visibility IN ('public', 'followers'))
                )
            )",
            'params' => [
                $p . '_author_viewer' => $viewerId,
                $p . '_follower_viewer' => $viewerId,
            ],
        ];
    }

    /**
     * SQL condition that excludes blocked users (both directions) and muted users.
     *
     * @return array{sql:string, params:array<string,int>}
     */
    public static function threadAudienceCondition(int $viewerId, string $prefix = 'sa'): array
    {
        if ($viewerId <= 0) {
            return ['sql' => '1=1', 'params' => []];
        }

        $p = ':' . $prefix;
        return [
            'sql' => "(
                NOT EXISTS (
                    SELECT 1 FROM social_blocks {$prefix}_b
                    WHERE ({$prefix}_b.blocker_id = {$p}_blocker AND {$prefix}_b.blocked_id = t.user_id)
                       OR ({$prefix}_b.blocker_id = t.user_id AND {$prefix}_b.blocked_id = {$p}_blocked)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM social_mutes {$prefix}_m
                    WHERE {$prefix}_m.user_id = {$p}_muter AND {$prefix}_m.muted_id = t.user_id
                )
            )",
            'params' => [
                $p . '_blocker' => $viewerId,
                $p . '_blocked' => $viewerId,
                $p . '_muter' => $viewerId,
            ],
        ];
    }
}
