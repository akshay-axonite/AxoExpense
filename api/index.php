<?php
/**
 * Axonite Expense Tracking System - Central API Router
 * 
 * Intercepts all incoming REST requests to /api/v1/*, parses the route,
 * handles CORS pre-flight OPTIONS requests, and dispatches the execution
 * to the appropriate controller and action.
 * 
 * @author Axonite Team
 * @package AxonitExpense
 */

// Enable error reporting for diagnostics during development
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

// Set comprehensive Cross-Origin Resource Sharing (CORS) headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Timezone-Offset, X-Timezone-Name, Accept-Language, X-Request-Id');
header('Access-Control-Max-Age: 86400');

// Handle pre-flight browser OPTIONS check immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';

// Autoload all controllers in the controllers/ directory
foreach (glob(__DIR__ . '/controllers/*.php') as $controllerFile) {
    require_once $controllerFile;
}

// Extract normalized route path from REQUEST_URI
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove leading project subdirectories or /api or /v1 prefix
$route = preg_replace('#^.*?/api/?(v1/?)?#i', '', $requestUri);
// Remove trailing .json extension or trailing slash
$route = preg_replace('#\.json$#i', '', $route);
$route = trim($route, '/');
$method = $_SERVER['REQUEST_METHOD'];

// Route Dispatcher Table
try {
    switch ($route) {
        // ==========================================
        // 1. Authentication & User Profile Routes
        // ==========================================
        case 'users/login':
        case 'authorize':
            (new AuthController())->login();
            break;

        case 'users/register':
        case 'register':
            (new AuthController())->register();
            break;

        case 'users/profile/get':
            (new AuthController())->getProfile();
            break;

        case 'users/profile/update':
            (new AuthController())->updateProfile();
            break;

        case 'tokens/list':
            (new AuthController())->listTokens();
            break;

        case 'tokens/refresh':
            (new AuthController())->refreshToken();
            break;

        case 'tokens/revoke':
        case 'logout':
            (new AuthController())->revokeToken();
            break;

        // ==========================================
        // 2. Account Master Data Routes
        // ==========================================
        case 'accounts/list':
            (new AccountController())->listAccounts();
            break;

        case 'accounts/get':
            (new AccountController())->getAccount();
            break;

        case 'accounts/add':
            (new AccountController())->addAccount();
            break;

        case 'accounts/modify':
            (new AccountController())->modifyAccount();
            break;

        case 'accounts/hide':
            (new AccountController())->hideAccount();
            break;

        case 'accounts/move':
            (new AccountController())->moveAccount();
            break;

        case 'accounts/delete':
            (new AccountController())->deleteAccount();
            break;

        // ==========================================
        // 3. Category Master Data Routes
        // ==========================================
        case 'transaction/categories/list':
            (new CategoryController())->listCategories();
            break;

        case 'transaction/categories/get':
            (new CategoryController())->getCategory();
            break;

        case 'transaction/categories/add':
            (new CategoryController())->addCategory();
            break;

        case 'transaction/categories/add_batch':
            (new CategoryController())->addBatchCategories();
            break;

        case 'transaction/categories/modify':
            (new CategoryController())->modifyCategory();
            break;

        case 'transaction/categories/hide':
            (new CategoryController())->hideCategory();
            break;

        case 'transaction/categories/move':
            (new CategoryController())->moveCategory();
            break;

        case 'transaction/categories/delete':
            (new CategoryController())->deleteCategory();
            break;

        // ==========================================
        // 4. Tag & Tag Group Routes
        // ==========================================
        case 'transaction/tags/list':
            (new TagController())->listTags();
            break;

        case 'transaction/tags/get':
            (new TagController())->getTag();
            break;

        case 'transaction/tags/add':
            (new TagController())->addTag();
            break;

        case 'transaction/tags/modify':
            (new TagController())->modifyTag();
            break;

        case 'transaction/tags/hide':
            (new TagController())->hideTag();
            break;

        case 'transaction/tags/move':
            (new TagController())->moveTag();
            break;

        case 'transaction/tags/delete':
            (new TagController())->deleteTag();
            break;

        case 'transaction/tags/groups/list':
            (new TagController())->listTagGroups();
            break;

        // ==========================================
        // 5. Transaction Ledger Routes
        // ==========================================
        case 'transactions/list':
        case 'transactions/list/all':
            (new TransactionController())->listTransactions();
            break;

        case 'transactions/list/by_month':
            (new TransactionController())->transactionMonthList();
            break;

        case 'transactions/count':
            (new TransactionController())->countTransactions();
            break;

        case 'transactions/get':
            (new TransactionController())->getTransaction();
            break;

        case 'transactions/add':
            (new TransactionController())->addTransaction();
            break;

        case 'transactions/modify':
            (new TransactionController())->modifyTransaction();
            break;

        case 'transactions/delete':
            (new TransactionController())->deleteTransaction();
            break;

        case 'transactions/statistics':
            (new StatsController())->transactionStatistics();
            break;

        case 'transactions/statistics/trends':
            (new StatsController())->transactionStatisticsTrends();
            break;

        case 'transactions/statistics/asset_trends':
            (new StatsController())->transactionStatisticsAssetTrends();
            break;

        case 'transactions/amounts':
            (new StatsController())->transactionAmounts();
            break;

        case 'transactions/amounts/daily':
            (new StatsController())->transactionDailyAmounts();
            break;

        // ==========================================
        // 6. Picture & Receipt Proof Routes
        // ==========================================
        case 'transaction/pictures/upload':
            (new PictureController())->uploadPicture();
            break;

        case 'transaction/pictures/remove_unused':
            (new PictureController())->removeUnusedPicture();
            break;

        // ==========================================
        // 7. Exchange Rates & Currencies
        // ==========================================
        case 'exchange_rates/latest':
            (new RatesController())->latestRates();
            break;

        case 'exchange_rates/user_custom/update':
            (new RatesController())->updateCustomRate();
            break;

        // ==========================================
        // 8. System & Cloud Setting Stubs
        // ==========================================
        case 'data/statistics':
            (new StatsController())->dataStatistics();
            break;

        case 'systems/version':
            (new StatsController())->systemVersion();
            break;

        case 'users/settings/cloud/get':
            Utils::successResponse(new stdClass());
            break;

        case 'users/settings/cloud/update':
            Utils::successResponse(true);
            break;

        case 'transaction/templates/list':
            Utils::successResponse([]);
            break;

        case 'custom_icons/list':
            Utils::successResponse([]);
            break;

        // Default: 404 handler
        default:
            Utils::errorResponse(100001, "API endpoint not found: [{$method}] {$route}", 404);
            break;
    }
} catch (Throwable $e) {
    Utils::errorResponse(500000, 'Internal Server Error: ' . $e->getMessage(), 500);
}
