<?php

/**
 * Runtime checks for the socialbored privacy/media primitives.
 *
 * The core test runner auto-discovers plugins/<name>/tests/*Test.php.
 */

require_once dirname(__DIR__, 3) . '/tests/harness.php';
require_once dirname(__DIR__) . '/src/Privacy.php';
require_once dirname(__DIR__) . '/src/Reactions.php';
require_once dirname(__DIR__) . '/src/Util.php';

if (!function_exists('can_view_thread')) {
    function can_view_thread(string $status): bool
    {
        return in_array($status, ['visible', 'sticky', 'locked'], true);
    }
}

function socialbored_privacy_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE users (id INTEGER PRIMARY KEY, profile_visibility TEXT DEFAULT 'public');
        CREATE TABLE threads (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            category_id INTEGER,
            visibility TEXT DEFAULT 'public',
            status TEXT DEFAULT 'visible',
            created_at DATETIME
        );
        CREATE TABLE posts (id INTEGER PRIMARY KEY, thread_id INTEGER, status TEXT DEFAULT 'visible');
        CREATE TABLE social_threads (thread_id INTEGER PRIMARY KEY, visibility TEXT DEFAULT 'public');
        CREATE TABLE social_profiles (user_id INTEGER PRIMARY KEY, privacy TEXT DEFAULT 'public');
        CREATE TABLE social_follows (follower_id INTEGER, following_id INTEGER, PRIMARY KEY (follower_id, following_id));
        CREATE TABLE social_blocks (blocker_id INTEGER, blocked_id INTEGER, PRIMARY KEY (blocker_id, blocked_id));
        CREATE TABLE social_mutes (user_id INTEGER, muted_id INTEGER, PRIMARY KEY (user_id, muted_id));
    ");
    $pdo->exec("INSERT INTO users (id, profile_visibility) VALUES (1, 'followers'), (2, 'public'), (3, 'public')");
    $pdo->exec("INSERT INTO social_profiles (user_id, privacy) VALUES (1, 'public')");
    $pdo->exec("INSERT INTO social_follows (follower_id, following_id) VALUES (3, 1)");
    $pdo->exec("
        INSERT INTO threads (id, user_id, category_id, visibility, status, created_at) VALUES
            (1, 1, NULL, 'private', 'visible', '2026-01-01'),
            (2, 1, NULL, 'followers', 'visible', '2026-01-01'),
            (3, 1, NULL, 'public', 'visible', '2026-01-01'),
            (4, 1, 1, 'private', 'visible', '2026-01-01')
    ");
    // Social metadata is deliberately missing for 1/2/4 and stricter for 3.
    $pdo->exec("INSERT INTO social_threads (thread_id, visibility) VALUES (3, 'private')");
    $pdo->exec("INSERT INTO posts (id, thread_id, status) VALUES (10, 1, 'hidden')");
    return $pdo;
}

function socialbored_privacy_visible_ids(PDO $pdo, int $viewer): array
{
    $condition = \SocialBored\Privacy::threadVisibilityCondition($viewer, 'v', 't', 'u');
    $stmt = $pdo->prepare("
        SELECT t.id FROM threads t
        JOIN users u ON u.id = t.user_id
        LEFT JOIN social_threads st ON st.thread_id = t.id
        LEFT JOIN social_profiles sp ON sp.user_id = t.user_id
        WHERE {$condition['sql']}
        ORDER BY t.id
    ");
    foreach ($condition['params'] as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_INT);
    }
    $stmt->execute();
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function test_socialbored_privacy_core_columns(): Test
{
    $t = new Test('Socialbored - core visibility wins over missing social metadata');
    $pdo = socialbored_privacy_pdo();

    $t->assertEquals('Anonymous sees nothing private/followers', [], socialbored_privacy_visible_ids($pdo, 0));
    $t->assertEquals('Stranger sees nothing private/followers', [], socialbored_privacy_visible_ids($pdo, 2));
    $t->assertEquals('Follower sees only the followers-only post', [2], socialbored_privacy_visible_ids($pdo, 3));
    $t->assertEquals('Author sees all their posts', [1, 2, 3, 4], socialbored_privacy_visible_ids($pdo, 1));

    return $t;
}

function socialbored_audience_ids(PDO $pdo, int $viewer): array
{
    $condition = \SocialBored\Privacy::threadAudienceCondition($viewer, 'a');
    $stmt = $pdo->prepare("
        SELECT t.id FROM threads t
        JOIN users u ON u.id = t.user_id
        WHERE {$condition['sql']}
        ORDER BY t.id
    ");
    foreach ($condition['params'] as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_INT);
    }
    $stmt->execute();
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function test_socialbored_audience_block_mute(): Test
{
    $t = new Test('Socialbored - blocks and mutes filter the audience');
    $pdo = socialbored_privacy_pdo();
    $pdo->exec("INSERT INTO threads (id, user_id, category_id, visibility, status, created_at) VALUES (5, 2, 1, 'public', 'visible', '2026-01-01')");

    $t->assertEquals('No block/mute: everything passes the audience filter', [1, 2, 3, 4, 5], socialbored_audience_ids($pdo, 3));

    $pdo->exec("INSERT INTO social_blocks (blocker_id, blocked_id) VALUES (2, 1)");
    $t->assertEquals('Blocked author is filtered out', [5], socialbored_audience_ids($pdo, 2));
    $pdo->exec("DELETE FROM social_blocks");

    $pdo->exec("INSERT INTO social_mutes (user_id, muted_id) VALUES (2, 1)");
    $t->assertEquals('Muted author is filtered out', [5], socialbored_audience_ids($pdo, 2));

    return $t;
}

function test_socialbored_hidden_status_and_post(): Test
{
    $t = new Test('Socialbored - hidden status blocks access and targets');
    $pdo = socialbored_privacy_pdo();

    $hiddenThread = ['id' => 9, 'status' => 'hidden', 'user_id' => 1, 'visibility' => 'public', 'category_id' => 1];
    $t->assertFalse('Hidden thread denied even to its author', \SocialBored\Privacy::canAccessThread($pdo, $hiddenThread, 1));

    $target = \SocialBored\Reactions::loadTarget($pdo, 'post', 10);
    $t->assertTrue('Hidden post cannot be a reaction target', $target === null);

    return $t;
}

function test_socialbored_media_urls(): Test
{
    $t = new Test('Socialbored - media URL validation');

    $youtube = \SocialBored\Util::videoEmbed('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    $t->assertEquals('YouTube URL becomes an iframe', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $youtube['src'] ?? '');

    $vimeo = \SocialBored\Util::videoEmbed('https://vimeo.com/123456789');
    $t->assertEquals('Vimeo URL becomes an iframe', 'https://player.vimeo.com/video/123456789', $vimeo['src'] ?? '');

    $direct = \SocialBored\Util::videoEmbed('https://example.com/video.mp4');
    $t->assertEquals('Direct MP4 becomes a video element', 'video', $direct['type'] ?? '');

    $t->assertTrue('Invalid video URL rejected', \SocialBored\Util::videoEmbed('javascript:alert(1)') === null);
    $t->assertTrue('Javascript image URL rejected', \SocialBored\Util::safeHttpUrl('javascript:alert(1)') === null);
    $t->assertTrue('HTTPS image URL accepted', \SocialBored\Util::safeHttpUrl('https://example.com/image.jpg') !== null);

    return $t;
}

register_tests(
    'test_socialbored_privacy_core_columns',
    'test_socialbored_audience_block_mute',
    'test_socialbored_hidden_status_and_post',
    'test_socialbored_media_urls'
);
