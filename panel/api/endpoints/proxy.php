<?php
/**
 * Proxy endpoint — manage Nginx reverse proxy
 * Routes:
 *  GET  proxy/status         — nginx status + mode
 *  POST proxy/install         — install nginx
 *  POST proxy/control         — {action: start|stop|restart|reload}
 *  GET  proxy/hosts           — list proxy hosts
 *  POST proxy/hosts           — add proxy host
 *  PUT  proxy/hosts/{id}      — update proxy host
 *  DELETE proxy/hosts/{id}    — delete proxy host
 *  POST proxy/hosts/{id}/toggle — {enabled: bool}
 *  POST proxy/sync            — sync hosts from accounts
 *  POST proxy/write-configs   — regenerate all nginx configs
 *  GET  proxy/setup-script    — return bash install script
 */

Auth::getInstance()->requireRole('admin');
require_once PANEL_ROOT . '/lib/ProxyManager.php';

$method  = $_SERVER['REQUEST_METHOD'];
$parts   = $routeParts ?? [];
$subpath = implode('/', array_slice($parts, 1));

// Numeric id extraction
preg_match('|hosts/(\d+)(/.+)?|', $subpath, $m);
$hostId  = isset($m[1]) ? (int)$m[1] : null;
$hostSub = $m[2] ?? '';

try {
    // GET proxy/status
    if ($method === 'GET' && $subpath === 'status') {
        json_ok(ProxyManager::status());

    // POST proxy/install
    } elseif ($method === 'POST' && $subpath === 'install') {
        $result = ProxyManager::install();
        json_ok(['result' => $result]);

    // POST proxy/control
    } elseif ($method === 'POST' && $subpath === 'control') {
        $action = $body['action'] ?? '';
        if (!in_array($action, ['start','stop','restart','reload'])) json_error('Invalid action', 400);
        $result = match($action) {
            'start'   => ProxyManager::start(),
            'stop'    => ProxyManager::stop(),
            'restart' => ProxyManager::restart(),
            'reload'  => ProxyManager::reload(),
        };
        json_ok(['result' => $result, 'running' => ProxyManager::isRunning()]);

    // GET proxy/hosts
    } elseif ($method === 'GET' && $subpath === 'hosts') {
        json_ok(ProxyManager::listHosts());

    // POST proxy/hosts — add
    } elseif ($method === 'POST' && $subpath === 'hosts') {
        if (empty($body['domain']))    json_error('domain required', 400);
        if (empty($body['upstream']))  json_error('upstream required', 400);
        $id = ProxyManager::addHost($body);
        json_ok(['id' => $id]);

    // PUT proxy/hosts/{id}
    } elseif ($method === 'PUT' && $hostId && !$hostSub) {
        ProxyManager::updateHost($hostId, $body);
        json_ok();

    // DELETE proxy/hosts/{id}
    } elseif ($method === 'DELETE' && $hostId && !$hostSub) {
        ProxyManager::deleteHost($hostId);
        json_ok();

    // POST proxy/hosts/{id}/toggle
    } elseif ($method === 'POST' && $hostId && $hostSub === '/toggle') {
        ProxyManager::toggleHost($hostId, (bool)($body['enabled'] ?? true));
        json_ok();

    // POST proxy/sync
    } elseif ($method === 'POST' && $subpath === 'sync') {
        $added = ProxyManager::syncFromAccounts();
        json_ok(['added' => $added]);

    // POST proxy/write-configs
    } elseif ($method === 'POST' && $subpath === 'write-configs') {
        ProxyManager::writeAllConfigs();
        json_ok(['result' => 'configs written']);

    // GET proxy/setup-script
    } elseif ($method === 'GET' && $subpath === 'setup-script') {
        header('Content-Type: text/plain');
        echo ProxyManager::setupScript();
        exit;

    } else {
        json_error('Not found', 404);
    }
} catch (Throwable $e) {
    novacpx_log('error', 'proxy endpoint: ' . $e->getMessage());
    json_error($e->getMessage(), 500);
}
