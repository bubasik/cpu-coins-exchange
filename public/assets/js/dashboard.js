// dashboard.js — User dashboard logic

(function (window) {
    document.addEventListener('DOMContentLoaded', () => {
        if (!document.getElementById('dashboard-page')) return;

        // Copy deposit address buttons
        document.querySelectorAll('.copy-deposit').forEach(btn => {
            btn.addEventListener('click', () => {
                const addr = btn.dataset.address;
                window.app.copyText(addr);
            });
        });

        // Withdraw form
        document.querySelectorAll('.withdraw-form').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const coin = form.dataset.coin;
                const addressInput = form.querySelector('.withdraw-address');
                const amountInput = form.querySelector('.withdraw-amount');
                const address = addressInput.value.trim();
                const amount = amountInput.value.trim();
                if (!address || !amount) return;
                try {
                    await window.app.api('/api/wallet/withdraw', {
                        method: 'POST',
                        body: { coin, address, amount }
                    });
                    window.app.toast(window.i18n.t('common.success'), 'success');
                    addressInput.value = '';
                    amountInput.value = '';
                    setTimeout(() => location.reload(), 1500);
                } catch (e) {
                    window.app.toast(e.message, 'error');
                }
            });
        });
    });
})(window);
