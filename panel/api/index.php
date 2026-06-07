<?php
/**
 * NovaCPX API Router
 * All requests: /api/{endpoint}/{action}
 */

define('NOVACPX_ROOT', dirname(__DIR__, 2));
define('NOVACPX_API',  __DIR__);
define('NOVACPX_LIB',  NOVACPX_ROOT . '/panel/lib');

header('Content-Type: application/json');
header('X-NovaCPX-Version: ' . (file_get_contents(NOVACPX_ROOT . '/VERSION') ?: '1.0.0'));

// CORS for same-origin panel requests
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^https?://[^/]+:2083$#', $origin)) {
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

require $endpointFile;
