<?php
/** @var array $data */
use App\Wallet\HdWallet;
$wallets = $data['wallets'] ?? [];
$depositAddresses = $data['depositAddresses'] ?? [];
$deposits = $data['deposits'] ?? [];
$withdrawals = $data['withdrawals'] ?? [];
$userOrders = $data['userOrders'] ?? [];
$userTrades = $data['userTrades'] ?? [];
?>
<div id="dashboard-page">
    <h1 class="mb-3" data-i18n="dashboard.title">Dashboard</h1>

    <!-- Balances + Deposit/Withdraw -->
    <div class="grid grid-2 mb-3">
        <div class="card">
            <h3 class="card-title" data-i18n="dashboard.balances">Balances</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th data-i18n="common.coin">Coin</th>
                        <th data-i18n="common.amount">Available</th>
                        <th>Locked</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (['YTN', 'SUGAR', 'ADVC'] as $coin):
                        $w = null;
                        foreach ($wallets as $row) if ($row['coin'] === $coin) { $w = $row; break; }
                    ?>
                        <tr>
                            <td><strong><?= $coin ?></strong></td>
                            <td class="mono"><?= HdWallet::satToCoin((int)($w['balance'] ?? 0)) ?></td>
                            <td class="mono text-muted"><?= HdWallet::satToCoin((int)($w['locked'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3 class="card-title" data-i18n="dashboard.depositAddress">Deposit Address</h3>
            <?php foreach (['YTN', 'SUGAR', 'ADVC'] as $coin): ?>
                <div class="mb-2">
                    <p class="text-muted"><?= $coin ?></p>
                    <div class="input-group">
                        <input type="text" class="form-input mono" value="<?= htmlspecialchars($depositAddresses[$coin] ?? '') ?>" readonly>
                        <button type="button" class="copy-btn" data-address="<?= htmlspecialchars($depositAddresses[$coin] ?? '') ?>" data-i18n="common.copy">Copy</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Withdraw -->
    <div class="card mb-3">
        <h3 class="card-title" data-i18n="dashboard.withdrawForm">Withdraw</h3>
        <div class="grid grid-2">
            <?php foreach (['YTN', 'SUGAR', 'ADVC'] as $coin): ?>
                <form class="withdraw-form" data-coin="<?= $coin ?>">
                    <h4><?= $coin ?></h4>
                    <div class="form-group">
                        <label class="form-label" data-i18n="dashboard.withdraw.address">Destination Address</label>
                        <input type="text" class="form-input withdraw-address" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" data-i18n="dashboard.withdraw.amount">Amount</label>
                        <input type="number" step="0.00000001" min="0" class="form-input withdraw-amount" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block" data-i18n="dashboard.withdraw.submit">Withdraw</button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- History -->
    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title" data-i18n="dashboard.deposits">Deposits</h3>
            <?php if (empty($deposits)): ?>
                <p class="text-muted text-center" data-i18n="dashboard.noRecords">No records</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr><th data-i18n="common.coin">Coin</th><th data-i18n="common.amount">Amount</th><th data-i18n="common.status">Status</th><th data-i18n="common.time">Time</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deposits as $d): ?>
                            <tr>
                                <td><?= $d['coin'] ?></td>
                                <td class="mono"><?= HdWallet::satToCoin((int)$d['amount_sat']) ?></td>
                                <td><span class="pill <?= $d['status'] === 'confirmed' ? 'pill-confirmed' : 'pill-pending' ?>"><?= $d['status'] ?></span></td>
                                <td class="text-muted"><?= date('m-d H:i', (int)$d['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3 class="card-title" data-i18n="dashboard.withdrawals">Withdrawals</h3>
            <?php if (empty($withdrawals)): ?>
                <p class="text-muted text-center" data-i18n="dashboard.noRecords">No records</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr><th data-i18n="common.coin">Coin</th><th data-i18n="common.amount">Amount</th><th data-i18n="common.status">Status</th><th data-i18n="common.time">Time</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($withdrawals as $w): ?>
                            <tr>
                                <td><?= $w['coin'] ?></td>
                                <td class="mono"><?= HdWallet::satToCoin((int)$w['amount_sat']) ?></td>
                                <td><span class="pill <?= $w['status'] === 'completed' ? 'pill-completed' : ($w['status'] === 'sent' ? 'pill-sent' : 'pill-pending') ?>"><?= $w['status'] ?></span></td>
                                <td class="text-muted"><?= date('m-d H:i', (int)$w['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-3">
        <h3 class="card-title" data-i18n="dashboard.orders">Orders</h3>
        <?php if (empty($userOrders)): ?>
            <p class="text-muted text-center" data-i18n="dashboard.noRecords">No records</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Side</th><th>Type</th><th>Price</th><th>Amount</th><th>Filled</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($userOrders as $o): ?>
                        <tr>
                            <td><?= $o['side'] ?></td>
                            <td><?= $o['type'] ?></td>
                            <td class="mono"><?= HdWallet::satToCoin((int)$o['price_sat']) ?></td>
                            <td class="mono"><?= HdWallet::satToCoin((int)$o['amount_sat']) ?></td>
                            <td class="mono"><?= HdWallet::satToCoin((int)$o['filled_sat']) ?></td>
                            <td><?= $o['status'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php $data['scripts'] = ['/assets/js/dashboard.js']; ?>
