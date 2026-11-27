<?php
/**
 * Plugin Name: socialbored
 * Author: mlzog
 * Description: Social network mode for bulletinbored: feed, follow, reactions and profiles.
 * Version: 1.0.0
 * License: BSD Zero Clause License
 */

if (!defined('SOCIALBORED_ROOT')) {
    define('SOCIALBORED_ROOT', __DIR__);
}

require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/Http.php';
require_once __DIR__ . '/src/Util.php';
require_once __DIR__ . '/src/View.php';
require_once __DIR__ . '/src/Privacy.php';
require_once __DIR__ . '/src/Notifications.php';
require_once __DIR__ . '/src/SocialGraph.php';
require_once __DIR__ . '/src/Reactions.php';
require_once __DIR__ . '/src/Reposts.php';
require_once __DIR__ . '/src/Bookmarks.php';
require_once __DIR__ . '/src/Media.php';
require_once __DIR__ . '/src/ClassicForum.php';
require_once __DIR__ . '/src/Feed.php';
require_once __DIR__ . '/src/Posts.php';
require_once __DIR__ . '/src/Profiles.php';
require_once __DIR__ . '/src/Reports.php';
require_once __DIR__ . '/src/Admin.php';

if (!function_exists('socialbored_init')) {
    function socialbored_init(): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        $app = App::getInstance();
        $pm = $app->pluginManager;
        $pdo = $app->pdo;

        if ($pm === null || !$pdo instanceof PDO) {
            return;
        }

        try {
            \SocialBored\Schema::ensure($pdo);
        } catch (\Throwable $e) {
            error_log('socialbored: schema setup failed: ' . $e->getMessage());
            return;
        }

        $mode = \SocialBored\Settings::mode($pm);
        $socialUi = in_array($mode, ['social', 'both'], true);

        socialbored_register_hooks($pm);
        socialbored_register_routes($pm, $mode, $socialUi);
    }
}

if (!function_exists('socialbored_register_hooks')) {
    function socialbored_register_hooks(\PluginManager $pm): void
    {
        $pm->addHook('frontend_before_render', function () use ($pm) {
            socialbored_head_assets($pm);
        });

        $pm->addHook('footer_before_render', function () use ($pm) {
            socialbored_footer_assets($pm);
        });

        $pm->addHook('navbar_icons', function () use ($pm) {
            socialbored_navbar_icons($pm);
        }, 10);

        $pm->addHook('mobile_tabbar_icons', function () use ($pm) {
            socialbored_mobile_icons($pm);
        }, 10);

        $pm->addHook('admin_sidebar_items', function () use ($pm) {
            socialbored_admin_sidebar($pm);
        });

        $pm->addHook('thread_before_view', function (array $thread): array {
            return \SocialBored\Posts::handleThreadHook($thread);
        }, 5);

        $pm->addHook('post_before_create', function (array $postData, array $thread = []): array {
            return \SocialBored\Posts::handlePostCreateHook($postData, $thread);
        }, 5);

        $pm->addHook('thread_after_update', function (int $threadId, array $updateData, array $thread = []): void {
            \SocialBored\Posts::handleThreadUpdate($threadId, $updateData, $thread);
        });

        $pm->addHook('thread_after_create', function (int $threadId, array $threadData = []): void {
            $pdo = App::getInstance()->pdo;
            if ($pdo instanceof PDO) {
                \SocialBored\SocialGraph::insertIgnore($pdo, 'social_threads', [
                    'thread_id' => $threadId,
                    'visibility' => 'public',
                    'kind' => 'post',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                \SocialBored\Posts::syncHashtags($pdo, $threadId, (string)($threadData['content'] ?? ''));
            }
        });

        $pm->addHook('thread_before_delete', function (int $threadId, array $thread = []): void {
            \SocialBored\Posts::cleanupThreadBeforeDelete($threadId, $thread);
        });

        $pm->addHook('thread_after_delete', function (int $threadId, array $thread = []): void {
            \SocialBored\Posts::cleanupThreadAfterDelete($threadId, $thread);
        });

        $pm->addHook('post_after_delete', function (int $postId, int $threadId = 0): void {
            \SocialBored\Posts::cleanupPostAfterDelete($postId, $threadId);
        });

        // Core visibility primitives. The core owns public/private and asks the
        // plugin only for the follow graph, so disabling the plugin downgrades
        // followers-only content to hidden instead of making it public.
        $pm->addHook('thread_followers_visibility_sql', function ($value, array $context) {
            $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string)($context['alias'] ?? 't')) ?: 't';
            $viewerId = (int)($context['viewer_id'] ?? 0);
            if ($viewerId <= 0) {
                return null;
            }
            $sub = 'sbf_' . $alias;
            return [
                'sql' => "EXISTS (
                    SELECT 1 FROM social_follows {$sub}
                    WHERE {$sub}.follower_id = ?
                      AND {$sub}.following_id = {$alias}.user_id
                )",
                'params' => [$viewerId],
            ];
        }, 5);

        $pm->addHook('thread_listing_audience_sql', function ($value, array $context) {
            $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string)($context['alias'] ?? 't')) ?: 't';
            $viewerId = (int)($context['viewer_id'] ?? 0);
            if ($viewerId <= 0) {
                return null;
            }
            return [
                'sql' => "NOT EXISTS (
                    SELECT 1 FROM social_blocks sb
                    WHERE (sb.blocker_id = ? AND sb.blocked_id = {$alias}.user_id)
                       OR (sb.blocker_id = {$alias}.user_id AND sb.blocked_id = ?)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM social_mutes sm
                    WHERE sm.user_id = ? AND sm.muted_id = {$alias}.user_id
                )",
                'params' => [$viewerId, $viewerId, $viewerId],
            ];
        }, 5);

        $pm->addHook('thread_view_decision', function (array $thread, int $viewerId, string $visibility): ?bool {
            if ($viewerId <= 0) {
                return null;
            }
            $pdo = App::getInstance()->pdo;
            if (!$pdo instanceof PDO) {
                return $visibility === 'followers' ? false : null;
            }
            $authorId = (int)($thread['user_id'] ?? 0);
            if ($authorId > 0 && \SocialBored\Privacy::isBlockedBetween($pdo, $viewerId, $authorId)) {
                return false;
            }
            if ($visibility !== 'followers') {
                return null;
            }
            return $authorId > 0 && \SocialBored\Privacy::isFollowing($pdo, $viewerId, $authorId);
        });

        $pm->addHook('profile_view_decision', function (array $user, int $viewerId, string $privacy): ?bool {
            if ($privacy !== 'followers' || $viewerId <= 0) {
                return null;
            }
            $pdo = App::getInstance()->pdo;
            if (!$pdo instanceof PDO) {
                return false;
            }
            $targetId = (int)($user['id'] ?? 0);
            return $targetId > 0 && \SocialBored\Privacy::isFollowing($pdo, $viewerId, $targetId);
        });

        $pm->addHook('user_after_delete', function (int $userId): void {
            \SocialBored\Posts::cleanupUserAfterDelete($userId);
        });
    }
}

