<?php
/**
 * Proxy endpoint — manage Nginx reverse proxy
 * Routes:
 *  GET  /api/proxy/status           — nginx status
 *  POST /api/proxy/install           — install nginx (local only)
 *  POST /api/proxy/control           — {action: start|stop|restart|reload}
 *  GET  /api/proxy/hosts             — list proxy hosts
 *  POST /api/proxy/hosts             — add proxy host
 *  PUT  /api/proxy/host              — {id, ...fields}  update host
 *  DELETE /api/proxy/host            — {id}  delete host
 *  POST /api/proxy/toggle            — {id, enabled} toggle host
 *  POST /api/proxy/sync              — sync hosts from accounts
 *  POST /api/proxy/write-configs     — regenerate all nginx configs
 *  GET  /api/proxy/setup-script      — return bash install script
 *  GET  /api/proxy/settings          — get proxy settings (mode, remote host, etc.)
 *  POST /api/proxy/settings          — save proxy settings
 *  POST /api/proxy/test-remote       — test SSH connectivity to remote proxy VM
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
            $result  = match($act) {
                'start'   => ProxyManager::start(),
                'stop'    => ProxyManager::stop(),
                'restart' => ProxyManager::restart(),
                'reload'  => ProxyManager::reload(),
            };
            $running = ProxyManager::isRunning();
            $success = match($act) {
                'start', 'restart' => $running,
                'stop'             => !$running,
                'reload'           => !str_starts_with($result, 'Config test failed'),
            };
            Response::json(['success' => $success, 'data' => ['result' => $result, 'running' => $running]]);
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

        // GET settings — return current proxy configuration
        $action === 'settings' && $method === 'GET' => (function() {
            $db  = DB::getInstance();
            $get = fn(string $k, string $d = '') => $db->fetchOne("SELECT value FROM settings WHERE `key`=?", [$k])['value'] ?? $d;
            Response::json(['success' => true, 'data' => [
                'mode'        => $get('proxy_mode', 'disabled'),
                'remote_host' => $get('proxy_remote_host'),
                'remote_user' => $get('proxy_remote_user', 'root'),
                'remote_pass' => $get('proxy_remote_pass') ? '••••••••' : '',
                'backend_ip'  => $get('proxy_backend_ip'),
            ]]);
        })(),

        // POST settings — save proxy configuration
        $action === 'settings' && $method === 'POST' => (function() use ($body) {
            $db      = DB::getInstance();
            $allowed = ['proxy_mode', 'proxy_remote_host', 'proxy_remote_user', 'proxy_remote_pass', 'proxy_backend_ip'];
            $map     = [
                'mode'        => 'proxy_mode',
                'remote_host' => 'proxy_remote_host',
                'remote_user' => 'proxy_remote_user',
                'remote_pass' => 'proxy_remote_pass',
                'backend_ip'  => 'proxy_backend_ip',
            ];
            foreach ($map as $field => $key) {
                if (!array_key_exists($field, $body)) continue;
                if ($field === 'remote_pass' && $body[$field] === '••••••••') continue; // unchanged placeholder
                $db->execute(
                    "INSERT INTO settings (`key`, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)",
                    [$key, $body[$field]]
                );
            }
            Response::json(['success' => true, 'message' => 'Proxy settings saved']);
        })(),

        // POST test-remote — verify SSH connection to remote proxy VM
        $action === 'test-remote' && $method === 'POST' =>
            Response::json(['success' => true, 'data' => ProxyManager::testRemote()]),

        // POST switch-local — migrate Apache to internal port, install nginx, enable local proxy mode
        ($action === 'switch-local') && $method === 'POST' => (function() use ($body) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            ob_implicit_flush(true);
            while (ob_get_level() > 0) ob_end_flush();
            $port = (int)($body['apache_port'] ?? 8090);
            foreach (ProxyManager::switchToLocalMode($port) as $line) {
                echo 'data: ' . json_encode(['line' => $line]) . "\n\n";
                flush();
            }
            echo "data: " . json_encode(['done' => true]) . "\n\n";
            flush();
            exit;
        })(),

        // POST disable-local — revert: Apache back to 80, stop nginx, disable proxy
        ($action === 'disable-local') && $method === 'POST' => (function() {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            ob_implicit_flush(true);
            while (ob_get_level() > 0) ob_end_flush();
            foreach (ProxyManager::disableLocalMode() as $line) {
                echo 'data: ' . json_encode(['line' => $line]) . "\n\n";
                flush();
            }
            echo "data: " . json_encode(['done' => true]) . "\n\n";
            flush();
            exit;
        })(),

        // POST setup-remote — run nginx setup on remote VM, stream output via SSE
        ($action === 'setup-remote') && $method === 'POST' => (function() {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            ob_implicit_flush(true);
            while (ob_get_level() > 0) ob_end_flush();
            foreach (ProxyManager::runSetupOnRemote() as $line) {
                echo 'data: ' . json_encode(['line' => $line]) . "\n\n";
                flush();
            }
            echo "data: " . json_encode(['done' => true]) . "\n\n";
            flush();
            exit;
        })(),

        // DELETE uninstall — remove proxy configs (and optionally nginx)
        $action === 'uninstall' && $method === 'DELETE' => (function() use ($body) {
            $removeNginx = !empty($body['remove_nginx']);
            $result      = ProxyManager::uninstall($removeNginx);
            if ($removeNginx) {
                $db = DB::getInstance();
                $db->execute("INSERT INTO settings (`key`, value) VALUES ('proxy_mode','disabled') ON DUPLICATE KEY UPDATE value='disabled'");
            }
            Response::json(['success' => true, 'data' => ['result' => $result]]);
        })(),

        default => Response::error('Not found', 404),
    };

} catch (Throwable $e) {
    novacpx_log('error', 'proxy endpoint: ' . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
