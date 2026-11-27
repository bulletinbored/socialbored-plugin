<?php

namespace SocialBored;

use App;

class Profiles
{
    public static function handle(array $params = []): bool|\Bulletin\Response
    {
        $pdo = App::getInstance()->pdo;
        $viewerId = Http::userId();
        $username = (string)($params['user'] ?? $_GET['user'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $profileUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$profileUser) {
            throw new \Bulletin\NotFoundException('User not found');
        }

        $profile = self::ensureProfile($pdo, (int)$profileUser['id'], (string)$profileUser['username']);
        $canView = Privacy::canViewProfile($pdo, $profileUser, $viewerId);
        $isSelf = $viewerId > 0 && $viewerId === (int)$profileUser['id'];
        $isFollowing = $viewerId > 0 && !$isSelf && Privacy::isFollowing($pdo, $viewerId, (int)$profileUser['id']);
        $viewerBlockedTarget = $viewerId > 0 && Privacy::hasBlock($pdo, $viewerId, (int)$profileUser['id']);
        $targetBlockedViewer = $viewerId > 0 && Privacy::hasBlock($pdo, (int)$profileUser['id'], $viewerId);
        $isBlocked = $viewerBlockedTarget || $targetBlockedViewer;
        $isMuted = $viewerId > 0 && Privacy::isMuted($pdo, $viewerId, (int)$profileUser['id']);
        $stats = self::stats($pdo, (int)$profileUser['id']);

        $pm = App::getInstance()->pluginManager;
        $features = Settings::featuresFor($pm);
        $tab = (string)($_GET['tab'] ?? 'posts');
        if (!in_array($tab, ['posts', 'replies', 'media', 'likes', 'reposts', 'bookmarks'], true)) {
            $tab = 'posts';
        }
        if ($tab === 'bookmarks' && !$isSelf) {
            $tab = 'posts';
        }
        if (($tab === 'likes' && !$features['reactions'])
            || ($tab === 'reposts' && !$features['reposts'])
            || ($tab === 'bookmarks' && !$features['bookmarks'])) {
            $tab = 'posts';
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $items = [];
        $total = 0;
        $pages = 1;
        $isReplyTab = false;

        if ($canView) {
            if ($tab === 'posts') {
                $result = Feed::query(['tab' => 'latest', 'user_id' => (int)$profileUser['id'], 'page' => $page, 'include_forum_threads' => true]);
                $items = Feed::attachState($pdo, $result['threads']);
                $total = $result['total'];
                $pages = $result['pages'];
            } elseif ($tab === 'replies') {
                $result = self::replies($pdo, (int)$profileUser['id'], $viewerId, $page);
                $items = $result['items'];
                $total = $result['total'];
                $pages = $result['pages'];
                $isReplyTab = true;
            } elseif ($tab === 'media') {
                $items = self::media($pdo, (int)$profileUser['id'], $viewerId);
                $total = count($items);
            } elseif ($tab === 'likes') {
                $result = Feed::query(['tab' => 'latest', 'liked_by' => (int)$profileUser['id'], 'page' => $page, 'include_forum_threads' => true]);
                $items = Feed::attachState($pdo, $result['threads']);
                $total = $result['total'];
                $pages = $result['pages'];
            } elseif ($tab === 'reposts') {
                $result = Feed::query(['tab' => 'latest', 'reposted_by' => (int)$profileUser['id'], 'page' => $page, 'include_forum_threads' => true]);
                $items = Feed::attachState($pdo, $result['threads']);
                $total = $result['total'];
                $pages = $result['pages'];
            } elseif ($tab === 'bookmarks') {
                $result = Feed::query(['tab' => 'latest', 'bookmarked_by' => (int)$profileUser['id'], 'page' => $page, 'include_forum_threads' => true]);
                $items = Feed::attachState($pdo, $result['threads']);
                $total = $result['total'];
                $pages = $result['pages'];
            }
        }

        $pinned = null;
        if ($canView && !empty($profile['pinned_thread_id'])) {
            $pinStmt = $pdo->prepare("
                SELECT t.*, u.username AS author, u.avatar AS author_avatar, u.role AS author_role,
                       st.visibility,
                       (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id AND p.status = 'visible') AS reply_count,
                       (SELECT COUNT(*) FROM social_reactions r WHERE r.target_type = 'thread' AND r.target_id = t.id) AS reaction_count,
                       (SELECT COUNT(*) FROM social_reposts rp WHERE rp.thread_id = t.id) AS repost_count,
                       (SELECT COUNT(*) FROM social_bookmarks b WHERE b.thread_id = t.id) AS bookmark_count
                FROM threads t
                JOIN users u ON u.id = t.user_id
                LEFT JOIN social_threads st ON st.thread_id = t.id
                WHERE t.id = ? AND t.status IN ('visible','sticky','locked')
            ");
            $pinStmt->execute([(int)$profile['pinned_thread_id']]);
            $pinned = $pinStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            if ($pinned !== null && !Privacy::canViewThread($pdo, $pinned, $viewerId)) {
                $pinned = null;
            } elseif ($pinned !== null) {
                $pinned = Feed::attachState($pdo, [$pinned])[0] ?? $pinned;
            }
        }

        include dirname(__DIR__) . '/views/profile.php';
        return true;
    }

    public static function handleFollowers(array $params = []): bool|\Bulletin\Response
    {
        return self::peopleList($params, 'followers');
    }

    public static function handleFollowing(array $params = []): bool|\Bulletin\Response
    {
        return self::peopleList($params, 'following');
    }

    private static function peopleList(array $params, string $kind): bool|\Bulletin\Response
    {
        $pdo = App::getInstance()->pdo;
        $viewerId = Http::userId();
        $username = (string)($params['user'] ?? $_GET['user'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $profileUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$profileUser) {
            throw new \Bulletin\NotFoundException('User not found');
        }
        if (!Privacy::canViewProfile($pdo, $profileUser, $viewerId)) {
            return Http::redirect('u/' . rawurlencode((string)$profileUser['username']));
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $profile = self::ensureProfile($pdo, (int)$profileUser['id'], (string)$profileUser['username']);
        $result = $kind === 'followers'
            ? SocialGraph::followers($pdo, (int)$profileUser['id'], $viewerId, $page)
            : SocialGraph::following($pdo, (int)$profileUser['id'], $viewerId, $page);

        $users = $result['users'];
        $total = $result['total'];
        $pages = max(1, (int)ceil($total / 30));
        $heading = $kind === 'followers' ? \pt('socialbored', 'followers') : \pt('socialbored', 'following');
        $empty = $kind === 'followers' ? \pt('socialbored', 'no_followers') : \pt('socialbored', 'no_following');

        include dirname(__DIR__) . '/views/people.php';
        return true;
    }

    public static function handleSettings(string $method): bool|\Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }

        $pdo = App::getInstance()->pdo;
        $me = Http::userId();
        $profile = self::ensureProfile($pdo, $me, (string)($_SESSION['username'] ?? ''));
        $error = '';

        if ($method === 'POST') {
            if ($denied = Http::requireCsrf()) {
                return $denied;
            }
            if (!Http::rateLimit('socialbored_profile', 30, 3600)) {
                $error = \pt('socialbored', 'profile_rate_limited');
            } else {
                $displayName = Util::truncate(trim((string)($_POST['display_name'] ?? '')), 100);
                $bio = trim((string)($_POST['bio'] ?? ''));
                $location = Util::truncate(trim((string)($_POST['location'] ?? '')), 100);
                $website = trim((string)($_POST['website'] ?? ''));
                $privacy = Posts::normalizeVisibility($_POST['privacy'] ?? 'public');

                if ($displayName === '') {
                    $displayName = (string)($_SESSION['username'] ?? '');
                }
                if (\mb_strlen($bio) > 500) {
                    $error = \pt('socialbored', 'bio_too_long');
                } elseif ($website !== '' && !self::validWebsite($website)) {
                    $error = \pt('socialbored', 'website_invalid');
                }

                $cover = (string)($profile['cover'] ?? '');
                if ($error === '' && !empty($_POST['remove_cover'])) {
                    Media::deleteCover($cover);
                    $cover = '';
                }
                if ($error === '' && !empty($_FILES['cover']['name'])) {
                    $stored = Media::storeCover((array)$_FILES['cover'], $me);
                    if ($stored === null) {
                        $error = \pt('socialbored', 'cover_invalid');
                    } else {
                        Media::deleteCover($cover);
                        $cover = $stored;
                    }
                }

                if ($error === '') {
                    $pdo->prepare("
                        UPDATE social_profiles
                        SET display_name = ?, bio = ?, location = ?, website = ?, cover = ?, privacy = ?, updated_at = ?
                        WHERE user_id = ?
                    ")->execute([$displayName, $bio, $location, $website, $cover, $privacy, date('Y-m-d H:i:s'), $me]);

                    // Mirror the profile privacy into the core column so the
                    // core can enforce it when this plugin is disabled.
                    try {
                        $pdo->prepare("UPDATE users SET profile_visibility = ? WHERE id = ?")
                            ->execute([$privacy, $me]);
                    } catch (\Throwable $e) {
                    }

                    Http::flash('settings', \pt('socialbored', 'settings_saved'));
                    return Http::redirect('u/' . rawurlencode((string)($_SESSION['username'] ?? '')));
                }
            }
        }

        $settingsError = $error;
        include dirname(__DIR__) . '/views/settings.php';
        return true;
    }

    public static function ensureProfile(\PDO $pdo, int $userId, ?string $username = null): array
    {
        if ($username === null) {
            try {
                $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $userStmt->execute([$userId]);
                $username = (string)($userStmt->fetchColumn() ?: '');
            } catch (\Throwable $e) {
                $username = '';
            }
        }

        // Adopt the core profile visibility when a social profile is created so
        // a privacy setting made outside this plugin is not silently reset.
        $privacy = 'public';
        try {
            $privacyStmt = $pdo->prepare("SELECT profile_visibility FROM users WHERE id = ?");
            $privacyStmt->execute([$userId]);
            $value = (string)($privacyStmt->fetchColumn() ?: 'public');
            if (in_array($value, ['public', 'followers', 'private'], true)) {
                $privacy = $value;
            }
        } catch (\Throwable $e) {
        }

        SocialGraph::insertIgnore($pdo, 'social_profiles', [
            'user_id' => $userId,
            'display_name' => $username,
            'privacy' => $privacy,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $stmt = $pdo->prepare("SELECT * FROM social_profiles WHERE user_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (\Throwable $e) {
        }

        return [
            'user_id' => $userId,
            'display_name' => $username,
            'bio' => '',
            'location' => '',
            'website' => '',
            'cover' => '',
            'privacy' => 'public',
            'pinned_thread_id' => null,
        ];
    }

    public static function stats(\PDO $pdo, int $userId): array
    {
        $stats = [
            'posts' => 0,
            'replies' => 0,
            'followers' => 0,
            'following' => 0,
            'reactions' => 0,
        ];

        try {
            $feed = Feed::query(['tab' => 'latest', 'user_id' => $userId, 'per_page' => 1, 'include_forum_threads' => true]);
            $stats['posts'] = (int)$feed['total'];

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ? AND status = 'visible'");
            $stmt->execute([$userId]);
            $stats['replies'] = (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
        }

        $stats['followers'] = SocialGraph::followerCount($pdo, $userId);
        $stats['following'] = SocialGraph::followingCount($pdo, $userId);
        $stats['reactions'] = Reactions::receivedCount($pdo, $userId);

        return $stats;
    }

    /**
     * @return array{items:array<int,array>,total:int,pages:int}
     */
    public static function replies(\PDO $pdo, int $userId, int $viewerId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $offset = ($page - 1) * $perPage;

        $visibility = Privacy::threadVisibilityCondition($viewerId, 'prv');
        $audience = Privacy::threadAudienceCondition($viewerId, 'pra');
        $where = [
            "p.user_id = :reply_author",
            "p.status = 'visible'",
            "t.status IN ('visible','sticky','locked')",
            $visibility['sql'],
            $audience['sql'],
        ];
        $params = $visibility['params'] + $audience['params'];
        $params[':reply_author'] = $userId;
        $whereSql = implode(' AND ', $where);

        $items = [];
        $total = 0;
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, t.title AS thread_title, t.user_id AS thread_author_id,
                       tu.username AS thread_author, t.status AS thread_status
                FROM posts p
                JOIN threads t ON t.id = p.thread_id
                JOIN users tu ON tu.id = t.user_id
                LEFT JOIN social_threads st ON st.thread_id = t.id
                LEFT JOIN social_profiles sp ON sp.user_id = t.user_id
                WHERE {$whereSql}
                ORDER BY p.created_at DESC, p.id DESC
                LIMIT :limit OFFSET :offset
            ");
            foreach ($params as $name => $value) {
                $stmt->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $count = $pdo->prepare("
                SELECT COUNT(*)
                FROM posts p
                JOIN threads t ON t.id = p.thread_id
                JOIN users tu ON tu.id = t.user_id
                LEFT JOIN social_threads st ON st.thread_id = t.id
                LEFT JOIN social_profiles sp ON sp.user_id = t.user_id
                WHERE {$whereSql}
            ");
            foreach ($params as $name => $value) {
                $count->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $count->execute();
            $total = (int)$count->fetchColumn();
        } catch (\Throwable $e) {
            error_log('socialbored replies query: ' . $e->getMessage());
        }

        return ['items' => $items, 'total' => $total, 'pages' => max(1, (int)ceil($total / $perPage))];
    }

    public static function media(\PDO $pdo, int $userId, int $viewerId): array
    {
        $visibility = Privacy::threadVisibilityCondition($viewerId, 'pmv');
        $audience = Privacy::threadAudienceCondition($viewerId, 'pma');
        $where = [
            "up.user_id = :media_author",
            "t.status IN ('visible','sticky','locked')",
            $visibility['sql'],
            $audience['sql'],
        ];
        $params = $visibility['params'] + $audience['params'];
        $params[':media_author'] = $userId;

        try {
            $stmt = $pdo->prepare("
                SELECT up.*, t.title AS thread_title, t.id AS thread_id
                FROM uploads up
                JOIN threads t ON t.id = up.thread_id
                JOIN users tu ON tu.id = t.user_id
                LEFT JOIN social_threads st ON st.thread_id = t.id
                LEFT JOIN social_profiles sp ON sp.user_id = t.user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY up.created_at DESC, up.id DESC
                LIMIT 60
            ");
            foreach ($params as $name => $value) {
                $stmt->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function validWebsite(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }
}
