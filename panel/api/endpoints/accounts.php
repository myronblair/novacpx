<?php
/**
 * Accounts endpoint — create/list/suspend/terminate hosting accounts
 * Admin + Reseller access
 */
Auth::getInstance()->require('admin', 'reseller');
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$user = Auth::getInstance()->user();

require_once NOVACPX_LIB . '/AccountManager.php';
require_once NOVACPX_LIB . '/VhostManager.php';
require_once NOVACPX_LIB . '/DNSManager.php';
require_once NOVACPX_LIB . '/PHPManager.php';

// Resellers can only see their own accounts
$ownerId     = $user['role'] === 'reseller' ? $user['uid'] : null;
$ownerClause = $ownerId ? "AND u.reseller_id = {$ownerId}" : '';

match ($action) {

    'list' => (function() use ($db, $ownerClause) {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, (int)($_GET['per_page'] ?? 25));
        $search  = $_GET['search'] ?? '';
        $status  = $_GET['status'] ?? '';
        $offset  = ($page - 1) * $perPage;

        $where = "WHERE 1=1 $ownerClause";
        $params = [];
        if ($search) { $where .= " AND (a.domain LIKE ? OR a.username LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
        if ($status) { $where .= " AND a.status = ?"; $params[] = $status; }

        $total = $db->fetchOne("SELECT COUNT(*) c FROM accounts a JOIN users u ON u.id = a.user_id $where", $params)['c'];
        $rows  = $db->fetchAll(
            "SELECT a.*, u.email, u.role,
                    p.name as package_name,
                    (SELECT COUNT(*) FROM domains WHERE account_id = a.id) as domain_count,
                    (SELECT COUNT(*) FROM email_accounts WHERE account_id = a.id) as email_count,
                    (SELECT COUNT(*) FROM databases WHERE account_id = a.id) as db_count
             FROM accounts a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN packages p ON p.id = a.package_id
             $where ORDER BY a.created_at DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );
        Response::paginate($rows, (int)$total, $page, $perPage);
    })(),

    'get' => (function() use ($db, $ownerClause) {
        $id   = (int)($_GET['id'] ?? 0);
        $acct = $db->fetchOne(
            "SELECT a.*, u.email, p.name as package_name FROM accounts a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN packages p ON p.id = a.package_id
             WHERE a.id = ? $ownerClause",
            [$id]
        );
        if (!$acct) Response::error("Account not found", 404);
        $acct['domains']   = $db->fetchAll("SELECT * FROM domains WHERE account_id = ?", [$id]);
        $acct['disk_used'] = AccountManager::getDiskUsage($acct['home_dir']);
        Response::success($acct);
    })(),

    'create' => (function() use ($db, $body, $user) {
        $required = ['username','domain','email','password'];
        foreach ($required as $f) { if (empty($body[$f])) Response::error("$f is required"); }

        // Create user account
        $userId = (int)$db->insert(
            "INSERT INTO users (username, password, email, role, status, reseller_id) VALUES (?,?,?,?,?,?)",
            [
                $body['username'],
                password_hash($body['password'], PASSWORD_BCRYPT),
                $body['email'],
                'user',
                'active',
                $user['role'] === 'reseller' ? $user['uid'] : null,
            ]
        );
        $body['user_id'] = $userId;

        $result = AccountManager::create($body);
        audit('account.create', $body['domain'], $result);
        Response::success($result, 'Account created successfully');
    })(),

    'suspend' => (function() use ($db, $body, $ownerClause) {
        $id = (int)($body['id'] ?? 0);
        $acct = $db->fetchOne("SELECT a.id FROM accounts a JOIN users u ON u.id = a.user_id WHERE a.id = ? $ownerClause", [$id]);
        if (!$acct) Response::error("Account not found", 404);
        AccountManager::suspend($id, $body['reason'] ?? '');
        audit('account.suspend', "account:$id");
        Response::success(null, 'Account suspended');
    })(),

    'unsuspend' => (function() use ($db, $body, $ownerClause) {
        $id = (int)($body['id'] ?? 0);
        AccountManager::unsuspend($id);
        audit('account.unsuspend', "account:$id");
        Response::success(null, 'Account unsuspended');
    })(),

    'terminate' => (function() use ($db, $body, $user) {
        Auth::getInstance()->require('admin');
        $id = (int)($body['id'] ?? 0);
        AccountManager::terminate($id);
        audit('account.terminate', "account:$id");
        Response::success(null, 'Account terminated');
    })(),

    'change-password' => (function() use ($db, $body) {
        $id   = (int)($body['id'] ?? 0);
        $pass = $body['password'] ?? '';
        if (strlen($pass) < 8) Response::error("Password must be at least 8 characters");
        $acct = $db->fetchOne("SELECT user_id FROM accounts WHERE id = ?", [$id]);
        if (!$acct) Response::error("Account not found", 404);
        $db->execute("UPDATE users SET password = ? WHERE id = ?", [password_hash($pass, PASSWORD_BCRYPT), $acct['user_id']]);
        // Also update system user password
        shell_exec("echo " . escapeshellarg("{$id}:{$pass}") . " | chpasswd 2>/dev/null");
        audit('account.change-password', "account:$id");
        Response::success(null, 'Password changed');
    })(),

    'usage' => (function() use ($db) {
        $id   = (int)($_GET['id'] ?? 0);
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$id]);
        if (!$acct) Response::error("Account not found", 404);
        $pkg  = $acct['package_id'] ? $db->fetchOne("SELECT * FROM packages WHERE id = ?", [$acct['package_id']]) : null;
        Response::success([
            'disk_used_mb'   => AccountManager::getDiskUsage($acct['home_dir']),
            'disk_limit_mb'  => $pkg['disk_mb'] ?? 0,
            'email_count'    => $db->fetchOne("SELECT COUNT(*) c FROM email_accounts WHERE account_id = ?", [$id])['c'],
            'email_limit'    => $pkg['max_email'] ?? 0,
            'db_count'       => $db->fetchOne("SELECT COUNT(*) c FROM databases WHERE account_id = ?", [$id])['c'],
            'db_limit'       => $pkg['max_databases'] ?? 0,
            'domain_count'   => $db->fetchOne("SELECT COUNT(*) c FROM domains WHERE account_id = ?", [$id])['c'],
            'domain_limit'   => $pkg['max_domains'] ?? 0,
            'ftp_count'      => $db->fetchOne("SELECT COUNT(*) c FROM ftp_accounts WHERE account_id = ?", [$id])['c'],
            'ftp_limit'      => $pkg['max_ftp'] ?? 0,
        ]);
    })(),

    default => Response::error("Unknown accounts action: $action", 404),
};
