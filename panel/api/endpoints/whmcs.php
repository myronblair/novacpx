<?php
/**
 * WHMCS provisioning bridge (#22b)
 * Auth: X-WHMCS-Key header or whmcs_key GET param
 * Actions: create, suspend, unsuspend, terminate, info
 */
require_once NOVACPX_LIB . '/AccountManager.php';

$db = DB::getInstance();

// WHMCS API key auth (bypasses session auth)
$storedKey = $db->fetchOne("SELECT value FROM settings WHERE `key`='whmcs_api_key'")['value'] ?? '';
$enabled   = (bool)($db->fetchOne("SELECT value FROM settings WHERE `key`='whmcs_enabled'")['value'] ?? '0');

if (!$enabled || !$storedKey) Response::error('WHMCS integration is disabled', 403);

$receivedKey = $_SERVER['HTTP_X_WHMCS_KEY'] ?? $_GET['whmcs_key'] ?? '';
if (!hash_equals($storedKey, $receivedKey)) Response::error('Invalid API key', 401);

$body = json_decode(file_get_contents('php://input'), true) ?? [];

match ($action) {

    'create' => (function() use ($db, $body) {
        $username = strtolower(preg_replace('/[^a-z0-9_]/', '', $body['username'] ?? ''));
        $domain   = strtolower(trim($body['domain'] ?? ''));
        $email    = trim($body['email'] ?? '');
        $pkgName  = trim($body['package'] ?? 'Default');
        $password = $body['password'] ?? bin2hex(random_bytes(8));

        if (!$username || !$domain) Response::error('username and domain required');

        // Find or use default package
        $pkg = $db->fetchOne("SELECT id FROM packages WHERE name = ? LIMIT 1", [$pkgName])
            ?? $db->fetchOne("SELECT id FROM packages WHERE is_default = 1 LIMIT 1")
            ?? $db->fetchOne("SELECT id FROM packages LIMIT 1");
        if (!$pkg) Response::error('No packages configured');

        // Create panel user
        $existing = $db->fetchOne("SELECT id FROM users WHERE email = ?", [$email]);
        if ($existing) {
            $userId = (int)$existing['id'];
        } else {
            $db->execute(
                "INSERT INTO users (username, email, password, role) VALUES (?,?,?,'user')",
                [$username, $email ?: "{$username}@{$domain}", password_hash($password, PASSWORD_BCRYPT)]
            );
            $userId = (int)$db->fetchOne("SELECT LAST_INSERT_ID() as id")['id'];
        }

        $result = AccountManager::create([
            'username'   => $username,
            'domain'     => $domain,
            'user_id'    => $userId,
            'package_id' => $pkg['id'],
            'password'   => $password,
        ]);

        audit('whmcs.create', "account:{$username}");
        Response::success([
            'account_id' => $result['account_id'] ?? null,
            'username'   => $username,
            'domain'     => $domain,
            'password'   => $password,
        ], 'Account created');
    })(),

    'suspend' => (function() use ($db, $body) {
        $username = $body['username'] ?? '';
        $reason   = $body['reason'] ?? 'WHMCS suspend';
        $acct = $db->fetchOne("SELECT id FROM accounts WHERE username = ?", [$username]);
        if (!$acct) Response::error("Account not found: $username", 404);
        AccountManager::suspend((int)$acct['id'], $reason);
        audit('whmcs.suspend', "account:{$username}");
        Response::success(null, 'Account suspended');
    })(),

    'unsuspend' => (function() use ($db, $body) {
        $username = $body['username'] ?? '';
        $acct = $db->fetchOne("SELECT id FROM accounts WHERE username = ?", [$username]);
        if (!$acct) Response::error("Account not found: $username", 404);
        AccountManager::unsuspend((int)$acct['id']);
        audit('whmcs.unsuspend', "account:{$username}");
        Response::success(null, 'Account unsuspended');
    })(),

    'terminate' => (function() use ($db, $body) {
        $username = $body['username'] ?? '';
        $acct = $db->fetchOne("SELECT id FROM accounts WHERE username = ?", [$username]);
        if (!$acct) Response::error("Account not found: $username", 404);
        AccountManager::terminate((int)$acct['id']);
        audit('whmcs.terminate', "account:{$username}");
        Response::success(null, 'Account terminated');
    })(),

    'changepackage' => (function() use ($db, $body) {
        $username = $body['username'] ?? '';
        $pkgName  = $body['package'] ?? '';
        $acct = $db->fetchOne("SELECT id FROM accounts WHERE username = ?", [$username]);
        if (!$acct) Response::error("Account not found: $username", 404);
        $pkg = $db->fetchOne("SELECT id FROM packages WHERE name = ?", [$pkgName]);
        if (!$pkg) Response::error("Package not found: $pkgName", 404);
        $db->execute("UPDATE accounts SET package_id = ? WHERE id = ?", [(int)$pkg['id'], (int)$acct['id']]);
        audit('whmcs.changepackage', "account:{$username} pkg:{$pkgName}");
        Response::success(null, 'Package changed');
    })(),

    'info' => (function() use ($db, $body) {
        $username = $body['username'] ?? $_GET['username'] ?? '';
        $acct = $db->fetchOne(
            "SELECT a.*, p.name as package_name FROM accounts a LEFT JOIN packages p ON p.id=a.package_id WHERE a.username = ?",
            [$username]
        );
        if (!$acct) Response::error("Account not found: $username", 404);
        Response::success([
            'username' => $acct['username'],
            'domain'   => $acct['domain'] ?? '',
            'status'   => $acct['status'],
            'package'  => $acct['package_name'] ?? '',
            'created'  => $acct['created_at'] ?? '',
        ]);
    })(),

    default => Response::error("Unknown whmcs action: $action", 404),
};
