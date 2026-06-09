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
        $data = [
            'id'       => $u['uid'] ?? $u['id'],
            'username' => $u['username'],
            'email'    => $u['email'],
            'role'     => $u['role'],
            'theme'    => $u['theme'],
        ];
        // Expose impersonation context so the UI can show a "return" banner
        if (!empty($u['impersonator_id'])) {
            $imp = DB::getInstance()->fetchOne(
                "SELECT id, username, role FROM users WHERE id = ?", [$u['impersonator_id']]
            );
            if ($imp) $data['impersonated_by'] = ['id' => $imp['id'], 'username' => $imp['username'], 'role' => $imp['role']];
        }
        Response::success($data);
    })(),

    'impersonate' => (function() use ($body) {
        $auth = Auth::getInstance();
        if (!$auth->check()) Response::error('Unauthorized', 401);
        $caller = $auth->user();

        // Only admin or reseller can impersonate
        if (!in_array($caller['role'], ['admin', 'reseller'], true)) {
            Response::error('Forbidden', 403);
        }

        $targetUserId = (int)($body['user_id'] ?? 0);
        if (!$targetUserId) Response::error('user_id required');

        $db     = DB::getInstance();
        $target = $db->fetchOne(
            "SELECT * FROM users WHERE id = ? AND status = 'active' AND role = 'user'",
            [$targetUserId]
        );
        if (!$target) Response::error('User not found', 404);

        // Resellers can only impersonate their own end-users
        if ($caller['role'] === 'reseller' && (int)$target['reseller_id'] !== (int)($caller['uid'] ?? $caller['id'])) {
            Response::error('Access denied', 403);
        }

        // Create a short-lived impersonation session (2h)
        $token     = bin2hex(random_bytes(32));
        $sessionId = hash('sha256', $token);
        $callerId  = (int)($caller['uid'] ?? $caller['id']);
        $db->execute(
            "INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at, impersonator_id, data)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 2 HOUR), ?, ?)",
            [
                $sessionId,
                $target['id'],
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $callerId,
                json_encode([]),
            ]
        );

        // Set cookie to the impersonation token — domain-wide so it works across all ports
        setcookie('ncpx_session', $token, [
            'expires'  => time() + 7200,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        audit('auth.impersonate', "caller:{$callerId} target:{$target['id']}");
        Response::success([
            'portal_url' => Auth::portalUrl('user'),
            'username'   => $target['username'],
        ], "Impersonating {$target['username']}");
    })(),

    'unimpersonate' => (function() {
        $sessionId = hash('sha256', $_COOKIE['ncpx_session'] ?? '');
        $db        = DB::getInstance();
        $sess      = $db->fetchOne(
            "SELECT s.*, u.role FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.id = ?",
            [$sessionId]
        );
        if (!$sess || !$sess['impersonator_id']) Response::error('Not impersonating', 400);

        $callerId = (int)$sess['impersonator_id'];
        $caller   = $db->fetchOne("SELECT id, role FROM users WHERE id = ?", [$callerId]);
        if (!$caller) Response::error('Caller account not found', 404);

        // Delete the impersonation session
        $db->execute("DELETE FROM sessions WHERE id = ?", [$sessionId]);

        // Issue a fresh session for the caller — no raw token is ever stored at rest
        $newToken     = bin2hex(random_bytes(32));
        $newSessionId = hash('sha256', $newToken);
        $db->execute(
            "INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at, data)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 8 HOUR), ?)",
            [
                $newSessionId,
                $callerId,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                json_encode([]),
            ]
        );
        setcookie('ncpx_session', $newToken, [
            'expires'  => time() + 28800,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        audit('auth.unimpersonate', "returning:{$callerId}");
        Response::success(['portal_url' => Auth::portalUrl($caller['role'])], 'Returned to your account');
    })(),

    'change-password' => (function() use ($body) {
        $auth = Auth::getInstance();
        if (!$auth->check()) Response::error('Unauthorized', 401);
        $user    = $auth->user();
        $current = $body['current_password'] ?? '';
        $new     = $body['new_password']     ?? '';
        $confirm = $body['confirm_password'] ?? '';
        if (!$current || !$new) Response::error('current_password and new_password required');
        if (strlen($new) < 8) Response::error('Password must be at least 8 characters');
        if ($new !== $confirm) Response::error('Passwords do not match');
        $db   = DB::getInstance();
        $full = $db->fetchOne("SELECT password FROM users WHERE id = ?", [$user['uid']]);
        if (!$full || !password_verify($current, $full['password'])) Response::error('Current password is incorrect', 400);
        $db->execute("UPDATE users SET password = ? WHERE id = ?", [password_hash($new, PASSWORD_BCRYPT), $user['uid']]);
        audit('auth.change-password', 'user:' . $user['uid']);
        Response::success(null, 'Password changed');
    })(),

    default => Response::error('Unknown auth action', 404),
};
