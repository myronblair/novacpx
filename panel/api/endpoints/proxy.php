<?php
/**
 * Proxy endpoint — manage Nginx reverse proxy
 * Routes:
 *  GET  /api/proxy/status           — nginx status
 *  POST /api/proxy/install           — install nginx
 *  POST /api/proxy/control           — {action: start|stop|restart|reload}
 *  GET  /api/proxy/hosts             — list proxy hosts
 *  POST /api/proxy/hosts             — add proxy host
 *  PUT  /api/proxy/host              — {id, ...fields}  update host
 *  DELETE /api/proxy/host            — {id}  delete host
 *  POST /api/proxy/toggle            — {id, enabled} toggle host
 *  POST /api/proxy/sync              — sync hosts from accounts
 *  POST /api/proxy/write-configs     — regenerate all nginx configs
 *  GET  /api/proxy/setup-script      — return bash install script
 */

Auth::getInstance()->require('admin');
$body = json_decode(file_get_contents('php://input'), true) ?? [];

require_once NOVACPX_LIB . '/ProxyManager.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];

    match (true) {

        // GET status
        $action === 'status' && $method === 'GET' =>
            Response::json(['success' => true, 'data' => ProxyManager::status()]),

        // POST install
        $action === 'install' && $method === 'POST' =>
            Response::json(['success' => true, 'data' => ['result' => ProxyManager::install()]]),

        // POST control
        $action === 'control' && $method === 'POST' => (function() use ($body) {
            $act = $body['action'] ?? '';
            if (!in_array($act, ['start','stop','restart','reload'])) Response::error('Invalid action', 400);
            $result = match($act) {
                'start'   => ProxyManager::start(),
                'stop'    => ProxyManager::stop(),
                'restart' => ProxyManager::restart(),
                'reload'  => ProxyManager::reload(),
            };
            Response::json(['success' => true, 'data' => ['result' => $result, 'running' => ProxyManager::isRunning()]]);
        })(),

        // GET hosts list
        $action === 'hosts' && $method === 'GET' =>
            Response::json(['success' => true, 'data' => ProxyManager::listHosts()]),

        // POST hosts — add
        $action === 'hosts' && $method === 'POST' => (function() use ($body) {
            if (empty($body['domain']))   Response::error('domain required', 400);
            if (empty($body['upstream'])) Response::error('upstream required', 400);
            $id = ProxyManager::addHost($body);
            Response::json(['success' => true, 'data' => ['id' => $id]]);
        })(),

        // PUT host — update (body has id)
        $action === 'host' && $method === 'PUT' => (function() use ($body) {
            if (empty($body['id'])) Response::error('id required', 400);
            ProxyManager::updateHost((int)$body['id'], $body);
            Response::json(['success' => true]);
        })(),

        // DELETE host (body has id)
        $action === 'host' && $method === 'DELETE' => (function() use ($body) {
            $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
            if (!$id) Response::error('id required', 400);
            ProxyManager::deleteHost($id);
            Response::json(['success' => true]);
        })(),

        // POST toggle
        $action === 'toggle' && $method === 'POST' => (function() use ($body) {
            if (empty($body['id'])) Response::error('id required', 400);
            ProxyManager::toggleHost((int)$body['id'], (bool)($body['enabled'] ?? true));
            Response::json(['success' => true]);
        })(),

        // POST sync
        $action === 'sync' && $method === 'POST' => (function() {
            $added = ProxyManager::syncFromAccounts();
            Response::json(['success' => true, 'data' => ['added' => $added]]);
        })(),

        // POST write-configs
        ($action === 'write-configs' || $action === 'write_configs') && $method === 'POST' => (function() {
            ProxyManager::writeAllConfigs();
            Response::json(['success' => true, 'data' => ['result' => 'configs written']]);
        })(),

        // GET setup-script
        ($action === 'setup-script' || $action === 'setup_script') && $method === 'GET' => (function() {
            header('Content-Type: text/plain');
            echo ProxyManager::setupScript();
            exit;
        })(),

        default => Response::error('Not found', 404),
    };

} catch (Throwable $e) {
    novacpx_log('error', 'proxy endpoint: ' . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
