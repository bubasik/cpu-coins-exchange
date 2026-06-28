<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    private static function layoutPath(): string
    {
        return dirname(__DIR__, 2) . '/public/views/layout.php';
    }

    public static function render(string $template, array $data = []): void
    {
        $user = Auth::user();
        $lang = self::detectLang($user);
        $theme = self::detectTheme($user);
        $csrf = self::csrfToken();
        $data['user'] = $user;
        $data['lang'] = $lang;
        $data['theme'] = $theme;
        $data['csrf'] = $csrf;

        $templatePath = dirname(__DIR__, 2) . '/public/views/' . $template . '.php';
        if (!file_exists($templatePath)) {
            Response::error(500, "Template not found: $template");
        }

        // Capture content
        extract($data, EXTR_SKIP);
        ob_start();
        require $templatePath;
        $content = ob_get_clean();

        // Layout wraps content
        require self::layoutPath();
    }

    public static function partial(string $name, array $data = []): void
    {
        $path = dirname(__DIR__, 2) . '/public/views/_partials/' . $name . '.php';
        if (file_exists($path)) {
            extract($data, EXTR_SKIP);
            require $path;
        }
    }

    private static function detectLang(?array $user): string
    {
        if ($user && !empty($user['lang'])) return $user['lang'];
        if (!empty($_COOKIE['lang'])) {
            $l = $_COOKIE['lang'];
            if (in_array($l, ['en','ru','ja','zh'])) return $l;
        }
        return 'en';
    }

    private static function detectTheme(?array $user): string
    {
        if ($user && !empty($user['theme'])) return $user['theme'];
        if (!empty($_COOKIE['theme'])) {
            return $_COOKIE['theme'] === 'light' ? 'light' : 'dark';
        }
        return 'dark';
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }
        // Sessions are stateless here, so use signed cookie approach
        $token = $_COOKIE['csrf'] ?? null;
        if (!$token) {
            $token = bin2hex(random_bytes(16));
            setcookie('csrf', $token, [
                'expires' => time() + 86400,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return $token;
    }

    public static function verifyCsrf(?string $token): bool
    {
        if (!$token) return false;
        return hash_equals($_COOKIE['csrf'] ?? '', $token);
    }
}
