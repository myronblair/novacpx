<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * AccountManager — creates/suspends/terminates Linux hosting accounts
 * Each account = system user + home dir + vhost + DNS zone + mail domain
 */
require_once __DIR__ . '/Root.php';

class AccountManager {

    public static function create(array $data): array {
        $db       = DB::getInstance();
        $username = strtolower(preg_replace('/[^a-z0-9_]/', '', $data['username']));
        $domain   = strtolower(trim($data['domain']));
        $userId   = (int)$data['user_id'];
        $pkgId    = (int)($data['package_id'] ?? 0);
        $phpVer   = $data['php_version'] ?? PHP_DEFAULT;
        $webSrv   = WEB_SERVER;

        if (strlen($username) < 2 || strlen($username) > 32) throw new RuntimeException("Username must be 2-32 chars");
        if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) throw new RuntimeException("Invalid domain");

        // Check uniqueness
        if ($db->fetchOne("SELECT id FROM accounts WHERE username = ?", [$username])) throw new RuntimeException("Username taken");
        if ($db->fetchOne("SELECT id FROM domains WHERE domain = ?", [$domain])) throw new RuntimeException("Domain already hosted");

        $homeDir  = "/home/{$username}";
        $docRoot  = "{$homeDir}/public_html";
        $password = $data['password'] ?? bin2hex(random_bytes(8));

        // Create Linux user and home directory first
        Root::ok('user.add', ['username' => $username]);
        Root::ok('user.passwd', ['username' => $username, 'password' => $password]);
        Root::ok('home.init', ['username' => $username]);

        // Default index page — use custom template from settings if set, else built-in
        $customTpl = null;
        try {
            $db2 = DB::getInstance();
            $tplRow = $db2->fetchOne("SELECT value FROM settings WHERE key='default_index_template'");
            $customTpl = $tplRow ? trim($tplRow['value']) : null;
        } catch (Throwable $e) {}

        $html = $customTpl
            ? str_replace(['{domain}', '{username}'], [$domain, $username], $customTpl)
            : "<!DOCTYPE html>\n<html lang=\"en\">\n<head><meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n<title>Welcome to {$domain}</title>\n<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f1117;color:#e2e4f0;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center}.wrap{padding:3rem 2rem}.domain{font-size:2rem;font-weight:700;background:linear-gradient(135deg,#6366f1,#0ea5e9);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:1rem}.sub{color:#8b90a8;font-size:1rem;margin-bottom:2rem}.badge{display:inline-block;padding:.4rem 1rem;border:1px solid #2e3350;border-radius:6px;font-size:.8rem;color:#8b90a8}</style>\n</head>\n<body><div class=\"wrap\">\n<div class=\"domain\">{$domain}</div>\n<p class=\"sub\">Your website is ready. Upload your files to get started.</p>\n<span class=\"badge\">Hosted by TomTom Enterprises, Powered by NovaCPX</span>\n</div></body></html>";

        Root::run('home.index', ['username' => $username, 'html' => $html]);

        // NOTE: caller (accounts.php) already owns the outer transaction -- do not
        // begin/commit/rollback here, PDO doesn't support nested transactions.
        try {
            $acctId = (int)$db->insert(
                "INSERT INTO accounts (user_id, username, domain, home_dir, package_id, php_version, web_server) VALUES (?,?,?,?,?,?,?)",
                [$userId, $username, $domain, $homeDir, $pkgId ?: null, $phpVer, $webSrv]
            );

            $db->insert(
                "INSERT INTO domains (account_id, domain, type, document_root) VALUES (?,?,?,?)",
                [$acctId, $domain, 'main', $docRoot]
            );

            // Create web vhost
            VhostManager::create($username, $domain, $docRoot, $phpVer);

            // Create DNS zone
            DNSManager::createZone($acctId, $domain);

            // Auto-provision SPF, DKIM, DMARC records
            self::provisionEmailDNS($acctId, $domain);

            // Create PHP-FPM pool
            PHPManager::createPool($username, $phpVer);

        } catch (Throwable $e) {
            // Clean up Linux user and PHP-FPM pool so orphaned configs can't crash php-fpm
            Root::run('user.del', ['username' => $username]);
            PHPManager::removePool($username);
            throw $e;
        }

