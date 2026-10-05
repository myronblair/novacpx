<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Site Shield API: per-account web rules (blocked addresses, hotlink guard, password-locked folders, custom error pages).
 *
 *   GET  /api/shield/get[?account_id=N]     current rules (password hashes are never returned)
 *   POST /api/shield/save   {account_id?, blocked_ips:[], hotlink:{enabled,allowed:[]}, folders:[{path,realm,users:[{name,password?}]}],
 *                            error_pages:{"404":"/404.html"}}
 *
 * The rules are rendered by the privileged helper (site.rules.write) into the account's nginx include directory; this endpoint
 * only validates for friendly messages, hashes passwords and keeps the JSON copy the panel shows back to the owner.
 */
require_once NOVACPX_LIB . '/Root.php';

$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$cu = Auth::getInstance()->user();
if ($cu['role'] === 'user') {
    $accountId = (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$cu['uid']])['id'] ?? 0);
} else {
    $accountId = (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
}
$acct = assert_account_access($accountId);

function shieldLoad($db, int $accountId): array {
    $row = $db->fetchOne("SELECT rules FROM site_rules WHERE account_id = ?", [$accountId]);
    $r = json_decode($row['rules'] ?? '{}', true);
    return is_array($r) ? $r : [];
}

match ($action) {
    'get' => (function() use ($db, $accountId, $acct) {
        $r = shieldLoad($db, $accountId);
        $folders = [];
        foreach ((array)($r['folders'] ?? []) as $f) {
            $folders[] = [
                'path'  => $f['path'], 'realm' => $f['realm'],
                'users' => array_map(fn($u) => ['name' => $u['name'], 'has_password' => !empty($u['hash'])], (array)($f['users'] ?? [])),
            ];
        }
        Response::success([
            'username'    => $acct['username'],
            'web_server'  => $db->fetchOne("SELECT value FROM settings WHERE key = 'web_server'")['value'] ?? 'nginx',
            'blocked_ips' => array_values((array)($r['blocked_ips'] ?? [])),
            'hotlink'     => ['enabled' => !empty($r['hotlink']['enabled']), 'allowed' => array_values((array)($r['hotlink']['allowed'] ?? []))],
            'folders'     => $folders,
            'error_pages' => (object)($r['error_pages'] ?? []),
        ]);
    })(),

    'save' => (function() use ($db, $body, $accountId, $acct) {
        $old = shieldLoad($db, $accountId);
        $oldHash = [];
        foreach ((array)($old['folders'] ?? []) as $f) {
            foreach ((array)($f['users'] ?? []) as $u) $oldHash[rtrim((string)$f['path'], '/')][(string)$u['name']] = (string)($u['hash'] ?? '');
        }

        $ips = [];
        foreach ((array)($body['blocked_ips'] ?? []) as $ip) {
            $ip = trim((string)$ip);
            if ($ip !== '') $ips[] = $ip;
        }

        $hot = ['enabled' => !empty($body['hotlink']['enabled']), 'allowed' => []];
        foreach ((array)($body['hotlink']['allowed'] ?? []) as $d) {
            $d = strtolower(trim((string)$d));
            if ($d !== '') $hot['allowed'][] = $d;
        }

        $folders = [];
        foreach ((array)($body['folders'] ?? []) as $f) {
            $path = rtrim(trim((string)($f['path'] ?? '')), '/');
            if ($path === '') continue;
            if ($path[0] !== '/') $path = '/' . $path;
            $users = [];
            foreach ((array)($f['users'] ?? []) as $u) {
                $name = trim((string)($u['name'] ?? ''));
                if ($name === '') continue;
                $pw = (string)($u['password'] ?? '');
                if ($pw !== '') {
                    if (strlen($pw) < 8) Response::error("Password for {$name} must be at least 8 characters");
                    $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]);
                } else {
                    $hash = $oldHash[$path][$name] ?? '';
                    if ($hash === '') Response::error("A password is required for the new user {$name} on {$path}");
                }
                $users[] = ['name' => $name, 'hash' => $hash];
            }
            $folders[] = ['path' => $path, 'realm' => trim((string)($f['realm'] ?? '')) ?: 'Restricted', 'users' => $users];
        }

        $errorPages = [];
        foreach ((array)($body['error_pages'] ?? []) as $code => $page) {
            $page = trim((string)$page);
            if ($page !== '') $errorPages[(string)(int)$code] = $page;
        }

        $rules = ['blocked_ips' => $ips, 'hotlink' => $hot, 'folders' => $folders, 'error_pages' => $errorPages];
        try {
            Root::ok('site.rules.write', ['username' => $acct['username']] + $rules);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage());
        }
        $db->execute(
            "INSERT INTO site_rules (account_id, rules, updated_at) VALUES (?,?,datetime('now'))
             ON CONFLICT(account_id) DO UPDATE SET rules = excluded.rules, updated_at = excluded.updated_at",
            [$accountId, json_encode($rules, JSON_UNESCAPED_SLASHES)]
        );
        audit('shield.save', $acct['username'], ['blocked' => count($ips), 'folders' => count($folders), 'hotlink' => $hot['enabled']]);
        Response::success(null, 'Site Shield rules applied');
    })(),

    default => Response::error("Unknown shield action: $action", 404),
};
