<?php
/** @var array $data */
$s = $data['status'] ?? [];
$coins = $s['coins'] ?? [];
$db = $s['database'] ?? [];
$redis = $s['redis'] ?? [];
$storage = $s['storage'] ?? [];
$ext = $s['extensions'] ?? [];
$summary = $s['summary'] ?? [];
$generatedAt = $data['generatedAt'] ?? time();

function status_pill(string $status): string {
    $map = [
        'healthy'  => 'pill-confirmed',
        'degraded' => 'pill-pending',
        'down'     => 'pill-failed',
    ];
    $cls = $map[$status] ?? 'pill-pending';
    return "<span class=\"pill $cls\">" . strtoupper($status) . "</span>";
}
?>

<div class="status-page">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px;">
        <h1 data-i18n="status.title">API Status</h1>
        <div>
            <button class="btn btn-secondary" onclick="window.location.reload()" data-i18n="status.refresh">Refresh</button>
            <a href="/api/status" class="btn btn-secondary" target="_blank">JSON</a>
        </div>
    </div>

    <!-- Summary -->
    <div class="card mb-3">
        <h3 class="card-title" data-i18n="status.summary">Summary</h3>
        <div class="stat-grid">
            <div class="stat">
                <div class="stat-label" data-i18n="status.totalChecks">Total Checks</div>
                <div class="stat-value"><?= (int)($summary['total'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="stat-label" data-i18n="status.healthy">Healthy</div>
                <div class="stat-value text-success"><?= (int)($summary['healthy'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="stat-label" data-i18n="status.degraded">Degraded</div>
                <div class="stat-value" style="color: var(--warning);"><?= (int)($summary['degraded'] ?? 0) ?></div>
            </div>
            <div class="stat">
                <div class="stat-label" data-i18n="status.down">Down</div>
                <div class="stat-value text-danger"><?= (int)($summary['down'] ?? 0) ?></div>
            </div>
        </div>
        <p class="text-muted mt-2">
            <span data-i18n="status.lastCheck">Last check</span>:
            <?= date('Y-m-d H:i:s', $generatedAt) ?>
            <?php if (!empty($s['response_time_ms'])): ?>
                &middot; <span data-i18n="status.responseTime">Response time</span>: <?= (int)$s['response_time_ms'] ?>ms
            <?php endif; ?>
        </p>
    </div>

    <!-- Coin APIs -->
    <div class="card mb-3">
        <h3 class="card-title" data-i18n="status.coinApis">Coin APIs</h3>
        <?php foreach ($coins as $symbol => $c): ?>
            <div class="coin-status" style="border-bottom: 1px solid var(--border); padding: 12px 0;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px;">
                    <h4 style="margin:0;"><?= htmlspecialchars($c['name']) ?> (<?= $symbol ?>)</h4>
                    <?= status_pill($c['status']) ?>
                </div>
                <div class="grid grid-4" style="font-size: 12px;">
                    <div>
                        <div class="text-muted">API URL</div>
                        <div class="mono" title="<?= htmlspecialchars($c['api_url']) ?>">
                            <?= htmlspecialchars($c['api_url']) ?>
                        </div>
                    </div>
                    <div>
                        <div class="text-muted" data-i18n="status.blocks">Blocks</div>
                        <div class="mono"><?= $c['blocks'] !== null ? number_format((int)$c['blocks']) : '-' ?></div>
                    </div>
                    <div>
                        <div class="text-muted" data-i18n="status.responseTime">Response Time</div>
                        <div class="mono"><?= (int)$c['response_time_ms'] ?>ms</div>
                    </div>
                    <div>
                        <div class="text-muted" data-i18n="status.confirmationsRequired">Required Confirmations</div>
                        <div class="mono"><?= (int)$c['confirmations_required'] ?></div>
                    </div>
                </div>
                <div class="grid grid-4 mt-2" style="font-size: 12px;">
                    <div>
                        <div class="text-muted" data-i18n="status.difficulty">Difficulty</div>
                        <div class="mono"><?= $c['difficulty'] !== null ? number_format((float)$c['difficulty'], 8) : '-' ?></div>
                    </div>
                    <div>
                        <div class="text-muted" data-i18n="status.nethash">Nethash (H/s)</div>
                        <div class="mono"><?= $c['nethash'] !== null ? number_format((int)$c['nethash']) : '-' ?></div>
                    </div>
                    <div>
                        <div class="text-muted" data-i18n="status.supply">Supply</div>
                        <div class="mono"><?= $c['supply'] !== null ? \App\Wallet\HdWallet::satToCoin((int)$c['supply']) : '-' ?></div>
                    </div>
                    <div>
                        <div class="text-muted" data-i18n="status.feeRate">Fee Rate (sat/vB)</div>
                        <div class="mono"><?= $c['fee_rate'] !== null ? number_format((int)$c['fee_rate']) : '-' ?></div>
                    </div>
                </div>
                <div class="grid grid-3 mt-2" style="font-size: 12px;">
                    <div>
                        <div class="text-muted" data-i18n="status.hotWallet">Hot Wallet</div>
                        <div class="mono">
                            <?= $c['hot_wallet_configured'] ? '✓ ' . data_i18n_configured() : '✗ ' . data_i18n_not_configured() ?>
                        </div>
                        <?php if ($c['hot_wallet_balance'] !== null): ?>
                            <div class="text-muted">
                                <span data-i18n="status.balance">Balance</span>:
                                <?= \App\Wallet\HdWallet::satToCoin((int)$c['hot_wallet_balance']) ?> <?= $symbol ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="text-muted">xprv</div>
                        <div class="mono"><?= $c['xprv_configured'] ? '✓ ' . data_i18n_configured() : '✗ ' . data_i18n_not_configured() ?></div>
                    </div>
                    <div>
                        <div class="text-muted">xpub</div>
                        <div class="mono"><?= $c['xpub_configured'] ? '✓ ' . data_i18n_configured() : '✗ ' . data_i18n_not_configured() ?></div>
                    </div>
                </div>
                <?php if (!empty($c['error'])): ?>
                    <div class="mt-2" style="padding: 8px; background: rgba(239,68,68,0.1); border-radius: 4px; color: var(--danger); font-size: 12px;">
                        <strong>Error:</strong> <?= htmlspecialchars($c['error']) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Database -->
    <div class="card mb-3">
        <h3 class="card-title" data-i18n="status.database">Database</h3>
        <div class="grid grid-4" style="font-size: 12px;">
            <div>
                <div class="text-muted" data-i18n="status.status">Status</div>
                <div><?= status_pill($db['status'] ?? 'down') ?></div>
            </div>
            <div>
                <div class="text-muted">Type</div>
                <div class="mono"><?= htmlspecialchars($db['type'] ?? '?') ?></div>
            </div>
            <div>
                <div class="text-muted" data-i18n="status.size">Size</div>
                <div class="mono"><?= htmlspecialchars($db['size_human'] ?? '-') ?></div>
            </div>
            <div>
                <div class="text-muted" data-i18n="status.responseTime">Response Time</div>
                <div class="mono"><?= (int)($db['response_time_ms'] ?? 0) ?>ms</div>
            </div>
        </div>
        <div class="grid grid-3 mt-2" style="font-size: 12px;">
            <div>
                <div class="text-muted" data-i18n="status.users">Users</div>
                <div class="mono"><?= number_format((int)($db['users'] ?? 0)) ?></div>
            </div>
            <div>
                <div class="text-muted" data-i18n="status.swapOrders">Swap Orders</div>
                <div class="mono"><?= number_format((int)($db['swap_orders'] ?? 0)) ?></div>
            </div>
            <div>
                <div class="text-muted" data-i18n="status.tradeOrders">Trade Orders</div>
                <div class="mono"><?= number_format((int)($db['trade_orders'] ?? 0)) ?></div>
            </div>
        </div>
        <?php if (!empty($db['error'])): ?>
            <div class="mt-2" style="padding: 8px; background: rgba(239,68,68,0.1); border-radius: 4px; color: var(--danger); font-size: 12px;">
                <strong>Error:</strong> <?= htmlspecialchars($db['error']) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Redis -->
    <div class="card mb-3">
        <h3 class="card-title">Redis / Cache</h3>
        <div class="grid grid-4" style="font-size: 12px;">
            <div>
                <div class="text-muted" data-i18n="status.status">Status</div>
                <div><?= status_pill($redis['status'] ?? 'down') ?></div>
            </div>
            <div>
                <div class="text-muted">Host</div>
                <div class="mono"><?= htmlspecialchars(($redis['host'] ?? '?') . ':' . ($redis['port'] ?? '?')) ?></div>
            </div>
            <div>
                <div class="text-muted">Mode</div>
                <div class="mono"><?= htmlspecialchars($redis['mode'] ?? '?') ?></div>
            </div>
            <div>
                <div class="text-muted" data-i18n="status.responseTime">Response Time</div>
                <div class="mono"><?= (int)($redis['response_time_ms'] ?? 0) ?>ms</div>
            </div>
        </div>
        <?php if (!empty($redis['version'])): ?>
            <p class="text-muted mt-2">Version: <?= htmlspecialchars($redis['version']) ?> &middot; PING: <?= htmlspecialchars($redis['ping'] ?? '') ?></p>
        <?php endif; ?>
        <?php if (!empty($redis['error'])): ?>
            <div class="mt-2" style="padding: 8px; background: rgba(245,158,11,0.1); border-radius: 4px; color: var(--warning); font-size: 12px;">
                <strong>Notice:</strong> <?= htmlspecialchars($redis['error']) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- PHP Environment -->
    <div class="card mb-3">
        <h3 class="card-title" data-i18n="status.phpEnv">PHP Environment</h3>
        <div class="grid grid-2" style="font-size: 12px;">
            <div>
                <div class="text-muted">PHP Version</div>
                <div class="mono"><?= htmlspecialchars($s['php_version'] ?? PHP_VERSION) ?></div>
            </div>
            <div>
                <div class="text-muted">Environment</div>
                <div class="mono"><?= htmlspecialchars($s['environment'] ?? '?') ?></div>
            </div>
        </div>
        <p class="text-muted mt-2">
            <span data-i18n="status.extensions">Extensions</span>:
            <span style="color: var(--success);">✓ <?= implode(', ', $ext['loaded'] ?? []) ?></span>
            <?php if (!empty($ext['missing'])): ?>
                <span style="color: var(--danger);">✗ <?= implode(', ', $ext['missing']) ?></span>
            <?php endif; ?>
        </p>
    </div>

    <!-- Storage -->
    <div class="card mb-3">
        <h3 class="card-title" data-i18n="status.storage">Storage Directories</h3>
        <table class="table">
            <thead>
                <tr>
                    <th data-i18n="status.directory">Directory</th>
                    <th data-i18n="status.exists">Exists</th>
                    <th data-i18n="status.writable">Writable</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($storage['directories'] ?? []) as $path => $info): ?>
                    <tr>
                        <td class="mono"><?= htmlspecialchars($path) ?></td>
                        <td><?= $info['exists'] ? '✓' : '✗' ?></td>
                        <td><?= $info['writable'] ? '✓' : '✗' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
function data_i18n_configured() {
    return 'Configured';
}
function data_i18n_not_configured() {
    return 'Not configured';
}
?>

<script>
// Auto-refresh every 30 seconds
setTimeout(() => window.location.reload(), 30000);
</script>

<?php $data['scripts'] = []; ?>
