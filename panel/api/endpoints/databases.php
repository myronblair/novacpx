<?php
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
require_once NOVACPX_LIB . '/DatabaseManager.php';

$user      = Auth::getInstance()->user();
if ($user['role'] === 'user') {
    $accountId = (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$user['uid']])['id'] ?? 0);
} else {
    $accountId = (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
    if ($accountId && $user['role'] === 'reseller') assert_account_access($accountId);
}

match ($action) {

    'list' => (function() use ($db, $accountId, $user) {
        if (!$accountId && $user['role'] !== 'admin') Response::error("account_id required");
        if ($accountId) {
            $rows = $db->fetchAll(
                "SELECT d.id, d.db_name, d.db_user, d.db_type, d.size_mb, d.created_at, a.username
                 FROM `databases` d LEFT JOIN accounts a ON a.id=d.account_id WHERE d.account_id = ?", [$accountId]);
        } else {
            $rows = $db->fetchAll(
                "SELECT d.id, d.db_name, d.db_user, d.db_type, d.size_mb, d.created_at, a.username
                 FROM `databases` d LEFT JOIN accounts a ON a.id=d.account_id ORDER BY d.created_at DESC LIMIT 500");
        }
        foreach ($rows as &$r) { $r['size_mb'] = DatabaseManager::getSize($r['db_name'], $r['db_type'] ?? 'mysql'); }
        Response::success($rows);
    })(),

    'create' => (function() use ($db, $body, $accountId) {
        if (!$accountId) Response::error("account_id required");
        // Package limit check
        $acctPkg = $db->fetchOne("SELECT p.max_databases FROM accounts a LEFT JOIN packages p ON p.id=a.package_id WHERE a.id=?", [$accountId]);
        if ($acctPkg && $acctPkg['max_databases'] > 0) {
            $count = (int)$db->fetchOne("SELECT COUNT(*) c FROM `databases` WHERE account_id=?", [$accountId])['c'];
            if ($count >= (int)$acctPkg['max_databases']) Response::error("Database limit ({$acctPkg['max_databases']}) reached for this package", 403);
        }
        // Default to active DB engine from settings so autoinstallers use whatever the admin has selected
        $activeEngine = $db->fetchOne("SELECT `value` FROM settings WHERE `key`='active_db_engine'")['value'] ?? 'mysql';
        $type   = $body['type'] ?? $body['db_type'] ?? ($activeEngine === 'postgresql' ? 'postgresql' : 'mysql');
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($body['db_name'] ?? ''));
        $rawUser = trim($body['db_user'] ?? '');
        $dbUser = $rawUser !== '' ? preg_replace('/[^a-zA-Z0-9_]/', '_', $rawUser) : '';
        $dbPass = $body['db_pass'] ?? bin2hex(random_bytes(8));
        if (!$dbName) Response::error("db_name required");

        // Prefix with account username to avoid conflicts
        $acct   = $db->fetchOne("SELECT username FROM accounts WHERE id = ?", [$accountId]);
        $prefix = $acct['username'] . '_';
        if (!str_starts_with($dbName, $prefix)) $dbName = $prefix . $dbName;
        // Default db_user from db_name if blank, then prefix
        if ($dbUser === '') $dbUser = $dbName . '_user';
        if (!str_starts_with($dbUser, $prefix)) $dbUser = $prefix . $dbUser;
        // Enforce max length (MySQL username limit is 32, db name 64)
        $dbName = substr($dbName, 0, 64);
        $dbUser = substr($dbUser, 0, 32);

        try {
            $id = $type === 'postgresql'
                ? DatabaseManager::createPostgres($accountId, $dbName, $dbUser, $dbPass)
                : DatabaseManager::createMySQL($accountId, $dbName, $dbUser, $dbPass);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage());
        }

        audit('database.create', $dbName, ['type' => $type]);
        Response::success(['id' => $id, 'db_name' => $dbName, 'db_user' => $dbUser, 'db_pass' => $dbPass], 'Database created');
    })(),

    'drop' => (function() use ($db, $body) {
        $id  = (int)($body['id'] ?? 0);
        $dbe = $db->fetchOne("SELECT db_name, db_user, db_type FROM `databases` WHERE id = ?", [$id]);
        if (!$dbe) Response::error("Database not found", 404);
        DatabaseManager::drop($dbe['db_name'], $dbe['db_user'], $dbe['db_type']);
        audit('database.drop', $dbe['db_name']);
        Response::success(null, 'Database deleted');
    })(),

    'change-password' => (function() use ($body) {
        $id   = (int)($body['id'] ?? 0);
        $pass = $body['password'] ?? '';
        if (strlen($pass) < 8) Response::error("Password must be at least 8 characters");
        DatabaseManager::changePassword($id, $pass);
        Response::success(null, 'Database password updated');
    })(),

    default => Response::error("Unknown databases action: $action", 404),
};
