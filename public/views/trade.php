<?php
/** @var array $data */
use App\Wallet\HdWallet;
$user = $data['user'] ?? null;
$pairs = $data['pairs'] ?? ['YTN/SUGAR' => 'YTN / SUGAR'];
$currentPair = $data['currentPair'] ?? 'YTN/SUGAR';
?>
<div class="card mb-3" style="padding: 12px 20px;">
    <div style="display: flex; gap: 12px; align-items: center;">
        <label class="text-muted" style="font-size: 13px;" data-i18n="trade.selectPair">Pair:</label>
        <select id="pair-select" class="form-select" style="width: auto; min-width: 200px;">
            <?php foreach ($pairs as $key => $label): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= $key === $currentPair ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="trade-layout">
    <!-- Chart -->
    <div class="card trade-chart">
        <div class="mb-2" style="display: flex; justify-content: space-between; align-items: center;">
            <strong id="current-pair-label"><?= htmlspecialchars($currentPair) ?></strong>
            <span class="text-muted" data-i18n="trade.chart">Price Chart</span>
        </div>
        <canvas id="trade-chart" class="chart-canvas"></canvas>
    </div>

    <!-- Order Form -->
    <div class="card trade-orderform">
        <div class="tabs">
            <div class="tab tab-side active" data-side="buy" data-i18n="trade.side.buy">Buy</div>
            <div class="tab tab-side" data-side="sell" data-i18n="trade.side.sell">Sell</div>
        </div>
        <div class="tabs">
            <div class="tab tab-type active" data-type="limit" data-i18n="trade.type.limit">Limit</div>
            <div class="tab tab-type" data-type="market" data-i18n="trade.type.market">Market</div>
        </div>

        <?php if (!$user): ?>
            <p class="text-muted text-center" style="padding: 20px;" data-i18n="trade.loginRequired">Login required to place orders</p>
            <a href="/login?next=/trade" class="btn btn-primary btn-block" data-i18n="nav.login">Login</a>
        <?php else: ?>
            <div class="form-group" id="price-group">
                <label class="form-label" data-i18n="trade.price">Price</label>
                <div class="input-group">
                    <input type="number" step="0.0001" min="0" class="form-input" id="order-price" placeholder="0.00">
                    <span class="input-suffix">SUGAR</span>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" id="amount-label" data-i18n="trade.amount">Amount (YTN)</label>
                <div class="input-group">
                    <input type="number" step="0.00000001" min="0" class="form-input" id="order-amount" placeholder="0.00">
                    <span class="input-suffix">YTN</span>
                </div>
            </div>
            <div class="form-group" id="total-group">
                <label class="form-label" data-i18n="trade.total">Total (SUGAR)</label>
                <div class="input-group">
                    <input type="text" class="form-input" id="order-total" readonly placeholder="0.00">
                    <span class="input-suffix">SUGAR</span>
                </div>
            </div>
            <button class="btn btn-block btn-buy" id="submit-order">
                <span data-i18n="trade.side.buy">Buy</span> YTN
            </button>
        <?php endif; ?>
    </div>

    <!-- Order Book -->
    <div class="card trade-orderbook">
        <h3 class="card-title" data-i18n="trade.orderbook">Order Book</h3>
        <div class="orderbook" style="border-top: 1px solid var(--border); padding-top: 6px;">
            <div class="orderbook-row" style="font-weight: 600; color: var(--text-muted); border-bottom: 1px solid var(--border); padding-bottom: 4px;">
                <span data-i18n="trade.orderbook.price">Price</span>
                <span data-i18n="trade.orderbook.amount">Amount</span>
                <span data-i18n="trade.orderbook.total">Total</span>
            </div>
            <div id="ob-asks"></div>
            <div class="orderbook-spread" id="ob-spread">Spread: -</div>
            <div id="ob-bids"></div>
        </div>
    </div>

    <!-- Trade History -->
    <div class="card trade-history">
        <h3 class="card-title" data-i18n="trade.history">Trade History</h3>
        <table class="table">
            <thead>
                <tr>
                    <th data-i18n="trade.history.price">Price</th>
                    <th data-i18n="trade.history.amount">Amount</th>
                    <th data-i18n="trade.history.time">Time</th>
                </tr>
            </thead>
            <tbody id="trade-history-list"></tbody>
        </table>
    </div>
</div>

<?php if ($user): ?>
<div class="card mt-3">
    <h3 class="card-title" data-i18n="trade.myOrders">My Orders</h3>
    <table class="table">
        <thead>
            <tr>
                <th data-i18n="trade.side.buy">Side</th>
                <th data-i18n="trade.type.limit">Type</th>
                <th data-i18n="trade.price">Price</th>
                <th data-i18n="trade.amount">Amount</th>
                <th>Filled</th>
                <th data-i18n="common.status">Status</th>
            </tr>
        </thead>
        <tbody id="my-orders-list"></tbody>
    </table>
</div>
<?php endif; ?>

<?php $data['scripts'] = ['/assets/js/chart.js', '/assets/js/trade.js']; ?>