if (!function_exists('socialbored_register_routes')) {
    function socialbored_register_routes(\PluginManager $pm, string $mode, bool $socialUi): void
    {
        $pm->registerRoute('GET', '/social', function (array $params = []) {
            return \SocialBored\Feed::handle($params);
        });
        $pm->registerRoute('GET', '/social/state', function (array $params = []) {
            return \SocialBored\Feed::handleState($params);
        });
        if ($mode === 'social') {
            // Full social mode gets a dedicated post page without the forum
            // sidebar. Forum/both mode keep the core thread view.
            $pm->registerRoute('GET', '/thread/{id:\d+}', function (array $params = []) {
                return \SocialBored\Posts::handleThread($params);
            });
            $pm->registerRoute('GET', '/thread/{id:\d+}-{slug}', function (array $params = []) {
                return \SocialBored\Posts::handleThread($params);
            });
        }
        $pm->registerRoute('GET', '/compose', function (array $params = []) {
            return \SocialBored\Posts::handleCompose('GET');
        });
        $pm->registerRoute('POST', '/compose', function (array $params = []) {
            return \SocialBored\Posts::handleCompose('POST');
        });
        $features = \SocialBored\Settings::featuresFor($pm);
        if ($features['hashtags']) {
            $pm->registerRoute('GET', '/hashtag/{tag}', function (array $params = []) {
                return \SocialBored\Feed::handleHashtag($params);
            });
        }
        // Account settings and social settings live on one page. Core links to
        // /edit-profile land here while the plugin is enabled; if it is
        // disabled, the core page is untouched.
        $pm->registerRoute('GET', '/edit-profile', function (array $params = []) {
            return \SocialBored\Http::redirect('settings/social');
        });
        $pm->registerRoute('GET', '/settings/social', function (array $params = []) {
            return \SocialBored\Profiles::handleSettings('GET');
        });
        $pm->registerRoute('POST', '/settings/social', function (array $params = []) {
            return \SocialBored\Profiles::handleSettings('POST');
        });

        $pm->registerRoute('POST', '/follow', function (array $params = []) {
            return \SocialBored\SocialGraph::handleFollow($params);
        });
        $pm->registerRoute('POST', '/unfollow', function (array $params = []) {
            return \SocialBored\SocialGraph::handleUnfollow($params);
        });
        $pm->registerRoute('POST', '/block', function (array $params = []) {
            return \SocialBored\SocialGraph::handleBlock($params);
        });
        $pm->registerRoute('POST', '/unblock', function (array $params = []) {
            return \SocialBored\SocialGraph::handleUnblock($params);
        });
        $pm->registerRoute('POST', '/mute', function (array $params = []) {
            return \SocialBored\SocialGraph::handleMute($params);
        });
        $pm->registerRoute('POST', '/unmute', function (array $params = []) {
            return \SocialBored\SocialGraph::handleUnmute($params);
        });
        if ($features['reactions']) {
            $pm->registerRoute('POST', '/react', function (array $params = []) {
                return \SocialBored\Reactions::handleToggle($params);
            });
        }
        if ($features['reposts']) {
            $pm->registerRoute('POST', '/repost', function (array $params = []) {
                return \SocialBored\Reposts::handleToggle($params);
            });
        }
        if ($features['bookmarks']) {
            $pm->registerRoute('POST', '/bookmark', function (array $params = []) {
                return \SocialBored\Bookmarks::handleToggle($params);
            });
        }
        $pm->registerRoute('POST', '/social/visibility', function (array $params = []) {
            return \SocialBored\Posts::handleVisibility($params);
        });
        $pm->registerRoute('POST', '/report', function (array $params = []) {
            return \SocialBored\Reports::handleCreate($params);
        });

        // Downloads are owned by the core now: its handler enforces the same
        // thread + profile visibility, so this plugin no longer shadows it.

        $pm->registerRoute('GET', '/admin/social', function (array $params = []) {
            return \SocialBored\Admin::handle('GET');
        });
        $pm->registerRoute('POST', '/admin/social', function (array $params = []) {
            return \SocialBored\Admin::handle('POST');
        });
        $pm->registerRoute('GET', '/admin/social/reports', function (array $params = []) {
            return \SocialBored\Admin::handleReports('GET');
        });
        $pm->registerRoute('POST', '/admin/social/reports', function (array $params = []) {
            return \SocialBored\Admin::handleReports('POST');
        });

        // The social profile is the privacy-aware profile in every mode. The
        // core profile lists the user's threads without social visibility.
        $pm->registerRoute('GET', '/u/{user}', function (array $params = []) {
            return \SocialBored\Profiles::handle($params);
        });
        $pm->registerRoute('GET', '/u/{user}/followers', function (array $params = []) {
            return \SocialBored\Profiles::handleFollowers($params);
        });
        $pm->registerRoute('GET', '/u/{user}/following', function (array $params = []) {
            return \SocialBored\Profiles::handleFollowing($params);
        });

        if ($socialUi) {
            $pm->registerRoute('GET', '/search', function (array $params = []) {
                return \SocialBored\Feed::handleSearch($params);
            });
            $pm->registerRoute('GET', '/forum', function (array $params = []) {
                return \SocialBored\Feed::handleForum(['_base' => 'forum']);
            });
        } else {
            $pm->registerRoute('GET', '/search', function (array $params = []) {
                return \SocialBored\Feed::handleForumSearch($params);
            });
        }

        if ($mode === 'social') {
            $pm->registerRoute('GET', '/', function (array $params = []) {
                return \SocialBored\Feed::handle($params);
            });
        } else {
            $pm->registerRoute('GET', '/', function (array $params = []) {
                return \SocialBored\Feed::handleForum(['_base' => 'home']);
            });
        }
    }
}

