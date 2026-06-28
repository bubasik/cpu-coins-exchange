<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Router;
use App\Core\Database;
use App\Core\Auth;
use App\Core\Session;
use App\Controller\AuthController;
use App\Controller\PageController;
use App\Controller\ExchangeController;
use App\Controller\TradeController;
use App\Controller\WalletController;
use App\Controller\StatusController;

require __DIR__ . '/../vendor/autoload.php';

Config::load();

// Error reporting
if (Config::get('APP_DEBUG')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../storage/logs/php-errors.log');
}

// Session cookie for CSRF (lightweight, no PHP session storage needed)
// Sessions are stored in Redis via App\Core\Session

// Build router
$router = new Router();
$auth = new AuthController();
$pages = new PageController();
$exchange = new ExchangeController();
$trade = new TradeController();
$wallet = new WalletController();
$status = new StatusController();

// ===== Pages (HTML) =====
$router->get('/',        [$pages, 'home']);
$router->get('/exchange', [$pages, 'exchange']);
$router->get('/trade',    [$pages, 'trade']);
$router->get('/dashboard',[$pages, 'dashboard']);
$router->get('/login',    [$auth, 'showLogin']);
$router->get('/register', [$auth, 'showRegister']);
$router->get('/status',   [$status, 'page']);

// ===== Status API =====
$router->get('/api/status',           [$status, 'api']);
$router->get('/api/status/{coin}',    [$status, 'apiCoin']);

// ===== Auth =====
$router->post('/auth/login',     [$auth, 'login']);
$router->post('/auth/register',  [$auth, 'register']);
$router->post('/auth/logout',    [$auth, 'logout']);
$router->post('/auth/preferences', [$auth, 'updatePreferences']);

// ===== Exchange =====
$router->post('/api/exchange/create',  [$exchange, 'createOrder']);
$router->get('/api/exchange/status/{ref}', [$exchange, 'orderStatus']);
$router->get('/api/exchange/estimate', [$exchange, 'estimate']);

// ===== Trading =====
$router->post('/api/trade/order',         [$trade, 'placeOrder']);
$router->post('/api/trade/order/{id}/cancel', [$trade, 'cancelOrder']);
$router->get('/api/trade/pairs',          [$trade, 'pairs']);
$router->get('/api/trade/orderbook',      [$trade, 'orderBook']);
$router->get('/api/trade/trades',         [$trade, 'recentTrades']);
$router->get('/api/trade/chart',          [$trade, 'chartData']);
$router->get('/api/trade/my-orders',      [$trade, 'userOrders']);
$router->get('/api/trade/my-trades',      [$trade, 'userTrades']);

// ===== Wallet =====
$router->get('/api/wallet/balances',                 [$wallet, 'balances']);
$router->get('/api/wallet/deposit-address/{coin}',   [$wallet, 'depositAddress']);
$router->post('/api/wallet/withdraw',                [$wallet, 'withdraw']);
$router->get('/api/wallet/history',                  [$wallet, 'history']);

// Dispatch
$req = new Request();
$router->dispatch($req);
