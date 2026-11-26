<?php

namespace SocialBored;

class View
{
    public static function render(string $view, array $data = []): string
    {
        $file = dirname(__DIR__) . '/views/' . ltrim($view, '/') . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('socialbored view not found: ' . $view);
        }

        return self::capture($file, $data);
    }

    public static function partial(string $partial, array $data = []): string
    {
        return self::render('partials/' . ltrim($partial, '/'), $data);
    }

    public static function postCard(array $post, array $ctx = []): string
    {
        return self::partial('post-card', ['post' => $post, 'ctx' => $ctx]);
    }

    public static function userCard(array $user, array $ctx = []): string
    {
        return self::partial('user-card', ['user' => $user, 'ctx' => $ctx]);
    }

    private static function capture(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }
}
