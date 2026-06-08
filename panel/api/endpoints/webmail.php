<?php
/**
 * Webmail endpoint — Roundcube integration + SSO
 */
require_once NOVACPX_LIB . '/EmailManager.php';

$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$user = Auth::getInstance()->user();

match ($action) {
    'url' => (function() use ($db, $body, $user) {
        $accountId = $user['role'] === 'user'
            ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$user['uid']])['id'] ?? 0)
            : (int)($body['account_id'] ?? 0);
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$accountId]);
        if (!$acct) Response::error("Account not found");

        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $hostname  = preg_replace('/:\d+$/', '', $host);
        $rcUrl     = "https://{$hostname}:" . PORT_WEBMAIL . "/";
        $installed = file_exists('/usr/share/roundcube/index.php');
        Response::success([
            'url'       => $rcUrl,
            'installed' => $installed,
        ]);
    })(),

    'install' => (function() {
        Auth::getInstance()->require('admin');
        $logFile = '/var/log/novacpx/webmail-install.log';
        $cmd = 'apt-get install -y roundcube roundcube-mysql php8.3-intl > ' . escapeshellarg($logFile) . ' 2>&1';
        shell_exec("nohup bash -c " . escapeshellarg($cmd) . " &");
        Response::success(['log' => $logFile], 'Webmail install started');
    })(),

    'login-url' => (function() use ($db, $body, $user) {
        $emailAccount = $db->fetchOne(
            "SELECT ea.*, a.user_id FROM email_accounts ea JOIN accounts a ON a.id = ea.account_id WHERE ea.id = ?",
            [(int)($body['email_id'] ?? 0)]
        );
        if (!$emailAccount) Response::error("Email account not found");
        // Users can only SSO into their own email accounts
        if ($user['role'] === 'user' && (int)$emailAccount['user_id'] !== (int)$user['uid']) {
            Response::error('Forbidden', 403);
        }
        if (empty($emailAccount['enc_password'])) Response::error("SSO not available for this account — password must be reset");

        // Create short-lived SSO token
        $token = bin2hex(random_bytes(24));
        $db->execute(
            "DELETE FROM webmail_sso_tokens WHERE expires_at < NOW()"
        );
        $db->execute(
            "INSERT INTO webmail_sso_tokens (token, email, enc_pass, expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 60 SECOND))",
            [hash('sha256', $token), $emailAccount['email'], $emailAccount['enc_password']]
        );
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $hostname = preg_replace('/:\d+$/', '', $host);
        Response::success([
            'url' => "https://{$hostname}:" . PORT_WEBMAIL . "/novacpx-sso.php?t=" . urlencode($token),
        ]);
    })(),

    default => Response::error("Unknown webmail action: $action", 404),
};
