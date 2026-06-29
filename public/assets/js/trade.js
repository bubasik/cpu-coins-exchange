// trade.js — Trading page logic

(function (window) {
    document.addEventListener('DOMContentLoaded', () => {
        const chartCanvas = document.getElementById('trade-chart');
        if (!chartCanvas) return; // not on trade page

        const chart = new window.CandleChart(chartCanvas);
        let currentSide = 'buy';
        let currentType = 'limit';
        let currentPair = document.getElementById('pair-select')?.value || 'YTN/SUGAR';

        // Pair switcher
        const pairSelect = document.getElementById('pair-select');
        if (pairSelect) {
            pairSelect.addEventListener('change', () => {
                currentPair = pairSelect.value;
                const labelEl = document.getElementById('current-pair-label');
                if (labelEl) labelEl.textContent = currentPair;
                updateForm();      // ← update labels/suffixes for new pair
                refreshAll();      // ← refresh orderbook, trades, chart, my-orders
            });
        }

        // Tab switching
        document.querySelectorAll('.tab-side').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab-side').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                currentSide = tab.dataset.side;
                updateForm();
            });
        });
        document.querySelectorAll('.tab-type').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab-type').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                currentType = tab.dataset.type;
                updateForm();
            });
        });

        function updateForm() {
            const priceGroup = document.getElementById('price-group');
            const totalGroup = document.getElementById('total-group');
            const amountLabel = document.getElementById('amount-label');
            const amountSuffix = document.querySelector('#order-amount').closest('.input-group')?.querySelector('.input-suffix');
            const totalSuffix = document.querySelector('#order-total')?.closest('.input-group')?.querySelector('.input-suffix');
            const priceSuffix = document.querySelector('#order-price')?.closest('.input-group')?.querySelector('.input-suffix');
            const submitBtn = document.getElementById('submit-order');

            // Parse pair: "YTN/SUGAR" → base=YTN, quote=SUGAR
            const [base, quote] = currentPair.split('/');

            if (currentType === 'market') {
                if (priceGroup) priceGroup.classList.add('hidden');
                if (totalGroup) totalGroup.classList.add('hidden');
                // Market BUY: pay quote, receive base → input is quote amount
                // Market SELL: pay base, receive quote → input is base amount
                if (amountLabel) {
                    if (currentSide === 'buy') {
                        amountLabel.textContent = window.i18n.t('trade.total') + ' (' + quote + ')';
                    } else {
                        amountLabel.textContent = window.i18n.t('trade.amount') + ' (' + base + ')';
                    }
                }
            } else {
                if (priceGroup) priceGroup.classList.remove('hidden');
                if (totalGroup) totalGroup.classList.remove('hidden');
                if (amountLabel) amountLabel.textContent = window.i18n.t('trade.amount') + ' (' + base + ')';
            }

            // Update suffix labels (the small grey text inside input)
            if (amountSuffix) amountSuffix.textContent = currentSide === 'buy' && currentType === 'market' ? quote : base;
            if (totalSuffix) totalSuffix.textContent = quote;
            if (priceSuffix) priceSuffix.textContent = quote;

            // Update submit button
            if (submitBtn) {
                submitBtn.className = 'btn btn-block ' + (currentSide === 'buy' ? 'btn-buy' : 'btn-sell');
                const action = currentSide === 'buy' ? window.i18n.t('trade.side.buy') : window.i18n.t('trade.side.sell');
                submitBtn.textContent = action + ' ' + base;
            }
        }

        // Price/amount/total sync
        const priceInput = document.getElementById('order-price');
        const amountInput = document.getElementById('order-amount');
        const totalInput = document.getElementById('order-total');
        if (amountInput && priceInput && totalInput) {
            amountInput.addEventListener('input', () => {
                if (currentType === 'limit') {
                    const p = parseFloat(priceInput.value) || 0;
                    const a = parseFloat(amountInput.value) || 0;
                    totalInput.value = (p * a).toFixed(8);
                }
            });
            priceInput.addEventListener('input', () => {
                const p = parseFloat(priceInput.value) || 0;
                const a = parseFloat(amountInput.value) || 0;
                totalInput.value = (p * a).toFixed(8);
            });
        }

        // Submit order
        document.getElementById('submit-order')?.addEventListener('click', async () => {
            const amount = amountInput?.value;
            const price = priceInput?.value;
            if (!amount) {
                window.app.toast(window.i18n.t('common.error'), 'error');
                return;
            }
            try {
                const body = {
                    pair: currentPair,
                    side: currentSide,
                    type: currentType,
                    amount: currentSide === 'buy' && currentType === 'market' ? totalInput.value : amount,
                };
                if (currentType === 'limit') body.price = price;
                await window.app.api('/api/trade/order', { method: 'POST', body });
                window.app.toast(window.i18n.t('common.success'), 'success');
                amountInput.value = '';
                if (totalInput) totalInput.value = '';
                refreshAll();
            } catch (e) {
                window.app.toast(e.message, 'error');
            }
        });

        // === Data refresh ===
        async function refreshOrderBook() {
            try {
                const r = await window.app.api(`/api/trade/orderbook?pair=${encodeURIComponent(currentPair)}&depth=15`);
                renderOrderBook(r);
            } catch (e) { /* ignore */ }
        }

        async function refreshTrades() {
            try {
                const r = await window.app.api(`/api/trade/trades?pair=${encodeURIComponent(currentPair)}&limit=20`);
                renderTrades(r);
            } catch (e) { /* ignore */ }
        }

        async function refreshChart() {
            try {
                const r = await window.app.api(`/api/trade/chart?pair=${encodeURIComponent(currentPair)}&interval=1m&limit=100`);
                chart.setData(r);
            } catch (e) { /* ignore */ }
        }

        async function refreshMyOrders() {
            const el = document.getElementById('my-orders-list');
            if (!el) return;
            try {
                const r = await window.app.api(`/api/trade/my-orders?pair=${encodeURIComponent(currentPair)}`);
                renderMyOrders(r);
            } catch (e) { /* ignore - not logged in */ }
        }

        function renderOrderBook(data) {
            const asksEl = document.getElementById('ob-asks');
            const bidsEl = document.getElementById('ob-bids');
            const spreadEl = document.getElementById('ob-spread');
            if (!asksEl || !bidsEl) return;

            const formatPrice = (sat) => parseFloat(window.app.satToCoin(sat)).toFixed(4);
            const formatAmt = (sat) => parseFloat(window.app.satToCoin(sat)).toFixed(4);

            let maxTotal = 0;
            const asksWithTotal = [];
            let acc = 0;
            (data.asks || []).forEach(a => { acc += a.amount; asksWithTotal.push({...a, total: acc}); maxTotal = Math.max(maxTotal, acc); });
            const bidsWithTotal = [];
            acc = 0;
            (data.bids || []).forEach(b => { acc += b.amount; bidsWithTotal.push({...b, total: acc}); maxTotal = Math.max(maxTotal, acc); });

            asksEl.innerHTML = asksWithTotal.slice(0, 12).reverse().map(a => `
                <div class="orderbook-row ask">
                    <div class="bar" style="width: ${(a.total/maxTotal)*100}%"></div>
                    <span class="price">${formatPrice(a.price)}</span>
                    <span>${formatAmt(a.amount)}</span>
                    <span>${formatAmt(a.total)}</span>
                </div>
            `).join('');
            bidsEl.innerHTML = bidsWithTotal.slice(0, 12).map(b => `
                <div class="orderbook-row bid">
                    <div class="bar" style="width: ${(b.total/maxTotal)*100}%"></div>
                    <span class="price">${formatPrice(b.price)}</span>
                    <span>${formatAmt(b.amount)}</span>
                    <span>${formatAmt(b.total)}</span>
                </div>
            `).join('');

            if (spreadEl && data.asks.length && data.bids.length) {
                const bestAsk = data.asks[0].price;
                const bestBid = data.bids[0].price;
                const spread = bestAsk - bestBid;
                spreadEl.textContent = `Spread: ${formatPrice(spread)} SUGAR`;
            }
        }

        function renderTrades(trades) {
            const el = document.getElementById('trade-history-list');
            if (!el) return;
            el.innerHTML = trades.map(t => `
                <tr>
                    <td class="${t.side === 'buy' ? 'text-success' : 'text-danger'}">
                        ${parseFloat(window.app.satToCoin(t.price_sat)).toFixed(4)}
                    </td>
                    <td>${parseFloat(window.app.satToCoin(t.amount_sat)).toFixed(4)}</td>
                    <td class="text-muted">${window.app.formatTime(t.created_at)}</td>
                </tr>
            `).join('');
        }

        function renderMyOrders(orders) {
            const el = document.getElementById('my-orders-list');
            if (!el) return;
            if (orders.length === 0) {
                el.innerHTML = `<tr><td colspan="6" class="text-center text-muted">${window.i18n.t('dashboard.noRecords')}</td></tr>`;
                return;
            }
            el.innerHTML = orders.map(o => `
                <tr>
                    <td><span class="pill ${o.side === 'buy' ? 'pill-open' : 'pill-cancelled'}">${o.side}</span></td>
                    <td>${o.type}</td>
                    <td>${parseFloat(window.app.satToCoin(o.price_sat)).toFixed(4)}</td>
                    <td>${parseFloat(window.app.satToCoin(o.amount_sat)).toFixed(4)}</td>
                    <td>${parseFloat(window.app.satToCoin(o.filled_sat)).toFixed(4)}</td>
                    <td>
                        <span class="pill ${window.app.pillForStatus(o.status)}">${o.status}</span>
                        ${o.status === 'open' ? `<button class="copy-btn cancel-order" data-id="${o.id}">${window.i18n.t('trade.cancel')}</button>` : ''}
                    </td>
                </tr>
            `).join('');
            el.querySelectorAll('.cancel-order').forEach(b => {
                b.addEventListener('click', async () => {
                    const id = b.dataset.id;
                    try {
                        await window.app.api(`/api/trade/order/${id}/cancel`, { method: 'POST' });
                        window.app.toast(window.i18n.t('common.success'), 'success');
                        refreshAll();
                    } catch (e) {
                        window.app.toast(e.message, 'error');
                    }
                });
            });
        }

        function refreshAll() {
            refreshOrderBook();
            refreshTrades();
            refreshChart();
            refreshMyOrders();
        }

        // Initialize form labels for current pair on page load
        updateForm();
        refreshAll();
        setInterval(refreshOrderBook, 2000);
        setInterval(refreshTrades, 5000);
        setInterval(refreshChart, 15000);
        setInterval(refreshMyOrders, 10000);
    });
})(window);
