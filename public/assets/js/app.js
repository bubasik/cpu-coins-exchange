// app.js — Theme + shared helpers

(function (window) {
    const THEME_KEY = 'ys_theme';

    function getTheme() {
        return localStorage.getItem(THEME_KEY) || 'dark';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem(THEME_KEY, theme);
        document.cookie = `theme=${theme}; path=/; max-age=${60*60*24*30}`;
        // Update icon
        const btn = document.getElementById('theme-toggle');
        if (btn) btn.textContent = theme === 'dark' ? '☀' : '☾';
        // Persist
        fetch('/auth/preferences', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `theme=${theme}`
        }).catch(() => {});
    }

    function toggleTheme() {
        const next = getTheme() === 'dark' ? 'light' : 'dark';
        applyTheme(next);
    }

    document.addEventListener('DOMContentLoaded', () => {
        applyTheme(getTheme());
        const btn = document.getElementById('theme-toggle');
        if (btn) btn.addEventListener('click', toggleTheme);

        const langSel = document.getElementById('lang-select');
        if (langSel) {
            langSel.value = window.i18n.getLang();
            langSel.addEventListener('change', (e) => window.i18n.setLang(e.target.value));
        }
    });

    // Toast notifications
    function toast(msg, type = 'info', timeout = 4000) {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.textContent = msg;
        container.appendChild(el);
        setTimeout(() => el.remove(), timeout);
    }

    // API helper
    async function api(url, options = {}) {
        const opts = {
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            ...options,
        };
        if (opts.body && typeof opts.body === 'object') opts.body = JSON.stringify(opts.body);
        const r = await fetch(url, opts);
        const data = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(data.error || `HTTP ${r.status}`);
        return data;
    }

    function copyText(text) {
        navigator.clipboard.writeText(text).then(() => {
            toast(window.i18n.t('common.copied'), 'success', 2000);
        });
    }

    function satToCoin(sat, decimals = 8) {
        const neg = sat < 0;
        sat = String(Math.abs(parseInt(sat)));
        while (sat.length <= decimals) sat = '0' + sat;
        const int = sat.slice(0, sat.length - decimals);
        const frac = sat.slice(sat.length - decimals).replace(/0+$/, '');
        const out = int + (frac ? '.' + frac : '');
        return neg ? '-' + out : out;
    }

    function coinToSat(coin, decimals = 8) {
        if (!/^\d+(\.\d+)?$/.test(coin)) return 0;
        const [int, frac = ''] = coin.split('.');
        const padded = (frac + '0'.repeat(decimals)).slice(0, decimals);
        return parseInt(int + padded);
    }

    function formatTime(ts) {
        if (!ts) return '-';
        const d = new Date(ts * 1000);
        return d.toLocaleString();
    }

    function formatShortHash(hash) {
        if (!hash || hash.length < 12) return hash || '-';
        return hash.slice(0, 6) + '...' + hash.slice(-6);
    }

    function pillForStatus(status) {
        const map = {
            pending: 'pill-pending', confirmed: 'pill-confirmed',
            sending: 'pill-pending', sent: 'pill-sent',
            completed: 'pill-completed', failed: 'pill-failed',
            open: 'pill-open', matching: 'pill-pending',
            partial: 'pill-pending', filled: 'pill-filled',
            cancelled: 'pill-cancelled',
        };
        return map[status] || 'pill-pending';
    }

    window.app = {
        getTheme, applyTheme, toggleTheme,
        toast, api, copyText,
        satToCoin, coinToSat, formatTime, formatShortHash, pillForStatus,
    };
})(window);
