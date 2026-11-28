<?php

namespace SocialBored;

use App;

class Feed
{
    public static function handle(array $params = []): bool|\Bulletin\Response
    {
        $app = App::getInstance();
        $pdo = $app->pdo;
        $pm = $app->pluginManager;

        if (!Http::loggedIn() && !Settings::bool($pm, 'allow_public_feed')) {
            return Http::redirect('login');
        }

        $tab = (string)($_GET['tab'] ?? Settings::defaultFeed($pm));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $result = self::query(['tab' => $tab, 'page' => $page]);

        $threads = self::attachState($pdo, $result['threads']);
        $viewerId = Http::userId();
        $suggestions = SocialGraph::suggestions($pdo, $viewerId, 5);
        $stats = self::stats($pdo);
        $feedHeading = (string)Settings::get($pm, 'site_label', 'Feed');
        if ($feedHeading === '') {
            $feedHeading = \pt('socialbored', 'feed');
        }
        $feedEmpty = null;
        $showTabs = true;
        $showComposer = true;
        $tab = $result['tab'];
        $page = (int)$result['page'];
        $pages = (int)$result['pages'];
        $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/');
        $feedBase = str_contains($path, '/social') ? Http::url('social') : Http::url('');

        include dirname(__DIR__) . '/views/feed.php';
        return true;
    }

    /**
     * Classic forum home, available as an escape hatch while social mode is on.
     */
    public static function handleForum(array $params = []): bool|\Bulletin\Response
    {
        $base = (string)($params['_base'] ?? 'home');
        if (!in_array($base, ['home', 'forum'], true)) {
            $base = 'home';
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $sort = (string)($_GET['sort'] ?? 'latest');
        $listing = ClassicForum::fetch(['page' => $page, 'sort' => $sort, 'per_page' => 15]);
        $threads = $listing['threads'];
        $total = $listing['total'];
        $totalPages = $listing['pages'];
        $page = $listing['page'];
        $sort = $listing['sort'];
        $listContext = 'home';
        $listUrlBase = $base;
        $listUrlArgs = [];

        include dirname(__DIR__) . '/views/forum.php';
        return true;
    }

    public static function handleSearch(array $params = []): bool
    {
        $pdo = App::getInstance()->pdo;
        $pm = App::getInstance()->pluginManager;
        $q = trim((string)($_GET['q'] ?? ''));
        $type = (string)($_GET['type'] ?? 'posts');
        if (!in_array($type, ['posts', 'people', 'tags'], true)) {
            $type = 'posts';
        }
        if ($type === 'tags' && $pm instanceof \PluginManager && !Settings::bool($pm, 'enable_hashtags')) {
            $type = 'posts';
        }
        $page = max(1, (int)($_GET['page'] ?? 1));

        $threads = [];
        $people = [];
        $tags = [];
        $total = 0;
        $pages = 1;

        if ($q !== '') {
            if ($type === 'people') {
                $people = self::searchPeople($pdo, $q);
                $total = count($people);
            } elseif ($type === 'tags') {
                $tags = self::searchTags($pdo, $q);
                $total = count($tags);
            } else {
                $result = self::query(['tab' => 'latest', 'search' => $q, 'page' => $page, 'include_forum_threads' => true]);
                $threads = self::attachState($pdo, $result['threads']);
                $total = $result['total'];
                $pages = $result['pages'];
                $page = $result['page'];
            }
        }

        $viewerId = Http::userId();
        $suggestions = SocialGraph::suggestions($pdo, $viewerId, 5);
        $stats = self::stats($pdo);

        include dirname(__DIR__) . '/views/search.php';
        return true;
    }

    public static function handleHashtag(array $params = []): bool
    {
        $pdo = App::getInstance()->pdo;
        $tag = strtolower(trim((string)($params['tag'] ?? $_GET['tag'] ?? '')));
        $tag = ltrim($tag, '#');
        $page = max(1, (int)($_GET['page'] ?? 1));

        $result = self::query(['tab' => 'latest', 'hashtag' => $tag, 'page' => $page, 'include_forum_threads' => true]);
        $threads = self::attachState($pdo, $result['threads']);
        $tab = 'latest';
        $page = (int)$result['page'];
        $pages = (int)$result['pages'];
        $viewerId = Http::userId();
        $suggestions = SocialGraph::suggestions($pdo, $viewerId, 5);
        $stats = self::stats($pdo);
        $feedHeading = '#' . $tag;
        $feedEmpty = \pt('socialbored', 'no_hashtag_posts');
        $showTabs = false;
        $showComposer = false;
        $feedBase = Http::url('hashtag/' . rawurlencode($tag));

        include dirname(__DIR__) . '/views/feed.php';
        return true;
    }

    /**
     * Build the feed query. Threads are the canonical social posts; posts are
     * comments. Visibility and blocking are enforced in SQL.
     *
     * @return array{threads:array<int,array>,total:int,pages:int,page:int,tab:string}
     */
    public static function query(array $opts = []): array
    {
        $pdo = App::getInstance()->pdo;
        $pm = App::getInstance()->pluginManager;
        $viewerId = Http::userId();

        $tab = (string)($opts['tab'] ?? Settings::defaultFeed($pm));
        if (!in_array($tab, ['following', 'discover', 'latest'], true)) {
            $tab = 'discover';
        }
        if ($tab === 'following' && ($viewerId <= 0 || !Settings::bool($pm, 'enable_following_feed'))) {
            $tab = 'discover';
        }

        $page = max(1, (int)($opts['page'] ?? 1));
        $perPage = max(1, min(50, (int)($opts['per_page'] ?? 15)));
        $offset = ($page - 1) * $perPage;

        $where = ["t.status IN ('visible', 'sticky', 'locked')"];
        $params = [];

        $visibility = Privacy::threadVisibilityCondition($viewerId, 'sv');
        $where[] = $visibility['sql'];
        $params += $visibility['params'];

        $audience = Privacy::threadAudienceCondition($viewerId, 'sa');
        $where[] = $audience['sql'];
        $params += $audience['params'];

        $includeForumThreads = $opts['include_forum_threads'] ?? Settings::bool($pm, 'include_forum_threads');
        if (!$includeForumThreads) {
            $where[] = 'st.thread_id IS NOT NULL';
        }

        if ($tab === 'following') {
            $where[] = "(t.user_id = :sf_author OR EXISTS (
                SELECT 1 FROM social_follows sfu
                WHERE sfu.follower_id = :sf_follower AND sfu.following_id = t.user_id
            ))";
            $params[':sf_author'] = $viewerId;
            $params[':sf_follower'] = $viewerId;
        }

