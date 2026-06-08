<?php
/**
 * AccountManager — creates/suspends/terminates Linux hosting accounts
 * Each account = system user + home dir + vhost + DNS zone + mail domain
 */
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

        // Create Linux user
        self::shell("useradd -m -d {$homeDir} -s /sbin/nologin -G www-data " . escapeshellarg($username));
        self::shell("echo " . escapeshellarg("{$username}:{$password}") . " | chpasswd");
        self::shell("sudo mkdir -p {$docRoot} {$homeDir}/logs {$homeDir}/tmp");
        self::shell("sudo chown -R {$username}:www-data {$homeDir}");
        self::shell("sudo chmod 750 {$homeDir}"); self::shell("sudo chmod 775 {$docRoot}");

        // Default index page
        file_put_contents("{$docRoot}/index.html",
            "<html><body style='font-family:sans-serif;text-align:center;padding:4rem'><h1>Welcome to {$domain}</h1><p>Hosted by NovaCPX</p></body></html>"
        );

        // Save account to DB
        $acctId = (int)$db->insert(
            "INSERT INTO accounts (user_id, username, domain, home_dir, package_id, php_version, web_server) VALUES (?,?,?,?,?,?,?)",
            [$userId, $username, $domain, $homeDir, $pkgId ?: null, $phpVer, $webSrv]
        );

        // Save domain
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

        novacpx_log('info', "Account created: $username ($domain)");
        return ['account_id' => $acctId, 'username' => $username, 'domain' => $domain, 'home_dir' => $homeDir];
    }

    public static function suspend(int $acctId, string $reason = ''): void {
        $db   = DB::getInstance();
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$acctId]);
        if (!$acct) throw new RuntimeException("Account not found");

        self::shell("usermod -L " . escapeshellarg($acct['username']));
        VhostManager::suspend($acct['username'], $acct['domain']);
        $db->execute("UPDATE accounts SET status = 'suspended', suspended_at = NOW() WHERE id = ?", [$acctId]);
        novacpx_log('info', "Account suspended: {$acct['username']} — $reason");
    }

    public static function unsuspend(int $acctId): void {
        $db   = DB::getInstance();
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$acctId]);
        if (!$acct) throw new RuntimeException("Account not found");

        self::shell("usermod -U " . escapeshellarg($acct['username']));
        VhostManager::unsuspend($acct['username'], $acct['domain']);
        $db->execute("UPDATE accounts SET status = 'active', suspended_at = NULL WHERE id = ?", [$acctId]);
    }

    public static function terminate(int $acctId): void {
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
        self::shell("userdel -r " . escapeshellarg($acct['username']) . " 2>/dev/null || true");

        // Remove from DB (cascade handles child tables)
        $db->execute("DELETE FROM users WHERE id = ?", [$acct['user_id']]);
        $db->execute("DELETE FROM accounts WHERE id = ?", [$acctId]);
        novacpx_log('info', "Account terminated: {$acct['username']}");
    }

    public static function provisionEmailDNS(int $acctId, string $domain): void {
        // Generate DKIM keypair
        $keyDir = "/etc/opendkim/keys/{$domain}";
        self::shell("sudo mkdir -p " . escapeshellarg($keyDir));
        self::shell("opendkim-genkey -b 2048 -s mail -d " . escapeshellarg($domain) . " -D " . escapeshellarg($keyDir));
        self::shell("sudo chown -R opendkim:opendkim " . escapeshellarg($keyDir));

        // Parse public key from .txt file
        $keyTxt = @file_get_contents("{$keyDir}/mail.txt") ?: '';
        preg_match('/p=([A-Za-z0-9+\/=]+)/', $keyTxt, $m);
        $pubKey = $m[1] ?? '';

        if ($pubKey) {
            // Register domain/key in opendkim tables
            self::shell("grep -q " . escapeshellarg($domain) . " /etc/opendkim/signing.table 2>/dev/null || echo " . escapeshellarg("*@{$domain} {$domain}") . " >> /etc/opendkim/signing.table");
            self::shell("grep -q " . escapeshellarg($domain) . " /etc/opendkim/key.table 2>/dev/null || echo " . escapeshellarg("{$domain} {$domain}:mail:{$keyDir}/mail.private") . " >> /etc/opendkim/key.table");
            self::shell("systemctl reload opendkim 2>/dev/null || true");

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
        self::shell("sudo mkdir -p " . escapeshellarg($keyDir));
        self::shell("opendkim-genkey -b 2048 -s {$selector} -d " . escapeshellarg($domain) . " -D " . escapeshellarg($keyDir));
        self::shell("sudo chown -R opendkim:opendkim " . escapeshellarg($keyDir));

        $keyTxt = @file_get_contents("{$keyDir}/{$selector}.txt") ?: '';
        preg_match('/p=([A-Za-z0-9+\/=]+)/', $keyTxt, $m);
        $pubKey = $m[1] ?? '';
        if (!$pubKey) throw new RuntimeException("DKIM key generation failed");

        // Update key.table
        $keyTableLine = "{$domain} {$domain}:{$selector}:{$keyDir}/{$selector}.private";
        self::shell("sed -i " . escapeshellarg("/^{$domain} /d") . " /etc/opendkim/key.table 2>/dev/null; echo " . escapeshellarg($keyTableLine) . " >> /etc/opendkim/key.table");
        self::shell("systemctl reload opendkim 2>/dev/null || true");

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
        // Prefix privileged commands with sudo so www-data can run them
        $privileged = ['useradd','userdel','usermod','chpasswd','a2ensite','a2dissite','apache2ctl','certbot','opendkim-genkey','rndc','named-checkzone','systemctl'];
        $cmdBase    = explode(' ', ltrim($cmd))[0];
        foreach ($privileged as $p) {
            if (str_ends_with($cmdBase, $p) || $cmdBase === $p) { $cmd = 'sudo ' . $cmd; break; }
        }
        $out = shell_exec($cmd . ' 2>&1');
        novacpx_log('debug', "shell: $cmd");
        return $out ?: '';
    }
}
