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
        self::shell("mkdir -p {$docRoot} {$homeDir}/logs {$homeDir}/tmp");
        self::shell("chown -R {$username}:www-data {$homeDir}");
        self::shell("chmod 750 {$homeDir}; chmod 755 {$docRoot}");

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

    public static function getDiskUsage(string $homeDir): int {
        $out = trim(shell_exec("du -sm " . escapeshellarg($homeDir) . " 2>/dev/null | awk '{print $1}'") ?: '0');
        return (int)$out;
    }

    private static function shell(string $cmd): string {
        $out = shell_exec($cmd . ' 2>&1');
        novacpx_log('debug', "shell: $cmd");
        return $out ?: '';
    }
}
