<?php

namespace SocialBored;

class Notifications
{
    public static function follow(\PDO $pdo, int $targetId, int $actorId): void
    {
        if ($targetId <= 0 || $actorId <= 0 || $targetId === $actorId) {
            return;
        }
        if (Privacy::isBlockedBetween($pdo, $targetId, $actorId)) {
            return;
        }

        $actor = self::username($pdo, $actorId);
        $title = self::label('notif_follow_title', ['user' => $actor]);
        $message = self::label('notif_follow_body', ['user' => $actor]);
        self::create($pdo, $targetId, 'follow', $title, $message, Util::profileUrl($actor));
    }

    public static function like(\PDO $pdo, array $thread, int $actorId): void
    {
        $ownerId = (int)($thread['user_id'] ?? 0);
        if ($ownerId <= 0 || $ownerId === $actorId) {
            return;
        }
        if (Privacy::isBlockedBetween($pdo, $ownerId, $actorId)) {
            return;
        }

        $actor = self::username($pdo, $actorId);
        $title = self::label('notif_like_title', ['user' => $actor]);
        $message = self::label('notif_like_body', [
            'user' => $actor,
            'title' => Util::truncate((string)($thread['title'] ?? ''), 80),
        ]);
        self::create($pdo, $ownerId, 'like', $title, $message, Util::threadUrl($thread));
    }

    public static function repost(\PDO $pdo, array $thread, int $actorId): void
    {
        $ownerId = (int)($thread['user_id'] ?? 0);
        if ($ownerId <= 0 || $ownerId === $actorId) {
            return;
        }
        if (Privacy::isBlockedBetween($pdo, $ownerId, $actorId)) {
            return;
        }

        $actor = self::username($pdo, $actorId);
        $title = self::label('notif_repost_title', ['user' => $actor]);
        $message = self::label('notif_repost_body', [
            'user' => $actor,
            'title' => Util::truncate((string)($thread['title'] ?? ''), 80),
        ]);
        self::create($pdo, $ownerId, 'repost', $title, $message, Util::threadUrl($thread));
    }

    public static function create(\PDO $pdo, int $userId, string $type, string $title, string $message, string $link): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            if (function_exists('create_notification')) {
                \create_notification($pdo, $userId, $type, $title, $message, $link);
            } else {
                $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, is_read) VALUES (?, ?, ?, ?, ?, 0)")
                    ->execute([$userId, $type, $title, $message, $link]);
            }
        } catch (\Throwable $e) {
            return;
        }

        $pm = \App::getInstance()->pluginManager;
        if (!$pm instanceof \PluginManager || !Settings::bool($pm, 'social_email_notifications')) {
            return;
        }

        try {
            $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $email = (string)($stmt->fetchColumn() ?: '');
            if ($email !== '' && function_exists('send_email')) {
                \send_email($email, $title, $message);
            }
        } catch (\Throwable $e) {
        }
    }

    private static function username(\PDO $pdo, int $userId): string
    {
        try {
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return (string)($stmt->fetchColumn() ?: 'Someone');
        } catch (\Throwable $e) {
            return 'Someone';
        }
    }

    private static function label(string $key, array $params = []): string
    {
        if (function_exists('pt')) {
            return \pt('socialbored', $key, $params);
        }

        $fallback = [
            'notif_follow_title' => '{user} started following you',
            'notif_follow_body' => 'You have a new follower: {user}.',
            'notif_like_title' => '{user} liked your post',
            'notif_like_body' => '{user} liked "{title}".',
            'notif_repost_title' => '{user} reposted your post',
            'notif_repost_body' => '{user} reposted "{title}".',
        ];
        $text = $fallback[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }
        return $text;
    }
}
