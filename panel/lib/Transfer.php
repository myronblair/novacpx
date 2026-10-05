<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Account transfer between NovaCPX servers. The source panel packs an account (website files, MySQL databases, login, settings)
 * into a bundle and gives the admin a single-use link; the target panel downloads it, creates the account and unpacks it.
 * Not carried over: mailboxes and their mail, DNS records (the target builds its own zone), SSL certificates, cron jobs, FTP
 * users, addon domains (listed in the result so they can be added again).
 */
require_once __DIR__ . '/Root.php';
require_once __DIR__ . '/DatabaseManager.php';

class Transfer {
    public const LINK_HOURS = 2;

    private static function log(string $dir, string $user, string $status, string $detail = ''): void {
        DB::getInstance()->execute("INSERT INTO transfer_log (direction, username, status, detail) VALUES (?,?,?,?)", [$dir, $user, $status, $detail]);
    }

    private static function cleanup(string $id): void {
        try { Root::ok('transfer.cleanup', ['id' => $id]); } catch (RuntimeException $e) { /* nothing left to remove */ }
    }

    /** Remove used or expired links and their bundles. */
    public static function purge(): void {
        $db = DB::getInstance();
        foreach ($db->fetchAll("SELECT token_hash, bundle_id FROM transfer_tokens WHERE used = 1 OR expires_at < datetime('now')") as $t) {
            self::cleanup($t['bundle_id']);
            $db->execute("DELETE FROM transfer_tokens WHERE token_hash = ?", [$t['token_hash']]);
        }
    }

    /** @return array{url:string,expires:string,size:int} */
    public static function export(array $acct, string $host): array {
        $db = DB::getInstance();
        self::purge();
        $user = $db->fetchOne("SELECT * FROM users WHERE id = ?", [$acct['user_id']]);
        $pkg  = $acct['package_id'] ? $db->fetchOne("SELECT name FROM packages WHERE id = ?", [$acct['package_id']]) : null;
        $dbs = []; $names = [];
        foreach ($db->fetchAll("SELECT db_name, db_user, db_pass FROM `databases` WHERE account_id = ? AND db_type = 'mysql'", [$acct['id']]) as $d) {
            $dbs[] = ['name' => $d['db_name'], 'user' => $d['db_user'], 'pass' => decrypt($d['db_pass'])];
            $names[] = $d['db_name'];
        }
        $meta = [
            'username'      => $acct['username'],
            'domain'        => $acct['domain'],
            'email'         => $user['email'],
            'password_hash' => $user['password'],
            'php_version'   => $acct['php_version'],
            'package'       => $pkg['name'] ?? null,
            'addon_domains' => array_column($db->fetchAll("SELECT domain FROM domains WHERE account_id = ? AND type != 'main'", [$acct['id']]), 'domain'),
            'databases'     => $dbs,
            'exported_at'   => gmdate('c'),
        ];
        $id = bin2hex(random_bytes(16));
        $r = Root::json('transfer.export', ['username' => $acct['username'], 'id' => $id, 'databases' => $names, 'meta' => $meta]);
        $token = bin2hex(random_bytes(32));
        $expires = gmdate('Y-m-d H:i:s', time() + self::LINK_HOURS * 3600);
        $db->execute("INSERT INTO transfer_tokens (token_hash, bundle_id, username, expires_at) VALUES (?,?,?,?)", [hash('sha256', $token), $id, $acct['username'], $expires]);
        self::log('export', $acct['username'], 'ready', round($r['size'] / 1048576, 1) . ' MB');
        return ['url' => "https://{$host}/api/transferdl/get?token={$token}&sum={$r['sha256']}", 'expires' => $expires . ' UTC', 'size' => (int)$r['size']];
    }

