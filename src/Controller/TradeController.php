<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Request;
use App\Service\TradeService;
use App\Service\TradePairRegistry;

final class TradeController
{
    public function placeOrder(Request $req, array $params): void
    {
        if (!Auth::check()) Response::json(['error' => 'Unauthorized'], 401);

        // Try JSON body first, fall back to form-data
        $data = $req->json();
        if (empty($data)) $data = $req->post;

        $pairKey = (string)($data['pair'] ?? TradePairRegistry::default()->key);
        $side = strtolower((string)($data['side'] ?? ''));
        $type = strtolower((string)($data['type'] ?? 'limit'));
        $price = (string)($data['price'] ?? '');
        $amount = (string)($data['amount'] ?? '');

        $svc = new TradeService();
        try {
            if ($type === 'limit') {
                $r = $svc->placeLimitOrder(Auth::id(), $pairKey, $side, $price, $amount);
            } elseif ($type === 'market') {
                $r = $svc->placeMarketOrder(Auth::id(), $pairKey, $side, $amount, $side === 'buy');
            } else {
                throw new \InvalidArgumentException('Invalid order type');
            }
            Response::json(['ok' => true, 'order' => $r]);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function cancelOrder(Request $req, array $params): void
    {
        if (!Auth::check()) Response::json(['error' => 'Unauthorized'], 401);
        $orderId = (int)($params['id'] ?? 0);
        $svc = new TradeService();
        $ok = $svc->cancelOrder(Auth::id(), $orderId);
        Response::json(['ok' => $ok]);
    }

    /**
     * GET /api/trade/orderbook?pair=YTN/SUGAR&depth=30
     */
    public function orderBook(Request $req, array $params): void
    {
        $pairKey = $req->query['pair'] ?? null;
        $depth = (int)($req->query['depth'] ?? 30);
        $svc = new TradeService();
        try {
            Response::json($svc->getOrderBook($pairKey, $depth));
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function recentTrades(Request $req, array $params): void
    {
        $limit = (int)($req->query['limit'] ?? 50);
        $pairKey = $req->query['pair'] ?? null;
        $svc = new TradeService();
        Response::json($svc->getRecentTrades($limit, $pairKey));
    }

    public function chartData(Request $req, array $params): void
    {
        $interval = $req->query['interval'] ?? '1m';
        $limit = (int)($req->query['limit'] ?? 200);
        $pairKey = $req->query['pair'] ?? null;
        $svc = new TradeService();
        Response::json($svc->getCandles($interval, $limit, $pairKey));
    }

    public function userOrders(Request $req, array $params): void
    {
        if (!Auth::check()) Response::json(['error' => 'Unauthorized'], 401);
        $pairKey = $req->query['pair'] ?? null;
        $svc = new TradeService();
        Response::json($svc->listUserOrders(Auth::id(), 50, $pairKey));
    }

    public function userTrades(Request $req, array $params): void
    {
        if (!Auth::check()) Response::json(['error' => 'Unauthorized'], 401);
        $pairKey = $req->query['pair'] ?? null;
        $svc = new TradeService();
        Response::json($svc->listUserTrades(Auth::id(), 50, $pairKey));
    }

    /**
     * GET /api/trade/pairs — list all configured trading pairs.
     */
    public function pairs(Request $req, array $params): void
    {
        Response::json([
            'pairs' => TradePairRegistry::listForUi(),
            'default' => TradePairRegistry::default()->key,
        ]);
    }
}
