<?php

namespace SocialBored;

use App;

class Media
{
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
