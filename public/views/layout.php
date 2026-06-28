<?php
/** @var array $data */
use App\Core\Auth;
$user = $data['user'] ?? null;
$lang = $data['lang'] ?? 'en';
$theme = $data['theme'] ?? 'dark';
$currentPage = $data['currentPage'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($data['title'] ?? 'Yenten-Sugar Exchange') ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=1">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
</head>
<body>
    <header class="header">
        <a href="/" class="logo">
            <span class="badge">YS</span>
            <span data-i18n="app.name">Yenten-Sugar Exchange</span>
        </a>
        <nav>
            <a href="/" class="<?= $currentPage === 'home' ? 'active' : '' ?>" data-i18n="nav.home">Home</a>
            <a href="/exchange" class="<?= $currentPage === 'exchange' ? 'active' : '' ?>" data-i18n="nav.exchange">Exchange</a>
            <a href="/trade" class="<?= $currentPage === 'trade' ? 'active' : '' ?>" data-i18n="nav.trade">Trade</a>
            <?php if ($user): ?>
                <a href="/dashboard" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>" data-i18n="nav.dashboard">Dashboard</a>
            <?php endif; ?>
            <a href="/status" class="<?= $currentPage === 'status' ? 'active' : '' ?>" data-i18n="nav.status">Status</a>
        </nav>
        <div class="actions">
            <select class="lang-select" id="lang-select" title="Language">
                <option value="en">English</option>
                <option value="ru">Русский</option>
                <option value="ja">日本語</option>
                <option value="zh">中文</option>
            </select>
            <button class="icon-btn" id="theme-toggle" title="Toggle theme" data-i18n-title="theme.toggle">☾</button>
            <?php if ($user): ?>
                <form method="post" action="/auth/logout" style="display:inline">
                    <button type="submit" class="btn btn-secondary" data-i18n="nav.logout">Logout</button>
                </form>
            <?php else: ?>
                <a href="/login" class="btn btn-secondary" data-i18n="nav.login">Login</a>
                <a href="/register" class="btn btn-primary" data-i18n="nav.register">Register</a>
            <?php endif; ?>
        </div>
    </header>

    <main class="container">
        <?= $content ?? '' ?>
    </main>

    <footer class="footer">
        <p data-i18n="footer.disclaimer">This is open-source software. Use at your own risk.</p>
        <p class="mt-2">&copy; <?= date('Y') ?> Yenten-Sugar Exchange &middot; YTN &harr; SUGAR</p>
    </footer>

    <script src="/assets/js/i18n.js"></script>
    <script src="/assets/js/app.js"></script>
    <?php if (!empty($data['scripts'])): foreach ($data['scripts'] as $s): ?>
        <script src="<?= htmlspecialchars($s) ?>"></script>
    <?php endforeach; endif; ?>
</body>
</html>
