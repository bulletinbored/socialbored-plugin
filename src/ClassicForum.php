<?php

namespace SocialBored;

use App;

/**
 * Privacy-aware version of the classic forum listing.
 *
 * The core fetch_threads() has no social visibility awareness. socialbored
 * owns "/" (forum/both mode) and "/forum" so private and followers-only posts
 * never leak into the classic list. The row shape matches views/partials/
 * thread_list.php.
 */
class ClassicForum
{
    /**
     * @return array{threads:array<int,array>,total:int,pages:int,page:int,sort:string}
     */
    public static function fetch(array $opts = []): array
    {
        $pdo = App::getInstance()->pdo;
        $viewerId = Http::userId();
        $page = max(1, (int)($opts['page'] ?? 1));
        $perPage = max(1, min(100, (int)($opts['per_page'] ?? 15)));
        $offset = ($page - 1) * $perPage;
        $sort = (string)($opts['sort'] ?? 'latest');

        $visibility = Privacy::threadVisibilityCondition($viewerId, 'cfv');
        $audience = Privacy::threadAudienceCondition($viewerId, 'cfa');
        $params = $visibility['params'] + $audience['params'];

        $where = [
            "t.status IN ('visible', 'sticky', 'locked')",
            $visibility['sql'],
            $audience['sql'],
        ];

        $search = trim((string)($opts['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(t.title LIKE :search_title OR t.content LIKE :search_content)';
            $params[':search_title'] = '%' . $search . '%';
            $params[':search_content'] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $where);

        $orderBy = match ($sort) {
            'replies' => '(SELECT COUNT(*) FROM posts WHERE thread_id = t.id) DESC, t.id DESC',
            'views' => 't.views DESC, t.id DESC',
            'newest' => 't.created_at DESC, t.id DESC',
            'oldest' => 't.created_at ASC, t.id ASC',
            default => 't.updated_at DESC, t.id DESC',
        };

        $pm = App::getInstance()->pluginManager;
        if ($pm !== null) {
            $orderBy = $pm->filter('thread_order_by', $orderBy, $sort);
        }

        $threads = [];
        $total = 0;
        try {
            $stmt = $pdo->prepare("
                SELECT t.*, u.username AS author, u.avatar AS author_avatar,
                       c.name AS category_name, c.id AS category_id,
                       COALESCE(t.views, 0) AS view_count,
                       (SELECT COUNT(*) FROM posts p WHERE p.thread_id = t.id AND p.status = 'visible') AS reply_count,
                       lp.user_id AS last_author_id,
                       lu.username AS last_author,
                       lu.avatar AS last_author_avatar,
                       lp.created_at AS last_post_at,
                       lp.id AS last_post_id,
                       lp.content AS last_post_content
                FROM threads t
                JOIN users u ON u.id = t.user_id
                LEFT JOIN categories c ON c.id = t.category_id
                LEFT JOIN social_threads st ON st.thread_id = t.id
                LEFT JOIN social_profiles sp ON sp.user_id = t.user_id
                LEFT JOIN posts lp ON lp.id = (
                    SELECT lp2.id
                    FROM posts lp2
                    WHERE lp2.thread_id = t.id AND lp2.status = 'visible'
                    ORDER BY lp2.created_at DESC, lp2.id DESC
                    LIMIT 1
                )
                LEFT JOIN users lu ON lu.id = lp.user_id
                WHERE {$whereSql}
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset
            ");
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
            error_log('socialbored classic forum query: ' . $e->getMessage());
        }

        return [
            'threads' => $threads,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $perPage)),
            'page' => $page,
            'sort' => $sort,
        ];
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
}
