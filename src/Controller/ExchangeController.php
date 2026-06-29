<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Request;
use App\Core\View;
use App\Service\ExchangeService;

final class ExchangeController
{
    public function createOrder(Request $req, array $params): void
    {
        $data = $req->isAjax() ? $req->json() : $req->post;
        $from = $data['from_coin'] ?? '';
        $to = $data['to_coin'] ?? '';
        $amount = $data['from_amount'] ?? '';
        $payout = $data['payout_address'] ?? '';

        try {
            $svc = new ExchangeService();
            $order = $svc->createOrder($from, $to, $amount, $payout);
            Response::json(['ok' => true, 'order' => $order]);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function orderStatus(Request $req, array $params): void
    {
        $ref = $params['ref'] ?? ($req->query['ref'] ?? '');
        $svc = new ExchangeService();
        $order = $svc->getOrderByRef($ref);
        if (!$order) {
            // Try by id
            $order = $svc->getOrder((int)$ref);
        }
        if (!$order) {
            Response::json(['error' => 'Order not found'], 404);
        }

        // Normalize: convert sat amounts to display strings for frontend
        $order['from_amount'] = \App\Wallet\HdWallet::satToCoin((int)$order['from_amount_sat']);
        $order['to_amount'] = \App\Wallet\HdWallet::satToCoin((int)$order['to_amount_sat']);
        $order['from_coin'] = $order['from_coin'];
        $order['to_coin'] = $order['to_coin'];

        Response::json(['order' => $order]);
    }

    public function estimate(Request $req, array $params): void
    {
        $from = $req->query['from'] ?? '';
        $to = $req->query['to'] ?? '';
        $amount = $req->query['amount'] ?? '';

        try {
            $svc = new ExchangeService();
            $rate = $svc->getCurrentRate($from, $to);
            $feePercent = (float)(\App\Core\Config::get('SWAP_FEE_PERCENT', '0.5'));
            $est = bcmul($amount, $rate, 8);
            $est = bcmul($est, (string)(1 - $feePercent / 100), 8);
            Response::json([
                'rate' => $rate,
                'estimated_output' => $est,
                'fee_percent' => $feePercent,
            ]);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }
}
