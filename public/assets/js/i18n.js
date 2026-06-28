// i18n.js — Lightweight i18n for the Yenten-Sugar Exchange frontend

(function (window) {
    const SUPPORTED = ['en', 'ru', 'ja', 'zh'];
    const STORAGE_KEY = 'ys_lang';

    let currentLang = localStorage.getItem(STORAGE_KEY) || detectBrowserLang();
    if (!SUPPORTED.includes(currentLang)) currentLang = 'en';

    let cache = {};

    async function loadLang(lang) {
        if (cache[lang]) return cache[lang];
        try {
            const r = await fetch(`/assets/locales/${lang}.json`, { cache: 'no-cache' });
            cache[lang] = await r.json();
            return cache[lang];
        } catch (e) {
            console.error('Failed to load lang', lang, e);
            return {};
        }
    }

    function t(key, params) {
        const dict = cache[currentLang] || {};
        let s = dict[key] || key;
        if (params) {
            for (const [k, v] of Object.entries(params)) {
                s = s.replace(new RegExp('\\{' + k + '\\}', 'g'), v);
            }
        }
        return s;
    }

    function applyTranslations() {
        document.querySelectorAll('[data-i18n]').forEach(el => {
            const key = el.getAttribute('data-i18n');
            el.textContent = t(key);
        });
        document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
            const key = el.getAttribute('data-i18n-placeholder');
            el.placeholder = t(key);
        });
        document.documentElement.lang = currentLang;
    }

    function detectBrowserLang() {
        const nav = navigator.language || navigator.userLanguage || 'en';
        const short = nav.split('-')[0].toLowerCase();
        return SUPPORTED.includes(short) ? short : 'en';
    }

    async function setLang(lang) {
        if (!SUPPORTED.includes(lang)) return;
        currentLang = lang;
        localStorage.setItem(STORAGE_KEY, lang);
        document.cookie = `lang=${lang}; path=/; max-age=${60*60*24*30}`;
        await loadLang(lang);
        applyTranslations();
        // Notify other components
        window.dispatchEvent(new CustomEvent('langchange', { detail: { lang } }));
        // Persist to server if logged in
        fetch('/auth/preferences', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `lang=${lang}`
        }).catch(() => {});
    }

    function getLang() { return currentLang; }

    window.i18n = { t, setLang, getLang, loadLang, applyTranslations, SUPPORTED };

    // Initial load
    document.addEventListener('DOMContentLoaded', async () => {
        await loadLang(currentLang);
        applyTranslations();
    });
})(window);
