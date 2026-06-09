<?php
/**
 * NovaCPX API Router
 * All requests: /api/{endpoint}/{action}
 */

define('NOVACPX_ROOT', dirname(__DIR__));
define('NOVACPX_API',  __DIR__);
define('NOVACPX_LIB',  NOVACPX_ROOT . '/lib');

header('Content-Type: application/json');
$_ver = file_get_contents(NOVACPX_ROOT . '/VERSION')
     ?: file_get_contents('/opt/novacpx-src/VERSION')
     ?: '1.0.0';
header('X-NovaCPX-Version: ' . trim($_ver));

// CORS for same-origin panel requests (ports 8880/8881/8882/8883)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^https?://[^/]+:(888[0-3])$#', $origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once NOVACPX_LIB . '/Core.php';
require_once NOVACPX_LIB . '/Auth.php';
require_once NOVACPX_LIB . '/DB.php';
require_once NOVACPX_LIB . '/Response.php';

// Parse route: /api/endpoint/action
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts  = array_values(array_filter(explode('/', $uri)));
$apiIdx = array_search('api', $parts);
$endpoint = $parts[$apiIdx + 1] ?? null;
$action   = $parts[$apiIdx + 2] ?? null;

if (!$endpoint) {
    Response::json(['status' => 'ok', 'panel' => 'NovaCPX', 'version' => NOVACPX_VERSION]);
}

// Public endpoints (no auth required)
$public = ['auth'];
if (!in_array($endpoint, $public)) {
    $auth = Auth::getInstance();
    if (!$auth->check()) {
        Response::error('Unauthorized', 401);
    }
    $currentUser = $auth->user();
}

// Route to endpoint handler
$endpointFile = NOVACPX_API . "/endpoints/{$endpoint}.php";
if (!file_exists($endpointFile)) {
    Response::error("Unknown endpoint: $endpoint", 404);
}

// Rate limiting — per-IP, per-endpoint bucket
(function() use ($endpoint) {
    $db     = DB::getInstance();
    $ip     = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";
    $now    = time();
    $window = 60;
    $limit  = $endpoint === "auth" ? 10 : 120;
    $bucket = $endpoint === "auth" ? "auth" : "api";
    try {
        $row = $db->fetchOne("SELECT hits, window_start FROM api_rate_limits WHERE ip=? AND endpoint=?", [$ip, $bucket]);
        if ($row && ($now - (int)$row["window_start"]) < $window) {
            $hits = (int)$row["hits"] + 1;
            $db->execute("UPDATE api_rate_limits SET hits=? WHERE ip=? AND endpoint=?", [$hits, $ip, $bucket]);
        } else {
            $hits = 1;
            $db->execute("INSERT INTO api_rate_limits (ip, endpoint, hits, window_start) VALUES (?,?,1,?) ON DUPLICATE KEY UPDATE hits=1, window_start=VALUES(window_start)", [$ip, $bucket, $now]);
        }
        $reset     = ($row ? (int)$row["window_start"] : $now) + $window;
        $remaining = max(0, $limit - $hits);
        header("X-RateLimit-Limit: {$limit}");
        header("X-RateLimit-Remaining: {$remaining}");
        header("X-RateLimit-Reset: {$reset}");
        if ($hits > $limit) {
            http_response_code(429);
            echo json_encode(["success"=>false,"message"=>"Too many requests. Try again in " . ($reset - $now) . " seconds.","errors"=>[]]);
            exit;
        }
    } catch (Throwable $e) {
        novacpx_log("warn", "rate limit error: " . $e->getMessage());
    }
})();


/**
 * Verify the current user can access a given account_id.
 * Returns the account row or sends a 404 error response.
 * Resellers may only access their own customers; users may only access their own account.
 */
function assert_account_access(int $accountId): array {
    global $currentUser;
    $db   = DB::getInstance();
    $acct = $db->fetchOne("SELECT a.*, u.reseller_id FROM accounts a JOIN users u ON u.id = a.user_id WHERE a.id = ?", [$accountId]);
    if (!$acct) Response::error("Account not found", 404);
    if ($currentUser['role'] === 'reseller' && (int)$acct['reseller_id'] !== $currentUser['uid']) {
        Response::error("Account not found", 404);
    }
    if ($currentUser['role'] === 'user') {
        $own = $db->fetchOne("SELECT id FROM accounts WHERE id = ? AND user_id = ?", [$accountId, $currentUser['uid']]);
        if (!$own) Response::error("Account not found", 404);
    }
    return $acct;
}

require $endpointFile;
