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
require_once NOVACPX_LIB . '/Notifier.php';

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
            "SELECT a.*, u.email, u.role, u.reseller_id,
                    p.name as package_name,
                    r.username as reseller_username,
                    (SELECT COUNT(*) FROM domains WHERE account_id = a.id) as domain_count,
                    (SELECT COUNT(*) FROM email_accounts WHERE account_id = a.id) as email_count,
                    (SELECT COUNT(*) FROM `databases` WHERE account_id = a.id) as db_count
             FROM accounts a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN packages p ON p.id = a.package_id
             LEFT JOIN users r ON r.id = u.reseller_id
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

        if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) Response::error("Invalid email address");
        if ($db->fetchOne("SELECT id FROM users WHERE email = ? AND role = 'user'", [$body['email']])) Response::error("Email already in use by another account");
        if ($db->fetchOne("SELECT id FROM users WHERE username = ?", [$body['username']])) Response::error("Username already taken");

        // Insert user first — AccountManager::create() wraps everything else in its own transaction
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

        try {
            $result = AccountManager::create($body);
        } catch (Throwable $e) {
            // Roll back the user insert if account provisioning failed
            $db->execute("DELETE FROM users WHERE id = ?", [$userId]);
            throw $e;
        }

        audit('account.create', $body['domain'], $result);
        Notifier::accountCreated(array_merge($body, ['email' => $body['email']]), $body['password']);
        Response::success($result, 'Account created successfully');
    })(),

    'update' => (function() use ($db, $body, $user, $ownerClause) {
        $id   = (int)($body['id'] ?? 0);
        $acct = $db->fetchOne(
            "SELECT a.*, u.email, u.reseller_id FROM accounts a JOIN users u ON u.id=a.user_id WHERE a.id=? $ownerClause",
            [$id]
        );
        if (!$acct) Response::error("Account not found", 404);
        $db->beginTransaction();
        try {

        $allowed = ['php_version', 'package_id', 'notes'];
        $sets = []; $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $body)) {
                $sets[]   = "`$col` = ?";
                $params[] = $body[$col] === '' ? null : $body[$col];
            }
        }

        // Email lives on users table
        if (array_key_exists('email', $body) && filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            $db->execute("UPDATE users SET email=? WHERE id=?", [$body['email'], $acct['user_id']]);
        }

        // Ownership change — admin only; moves account + all related settings to new reseller
        if ($user['role'] === 'admin' && array_key_exists('reseller_id', $body)) {
            $newResellerId = $body['reseller_id'] === '' || $body['reseller_id'] === null ? null : (int)$body['reseller_id'];

            // Validate target reseller exists (if not null)
            if ($newResellerId !== null) {
                $reseller = $db->fetchOne("SELECT id FROM users WHERE id=? AND role='reseller'", [$newResellerId]);
                if (!$reseller) Response::error("Reseller not found", 404);
            }

            // Update the user's reseller_id — this is the ownership pivot
            $db->execute("UPDATE users SET reseller_id=? WHERE id=?", [$newResellerId, $acct['user_id']]);

            // Move package to the new owner's scope: if new owner has a default package, assign it;
            // otherwise keep existing package if it's globally available (owner_id IS NULL), else clear it
            if ($newResellerId !== null) {
                $pkgOwned = $db->fetchOne(
                    "SELECT id FROM packages WHERE id=? AND (owner_id=? OR owner_id IS NULL)",
                    [$acct['package_id'], $newResellerId]
                );
                if (!$pkgOwned) {
                    // Find a suitable package for the new owner
                    $fallbackPkg = $db->fetchOne(
                        "SELECT id FROM packages WHERE owner_id=? AND is_default=1 LIMIT 1",
                        [$newResellerId]
                    );
                    if (!$fallbackPkg) $fallbackPkg = $db->fetchOne(
                        "SELECT id FROM packages WHERE owner_id IS NULL AND is_default=1 LIMIT 1"
                    );
                    $sets[]   = '`package_id` = ?';
                    $params[] = $fallbackPkg ? $fallbackPkg['id'] : null;
                }
            }

            // Migrate DNS zone nameservers to new owner's default NS
            $newNs1 = $newResellerId
                ? ($db->fetchOne("SELECT value FROM settings WHERE `key`=?", ["reseller_{$newResellerId}_ns1"])['value'] ?? null)
                : null;
            $newNs1 = $newNs1 ?: $db->fetchOne("SELECT value FROM settings WHERE `key`='ns1_hostname'")['value'] ?? null;
            $newNs2 = $newResellerId
                ? ($db->fetchOne("SELECT value FROM settings WHERE `key`=?", ["reseller_{$newResellerId}_ns2"])['value'] ?? null)
                : null;
            $newNs2 = $newNs2 ?: $db->fetchOne("SELECT value FROM settings WHERE `key`='ns2_hostname'")['value'] ?? null;

            if ($newNs1 || $newNs2) {
                $zone = $db->fetchOne("SELECT id FROM dns_zones WHERE account_id=?", [$id]);
                if ($zone) {
                    $nsUpdates = [];
                    if ($newNs1) { $nsUpdates[] = "primary_ns=?"; }
                    if ($newNs2) { $nsUpdates[] = "secondary_ns=?"; }
                    $nsParams = array_filter([$newNs1, $newNs2]);
                    $nsParams[] = $zone['id'];
                    $db->execute("UPDATE dns_zones SET " . implode(',', $nsUpdates) . " WHERE id=?", array_values($nsParams));
                    try { require_once NOVACPX_LIB . '/DNSManager.php'; DNSManager::writeZoneFile($zone['id']); } catch (Throwable $e) {}
                }
            }

            audit('account.ownership-change', "account:{$id} prev_reseller:{$acct['reseller_id']} new_reseller:{$newResellerId}");
        }

        // DNS nameservers — admin can update per-account
        if ($user['role'] === 'admin' && (array_key_exists('ns1', $body) || array_key_exists('ns2', $body))) {
            $zone = $db->fetchOne("SELECT id FROM dns_zones WHERE account_id=?", [$id]);
            if ($zone) {
                $nsSet = []; $nsP = [];
                if (!empty($body['ns1'])) { $nsSet[] = "primary_ns=?";   $nsP[] = $body['ns1']; }
                if (!empty($body['ns2'])) { $nsSet[] = "secondary_ns=?"; $nsP[] = $body['ns2']; }
                if ($nsSet) {
                    $nsP[] = $zone['id'];
                    $db->execute("UPDATE dns_zones SET " . implode(',', $nsSet) . " WHERE id=?", $nsP);
                    try { require_once NOVACPX_LIB . '/DNSManager.php'; DNSManager::writeZoneFile($zone['id']); } catch (Throwable $e) {}
                }
            }
        }

        if ($sets) {
            $params[] = $id;
            $db->execute("UPDATE accounts SET " . implode(', ', $sets) . " WHERE id=?", $params);
        }

        // If PHP version changed, update the FPM pool
        if (!empty($body['php_version']) && $body['php_version'] !== $acct['php_version']) {
            require_once NOVACPX_LIB . '/PHPManager.php';
            try {
                PHPManager::removePool($acct['username']);
                PHPManager::createPool($acct['username'], $body['php_version']);
            } catch (Throwable $e) {
                novacpx_log('warn', "PHP pool update failed for {$acct['username']}: " . $e->getMessage());
            }
        }

        $db->commit();
        audit('account.update', "account:$id", $body);
        Response::success(null, 'Account updated');
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    })(),

    'suspend' => (function() use ($db, $body, $ownerClause) {
        $id = (int)($body['id'] ?? $body['account_id'] ?? 0);
        $acct = $db->fetchOne(
            "SELECT a.id, a.username, a.domain, u.email FROM accounts a JOIN users u ON u.id = a.user_id WHERE a.id = ? $ownerClause",
            [$id]
        );
        if (!$acct) Response::error("Account not found", 404);
        $reason = $body['reason'] ?? '';
        AccountManager::suspend($id, $reason);
        Notifier::accountSuspended($acct, $reason);
        audit('account.suspend', "account:$id");
        Response::success(null, 'Account suspended');
    })(),

    'unsuspend' => (function() use ($db, $body, $ownerClause) {
        $id = (int)($body['id'] ?? $body['account_id'] ?? 0);
        AccountManager::unsuspend($id);
        audit('account.unsuspend', "account:$id");
        Response::success(null, 'Account unsuspended');
    })(),

    'terminate' => (function() use ($db, $body, $user) {
        Auth::getInstance()->require('admin');
        $id = (int)($body['id'] ?? $body['account_id'] ?? 0);
        AccountManager::terminate($id);
        audit('account.terminate', "account:$id");
        Response::success(null, 'Account terminated');
    })(),

    'change-password' => (function() use ($db, $body, $user) {
        Auth::getInstance()->require('admin');
        $id   = (int)($body['account_id'] ?? $body['id'] ?? 0);
        $pass = $body['password'] ?? '';
        if (strlen($pass) < 8) Response::error("Password must be at least 8 characters");
        $acct = $db->fetchOne("SELECT a.user_id, a.username FROM accounts a WHERE a.id = ?", [$id]);
        if (!$acct) Response::error("Account not found", 404);
        $db->execute("UPDATE users SET password = ? WHERE id = ?", [password_hash($pass, PASSWORD_BCRYPT), $acct['user_id']]);
        shell_exec("echo " . escapeshellarg("{$acct['username']}:{$pass}") . " | sudo chpasswd 2>/dev/null");
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
            'db_count'       => $db->fetchOne("SELECT COUNT(*) c FROM `databases` WHERE account_id = ?", [$id])['c'],
            'db_limit'       => $pkg['max_databases'] ?? 0,
            'domain_count'   => $db->fetchOne("SELECT COUNT(*) c FROM domains WHERE account_id = ?", [$id])['c'],
            'domain_limit'   => $pkg['max_domains'] ?? 0,
            'ftp_count'      => $db->fetchOne("SELECT COUNT(*) c FROM ftp_accounts WHERE account_id = ?", [$id])['c'],
            'ftp_limit'      => $pkg['max_ftp'] ?? 0,
        ]);
    })(),

    default => Response::error("Unknown accounts action: $action", 404),
};
