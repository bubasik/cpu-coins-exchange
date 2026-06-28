<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\RateLimit;
use App\Core\Response;
use App\Core\View;
use App\Core\Request;

final class AuthController
{
    public function showLogin(Request $req, array $params): void
    {
        if (Auth::check()) Response::redirect('/dashboard');
        View::render('login', ['next' => $req->query['next'] ?? '/']);
    }

    public function showRegister(Request $req, array $params): void
    {
        if (Auth::check()) Response::redirect('/dashboard');
        View::render('register', []);
    }

    public function login(Request $req, array $params): void
    {
        if (!RateLimit::byIp('login', 10, 60)) {
            Response::json(['error' => 'Too many attempts, try later'], 429);
        }
        $email = strtolower(trim($req->post['email'] ?? ''));
        $password = $req->post['password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 1) {
            Response::json(['error' => 'Invalid credentials'], 400);
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !Auth::verifyPassword($password, $user['password_hash'])) {
            Response::json(['error' => 'Invalid credentials'], 401);
        }

        Auth::login((int)$user['id']);
        Response::json(['ok' => true, 'redirect' => $req->post['next'] ?? '/dashboard']);
    }

    public function register(Request $req, array $params): void
    {
        if (!RateLimit::byIp('register', 5, 3600)) {
            Response::json(['error' => 'Too many registrations, try later'], 429);
        }
        $email = strtolower(trim($req->post['email'] ?? ''));
        $password = $req->post['password'] ?? '';
        $password2 = $req->post['password2'] ?? '';
        $lang = $req->post['lang'] ?? 'en';
        $theme = $req->post['theme'] ?? 'dark';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['error' => 'Invalid email'], 400);
        }
        if (strlen($password) < 8) {
            Response::json(['error' => 'Password must be at least 8 characters'], 400);
        }
        if ($password !== $password2) {
            Response::json(['error' => 'Passwords do not match'], 400);
        }
        if (!in_array($lang, ['en','ru','ja','zh'])) $lang = 'en';
        if (!in_array($theme, ['light','dark'])) $theme = 'dark';

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            Response::json(['error' => 'Email already registered'], 409);
        }

        $hash = Auth::hashPassword($password);
        $stmt = $pdo->prepare('
            INSERT INTO users (email, password_hash, lang, theme, created_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$email, $hash, $lang, $theme, time()]);
        $userId = (int)$pdo->lastInsertId();

        // Initialize wallets for all supported coins
        foreach (['YTN', 'SUGAR', 'ADVC'] as $coin) {
            $pdo->prepare('INSERT OR IGNORE INTO user_wallets (user_id, coin, balance, locked) VALUES (?, ?, 0, 0)')
                ->execute([$userId, $coin]);
        }

        Auth::login($userId);

        // Send welcome email (async-safe: fire and forget)
        Mailer::sendWelcome($email, $lang);

        Response::json(['ok' => true, 'redirect' => '/dashboard']);
    }

    public function logout(Request $req, array $params): void
    {
        Auth::logout();
        Response::redirect('/');
    }

    public function updatePreferences(Request $req, array $params): void
    {
        if (!Auth::check()) Response::json(['error' => 'Unauthorized'], 401);
        $lang = $req->post['lang'] ?? null;
        $theme = $req->post['theme'] ?? null;
        $updates = [];
        if ($lang && in_array($lang, ['en','ru','ja','zh'])) $updates[] = $lang;
        if ($theme && in_array($theme, ['light','dark'])) $updates[] = $theme;

        if ($lang) {
            Database::pdo()->prepare('UPDATE users SET lang = ? WHERE id = ?')
                ->execute([$lang, Auth::id()]);
            setcookie('lang', $lang, ['expires' => time()+86400*30, 'path' => '/']);
        }
        if ($theme) {
            Database::pdo()->prepare('UPDATE users SET theme = ? WHERE id = ?')
                ->execute([$theme, Auth::id()]);
            setcookie('theme', $theme, ['expires' => time()+86400*30, 'path' => '/']);
        }
        Response::json(['ok' => true]);
    }
}
