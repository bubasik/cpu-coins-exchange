<?php
/** @var array $data */
use App\Wallet\HdWallet;
$ytn = $data['ytnInfo'] ?? [];
$sugar = $data['sugarInfo'] ?? [];
$advc = $data['advcInfo'] ?? [];
?>
<div class="hero">
    <h1 data-i18n="home.hero.title">Trade Yenten & Sugarchain</h1>
    <p data-i18n="home.hero.subtitle">Instant swap and limit/market trading with on-chain settlement.</p>
    <div class="hero-cta">
        <a href="/exchange" class="btn btn-primary" data-i18n="home.cta.exchange">Start Exchange</a>
        <a href="/trade" class="btn btn-secondary" data-i18n="home.cta.trade">Open Trading</a>
    </div>
</div>

<section>
    <h2 class="mb-3" data-i18n="home.stats.network">Network Stats</h2>
    <div class="stat-grid">
        <div class="stat">
            <div class="stat-label">Yenten (YTN)</div>
            <div class="stat-value"><?= number_format((int)($ytn['blocks'] ?? 0)) ?></div>
            <div class="stat-sub" data-i18n="home.stats.blocks">Blocks</div>
        </div>
        <div class="stat">
            <div class="stat-label">YTN Supply</div>
            <div class="stat-value"><?= HdWallet::satToCoin((int)($ytn['supply'] ?? 0)) ?></div>
            <div class="stat-sub" data-i18n="home.stats.supply">Supply</div>
        </div>
        <div class="stat">
            <div class="stat-label">YTN Difficulty</div>
            <div class="stat-value"><?= number_format((float)($ytn['difficulty'] ?? 0), 6) ?></div>
            <div class="stat-sub" data-i18n="home.stats.difficulty">Difficulty</div>
        </div>
        <div class="stat">
            <div class="stat-label">YTN Nethash</div>
            <div class="stat-value"><?= number_format((int)($ytn['nethash'] ?? 0)) ?> H/s</div>
            <div class="stat-sub" data-i18n="home.stats.nethash">Net Hash</div>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-label">Sugarchain (SUGAR)</div>
            <div class="stat-value"><?= number_format((int)($sugar['blocks'] ?? 0)) ?></div>
            <div class="stat-sub" data-i18n="home.stats.blocks">Blocks</div>
        </div>
        <div class="stat">
            <div class="stat-label">SUGAR Supply</div>
            <div class="stat-value"><?= HdWallet::satToCoin((int)($sugar['supply'] ?? 0)) ?></div>
            <div class="stat-sub" data-i18n="home.stats.supply">Supply</div>
        </div>
        <div class="stat">
            <div class="stat-label">SUGAR Difficulty</div>
            <div class="stat-value"><?= number_format((float)($sugar['difficulty'] ?? 0), 8) ?></div>
            <div class="stat-sub" data-i18n="home.stats.difficulty">Difficulty</div>
        </div>
        <div class="stat">
            <div class="stat-label">SUGAR Nethash</div>
            <div class="stat-value"><?= number_format((int)($sugar['nethash'] ?? 0)) ?> H/s</div>
            <div class="stat-sub" data-i18n="home.stats.nethash">Net Hash</div>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-label">Adventurecoin (ADVC)</div>
            <div class="stat-value"><?= number_format((int)($advc['blocks'] ?? 0)) ?></div>
            <div class="stat-sub" data-i18n="home.stats.blocks">Blocks</div>
        </div>
        <div class="stat">
            <div class="stat-label">ADVC Supply</div>
            <div class="stat-value"><?= HdWallet::satToCoin((int)($advc['supply'] ?? 0)) ?></div>
            <div class="stat-sub" data-i18n="home.stats.supply">Supply</div>
        </div>
        <div class="stat">
            <div class="stat-label">ADVC Difficulty</div>
            <div class="stat-value"><?= number_format((float)($advc['difficulty'] ?? 0), 6) ?></div>
            <div class="stat-sub" data-i18n="home.stats.difficulty">Difficulty</div>
        </div>
        <div class="stat">
            <div class="stat-label">ADVC Nethash</div>
            <div class="stat-value"><?= number_format((int)($advc['nethash'] ?? 0)) ?> H/s</div>
            <div class="stat-sub" data-i18n="home.stats.nethash">Net Hash</div>
        </div>
    </div>
</section>

<section class="mt-3">
    <h2 class="mb-3" data-i18n="home.features.title">Features</h2>
    <div class="grid grid-2">
        <div class="card">
            <h3 data-i18n="home.features.swap.title">Instant Swap</h3>
            <p class="text-muted mt-2" data-i18n="home.features.swap.desc">Exchange YTN for SUGAR in under a minute.</p>
        </div>
        <div class="card">
            <h3 data-i18n="home.features.trade.title">Limit & Market Orders</h3>
            <p class="text-muted mt-2" data-i18n="home.features.trade.desc">Place limit orders or execute instantly with market orders.</p>
        </div>
        <div class="card">
            <h3 data-i18n="home.features.secure.title">On-Chain Security</h3>
            <p class="text-muted mt-2" data-i18n="home.features.secure.desc">All deposits and withdrawals use Bitcoin-compatible UTXO model.</p>
        </div>
        <div class="card">
            <h3 data-i18n="home.features.i18n.title">4 Languages, 2 Themes</h3>
            <p class="text-muted mt-2" data-i18n="home.features.i18n.desc">English, Russian, Japanese, Chinese. Light or dark theme.</p>
        </div>
    </div>
</section>
