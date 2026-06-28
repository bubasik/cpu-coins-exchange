<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    private const TOKEN_COOKIE = 'ys_sess';
    private const TTL = 604800; // 7 days

    public static function start(int $userId, array $extra = []): string
    {
        $token = bin2hex(random_bytes(32));
        $payload = [
            'uid' => $userId,
            'exp' => time() + self::TTL,
            'iat' => time(),
        ] + $extra;
        Redis::set('sess:' . $token, $payload, self::TTL);

        setcookie(self::TOKEN_COOKIE, $token, [
            'expires' => time() + self::TTL,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return $token;
    }

    public static function user(): ?array
    {
        $token = $_COOKIE[self::TOKEN_COOKIE] ?? null;
        if (!$token) return null;
        $payload = Redis::get('sess:' . $token);
        if (!$payload || ($payload['exp'] ?? 0) < time()) {
            self::destroy();
            return null;
        }
        return $payload;
    }

    public static function destroy(): void
    {
        $token = $_COOKIE[self::TOKEN_COOKIE] ?? null;
        if ($token) Redis::del('sess:' . $token);
        setcookie(self::TOKEN_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
        ]);
    }
}
