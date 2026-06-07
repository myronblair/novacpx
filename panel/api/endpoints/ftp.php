<?php
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
require_once NOVACPX_LIB . '/FTPManager.php';

$user      = Auth::getInstance()->user();
$accountId = $user['role'] === 'user'
    ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$user['uid']])['id'] ?? 0)
    : (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);

match ($action) {
    'list' => (function() use ($db, $accountId) {
        Response::success($db->fetchAll("SELECT id, username, home_dir, quota_mb, status, created_at FROM ftp_accounts WHERE account_id = ?", [$accountId]));
    })(),

    'create' => (function() use ($db, $body, $accountId) {
        if (!$accountId) Response::error("account_id required");
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? bin2hex(random_bytes(6));
        $acct     = $db->fetchOne("SELECT home_dir FROM accounts WHERE id = ?", [$accountId]);
        $homeDir  = $body['home_dir'] ?? ($acct['home_dir'] . '/public_html');
        if (!$username) Response::error("username required");
        $id = FTPManager::createAccount($accountId, $username, $password, $homeDir, (int)($body['quota_mb'] ?? 0));
        audit('ftp.create', $username);
        Response::success(['id' => $id, 'password' => $password], 'FTP account created');
    })(),

    'delete' => (function() use ($body) {
        FTPManager::deleteAccount((int)($body['id'] ?? 0));
        audit('ftp.delete', "ftp:{$body['id']}");
        Response::success(null, 'FTP account deleted');
    })(),

    'change-password' => (function() use ($body) {
        FTPManager::changePassword((int)($body['id'] ?? 0), $body['password'] ?? '');
        Response::success(null, 'FTP password updated');
    })(),

    default => Response::error("Unknown ftp action: $action", 404),
};