    /** Pull a bundle from another panel and build the account. @return array{username:string,domain:string,databases:string[],notes:string[]} */
    public static function import(string $link): array {
        $db = DB::getInstance();
        if (!preg_match('#^(https://[^?\s]+/api/transferdl/get\?token=[0-9a-f]{64})(?:&sum=([0-9a-f]{64}))?$#', trim($link), $m)) throw new RuntimeException('That is not a NovaCPX transfer link');
        $id = bin2hex(random_bytes(16));
        $f = Root::json('transfer.fetch', ['id' => $id, 'url' => $m[1], 'sha256' => $m[2] ?? '']);
        $meta = $f['meta'];
        $username = (string)($meta['username'] ?? '');
        $domain   = (string)($meta['domain'] ?? '');
        $email    = (string)($meta['email'] ?? '');
        $hash     = (string)($meta['password_hash'] ?? '');
        try {
            if ($db->fetchOne("SELECT id FROM accounts WHERE username = ?", [$username]) || $db->fetchOne("SELECT id FROM users WHERE username = ?", [$username]))
                throw new RuntimeException("An account named {$username} already exists on this server");
            if ($db->fetchOne("SELECT id FROM domains WHERE domain = ?", [$domain])) throw new RuntimeException("{$domain} is already hosted on this server");
            if ($db->fetchOne("SELECT id FROM users WHERE email = ? AND role = 'user'", [$email])) throw new RuntimeException("The e-mail address {$email} is already used by another account here");
            if (!preg_match('/^\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/', $hash)) throw new RuntimeException('The bundle has no valid login');
        } catch (RuntimeException $e) {
            self::cleanup($id);
            self::log('import', $username ?: '?', 'failed', $e->getMessage());
            throw $e;
        }
        $notes = [];
        $pkg = !empty($meta['package']) ? $db->fetchOne("SELECT id FROM packages WHERE name = ? ORDER BY owner_id IS NULL DESC LIMIT 1", [$meta['package']]) : null;
        if (!empty($meta['package']) && !$pkg) $notes[] = "Package \"{$meta['package']}\" does not exist here - the account has no package until you assign one.";

        $db->beginTransaction();
        try {
            $userId = (int)$db->insert("INSERT INTO users (username, password, email, role, status) VALUES (?,?,?,?,?)", [$username, $hash, $email, 'user', 'active']);
            AccountManager::create(['username' => $username, 'domain' => $domain, 'user_id' => $userId, 'package_id' => $pkg['id'] ?? 0,
                                    'php_version' => $meta['php_version'] ?? null, 'password' => bin2hex(random_bytes(12))]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            self::cleanup($id);
            self::log('import', $username, 'failed', $e->getMessage());
            throw new RuntimeException($e->getMessage());
        }
        $acctId = (int)($db->fetchOne("SELECT id FROM accounts WHERE username = ?", [$username])['id'] ?? 0);

        try {
            $r = Root::json('transfer.restore', ['username' => $username, 'id' => $id]);
            foreach ((array)($meta['databases'] ?? []) as $d) {
                $db->insert("INSERT INTO `databases`(account_id, db_name, db_user, db_pass, db_type) VALUES (?,?,?,?,?)", [$acctId, $d['name'], $d['user'], encrypt($d['pass']), 'mysql']);
            }
        } catch (Throwable $e) {
            self::cleanup($id);
            // the account was created but could not be filled: remove it again so the admin can simply retry
            try { AccountManager::terminate($acctId); } catch (Throwable $x) { error_log('[transfer] cleanup of ' . $username . ': ' . $x->getMessage()); }
            self::log('import', $username, 'failed', $e->getMessage());
            throw new RuntimeException('Import failed and was undone: ' . $e->getMessage());
        }
        if (!empty($meta['addon_domains'])) $notes[] = 'Addon domains to add again here: ' . implode(', ', $meta['addon_domains']);
        $notes[] = 'Mailboxes, DNS records, SSL certificates, cron jobs and FTP users are not carried over - set them up here, then point DNS at this server.';
        self::log('import', $username, 'done', count($r['databases']) . ' database(s)');
        return ['username' => $username, 'domain' => $domain, 'databases' => $r['databases'], 'notes' => $notes];
    }
}
