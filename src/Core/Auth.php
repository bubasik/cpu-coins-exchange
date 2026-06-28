<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function id(): ?int
    {
        $sess = Session::user();
        return $sess['uid'] ?? null;
    }

    public static function user(): ?array
    {
        $uid = self::id();
        if (!$uid) return null;
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$uid]);
        $u = $stmt->fetch();
        return $u ?: null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login?next=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
            exit;
        }
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function login(int $userId): void
    {
        Session::start($userId);
        // rotate session token
    }

    public static function logout(): void
    {
        Session::destroy();
    }
}
