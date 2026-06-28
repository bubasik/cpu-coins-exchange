<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Response;
use App\Core\View;
use App\Core\Request;
use App\Service\ExchangeService;
use App\Service\TradeService;
use App\Service\NetworkStats;
use App\Service\TradePairRegistry;
use App\Wallet\AdapterRegistry;

final class PageController
{
    public function home(Request $req, array $params): void
    {
        // Use cached network stats (60s TTL via NetworkStats service).
        $stats = new NetworkStats();
        $all = $stats->getAllInfo();

        View::render('home', [
            'ytnInfo'   => $all['YTN']   ?? [],
            'sugarInfo' => $all['SUGAR'] ?? [],
            'advcInfo'  => $all['ADVC']  ?? [],
        ]);
    }

    public function exchange(Request $req, array $params): void
    {
        $svc = new ExchangeService();
        View::render('exchange', [
            'recentSwaps' => $svc->listRecent(10),
            'rate' => $svc->getCurrentRate('YTN', 'SUGAR'),
        ]);
    }

    public function trade(Request $req, array $params): void
    {
        $svc = new TradeService();
        $pairKey = $req->query['pair'] ?? TradePairRegistry::default()->key;
        View::render('trade', [
            'orderBook' => $svc->getOrderBook($pairKey, 20),
            'recentTrades' => $svc->getRecentTrades(20, $pairKey),
            'userOrders' => Auth::check() ? $svc->listUserOrders(Auth::id(), 20, $pairKey) : [],
            'pairs' => TradePairRegistry::listForUi(),
            'currentPair' => $pairKey,
            'currentPage' => 'trade',
        ]);
    }

    public function dashboard(Request $req, array $params): void
    {
        Auth::requireLogin();
        $userId = Auth::id();

        $pdo = \App\Core\Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM user_wallets WHERE user_id = ?');
        $stmt->execute([$userId]);
        $wallets = $stmt->fetchAll();

        // Get deposit addresses (one per coin, derived from user_id-based HD index)
        $depositAddresses = [];
        foreach (['YTN', 'SUGAR', 'ADVC'] as $coin) {
            try {
                $adapter = AdapterRegistry::get($coin);
                $index = $userId * 1000;
                $depositAddresses[$coin] = $adapter->deriveDepositAddress($index);
            } catch (\Throwable $e) {
                $depositAddresses[$coin] = '(configure ' . $coin . '_XPUB to enable deposits)';
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 10');
        $stmt->execute([$userId]);
        $deposits = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 10');
        $stmt->execute([$userId]);
        $withdrawals = $stmt->fetchAll();

        $svc = new TradeService();
        $userOrders = $svc->listUserOrders($userId, 20);
        $userTrades = $svc->listUserTrades($userId, 20);

        View::render('dashboard', [
            'wallets' => $wallets,
            'depositAddresses' => $depositAddresses,
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'userOrders' => $userOrders,
            'userTrades' => $userTrades,
        ]);
    }

    public function notFound(Request $req, array $params): void
    {
        Response::error(404, 'Page not found');
    }
}
