<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
Auth::getInstance()->require('admin', 'reseller');
require_once NOVACPX_LIB . '/PackageTools.php';
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$user = Auth::getInstance()->user();
$ownerFilter = $user['role'] === 'reseller' ? "AND (owner_id = {$user['uid']} OR owner_id IS NULL)" : '';

match ($action) {
    'list' => (function() use ($db, $ownerFilter) {
        $rows = $db->fetchAll("SELECT p.*, (SELECT COUNT(*) FROM accounts WHERE package_id = p.id) as account_count FROM packages p WHERE 1=1 $ownerFilter ORDER BY p.name");
        Response::success($rows);
    })(),

    'get' => (function() use ($db, $user) {
        $id  = (int)($_GET['id'] ?? 0);
        $row = $db->fetchOne("SELECT * FROM packages WHERE id = ?", [$id]);
        if ($row && $user['role'] === 'reseller' && $row['owner_id'] !== null && (int)$row['owner_id'] !== (int)$user['uid']) $row = null;
        if (!$row) Response::error("Package not found", 404);
        Response::success($row);
    })(),

    'create' => (function() use ($db, $body, $user) {
        $name = trim($body['name'] ?? '');
        if (!$name) Response::error("Package name required");
        $id = (int)$db->insert(
            "INSERT INTO packages (name, owner_id, disk_mb, bandwidth_mb, max_domains, max_subdomains, max_addon_domains, max_parked_domains, max_email, max_ftp, max_databases, php_version, ssl_enabled)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $name,
                $user['role'] === 'reseller' ? $user['uid'] : null,
                (int)($body['disk_mb']          ?? 1024),
                (int)($body['bandwidth_mb']      ?? 10240),
                (int)($body['max_domains']       ?? 1),
                (int)($body['max_subdomains']     ?? 10),
                (int)($body['max_addon_domains']  ?? 0),
                (int)($body['max_parked_domains'] ?? 5),
                (int)($body['max_email']          ?? 10),
                (int)($body['max_ftp']            ?? 5),
                (int)($body['max_databases']      ?? 5),
                $body['php_version'] ?? '8.3',
                (int)($body['ssl_enabled'] ?? 1),
            ]
        );
        $db->execute("UPDATE packages SET php_max_children = ?, php_memory_mb = ? WHERE id = ?",
            [max(1, min(100, (int)($body['php_max_children'] ?? 5))), max(32, min(8192, (int)($body['php_memory_mb'] ?? 256))), $id]);
        if (array_key_exists('tools', $body)) $db->execute("UPDATE packages SET tools = ? WHERE id = ?", [PackageTools::normalise($body['tools']), $id]);
        audit('package.create', $name);
        Response::success(['id' => $id], 'Package created');
    })(),

    'update' => (function() use ($db, $body, $user) {
        $id = (int)($body['id'] ?? 0);
        $pkg = $db->fetchOne("SELECT id, owner_id FROM packages WHERE id = ?", [$id]);
        if (!$pkg) Response::error("Package not found", 404);
        // a reseller changes only their own packages (the shared ones, with no owner, are the admin's)
        if ($user['role'] === 'reseller' && (int)$pkg['owner_id'] !== (int)$user['uid']) Response::error("Package not found", 404);
        // only the fields that were sent are changed (a form that leaves one out must not reset it to 0)
        $ints = ['disk_mb', 'bandwidth_mb', 'max_domains', 'max_subdomains', 'max_addon_domains', 'max_parked_domains', 'max_email', 'max_ftp', 'max_databases', 'ssl_enabled'];
        $sets = []; $vals = [];
        if (isset($body['name']) && trim((string)$body['name']) !== '') { $sets[] = 'name = ?'; $vals[] = trim((string)$body['name']); }
        if (isset($body['php_version']) && preg_match('/^[0-9]\.[0-9]$/', (string)$body['php_version'])) { $sets[] = 'php_version = ?'; $vals[] = $body['php_version']; }
        foreach ($ints as $k) { if (isset($body[$k])) { $sets[] = "$k = ?"; $vals[] = max(0, (int)$body[$k]); } }
        if ($sets) { $vals[] = $id; $db->execute("UPDATE packages SET " . implode(', ', $sets) . " WHERE id = ?", $vals); }
        if (array_key_exists('tools', $body)) $db->execute("UPDATE packages SET tools = ? WHERE id = ?", [PackageTools::normalise($body['tools']), $id]);
        if (isset($body['php_max_children']) || isset($body['php_memory_mb'])) {
            $db->execute("UPDATE packages SET php_max_children = COALESCE(?, php_max_children), php_memory_mb = COALESCE(?, php_memory_mb) WHERE id = ?",
                [isset($body['php_max_children']) ? max(1, min(100, (int)$body['php_max_children'])) : null,
                 isset($body['php_memory_mb']) ? max(32, min(8192, (int)$body['php_memory_mb'])) : null, $id]);
            // Apply to every account on the package right away - but only after the answer has been sent: rewriting a pool reloads
            // PHP-FPM, which serves this very request, and the browser would otherwise see a 502.
            $accts = $db->fetchAll("SELECT id, username, php_version FROM accounts WHERE package_id = ? AND status = 'active'", [$id]);
            register_shutdown_function(function () use ($accts) {
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                require_once NOVACPX_LIB . '/PHPManager.php';
                foreach ($accts as $a) {
                    try { PHPManager::createPool($a['username'], $a['php_version'], PHPManager::currentSettings((int)$a['id'])); }
                    catch (Throwable $e) { error_log('[packages] pool ' . $a['username'] . ': ' . $e->getMessage()); }
                }
            });
        }
        audit('package.update', "package:$id");
        Response::success(null, 'Package updated');
    })(),

    'delete' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        $id  = (int)($body['id'] ?? 0);
        $cnt = $db->fetchOne("SELECT COUNT(*) c FROM accounts WHERE package_id = ?", [$id])['c'];
        if ($cnt > 0) Response::error("Cannot delete: $cnt accounts use this package");
        $db->execute("DELETE FROM packages WHERE id = ?", [$id]);
        audit('package.delete', "package:$id");
        Response::success(null, 'Package deleted');
    })(),

    default => Response::error("Unknown packages action: $action", 404),
};