        novacpx_log('info', "Account created: $username ($domain)");
        return ['account_id' => $acctId, 'username' => $username, 'domain' => $domain, 'home_dir' => $homeDir];
    }

    public static function suspend(int $acctId, string $reason = ''): void {
        $db   = DB::getInstance();
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$acctId]);
        if (!$acct) throw new RuntimeException("Account not found");

        Root::ok('user.lock', ['username' => $acct['username']]);
        VhostManager::suspend($acct['username'], $acct['domain']);
        $db->execute("UPDATE accounts SET status = 'suspended', suspended_at = NOW() WHERE id = ?", [$acctId]);
        novacpx_log('info', "Account suspended: {$acct['username']} — $reason");
    }

    public static function unsuspend(int $acctId): void {
        $db   = DB::getInstance();
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$acctId]);
        if (!$acct) throw new RuntimeException("Account not found");

        Root::ok('user.lock', ['username' => $acct['username'], 'unlock' => true]);
        VhostManager::unsuspend($acct['username'], $acct['domain']);
        $db->execute("UPDATE accounts SET status = 'active', suspended_at = NULL WHERE id = ?", [$acctId]);
    }

    public static function terminate(int $acctId): void {
        require_once NOVACPX_LIB . '/DatabaseManager.php';
        $db   = DB::getInstance();
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$acctId]);
        if (!$acct) throw new RuntimeException("Account not found");

        // Remove vhost
        VhostManager::remove($acct['username'], $acct['domain']);

        // Remove DNS zone
        DNSManager::removeZone($acct['domain']);

        // Drop databases
        $dbs = $db->fetchAll("SELECT * FROM databases WHERE account_id = ?", [$acctId]);
        foreach ($dbs as $dbe) { DatabaseManager::drop($dbe['db_name'], $dbe['db_user'], $dbe['db_type']); }

        // Remove PHP-FPM pool
        PHPManager::removePool($acct['username']);

        // Remove Linux user and home dir
        Root::run('user.del', ['username' => $acct['username']]);

        // Remove from DB (cascade handles child tables)
        $db->execute("DELETE FROM users WHERE id = ?", [$acct['user_id']]);
        $db->execute("DELETE FROM accounts WHERE id = ?", [$acctId]);
        novacpx_log('info', "Account terminated: {$acct['username']}");
    }

    public static function provisionEmailDNS(int $acctId, string $domain): void {
        // Generate DKIM keypair
        $keyDir = "/etc/opendkim/keys/{$domain}";
        $gen    = Root::json('dkim.genkey', ['domain' => $domain, 'selector' => 'mail']);

        // Parse public key from the generated .txt record
        $keyTxt = (string)($gen['txt'] ?? '');
        preg_match_all('/"([^"]*)"/', $keyTxt, $chunks);
        preg_match('/p=([A-Za-z0-9+\/=]+)/', implode('', $chunks[1]), $m);   // the record is split over several quoted strings
        $pubKey = $m[1] ?? '';

        if ($pubKey) {
            // Register domain/key in opendkim tables
            Root::run('dkim.register', ['domain' => $domain, 'selector' => 'mail']);

            // Store in DB
            $db = DB::getInstance();
            $db->execute(
                "INSERT INTO dkim_keys (account_id, domain, selector, public_key, private_key_path, created_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE public_key=VALUES(public_key)",
                [$acctId, $domain, 'mail', $pubKey, "{$keyDir}/mail.private"]
            );

            // DKIM TXT record
            $zoneRow = DB::getInstance()->fetchOne("SELECT id FROM dns_zones WHERE account_id=? AND domain=?", [$acctId, $domain]);
            if ($zoneRow) DNSManager::addRecord((int)$zoneRow['id'], 'mail._domainkey', 'TXT', "v=DKIM1; k=rsa; p={$pubKey}", 300);
        }

        // SPF + DMARC — look up zone once
        $db2     = DB::getInstance();
        $zoneRow = $zoneRow ?? $db2->fetchOne("SELECT id FROM dns_zones WHERE account_id=? AND domain=?", [$acctId, $domain]);
        if ($zoneRow) {
            DNSManager::addRecord((int)$zoneRow['id'], '@',      'TXT', "v=spf1 mx a ~all", 3600);
            DNSManager::addRecord((int)$zoneRow['id'], '_dmarc', 'TXT', "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$domain}", 3600);
        }

        novacpx_log('info', "Email DNS provisioned for $domain");
    }

    public static function rotateDKIM(int $acctId, string $domain): string {
        $db       = DB::getInstance();
        $selector = 'mail' . date('Ym');
        $keyDir   = "/etc/opendkim/keys/{$domain}";
        $gen      = Root::json('dkim.genkey', ['domain' => $domain, 'selector' => $selector]);

        $keyTxt = (string)($gen['txt'] ?? '');
        preg_match_all('/"([^"]*)"/', $keyTxt, $chunks);
        preg_match('/p=([A-Za-z0-9+\/=]+)/', implode('', $chunks[1]), $m);   // the record is split over several quoted strings
        $pubKey = $m[1] ?? '';
        if (!$pubKey) throw new RuntimeException("DKIM key generation failed");

        // Update the signing/key tables to the new selector
        Root::run('dkim.register', ['domain' => $domain, 'selector' => $selector]);

        $db->execute(
            "INSERT INTO dkim_keys (account_id, domain, selector, public_key, private_key_path, created_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE selector=VALUES(selector), public_key=VALUES(public_key), private_key_path=VALUES(private_key_path)",
            [$acctId, $domain, $selector, $pubKey, "{$keyDir}/{$selector}.private"]
        );

        // Add new TXT record, remove old mail._domainkey
        $zoneRow = $db->fetchOne("SELECT id FROM dns_zones WHERE account_id=? AND domain=?", [$acctId, $domain]);
        if ($zoneRow) DNSManager::addRecord((int)$zoneRow['id'], "{$selector}._domainkey", 'TXT', "v=DKIM1; k=rsa; p={$pubKey}", 300);
        novacpx_log('info', "DKIM rotated for $domain, new selector: $selector");
        return $selector;
    }

    public static function getDiskUsage(string $homeDir): int {
        $out = trim(shell_exec("du -sm " . escapeshellarg($homeDir) . " 2>/dev/null | awk '{print $1}'") ?: '0');
        return (int)$out;
    }

    private static function shell(string $cmd): string {
        // Unprivileged helper only; everything that needs root goes through Root (see lib/Root.php, deploy/novacpx-root).
        $out = shell_exec($cmd . ' 2>&1');
        novacpx_log('debug', "shell: $cmd");
        return $out ?: '';
    }
}
