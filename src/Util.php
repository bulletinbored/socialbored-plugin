<?php

namespace SocialBored;

class Util
{
    public static function route(string $path = ''): string
    {
        return Http::url($path);
    }

    public static function threadUrl(array $thread): string
    {
        return \url('thread', [
            'id' => (int)($thread['id'] ?? 0),
            'slug' => \slugify((string)($thread['title'] ?? 'post')),
        ]);
    }

    public static function profileUrl(string $username): string
    {
        return \url('profile', ['user' => $username]);
    }

    public static function hashtagUrl(string $tag): string
    {
        return Http::url('hashtag/' . rawurlencode(ltrim($tag, '#')));
    }

    public static function searchUrl(string $q = '', string $type = 'posts'): string
    {
        $params = [];
        if ($q !== '') {
            $params['q'] = $q;
        }
        if ($type !== '') {
            $params['type'] = $type;
        }
        return Http::url('search') . ($params ? '?' . http_build_query($params) : '');
    }

    public static function visibilityLabel(string $visibility): string
    {
        $key = match ($visibility) {
            'followers' => 'visibility_followers',
            'private' => 'visibility_private',
            default => 'visibility_public',
        };
        if (!function_exists('pt')) {
            return ucfirst($visibility);
        }
        return \pt('socialbored', $key);
    }

    public static function reactionLabel(string $kind = 'like'): string
    {
        $key = 'reaction_' . preg_replace('/[^a-z_]/', '', strtolower($kind));
        if (!function_exists('pt')) {
            return ucfirst($kind);
        }
        return \pt('socialbored', $key);
    }

    public static function timeAgo(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }
        return \time_ago($datetime);
    }

    public static function compact(int|string $n): string
    {
        return \compact_number((int)$n);
    }

    public static function content(?string $content): string
    {
        return \bb_render_content((string)$content);
    }

    public static function excerpt(?string $content, int $len = 180): string
    {
        $text = (string)$content;
        if ($text === '') {
            return '';
        }
        if (function_exists('excerpt_html')) {
            return \excerpt_html($text, $len);
        }
        return \escape(\mb_substr(strip_tags($text), 0, $len));
    }

    public static function avatar(string $username, ?string $avatar, int $size = 44): string
    {
        return \render_avatar($username, (string)$avatar, $size);
    }

    public static function coverUrl(?string $cover): string
    {
        $cover = (string)$cover;
        if ($cover === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $cover)) {
            return $cover;
        }
        return rtrim((string)\base_url(), '/') . '/uploads/social/covers/' . rawurlencode($cover);
    }

    public static function hasCover(?string $cover): bool
    {
        $cover = (string)$cover;
        if ($cover === '') {
            return false;
        }
        if (preg_match('#^https?://#i', $cover)) {
            return true;
        }
        return is_file(\dirname(__DIR__, 3) . '/uploads/social/covers/' . $cover);
    }

    public static function initials(string $username): string
    {
        $username = trim($username);
        return $username === '' ? '?' : \mb_strtoupper(\mb_substr($username, 0, 1));
    }

    public static function currentUser(): array
    {
        return [
            'id' => Http::userId(),
            'username' => (string)($_SESSION['username'] ?? ''),
            'avatar' => (string)($_SESSION['avatar'] ?? ''),
        ];
    }

    public static function isSelf(int $userId): bool
    {
        return Http::userId() > 0 && Http::userId() === $userId;
    }

    public static function truncate(string $text, int $length = 120): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (\mb_strlen($text) <= $length) {
            return $text;
        }
        return rtrim(\mb_substr($text, 0, $length - 1)) . '…';
    }

    public static function firstLine(string $text): string
    {
        $line = strtok($text, "\r\n");
        if ($line === false) {
            $line = $text;
        }
        return self::truncate((string)$line, 120);
    }

    public static function flash(string $key): string
    {
        return Http::pullFlash($key);
    }

    /**
     * @return string[] lower-case hashtags without the leading '#'
     */
    public static function extractHashtags(string $content): array
    {
        if (!preg_match_all('/(?<![\w#])#([\p{L}\p{N}_]{2,50})/u', $content, $matches)) {
            return [];
        }
        $tags = array_map(static fn($tag) => \mb_strtolower((string)$tag), $matches[1] ?? []);
        return array_slice(array_values(array_unique($tags)), 0, 10);
    }
}