if (!function_exists('socialbored_head_assets')) {
    function socialbored_head_assets(\PluginManager $pm): void
    {
        $nonce = function_exists('csp_nonce') ? csp_nonce() : (App::getInstance()->cspNonce ?? '');

        // In full social mode the core mobile actions are redundant even on
        // /forum, where the social stylesheet is not loaded. Emit the rule
        // inline so it applies everywhere without depending on social-mode.
        if (\SocialBored\Settings::mode($pm) === 'social') {
            echo '<style nonce="' . htmlspecialchars((string)$nonce, ENT_QUOTES, 'UTF-8') . '">'
                . '@media (max-width: 991.98px){'
                . 'a[href*="/new-thread"],a[href*="/new_thread"],#mobileMenuToggle{display:none!important;}'
                . '}'
                . '</style>' . "\n";
        }

        if (!socialbored_is_social_request($pm)) {
            return;
        }

        $css = socialbored_asset_url('assets/social.css');
        echo '<link href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet">' . "\n";

        echo '<script nonce="' . htmlspecialchars((string)$nonce, ENT_QUOTES, 'UTF-8') . '">'
            . 'document.documentElement.classList.add("social-mode");'
            . '</script>' . "\n";
    }
}

if (!function_exists('socialbored_footer_assets')) {
    function socialbored_footer_assets(\PluginManager $pm): void
    {
        if (!socialbored_is_social_request($pm)) {
            return;
        }

        $boot = [
            'csrf' => function_exists('generate_csrf_token') ? generate_csrf_token() : '',
            'mode' => \SocialBored\Settings::mode($pm),
            'userId' => (int)($_SESSION['user_id'] ?? 0),
            'loggedIn' => function_exists('is_logged_in') && is_logged_in(),
            'endpoints' => [
                'react' => \SocialBored\Http::url('react'),
                'bookmark' => \SocialBored\Http::url('bookmark'),
                'repost' => \SocialBored\Http::url('repost'),
                'follow' => \SocialBored\Http::url('follow'),
                'unfollow' => \SocialBored\Http::url('unfollow'),
                'state' => \SocialBored\Http::url('social/state'),
                'compose' => \SocialBored\Http::url('compose'),
            ],
            'features' => \SocialBored\Settings::features($pm),
            'labels' => [
                'like' => pt('socialbored', 'action_like'),
                'liked' => pt('socialbored', 'action_liked'),
                'bookmark' => pt('socialbored', 'action_bookmark'),
                'bookmarked' => pt('socialbored', 'action_bookmarked'),
                'repost' => pt('socialbored', 'action_repost'),
                'reposted' => pt('socialbored', 'action_reposted'),
                'reply' => pt('socialbored', 'action_reply'),
                'follow' => pt('socialbored', 'action_follow'),
                'following' => pt('socialbored', 'action_following'),
                'unfollow' => pt('socialbored', 'action_unfollow'),
                'report' => pt('socialbored', 'action_report'),
                'error' => pt('socialbored', 'action_error'),
                'copied' => pt('socialbored', 'copy_link'),
                'linkCopied' => pt('socialbored', 'link_copied'),
            ],
        ];

        $nonce = function_exists('csp_nonce') ? csp_nonce() : (App::getInstance()->cspNonce ?? '');
        echo '<div id="socialbored-boot" style="display:none"'
            . ' data-boot="' . htmlspecialchars(json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '"></div>' . "\n";
        echo '<script src="' . htmlspecialchars(socialbored_asset_url('assets/social.js'), ENT_QUOTES, 'UTF-8') . '" nonce="' . htmlspecialchars((string)$nonce, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    }
}

if (!function_exists('socialbored_navbar_icons')) {
    function socialbored_navbar_icons(\PluginManager $pm): void
    {
        $mode = \SocialBored\Settings::mode($pm);
        if (!\SocialBored\Settings::bool($pm, 'show_nav')) {
            return;
        }

        if ($mode === 'forum') {
            if (socialbored_is_social_request($pm)) {
                echo '<li class="nav-item"><a class="nav-link nav-icon" href="' . htmlspecialchars(\SocialBored\Http::url('social'), ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars(pt('socialbored', 'feed'), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-house"></i></a></li>';
                echo '<li class="nav-item"><a class="nav-link nav-icon" href="' . htmlspecialchars(\SocialBored\Http::url('compose'), ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars(pt('socialbored', 'compose'), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-pen-to-square"></i></a></li>';
            }
            return;
        }

        if ($mode === 'social') {
            if (!socialbored_is_social_request($pm)) {
                return;
            }
            echo '<li class="nav-item"><a class="nav-link nav-icon" href="' . htmlspecialchars(\SocialBored\Http::url('compose'), ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars(pt('socialbored', 'compose'), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-pen-to-square"></i></a></li>';
            echo '<li class="nav-item"><a class="nav-link nav-icon" href="' . htmlspecialchars(\SocialBored\Http::url('social'), ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars(pt('socialbored', 'feed'), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-house"></i></a></li>';
            return;
        }

        echo '<li class="nav-item"><a class="nav-link nav-icon" href="' . htmlspecialchars(\SocialBored\Http::url('social'), ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars(pt('socialbored', 'feed'), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-users"></i></a></li>';
    }
}

if (!function_exists('socialbored_mobile_icons')) {
    function socialbored_mobile_icons(\PluginManager $pm): void
    {
        $mode = \SocialBored\Settings::mode($pm);
        if (!\SocialBored\Settings::bool($pm, 'show_nav')) {
            return;
        }

        if (socialbored_is_social_request($pm)) {
            if ($mode === 'forum' || $mode === 'both') {
                echo '<a href="' . htmlspecialchars(\SocialBored\Http::url('social'), ENT_QUOTES, 'UTF-8') . '" class="mobile-tab" title="' . htmlspecialchars(pt('socialbored', 'feed'), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-house"></i></a>';
            }
            echo '<a href="' . htmlspecialchars(\SocialBored\Http::url('compose'), ENT_QUOTES, 'UTF-8') . '" class="mobile-tab" title="' . htmlspecialchars(pt('socialbored', 'compose'), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-pen-to-square"></i></a>';
            return;
        }

        if ($mode === 'both') {
            echo '<a href="' . htmlspecialchars(\SocialBored\Http::url('social'), ENT_QUOTES, 'UTF-8') . '" class="mobile-tab" title="' . htmlspecialchars(pt('socialbored', 'feed'), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-house"></i></a>';
        }
    }
}

if (!function_exists('socialbored_admin_sidebar')) {
    function socialbored_admin_sidebar(\PluginManager $pm): void
    {
        $url = \SocialBored\Http::url('admin/social');
        $reports = \SocialBored\Http::url('admin/social/reports');
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $active = str_contains($path, '/admin/social') ? 'active' : '';

        echo '<li><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="' . $active . '">'
            . '<i class="fas fa-users-rectangle"></i><span>' . htmlspecialchars(pt('socialbored', 'social'), ENT_QUOTES, 'UTF-8') . '</span></a></li>';
        echo '<li><a href="' . htmlspecialchars($reports, ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="fas fa-flag"></i><span>' . htmlspecialchars(pt('socialbored', 'reports'), ENT_QUOTES, 'UTF-8') . '</span></a></li>';
    }
}

if (!function_exists('socialbored_asset_url')) {
    function socialbored_asset_url(string $relative): string
    {
        $relative = ltrim($relative, '/');
        $path = __DIR__ . '/' . $relative;
        $version = is_file($path) ? (string)filemtime($path) : '1';
        return rtrim((string)base_url(), '/') . '/plugins/socialbored/' . $relative . '?v=' . rawurlencode($version);
    }
}

if (!function_exists('socialbored_is_social_request')) {
    function socialbored_is_social_request(\PluginManager $pm): bool
    {
        $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/');
        $base = (string)(parse_url((string)base_url(), PHP_URL_PATH) ?: '');
        $base = trim($base, '/');
        if ($base !== '') {
            if ($path === $base) {
                $path = '/';
            } elseif (str_starts_with($path, $base . '/')) {
                $path = substr($path, strlen($base) + 1);
            }
        }
        $path = '/' . ltrim($path, '/');

        if (str_starts_with($path, '/admin')) {
            return false;
        }

        $mode = \SocialBored\Settings::mode($pm);
        if ($mode === 'social') {
            return !str_starts_with($path, '/forum');
        }

        if (str_starts_with($path, '/social')
            || str_starts_with($path, '/compose')
            || str_starts_with($path, '/hashtag')
            || str_starts_with($path, '/settings/social')
            || str_starts_with($path, '/u/')) {
            return true;
        }

        if ($mode === 'both' && (
            str_starts_with($path, '/search')
            || str_starts_with($path, '/thread/')
        )) {
            return true;
        }

        return false;
    }
}

if (!function_exists('socialbored_on_install')) {
    function socialbored_on_install(): void
    {
        $pdo = App::getInstance()->pdo;
        if ($pdo instanceof PDO) {
            \SocialBored\Schema::ensure($pdo);
        }
    }
}

if (!function_exists('socialbored_on_update')) {
    function socialbored_on_update(): void
    {
        $pdo = App::getInstance()->pdo;
        if ($pdo instanceof PDO) {
            \SocialBored\Schema::ensure($pdo);
        }
    }
}

if (!function_exists('socialbored_on_uninstall')) {
    function socialbored_on_uninstall(): void
    {
        // Social data is intentionally preserved on uninstall. The admin page
        // offers an explicit "delete social data" action instead.
    }
}

if (!function_exists('socialbored_migration_rollback')) {
    function socialbored_migration_rollback(): void
    {
        // No destructive rollback: uninstalling must never drop user content
        // without an explicit administrator decision.
    }
}
