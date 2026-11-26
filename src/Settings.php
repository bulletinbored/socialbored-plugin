<?php

namespace SocialBored;

/**
 * Plugin settings are stored by the core PluginManager in data/plugins.json.
 */
class Settings
{
    public const DEFAULTS = [
        'mode' => 'forum',
        'feed_default' => 'discover',
        'default_visibility' => 'public',
        'include_forum_threads' => true,
        'allow_public_feed' => true,
        'social_email_notifications' => false,
        'show_nav' => true,
        'enable_reactions' => true,
        'enable_reposts' => true,
        'enable_bookmarks' => true,
        'enable_hashtags' => true,
        'enable_following_feed' => true,
        'site_label' => 'Social',
    ];

    public static function get(\PluginManager $pm, string $key, mixed $default = null): mixed
    {
        $fallback = $default ?? (self::DEFAULTS[$key] ?? null);
        return $pm->getSetting('socialbored', $key, $fallback);
    }

    public static function set(\PluginManager $pm, string $key, mixed $value): void
    {
        $pm->setSetting('socialbored', $key, $value);
    }

    public static function all(\PluginManager $pm): array
    {
        $all = [];
        foreach (self::DEFAULTS as $key => $default) {
            $all[$key] = self::get($pm, $key, $default);
        }
        return $all;
    }

    public static function mode(\PluginManager $pm): string
    {
        $mode = (string)self::get($pm, 'mode', 'forum');
        return in_array($mode, ['forum', 'social', 'both'], true) ? $mode : 'forum';
    }

    public static function isSocial(\PluginManager $pm): bool
    {
        return self::mode($pm) === 'social';
    }

    public static function bool(\PluginManager $pm, string $key): bool
    {
        return (bool)self::get($pm, $key, self::DEFAULTS[$key] ?? false);
    }

    public static function defaultFeed(\PluginManager $pm): string
    {
        $feed = (string)self::get($pm, 'feed_default', 'discover');
        return in_array($feed, ['following', 'discover', 'latest'], true) ? $feed : 'discover';
    }

    public static function defaultVisibility(\PluginManager $pm): string
    {
        $visibility = (string)self::get($pm, 'default_visibility', 'public');
        return in_array($visibility, ['public', 'followers', 'private'], true) ? $visibility : 'public';
    }

    /**
     * @return array{reactions:bool,reposts:bool,bookmarks:bool,hashtags:bool,following_feed:bool}
     */
    public static function features(\PluginManager $pm): array
    {
        return [
            'reactions' => self::bool($pm, 'enable_reactions'),
            'reposts' => self::bool($pm, 'enable_reposts'),
            'bookmarks' => self::bool($pm, 'enable_bookmarks'),
            'hashtags' => self::bool($pm, 'enable_hashtags'),
            'following_feed' => self::bool($pm, 'enable_following_feed'),
        ];
    }

    /**
     * @return array{reactions:bool,reposts:bool,bookmarks:bool,hashtags:bool,following_feed:bool}
     */
    public static function featuresFor(?\PluginManager $pm): array
    {
        if (!$pm instanceof \PluginManager) {
            return [
                'reactions' => true,
                'reposts' => true,
                'bookmarks' => true,
                'hashtags' => true,
                'following_feed' => true,
            ];
        }
        return self::features($pm);
    }
}