        if (!empty($opts['search'])) {
            $where[] = '(t.title LIKE :search_title OR t.content LIKE :search_content)';
            $params[':search_title'] = '%' . (string)$opts['search'] . '%';
            $params[':search_content'] = '%' . (string)$opts['search'] . '%';
        }

        if (!empty($opts['hashtag'])) {
            $where[] = "EXISTS (
                SELECT 1 FROM social_thread_hashtags sth
                JOIN social_hashtags sh ON sh.id = sth.hashtag_id
                WHERE sth.thread_id = t.id AND sh.tag = :hashtag
            )";
            $params[':hashtag'] = strtolower((string)$opts['hashtag']);
        }

        if (!empty($opts['user_id'])) {
            $where[] = 't.user_id = :author_id';
            $params[':author_id'] = (int)$opts['user_id'];
        }

        if (!empty($opts['bookmarked_by'])) {
            $where[] = 'EXISTS (
                SELECT 1 FROM social_bookmarks sbm
                WHERE sbm.user_id = :bookmark_user AND sbm.thread_id = t.id
            )';
            $params[':bookmark_user'] = (int)$opts['bookmarked_by'];
        }

        if (!empty($opts['reposted_by'])) {
            $where[] = 'EXISTS (
                SELECT 1 FROM social_reposts srp
                WHERE srp.user_id = :repost_user AND srp.thread_id = t.id
            )';
            $params[':repost_user'] = (int)$opts['reposted_by'];
        }

        if (!empty($opts['liked_by'])) {
            $where[] = "EXISTS (
                SELECT 1 FROM social_reactions slk
                WHERE slk.user_id = :like_user AND slk.target_type = 'thread' AND slk.target_id = t.id
            )";
            $params[':like_user'] = (int)$opts['liked_by'];
        }

        $whereSql = implode(' AND ', $where);

        $orderBy = match ($tab) {
            'latest', 'following' => 't.created_at DESC, t.id DESC',
            default => '(reaction_count * 3 + repost_count * 4 + reply_count * 2) DESC, t.created_at DESC, t.id DESC',
        };

        $select = "
            SELECT t.*, u.username AS author, u.avatar AS author_avatar, u.role AS author_role,
                   st.visibility, st.kind,
                   COALESCE(t.views, 0) AS view_count,
                   (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id AND p.status = 'visible') AS reply_count,
                   (SELECT COUNT(*) FROM social_reactions r WHERE r.target_type = 'thread' AND r.target_id = t.id) AS reaction_count,
                   (SELECT COUNT(*) FROM social_reposts rp WHERE rp.thread_id = t.id) AS repost_count,
                   (SELECT COUNT(*) FROM social_bookmarks b WHERE b.thread_id = t.id) AS bookmark_count
            FROM threads t
            JOIN users u ON u.id = t.user_id
            LEFT JOIN social_threads st ON st.thread_id = t.id
            LEFT JOIN social_profiles sp ON sp.user_id = t.user_id
            WHERE {$whereSql}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ";

        $threads = [];
        try {
            $stmt = $pdo->prepare($select);
            self::bind($stmt, $params);
            $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $threads = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $countStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM threads t
                JOIN users u ON u.id = t.user_id
                LEFT JOIN social_threads st ON st.thread_id = t.id
                LEFT JOIN social_profiles sp ON sp.user_id = t.user_id
                WHERE {$whereSql}
            ");
            self::bind($countStmt, $params);
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('socialbored feed query: ' . $e->getMessage());
            $total = 0;
        }

        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }

        return [
            'threads' => $threads,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'tab' => $tab,
        ];
    }

    /**
     * @param array<int,array> $threads
     * @return array<int,array>
     */
    public static function attachState(\PDO $pdo, array $threads): array
    {
        $viewerId = Http::userId();
        $threads = Reactions::attachToThreads($pdo, $threads, $viewerId);
        $threads = Reposts::attachToThreads($pdo, $threads, $viewerId);
        $threads = Bookmarks::attachToThreads($pdo, $threads, $viewerId);
        $threads = self::attachMedia($pdo, $threads);

        return $threads;
    }

    /**
     * Attach opening-post attachments to feed rows in one query.
     *
     * @param array<int,array> $threads
     * @return array<int,array>
     */
    public static function attachMedia(\PDO $pdo, array $threads): array
    {
        if ($threads === []) {
            return $threads;
        }
        $ids = array_values(array_unique(array_filter(array_map(static fn($row) => (int)$row['id'], $threads))));
        if ($ids === []) {
            return $threads;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $byThread = [];
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM uploads
                WHERE thread_id IN ({$placeholders}) AND post_id IS NULL
                ORDER BY created_at ASC, id ASC
            ");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $upload) {
                $byThread[(int)$upload['thread_id']][] = $upload;
            }
        } catch (\Throwable $e) {
        }

        $byUrl = [];
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM social_media
                WHERE thread_id IN ({$placeholders})
                ORDER BY id ASC
            ");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $media) {
                $byUrl[(int)$media['thread_id']][] = $media;
            }
        } catch (\Throwable $e) {
        }

        foreach ($threads as &$row) {
            $row['attachments'] = $byThread[(int)$row['id']] ?? [];
            $row['url_media'] = $byUrl[(int)$row['id']] ?? [];
        }
        unset($row);

        return $threads;
    }

    public static function sidebarData(\PDO $pdo, int $viewerId): array
    {
        $profile = null;
        $viewerStats = null;

        if ($viewerId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT id, username, avatar, role FROM users WHERE id = ?");
                $stmt->execute([$viewerId]);
                $viewer = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($viewer) {
                    $profile = Profiles::ensureProfile($pdo, $viewerId, (string)$viewer['username']);
                    $profile['username'] = (string)$viewer['username'];
                    $profile['avatar'] = (string)($viewer['avatar'] ?? '');
                    $profile['role'] = (string)($viewer['role'] ?? 'user');
                    $viewerStats = Profiles::stats($pdo, $viewerId);
                }
            } catch (\Throwable $e) {
            }
        }

        return [
            'profile' => $profile,
            'stats' => $viewerStats,
            'trending' => self::trendingTags($pdo, $viewerId, 5),
        ];
    }

    /**
     * @return array<int,array{tag:string,total:int}>
     */
    public static function trendingTags(\PDO $pdo, int $viewerId, int $limit = 5): array
    {
        $limit = max(1, min(10, $limit));
        $visibility = Privacy::threadVisibilityCondition($viewerId, 'tt');
        $audience = Privacy::threadAudienceCondition($viewerId, 'ta');
        $params = $visibility['params'] + $audience['params'];

        try {
            $stmt = $pdo->prepare("
                SELECT sh.tag, COUNT(DISTINCT t.id) AS total
                FROM social_hashtags sh
                JOIN social_thread_hashtags sth ON sth.hashtag_id = sh.id
                JOIN threads t ON t.id = sth.thread_id
                JOIN users u ON u.id = t.user_id
                LEFT JOIN social_threads st ON st.thread_id = t.id
                LEFT JOIN social_profiles sp ON sp.user_id = t.user_id
                WHERE t.status IN ('visible', 'sticky', 'locked')
                  AND {$visibility['sql']}
                  AND {$audience['sql']}
                GROUP BY sh.id, sh.tag
                ORDER BY total DESC, sh.tag ASC
                LIMIT :limit
            ");
            foreach ($params as $name => $value) {
                $stmt->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return array_map(
                static fn(array $row): array => [
                    'tag' => (string)$row['tag'],
                    'total' => (int)$row['total'],
                ],
                $stmt->fetchAll(\PDO::FETCH_ASSOC)
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function stats(\PDO $pdo): array
    {
        $stats = [
            'posts' => 0,
            'members' => 0,
            'follows' => 0,
            'reactions' => 0,
        ];
        foreach ($stats as $key => $_) {
            try {
                $sql = match ($key) {
                    'posts' => 'SELECT COUNT(*) FROM social_threads',
                    'members' => 'SELECT COUNT(*) FROM users',
                    'follows' => 'SELECT COUNT(*) FROM social_follows',
                    'reactions' => 'SELECT COUNT(*) FROM social_reactions',
                };
                $stats[$key] = (int)$pdo->query($sql)->fetchColumn();
            } catch (\Throwable $e) {
            }
        }
        return $stats;
    }

    public static function searchPeople(\PDO $pdo, string $q): array
    {
        $viewerId = Http::userId();
        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.avatar, u.role, u.created_at,
                       (SELECT COUNT(*) FROM social_follows f WHERE f.following_id = u.id) AS followers_count,
                       EXISTS(SELECT 1 FROM social_follows f2 WHERE f2.follower_id = :viewer AND f2.following_id = u.id) AS viewer_follows
                FROM users u
                WHERE u.username LIKE :q AND u.status = 'active'
                  AND NOT EXISTS (
                      SELECT 1 FROM social_blocks b
                      WHERE (b.blocker_id = :viewer_blocker AND b.blocked_id = u.id)
                         OR (b.blocker_id = u.id AND b.blocked_id = :viewer_blocked)
                  )
                ORDER BY followers_count DESC, u.username ASC
                LIMIT 30
            ");
            $stmt->bindValue(':viewer', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':viewer_blocker', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':viewer_blocked', $viewerId, \PDO::PARAM_INT);
            $stmt->bindValue(':q', '%' . $q . '%', \PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function searchTags(\PDO $pdo, string $q): array
    {
        $viewerId = Http::userId();
        $visibility = Privacy::threadVisibilityCondition($viewerId, 'stv');
        $audience = Privacy::threadAudienceCondition($viewerId, 'sta');
        $params = $visibility['params'] + $audience['params'];
        $params[':tag_query'] = '%' . strtolower(ltrim($q, '#')) . '%';

        try {
            $stmt = $pdo->prepare("
                SELECT sh.tag, COUNT(DISTINCT t.id) AS total
                FROM social_hashtags sh
                JOIN social_thread_hashtags sth ON sth.hashtag_id = sh.id
                JOIN threads t ON t.id = sth.thread_id
                JOIN users u ON u.id = t.user_id
                LEFT JOIN social_threads st ON st.thread_id = t.id
                LEFT JOIN social_profiles sp ON sp.user_id = t.user_id
                WHERE sh.tag LIKE :tag_query
                  AND t.status IN ('visible','sticky','locked')
                  AND {$visibility['sql']}
                  AND {$audience['sql']}
                GROUP BY sh.id, sh.tag
                ORDER BY total DESC, sh.tag ASC
                LIMIT 30
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

    /**
     * @param array<string,int|string> $params
     */
    private static function bind(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
    }

    public static function handleForumSearch(array $params = []): bool|\Bulletin\Response
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $sort = (string)($_GET['sort'] ?? 'latest');
        $listing = ClassicForum::fetch([
            'search' => $q,
            'page' => $page,
            'sort' => $sort,
            'per_page' => 15,
        ]);
        $threads = $listing['threads'];
        $total = $listing['total'];
        $totalPages = $listing['pages'];
        $page = $listing['page'];
        $sort = $listing['sort'];
        $listContext = 'search';
        $listUrlBase = 'search';
        $listUrlArgs = $q !== '' ? ['q' => $q] : [];
        $searchQuery = $q;
        $isSearch = true;

        include dirname(__DIR__) . '/views/forum_search.php';
        return true;
    }

    public static function handleState(array $params = []): \Bulletin\Response
    {
        $pdo = App::getInstance()->pdo;
        $threadId = (int)($_GET['thread_id'] ?? $params['thread_id'] ?? 0);
        if ($threadId <= 0) {
            return Http::stateJson(['ok' => false, 'error' => 'invalid_thread'], 422);
        }

        $stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
        $stmt->execute([$threadId]);
        $thread = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$thread) {
            return Http::stateJson(['ok' => false, 'error' => 'not_found'], 404);
        }
        try {
            Privacy::assertThreadViewable($pdo, $thread);
        } catch (\Throwable $e) {
            return Http::stateJson(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $viewerId = Http::userId();
        $threadKind = $viewerId > 0 ? Reactions::userKind($pdo, $viewerId, 'thread', $threadId) : null;

        $response = [
            'ok' => true,
            'thread' => [
                'reactions' => [
                    'count' => Reactions::countForTarget($pdo, 'thread', $threadId),
                    'counts' => Reactions::countsByKind($pdo, 'thread', $threadId),
                    'kind' => $threadKind,
                    'active' => $threadKind !== null,
                ],
                'bookmarks' => [
                    'count' => Bookmarks::countForThread($pdo, $threadId),
                    'active' => $viewerId > 0 && Bookmarks::has($pdo, $viewerId, $threadId),
                ],
                'reposts' => [
                    'count' => Reposts::countForThread($pdo, $threadId),
                    'active' => $viewerId > 0 && Reposts::has($pdo, $viewerId, $threadId),
                ],
            ],
            'posts' => [],
        ];

        $postIds = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string)($_GET['post_ids'] ?? ''))
        ))));
        $postIds = array_slice($postIds, 0, 100);
        if ($postIds !== []) {
            $placeholders = implode(',', array_fill(0, count($postIds), '?'));
            $postStmt = $pdo->prepare("SELECT id FROM posts WHERE thread_id = ? AND id IN ({$placeholders})");
            $postStmt->execute(array_merge([$threadId], $postIds));
            $valid = array_map('intval', $postStmt->fetchAll(\PDO::FETCH_COLUMN));
            foreach ($valid as $postId) {
                $kind = $viewerId > 0 ? Reactions::userKind($pdo, $viewerId, 'post', $postId) : null;
                $response['posts'][(string)$postId] = [
                    'count' => Reactions::countForTarget($pdo, 'post', $postId),
                    'kind' => $kind,
                    'active' => $kind !== null,
                ];
            }
        }

        return Http::stateJson($response);
    }
}
