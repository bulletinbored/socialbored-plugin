<?php

namespace SocialBored;

use App;

class Media
{
    public static function handleDownload(array $params = []): bool
    {
        $pdo = App::getInstance()->pdo;
        $uploadId = (int)($params['id'] ?? $_GET['id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM uploads WHERE id = ?");
        $stmt->execute([$uploadId]);
        $upload = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$upload) {
            throw new \Bulletin\NotFoundException('File not found');
        }

        $threadId = (int)($upload['thread_id'] ?? 0);
        if ($threadId <= 0 && !empty($upload['post_id'])) {
            $postStmt = $pdo->prepare("SELECT thread_id FROM posts WHERE id = ?");
            $postStmt->execute([(int)$upload['post_id']]);
            $threadId = (int)($postStmt->fetchColumn() ?: 0);
        }

        if ($threadId <= 0) {
            throw new \Bulletin\NotFoundException('File not found');
        }

        $threadStmt = $pdo->prepare("SELECT * FROM threads WHERE id = ?");
        $threadStmt->execute([$threadId]);
        $thread = $threadStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$thread || !\can_view_thread((string)($thread['status'] ?? 'visible'))) {
            throw new \Bulletin\NotFoundException('File not found');
        }
        Privacy::assertThreadViewable($pdo, $thread);

        $root = dirname(__DIR__, 3);
        $privatePath = $root . '/uploads/private/' . basename((string)$upload['filename']);
        $publicPath = $root . '/uploads/' . basename((string)$upload['filename']);
        if (is_file($privatePath)) {
            $filePath = $privatePath;
        } elseif (is_file($publicPath)) {
            $filePath = $publicPath;
        } else {
            throw new \Bulletin\NotFoundException('File not found');
        }

        $mime = (string)($upload['mime_type'] ?? '');
        if ($mime === '') {
            $mime = function_exists('mime_content_type') ? (string)\mime_content_type($filePath) : 'application/octet-stream';
        }
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        $isImage = str_starts_with($mime, 'image/');
        $disposition = $isImage ? 'inline' : 'attachment';
        $safeName = basename(preg_replace('/[\x00-\x1F\x7F\r\n]/', '', (string)($upload['original_name'] ?? 'file')));
        $quotedName = str_replace(['\\', '"'], '', $safeName);
        $utf8Name = rawurlencode($safeName);

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . $disposition . '; filename="' . $quotedName . '"; filename*=UTF-8\'\'' . $utf8Name);
        header('Content-Length: ' . (string)filesize($filePath));
        header('Cache-Control: private, max-age=3600');
        readfile($filePath);
        exit;
    }

    public static function coverDir(): string
    {
        return dirname(__DIR__, 3) . '/uploads/social/covers';
    }

    public static function storeCover(array $file, int $userId): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if ((int)($file['size'] ?? 0) > 4 * 1024 * 1024) {
            return null;
        }

        if (!class_exists('\finfo')) {
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file((string)($file['tmp_name'] ?? ''));
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extensions[$mime])) {
            return null;
        }

        $dir = self::coverDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\.(php|phtml|php3|php4|php5|php7|phar|cgi|pl|py|rb|asp|aspx|jsp)$\">\n    Require all denied\n</FilesMatch>\n");
        }

        $filename = 'cover_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        if (!@move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $filename)) {
            return null;
        }

        return $filename;
    }

    public static function deleteCover(?string $cover): void
    {
        $cover = (string)$cover;
        if ($cover === '' || preg_match('#^https?://#i', $cover)) {
            return;
        }
        $path = self::coverDir() . '/' . basename($cover);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
