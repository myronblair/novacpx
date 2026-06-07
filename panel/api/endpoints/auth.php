<?php
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

match ($action) {
    'login' => (function() use ($body) {
        $username  = trim($body['username'] ?? '');
        $password  = $body['password']   ?? '';
        $totpCode  = isset($body['totp_code']) ? trim($body['totp_code']) : null;
        if (!$username || !$password) Response::error('Username and password required');
        $auth  = Auth::getInstance();
        $token = $auth->attempt($username, $password, $totpCode);
        if ($token === Auth::TOTP_REQUIRED) {
            Response::json(['success' => false, 'totp_required' => true, 'message' => 'Enter your 2FA code'], 200);
        }
        if (!$token) {
            // Log failure for Fail2Ban to detect
            $ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $port   = (int)($_SERVER['SERVER_PORT'] ?? 0);
            $portal = $port === PORT_ADMIN ? 'admin' : ($port === PORT_RESELLER ? 'reseller' : ($port === PORT_WEBMAIL ? 'webmail' : 'user'));
            $logLine = date('Y-m-d H:i:s') . " FAILED LOGIN from {$ip} [{$portal}] user:{$username}\n";
            @file_put_contents('/var/log/novacpx/access.log', $logLine, FILE_APPEND | LOCK_EX);
            novacpx_log('warn', "Failed login for '$username' from $ip");
            Response::error('Invalid credentials', 401);
        }
        $user = $auth->user();
        audit('login', 'auth');
        Response::success([
            'token'       => $token,
            'portal_url'  => Auth::portalUrl($user['role']),
            'user'        => [
                'id'       => $user['id'],
                'username' => $user['username'],
                'email'    => $user['email'],
                'role'     => $user['role'],
                'theme'    => $user['theme'],
            ],
        ], 'Login successful');
    })(),

    'logout' => (function() {
        Auth::getInstance()->logout();
        audit('logout', 'auth');
        Response::success(null, 'Logged out');
    })(),

    'me' => (function() {
        $auth = Auth::getInstance();
        if (!$auth->check()) Response::error('Unauthorized', 401);
        $u = $auth->user();
        Response::success([
            'id'       => $u['uid'] ?? $u['id'],
            'username' => $u['username'],
            'email'    => $u['email'],
            'role'     => $u['role'],
            'theme'    => $u['theme'],
        ]);
    })(),

    default => Response::error('Unknown auth action', 404),
};
