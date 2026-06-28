<?php /** @var array $data */ ?>
<div class="container" style="max-width: 420px; padding-top: 40px;">
    <div class="card">
        <h2 class="text-center" data-i18n="auth.register.title">Create Account</h2>
        <p class="text-center text-muted mb-3" data-i18n="auth.register.subtitle">Join in less than a minute</p>

        <form id="register-form" autocomplete="off">
            <div class="form-group">
                <label class="form-label" data-i18n="auth.email">Email</label>
                <input type="email" name="email" class="form-input" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label" data-i18n="auth.password">Password</label>
                <input type="password" name="password" class="form-input" minlength="8" required>
            </div>
            <div class="form-group">
                <label class="form-label" data-i18n="auth.passwordConfirm">Confirm Password</label>
                <input type="password" name="password2" class="form-input" minlength="8" required>
            </div>
            <div class="grid grid-2">
                <div class="form-group">
                    <label class="form-label" data-i18n="lang.select">Language</label>
                    <select name="lang" class="form-select">
                        <option value="en">English</option>
                        <option value="ru">Русский</option>
                        <option value="ja">日本語</option>
                        <option value="zh">中文</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" data-i18n="theme.toggle">Theme</label>
                    <select name="theme" class="form-select">
                        <option value="dark">Dark</option>
                        <option value="light">Light</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block" data-i18n="auth.register.submit">Register</button>
        </form>
        <p class="text-center mt-3">
            <a href="/login" data-i18n="auth.haveAccount">Already have an account? Login</a>
        </p>
    </div>
</div>

<script>
document.getElementById('register-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = new FormData(e.target);
    const body = Object.fromEntries(f);
    try {
        const r = await fetch('/auth/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(body)
        });
        const d = await r.json();
        if (d.ok) {
            window.app.toast('Welcome!', 'success');
            setTimeout(() => window.location.href = d.redirect || '/dashboard', 1000);
        } else {
            window.app.toast(d.error || 'Error', 'error');
        }
    } catch (err) {
        window.app.toast(err.message, 'error');
    }
});
</script>
