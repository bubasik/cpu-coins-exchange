// exchange.js — Instant Exchange page logic

(function (window) {
    document.addEventListener('DOMContentLoaded', () => {
        const fromSel = document.getElementById('from-coin');
        const toSel = document.getElementById('to-coin');
        const fromAmount = document.getElementById('from-amount');
        const toAmount = document.getElementById('to-amount');
        const rateEl = document.getElementById('rate-display');
        const payoutAddr = document.getElementById('payout-address');
        const submitBtn = document.getElementById('submit-exchange');
        const switchBtn = document.getElementById('switch-coins');
        const resultPanel = document.getElementById('order-result');
        const findForm = document.getElementById('find-order-form');

        if (!fromSel) return; // not on exchange page

        async function updateEstimate() {
            const from = fromSel.value;
            const to = toSel.value;
            const amount = fromAmount.value;
            if (!amount || parseFloat(amount) <= 0) {
                toAmount.value = '';
                rateEl.textContent = '-';
                return;
            }
            try {
                const r = await window.app.api(`/api/exchange/estimate?from=${from}&to=${to}&amount=${amount}`);
                toAmount.value = parseFloat(r.estimated_output).toFixed(8);
                rateEl.textContent = `1 ${from} = ${parseFloat(r.rate).toFixed(4)} ${to} (fee ${r.fee_percent}%)`;
            } catch (e) {
                /* ignore */
            }
        }

        function doSwitch() {
            const tmp = fromSel.value;
            fromSel.value = toSel.value;
            toSel.value = tmp;
            updateEstimate();
        }

        async function submit() {
            const from = fromSel.value;
            const to = toSel.value;
            const amount = fromAmount.value;
            const payout = payoutAddr.value.trim();
            if (!amount || parseFloat(amount) <= 0) {
                window.app.toast(window.i18n.t('common.error') + ': amount', 'error');
                return;
            }
            if (!payout) {
                window.app.toast(window.i18n.t('common.error') + ': address', 'error');
                return;
            }
            submitBtn.disabled = true;
            submitBtn.textContent = window.i18n.t('common.loading');
            try {
                const r = await window.app.api('/api/exchange/create', {
                    method: 'POST',
                    body: {
                        from_coin: from,
                        to_coin: to,
                        from_amount: amount,
                        payout_address: payout,
                    },
                });
                showOrderResult(r.order);
            } catch (e) {
                window.app.toast(e.message, 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = window.i18n.t('exchange.create');
            }
        }

        function showOrderResult(order) {
            if (!resultPanel) return;
            resultPanel.classList.remove('hidden');

            // result-deposit-addr is an <input readonly>, use .value not .textContent
            const addrInput = document.getElementById('result-deposit-addr');
            if (addrInput) {
                addrInput.value = order.deposit_address || '';
            }

            // Other elements are <span>, use .textContent
            const setText = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.textContent = val ?? '';
            };
            setText('result-from-coin', order.from_coin);
            setText('result-to-coin', order.to_coin);
            setText('result-from-amount', order.from_amount);
            setText('result-to-amount', order.to_amount);
            setText('result-rate', order.rate);
            setText('result-status', order.status);

            const statusEl = document.getElementById('result-status');
            if (statusEl) statusEl.className = 'pill ' + window.app.pillForStatus(order.status);

            resultPanel.scrollIntoView({ behavior: 'smooth' });

            // Wire up copy button
            const copyBtn = document.getElementById('copy-deposit-addr');
            if (copyBtn) {
                copyBtn.onclick = () => {
                    if (addrInput && addrInput.value) {
                        window.app.copyText(addrInput.value);
                    }
                };
            }

            // Start polling status
            const ref = order.ref || order.id;
            pollStatus(ref);
        }

        let pollTimer = null;
        function pollStatus(ref) {
            if (pollTimer) clearInterval(pollTimer);
            pollTimer = setInterval(async () => {
                try {
                    const r = await window.app.api(`/api/exchange/status/${ref}`);
                    const order = r.order;
                    if (!order) return;
                    const statusEl = document.getElementById('result-status');
                    if (statusEl) {
                        statusEl.textContent = order.status;
                        statusEl.className = 'pill ' + window.app.pillForStatus(order.status);
                    }
                    if (order.deposit_txid) {
                        const txEl = document.getElementById('result-deposit-tx');
                        if (txEl) {
                            txEl.textContent = window.app.formatShortHash(order.deposit_txid);
                            txEl.title = order.deposit_txid;
                        }
                    }
                    if (order.payout_txid) {
                        const txEl = document.getElementById('result-payout-tx');
                        if (txEl) {
                            txEl.textContent = window.app.formatShortHash(order.payout_txid);
                            txEl.title = order.payout_txid;
                        }
                    }
                    const confEl = document.getElementById('result-confirmations');
                    if (confEl) confEl.textContent = order.confirmations || 0;

                    if (order.status === 'completed' || order.status === 'failed') {
                        clearInterval(pollTimer);
                    }
                } catch (e) { /* ignore */ }
            }, 15000);
        }

        fromSel.addEventListener('change', updateEstimate);
        toSel.addEventListener('change', updateEstimate);
        fromAmount.addEventListener('input', updateEstimate);
        if (switchBtn) switchBtn.addEventListener('click', doSwitch);
        if (submitBtn) submitBtn.addEventListener('click', submit);

        if (findForm) {
            findForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const ref = document.getElementById('find-ref').value.trim();
                if (!ref) return;
                try {
                    const r = await window.app.api(`/api/exchange/status/${ref}`);
                    showOrderResult(r.order);
                } catch (e) {
                    window.app.toast(e.message, 'error');
                }
            });
        }
    });
})(window);
