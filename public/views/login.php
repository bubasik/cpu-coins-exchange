<?php /** @var array $data */ ?>
<div class="container" style="max-width: 400px; padding-top: 60px;">
    <div class="card">
        <h2 class="text-center" data-i18n="auth.login.title">Login</h2>
        <p class="text-center text-muted mb-3" data-i18n="auth.login.subtitle">Access your account</p>

        <form id="login-form" autocomplete="off">
            <div class="form-group">
                <label class="form-label" data-i18n="auth.email">Email</label>
                <input type="email" name="email" class="form-input" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label" data-i18n="auth.password">Password</label>
                <input type="password" name="password" class="form-input" required>
            </div>
            <input type="hidden" name="next" value="<?= htmlspecialchars($data['next'] ?? '/') ?>">
            <button type="submit" class="btn btn-primary btn-block" data-i18n="auth.login.submit">Login</button>
        </form>
        <p class="text-center mt-3">
            <a href="/register" data-i18n="auth.noAccount">Don't have an account? Register</a>
        </p>
    </div>
</div>

<script>
document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = new FormData(e.target);
    const body = Object.fromEntries(f);
    try {
        const r = await fetch('/auth/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(body)
        });
        const d = await r.json();
        if (d.ok) {
            window.location.href = d.redirect || '/dashboard';
        } else {
            window.app.toast(d.error || 'Error', 'error');
        }
    } catch (err) {
        window.app.toast(err.message, 'error');
    }
});
</script>
