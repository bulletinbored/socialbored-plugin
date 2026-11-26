<?php

namespace SocialBored;

use App;

class Reports
{
    public static function handleCreate(array $params = []): \Bulletin\Response
    {
        if ($denied = Http::requireLogin()) {
            return $denied;
        }
        if ($denied = Http::requireCsrf()) {
            return $denied;
        }
        if (!Http::rateLimit('socialbored_report', 20, 3600)) {
            if (Http::wantsJson()) {
                return Http::json(['ok' => false, 'error' => 'rate_limited'], 429);
            }
            Http::flash('report_error', \pt('socialbored', 'report_rate_limited'));
            return Http::back('/');
        }

        $pdo = App::getInstance()->pdo;
        $type = (string)($_POST['target_type'] ?? '');
        $targetId = (int)($_POST['target_id'] ?? 0);
        $reasonType = trim((string)($_POST['reason_type'] ?? ''));
        $details = trim((string)($_POST['reason_details'] ?? ''));

        if (!in_array($type, ['thread', 'post', 'user'], true) || $targetId <= 0) {
            Http::flash('report_error', \pt('socialbored', 'report_invalid'));
            return Http::back('/');
        }

        $target = Admin::reportTarget($pdo, $type, $targetId);
        if ($target === null) {
            Http::flash('report_error', \pt('socialbored', 'report_invalid'));
            return Http::back('/');
        }

        if ($type === 'thread') {
            $stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
            $stmt->execute([$targetId]);
            $thread = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$thread) {
                Http::flash('report_error', \pt('socialbored', 'report_invalid'));
                return Http::back('/');
            }
            try {
                Privacy::assertThreadViewable($pdo, $thread);
            } catch (\Throwable $e) {
                return \Bulletin\Response::error(403, 'Forbidden');
            }
        } elseif ($type === 'post') {
            $stmt = $pdo->prepare("SELECT p.*, t.id AS thread_id FROM posts p JOIN threads t ON t.id = p.thread_id WHERE p.id = ?");
            $stmt->execute([$targetId]);
            $post = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$post) {
                Http::flash('report_error', \pt('socialbored', 'report_invalid'));
                return Http::back('/');
            }
            $stmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
            $stmt->execute([(int)$post['thread_id']]);
            $thread = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$thread) {
                Http::flash('report_error', \pt('socialbored', 'report_invalid'));
                return Http::back('/');
            }
            try {
                Privacy::assertThreadViewable($pdo, $thread);
            } catch (\Throwable $e) {
                return \Bulletin\Response::error(403, 'Forbidden');
            }
        }

        $allowedReasons = ['spam', 'harassment', 'hate', 'misinformation', 'other'];
        if (!in_array($reasonType, $allowedReasons, true)) {
            $reasonType = 'other';
        }
        $reason = $reasonType;
        if ($details !== '') {
            $reason .= ': ' . \mb_substr($details, 0, 900);
        }

        $me = Http::userId();
        try {
            $dup = $pdo->prepare("SELECT 1 FROM social_reports WHERE reporter_id = ? AND target_type = ? AND target_id = ? AND status = 'open'");
            $dup->execute([$me, $type, $targetId]);
            if ($dup->fetchColumn()) {
                Http::flash('report_error', \pt('socialbored', 'report_duplicate'));
                return Http::back('/');
            }

            $pdo->prepare("INSERT INTO social_reports (reporter_id, target_type, target_id, reason, status, created_at) VALUES (?, ?, ?, ?, 'open', ?)")
                ->execute([$me, $type, $targetId, $reason, date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            Http::flash('report_error', \pt('socialbored', 'report_failed'));
            return Http::back('/');
        }

        if (Http::wantsJson()) {
            return Http::json(['ok' => true, 'csrf' => Http::token()]);
        }
        Http::flash('report_success', \pt('socialbored', 'report_sent'));
        return Http::back('/');
    }
}
