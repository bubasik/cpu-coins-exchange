<?php
/** @var array $data */
use App\Wallet\HdWallet;
$rate = $data['rate'] ?? '100';
$recent = $data['recentSwaps'] ?? [];
?>
<div class="card mb-3">
    <h2 data-i18n="exchange.title">Instant Exchange</h2>
    <p class="text-muted" data-i18n="exchange.subtitle">Swap YTN &lt;-&gt; SUGAR quickly</p>

    <div class="grid grid-2 mt-3">
        <div>
            <div class="form-group">
                <label class="form-label" data-i18n="exchange.from">You Send</label>
                <div class="input-group">
                    <input type="number" step="0.00000001" min="0" class="form-input" id="from-amount" placeholder="0.00">
                    <select class="input-suffix" id="from-coin">
                        <option value="YTN">YTN</option>
                        <option value="SUGAR">SUGAR</option>
                        <option value="ADVC">ADVC</option>
                    </select>
                </div>
            </div>

            <div class="text-center mb-2">
                <button class="icon-btn" id="switch-coins" title="Switch">⇅</button>
            </div>

            <div class="form-group">
                <label class="form-label" data-i18n="exchange.to">You Receive</label>
                <div class="input-group">
                    <input type="text" class="form-input" id="to-amount" readonly placeholder="0.00">
                    <select class="input-suffix" id="to-coin">
                        <option value="SUGAR">SUGAR</option>
                        <option value="YTN">YTN</option>
                        <option value="ADVC">ADVC</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" data-i18n="exchange.payoutAddress">Payout Address</label>
                <input type="text" class="form-input" id="payout-address" placeholder="...">
            </div>

            <div class="mb-2 text-muted">
                <span data-i18n="exchange.rate">Rate</span>:
                <span id="rate-display" class="mono">-</span>
            </div>

            <button class="btn btn-primary btn-block" id="submit-exchange" data-i18n="exchange.create">Create Swap Order</button>

            <div class="mt-3" style="padding: 10px 14px; background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); border-radius: 6px; font-size: 12px; color: var(--warning);">
                <span data-i18n="exchange.expiryWarning">⚠ The order will be automatically deleted if the deposit address is not funded within 4 hours.</span>
            </div>
        </div>

        <div>
            <div class="form-group">
                <label class="form-label" data-i18n="exchange.findOrder">Find Order</label>
                <form id="find-order-form" class="input-group">
                    <input type="text" class="form-input" id="find-ref" placeholder="SW...">
                    <button type="submit" class="btn btn-secondary" data-i18n="exchange.find">Find</button>
                </form>
            </div>

            <div class="card mt-3">
                <h3 class="card-title" data-i18n="exchange.recentSwaps">Recent Swaps</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th data-i18n="common.amount">Amount</th>
                            <th data-i18n="common.status">Status</th>
                            <th data-i18n="common.time">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent)): ?>
                            <tr><td colspan="3" class="text-muted text-center" data-i18n="dashboard.noRecords">No records</td></tr>
                        <?php else: foreach ($recent as $s): ?>
                            <tr>
                                <td class="mono"><?= HdWallet::satToCoin((int)$s['from_amount_sat']) ?> <?= $s['from_coin'] ?> → <?= HdWallet::satToCoin((int)$s['to_amount_sat']) ?> <?= $s['to_coin'] ?></td>
                                <td><span class="pill <?= $s['status'] === 'completed' ? 'pill-completed' : ($s['status'] === 'sent' ? 'pill-sent' : 'pill-pending') ?>"><?= $s['status'] ?></span></td>
                                <td class="text-muted"><?= date('Y-m-d H:i', (int)$s['created_at']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card hidden" id="order-result">
    <h3 data-i18n="exchange.created.title">Order Created</h3>

    <!-- Order reference (SW code) -->
    <div class="mb-3" style="padding: 12px; background: var(--bg-elev-2); border-radius: 6px;">
        <p class="text-muted" style="font-size: 12px; margin-bottom: 4px;" data-i18n="exchange.orderRef">Order Reference</p>
        <p class="mono" style="font-size: 18px; font-weight: 700; color: var(--primary);">
            <span id="result-ref">-</span>
        </p>
        <p class="text-muted" style="font-size: 11px; margin-top: 4px;">
            <span data-i18n="exchange.findOrderHint">Save this code to check status later</span>
        </p>
    </div>

    <div class="mt-2">
        <p id="result-deposit-instruction">Send your coins to:</p>
        <div class="input-group mt-2 mb-2">
            <input type="text" class="form-input mono" id="result-deposit-addr" readonly>
            <button type="button" class="btn btn-secondary copy-btn" id="copy-deposit-addr" data-i18n="common.copy">Copy</button>
        </div>

        <!-- Amount to send (prominent) -->
        <div class="mb-3" style="padding: 12px; background: var(--bg-elev-2); border-radius: 6px; border-left: 3px solid var(--primary);">
            <p class="text-muted" style="font-size: 12px; margin-bottom: 4px;" data-i18n="exchange.amountToSend">Amount to Send</p>
            <p class="mono" style="font-size: 20px; font-weight: 700;">
                <span id="result-send-amount">-</span>
                <span id="result-send-coin" style="color: var(--primary);"></span>
            </p>
        </div>

        <div class="grid grid-2 mt-3">
            <div>
                <p class="text-muted" data-i18n="exchange.youReceive">You Receive</p>
                <p class="mono"><span id="result-to-amount"></span> <span id="result-to-coin"></span></p>
            </div>
            <div>
                <p class="text-muted" data-i18n="exchange.rate">Rate</p>
                <p class="mono" id="result-rate"></p>
            </div>
        </div>
        <div class="grid grid-3 mt-3">
            <div>
                <p class="text-muted" data-i18n="common.status">Status</p>
                <p><span class="pill" id="result-status">pending</span></p>
            </div>
            <div>
                <p class="text-muted" data-i18n="common.confirmations">Confirmations</p>
                <p class="mono" id="result-confirmations">0</p>
            </div>
            <div>
                <p class="text-muted" data-i18n="common.txid">TxID</p>
                <p class="mono" id="result-deposit-tx">-</p>
                <p class="mono text-muted" id="result-payout-tx">-</p>
            </div>
        </div>

        <!-- Expiry warning (only for pending status) -->
        <div id="result-expiry-warning" class="mt-3" style="padding: 10px 14px; background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.3); border-radius: 6px; font-size: 12px; color: var(--warning);">
            <span data-i18n="exchange.expiryWarning">⚠ The order will be automatically deleted if the deposit address is not funded within 4 hours.</span>
        </div>
    </div>
</div>

<?php $data['scripts'] = ['/assets/js/exchange.js']; ?>
