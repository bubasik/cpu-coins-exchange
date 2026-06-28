<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;
use App\Core\Request;
use App\Wallet\AdapterRegistry;
use App\Wallet\HdWallet;

final class WalletController
{
    public function balances(Request $req, array $params): void
    {
        if (!Auth::check()) Response::json(['error' => 'Unauthorized'], 401);
        $stmt = Database::pdo()->prepare('SELECT coin, balance, locked FROM user_wallets WHERE user_id = ?');
        $stmt->execute([Auth::id()]);
        Response::json(['wallets' => $stmt->fetchAll()]);
    }

    public function depositAddress(Request $req, array $params): void
    {
        if (!Auth::check()) Response::json(['error' => 'Unauthorized'], 401);
        $coin = strtoupper($params['coin'] ?? '');
        try {
            $adapter = AdapterRegistry::get($coin);
            $index = Auth::id() * 1000;
            $addr = $adapter->deriveDepositAddress($index);

            // Insert pending deposit row (only if not already exists for this address)
            Database::pdo()->prepare('
                INSERT OR IGNORE INTO deposits (user_id, coin, address, amount_sat, status, created_at)
                VALUES (?, ?, ?, 0, "pending", ?)
            ')->execute([Auth::id(), $coin, $addr, time()]);

            Response::json(['address' => $addr]);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function withdraw(Request $req, array $params): void
    {
        if (!Auth::check()) Response::json(['error' => 'Unauthorized'], 401);
        $data = $req->isAjax() ? $req->json() : $req->post;
        $coin = strtoupper($data['coin'] ?? '');
        $address = trim($data['address'] ?? '');
        $amount = (string)($data['amount'] ?? '');

        try {
            $adapter = AdapterRegistry::get($coin);
            if (!$adapter->hdWallet()->validateAddress($address)) {
                throw new \InvalidArgumentException('Invalid address for ' . $coin);
            }
            $amountSat = HdWallet::coinToSat($amount, $adapter->decimals());
            $minSat = HdWallet::coinToSat('0.001', $adapter->decimals());
            if ($amountSat < $minSat) {
                throw new \InvalidArgumentException('Minimum withdrawal: 0.001 ' . $coin);
            }

            $pdo = Database::pdo();
            $pdo->beginTransaction();
            try {
                // Check and lock balance
                $stmt = $pdo->prepare('
                    UPDATE user_wallets SET balance = balance - ?
                    WHERE user_id = ? AND coin = ? AND balance >= ?
                ');
                $stmt->execute([$amountSat, Auth::id(), $coin, $amountSat]);
                if ($stmt->rowCount() === 0) {
                    throw new \RuntimeException('Insufficient balance');
                }
                $stmt = $pdo->prepare('
                    INSERT INTO withdrawals (user_id, coin, address, amount_sat, status, created_at)
                    VALUES (?, ?, ?, ?, "pending", ?)
                ');
                $stmt->execute([Auth::id(), $coin, $address, $amountSat, time()]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            Response::json(['ok' => true, 'message' => 'Withdrawal queued']);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function history(Request $req, array $params): void
    {
        if (!Auth::check()) Response::json(['error' => 'Unauthorized'], 401);
        $pdo = Database::pdo();
        $deposits = $pdo->prepare('SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 20');
        $deposits->execute([Auth::id()]);
        $withdrawals = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 20');
        $withdrawals->execute([Auth::id()]);
        Response::json([
            'deposits' => $deposits->fetchAll(),
            'withdrawals' => $withdrawals->fetchAll(),
        ]);
    }
}
