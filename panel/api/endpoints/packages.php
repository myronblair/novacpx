<?php
Auth::getInstance()->require('admin', 'reseller');
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$user = Auth::getInstance()->user();
$ownerFilter = $user['role'] === 'reseller' ? "AND (owner_id = {$user['uid']} OR owner_id IS NULL)" : '';

match ($action) {
    'list' => (function() use ($db, $ownerFilter) {
        $rows = $db->fetchAll("SELECT p.*, (SELECT COUNT(*) FROM accounts WHERE package_id = p.id) as account_count FROM packages p WHERE 1=1 $ownerFilter ORDER BY p.name");
        Response::success($rows);
    })(),

    'get' => (function() use ($db) {
        $id  = (int)($_GET['id'] ?? 0);
        $row = $db->fetchOne("SELECT * FROM packages WHERE id = ?", [$id]);
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
        audit('package.create', $name);
        Response::success(['id' => $id], 'Package created');
    })(),

    'update' => (function() use ($db, $body) {
        $id = (int)($body['id'] ?? 0);
        $db->execute(
            "UPDATE packages SET name=?, disk_mb=?, bandwidth_mb=?, max_domains=?, max_subdomains=?,
             max_addon_domains=?, max_parked_domains=?, max_email=?, max_ftp=?, max_databases=?, php_version=?, ssl_enabled=? WHERE id=?",
            [
                $body['name'] ?? '', $body['disk_mb'] ?? 0, $body['bandwidth_mb'] ?? 0,
                $body['max_domains'] ?? 0, $body['max_subdomains'] ?? 0,
                $body['max_addon_domains'] ?? 0, $body['max_parked_domains'] ?? 0,
                $body['max_email'] ?? 0, $body['max_ftp'] ?? 0, $body['max_databases'] ?? 0,
                $body['php_version'] ?? '8.3', $body['ssl_enabled'] ?? 1, $id,
            ]
        );
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
