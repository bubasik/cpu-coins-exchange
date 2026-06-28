// chart.js — Lightweight candlestick chart (Canvas, no dependencies)

(function (window) {
    class CandleChart {
        constructor(canvas, options = {}) {
            this.canvas = canvas;
            this.ctx = canvas.getContext('2d');
            this.options = {
                padding: { top: 10, right: 60, bottom: 20, left: 10 },
                candleWidth: 6,
                candleGap: 2,
                gridColor: 'rgba(128,128,128,0.2)',
                textColor: 'var(--text-muted)',
                upColor: 'var(--bid)',
                downColor: 'var(--ask)',
                ...options,
            };
            this.candles = [];
            this.visibleCount = 100;
            this.resize();
            window.addEventListener('resize', () => this.resize());
        }

        resize() {
            const dpr = window.devicePixelRatio || 1;
            const rect = this.canvas.getBoundingClientRect();
            this.canvas.width = rect.width * dpr;
            this.canvas.height = rect.height * dpr;
            this.ctx.scale(dpr, dpr);
            this.width = rect.width;
            this.height = rect.height;
            this.render();
        }

        setData(candles) {
            // candles: [{ts, open, high, low, close, volume}]
            this.candles = candles.map(c => ({
                ts: c.ts,
                o: parseInt(c.open),
                h: parseInt(c.high),
                l: parseInt(c.low),
                c: parseInt(c.close),
                v: parseInt(c.volume),
            }));
            this.render();
        }

        render() {
            const ctx = this.ctx;
            ctx.clearRect(0, 0, this.width, this.height);
            if (this.candles.length === 0) {
                ctx.fillStyle = 'rgba(128,128,128,0.6)';
                ctx.font = '14px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('No data', this.width / 2, this.height / 2);
                return;
            }

            const pad = this.options.padding;
            const plotW = this.width - pad.left - pad.right;
            const plotH = this.height - pad.top - pad.bottom;

            const visible = this.candles.slice(-this.visibleCount);
            const prices = visible.flatMap(c => [c.h, c.l]);
            const minP = Math.min(...prices);
            const maxP = Math.max(...prices);
            const range = maxP - minP || 1;
            const padR = range * 0.1;
            const yMin = minP - padR;
            const yMax = maxP + padR;

            const candleSpace = this.options.candleWidth + this.options.candleGap;
            const totalWidth = visible.length * candleSpace;
            const startX = pad.left + Math.max(0, (plotW - totalWidth) / 2);

            // Grid + price axis
            ctx.strokeStyle = this.options.gridColor;
            ctx.lineWidth = 1;
            ctx.font = '10px sans-serif';
            ctx.textAlign = 'left';
            ctx.fillStyle = 'rgba(128,128,128,0.8)';
            for (let i = 0; i <= 4; i++) {
                const y = pad.top + (plotH / 4) * i;
                const p = yMax - (yMax - yMin) * (i / 4);
                ctx.beginPath();
                ctx.moveTo(pad.left, y);
                ctx.lineTo(this.width - pad.right, y);
                ctx.stroke();
                ctx.fillText(this.formatPrice(p), this.width - pad.right + 4, y + 3);
            }

            // Candles
            const up = this.getCss('var(--bid)', '#22c55e');
            const down = this.getCss('var(--ask)', '#ef4444');

            visible.forEach((c, i) => {
                const x = startX + i * candleSpace + this.options.candleWidth / 2;
                const yOpen = pad.top + ((yMax - c.o) / (yMax - yMin)) * plotH;
                const yClose = pad.top + ((yMax - c.c) / (yMax - yMin)) * plotH;
                const yHigh = pad.top + ((yMax - c.h) / (yMax - yMin)) * plotH;
                const yLow = pad.top + ((yMax - c.l) / (yMax - yMin)) * plotH;
                const isUp = c.c >= c.o;
                const color = isUp ? up : down;

                ctx.strokeStyle = color;
                ctx.fillStyle = color;
                ctx.lineWidth = 1;

                // Wick
                ctx.beginPath();
                ctx.moveTo(x, yHigh);
                ctx.lineTo(x, yLow);
                ctx.stroke();

                // Body
                const bodyTop = Math.min(yOpen, yClose);
                const bodyH = Math.max(1, Math.abs(yClose - yOpen));
                ctx.fillRect(x - this.options.candleWidth / 2, bodyTop, this.options.candleWidth, bodyH);
            });

            // Last price line
            const last = visible[visible.length - 1];
            const lastY = pad.top + ((yMax - last.c) / (yMax - yMin)) * plotH;
            ctx.strokeStyle = last.c >= last.o ? up : down;
            ctx.setLineDash([4, 4]);
            ctx.beginPath();
            ctx.moveTo(pad.left, lastY);
            ctx.lineTo(this.width - pad.right, lastY);
            ctx.stroke();
            ctx.setLineDash([]);

            // Last price label
            ctx.fillStyle = last.c >= last.o ? up : down;
            ctx.fillRect(this.width - pad.right, lastY - 8, pad.right, 16);
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 11px sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText(this.formatPrice(last.c), this.width - pad.right + 4, lastY + 3);
        }

        formatPrice(sat) {
            const coin = parseFloat(window.app.satToCoin(sat));
            if (coin === 0) return '0';
            if (coin < 0.001) return coin.toExponential(2);
            return coin.toFixed(4);
        }

        getCss(varName, fallback) {
            const v = getComputedStyle(document.documentElement).getPropertyValue(varName.replace('var(', '').replace(')', '').trim());
            return v.trim() || fallback;
        }
    }

    window.CandleChart = CandleChart;
})(window);
