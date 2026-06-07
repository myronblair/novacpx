<?php
/**
 * Webmail endpoint — Roundcube integration proxy
 * Redirects authenticated users to Roundcube with SSO token
 */
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

        $domain = $acct['domain'];
        // Roundcube installed by default at /var/www/roundcube
        $rcUrl  = "https://{$domain}/webmail/";
        // Check if Roundcube is installed
        $installed = file_exists('/var/www/roundcube/index.php');
        Response::success([
            'url'       => $rcUrl,
            'installed' => $installed,
            'domain'    => $domain,
        ]);
    })(),

    'install' => (function() use ($db) {
        Auth::getInstance()->requireRole(['admin']);
        // Background install
        $logFile = '/var/log/novacpx/webmail-install.log';
        $cmd = 'apt-get install -y roundcube roundcube-mysql php8.3-intl > ' . escapeshellarg($logFile) . ' 2>&1 && ' .
               'ln -sf /usr/share/roundcube /var/www/roundcube >> ' . escapeshellarg($logFile) . ' 2>&1';
        shell_exec("nohup bash -c " . escapeshellarg($cmd) . " &");
        Response::success(['log' => $logFile], 'Webmail install started');
    })(),

    'login-url' => (function() use ($db, $body, $user) {
        // Generate a short-lived token for auto-login
        $emailAccount = $db->fetchOne("SELECT * FROM email_accounts WHERE id = ?", [(int)($body['email_id'] ?? 0)]);
        if (!$emailAccount) Response::error("Email account not found");
        $token = bin2hex(random_bytes(16));
        $db->execute("INSERT INTO api_tokens (user_id, token, purpose, expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 30 SECOND))",
            [$user['uid'], hash('sha256', $token), 'webmail_sso']);
        $domain  = parse_url($emailAccount['email'], PHP_URL_HOST) ?: '';
        Response::success(['url' => "https://{$domain}/webmail/?_token={$token}"]);
    })(),

    default => Response::error("Unknown webmail action: $action", 404),
};
