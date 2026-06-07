<?php
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

match ($action) {
    'login' => (function() use ($body) {
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        if (!$username || !$password) Response::error('Username and password required');
        $auth  = Auth::getInstance();
        $token = $auth->attempt($username, $password);
        if (!$token) Response::error('Invalid credentials', 401);
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
