<?php

namespace SocialBored;

use App;
use Bulletin\Response;

class Http
{
    public static function userId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public static function loggedIn(): bool
    {
        return function_exists('is_logged_in') && is_logged_in();
    }

    public static function requireLogin(): ?Response
    {
        return self::loggedIn() ? null : Response::redirect(\url('login'));
    }

    public static function requireCsrf(): ?Response
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !\csrf_validate_request()) {
            return Response::error(403, 'CSRF token invalid');
        }
        return null;
    }

    public static function isAdmin(): bool
    {
        $authz = App::getInstance()->authz;
        $uid = self::userId();
        return $authz !== null && $uid > 0 && $authz->can($uid, 'admin.access');
    }

    public static function canModerate(): bool
    {
        $authz = App::getInstance()->authz;
        $uid = self::userId();
        if ($authz === null || $uid <= 0) {
            return false;
        }
        return $authz->can($uid, 'admin.access') || $authz->can($uid, 'moderation.manage');
    }

    public static function requireAdmin(): ?Response
    {
        return self::isAdmin() ? null : Response::error(403, 'Forbidden');
    }

    public static function requireModerator(): ?Response
    {
        return self::canModerate() ? null : Response::error(403, 'Forbidden');
    }

    public static function url(string $path = ''): string
    {
        $base = rtrim((string)\base_url(), '/');
        $path = ltrim($path, '/');
        return $base . ($path === '' ? '/' : '/' . $path);
    }

    public static function route(string $path = ''): string
    {
        return self::url($path);
    }

    public static function redirect(string $path, int $status = 302): Response
    {
        return Response::redirect(self::url($path), $status);
    }

    public static function back(string $fallback = '/'): Response
    {
        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $base = rtrim((string)\base_url(), '/');

        if ($referer !== '') {
            if ($base !== '' && ($referer === $base || str_starts_with($referer, $base . '/'))) {
                return Response::redirect($referer);
            }

            $refHost = parse_url($referer, PHP_URL_HOST);
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            if (is_string($refHost) && $refHost !== '' && $host !== '' && strcasecmp($refHost, $host) === 0) {
                return Response::redirect($referer);
            }

            // Relative same-site path, never protocol-relative.
            if ($base === '' && str_starts_with($referer, '/') && !str_starts_with($referer, '//')) {
                return Response::redirect($referer);
            }
        }

        return self::redirect($fallback);
    }

    public static function wantsJson(): bool
    {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }
        return !empty($_POST['_json']);
    }

    public static function json(array $data, int $status = 200): Response
    {
        // Action responses include the fresh CSRF token: the core rotates the
        // token after every successful validation, and the social UI performs
        // several AJAX actions without reloading the page.
        if (!array_key_exists('csrf', $data)) {
            $data['csrf'] = self::token();
        }
        return Response::json($data, $status);
    }

    public static function stateJson(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    public static function rateLimit(string $action, int $max, int $window, ?int $userId = null): bool
    {
        if (!function_exists('rate_limit')) {
            return true;
        }
        return \rate_limit($action, $max, $window, (string)($userId ?? self::userId()));
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . \escape(self::token()) . '">';
    }

    public static function token(): string
    {
        return function_exists('generate_csrf_token') ? \generate_csrf_token() : '';
    }

    public static function flash(string $key, string $message): void
    {
        $_SESSION['socialbored_flash'][$key] = $message;
    }

    public static function pullFlash(string $key): string
    {
        $message = (string)($_SESSION['socialbored_flash'][$key] ?? '');
        unset($_SESSION['socialbored_flash'][$key]);
        return $message;
    }
}
