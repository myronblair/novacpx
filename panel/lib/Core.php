<?php
/**
 * NovaCPX Core — constants, config loader, helpers
 */

define('NOVACPX_VERSION', trim(file_get_contents(NOVACPX_ROOT . '/VERSION') ?: '1.0.0'));
define('NOVACPX_CONFIG',  '/etc/novacpx/config.ini');

// Load config
$_cfg = parse_ini_file(NOVACPX_CONFIG, true);
if (!$_cfg) {
    http_response_code(503);
    die(json_encode(['error' => 'NovaCPX not configured. Run the installer.']));
}

define('DB_PATH',        $_cfg['database']['path']        ?? '/var/lib/novacpx/panel.db');
define('DB_WP_USER',     $_cfg['database']['wp_user']     ?? '');
define('DB_WP_PASS',     $_cfg['database']['wp_pass']     ?? '');
define('SECRET_KEY',     $_cfg['panel']['secret']         ?? '');
define('PANEL_VER',      $_cfg['panel']['version']        ?? NOVACPX_VERSION);
define('PORT_USER',      (int)($_cfg['panel']['port_user']     ?? 8880));
define('PORT_RESELLER',  (int)($_cfg['panel']['port_reseller'] ?? 8881));
define('PORT_ADMIN',     (int)($_cfg['panel']['port_admin']    ?? 8882));
define('PORT_WEBMAIL',   (int)($_cfg['panel']['port_webmail']  ?? 8883));
define('WEB_SERVER',     $_cfg['web']['server']           ?? 'apache');
define('PHP_DEFAULT',    $_cfg['web']['php_default']      ?? '8.3');

// Detect which portal is being accessed by the request port
$requestPort = (int)($_SERVER['SERVER_PORT'] ?? 0);
define('CURRENT_PORTAL',
    $requestPort === PORT_ADMIN    ? 'admin'    :
    ($requestPort === PORT_RESELLER ? 'reseller' :
    ($requestPort === PORT_WEBMAIL  ? 'webmail'  : 'user'))
);

function novacpx_log(string $level, string $msg, array $ctx = []): void {
    $line = sprintf("[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'), strtoupper($level), $msg,
        $ctx ? json_encode($ctx) : ''
    );
    file_put_contents('/var/log/novacpx/panel.log', $line, FILE_APPEND | LOCK_EX);
}

function audit(string $action, string $resource = '', array $detail = []): void {
    try {
        $db   = DB::getInstance();
        $auth = Auth::getInstance();
        $user = $auth->user();
        $db->execute(
            "INSERT INTO audit_log (user_id, username, action, resource, detail, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $user['id'] ?? null,
                $user['username'] ?? 'system',
                $action,
                $resource,
                json_encode($detail),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]
        );
    } catch (Throwable $e) {
        novacpx_log('error', 'audit failed: ' . $e->getMessage());
    }
}
