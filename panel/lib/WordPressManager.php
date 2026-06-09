<?php
class WordPressManager {
    private \PDO $db;
    private \PDO $provDb;
    private string $wpcli = '/usr/local/bin/wp';

    public function __construct() {
        $this->db = DB::getInstance()->pdo();
        // Separate privileged connection for CREATE DATABASE / CREATE USER / GRANT
        $this->provDb = $this->makeProvPdo();
        $this->ensureWpCli();
    }

    private function makeProvPdo(): \PDO {
        $wpUser = DB_WP_USER;
        $wpPass = DB_WP_PASS;
        if ($wpUser) {
            try {
                return new \PDO(
                    'mysql:host=' . DB_HOST . ';charset=utf8mb4',
                    $wpUser, $wpPass,
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
                );
            } catch (\PDOException $e) {
                // Fall through to root socket attempt
            }
        }
        // Fallback: root via Unix socket (works on fresh installs where root has no password)
        return new \PDO(
            'mysql:host=localhost;unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4',
            'root', '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    // ── Install ───────────────────────────────────────────────────────────────
    public function install(int $accountId, string $domain, string $path,
                            string $adminUser, string $adminEmail, string $adminPass,
                            string $siteTitle): array {
        $account = $this->getAccount($accountId);
        $docRoot = $account['document_root'] . rtrim($path, '/');
        $dbName  = 'wp_' . preg_replace('/[^a-z0-9]/', '_', strtolower($account['username'])) . '_' . substr(md5($domain), 0, 6);
        $dbPass  = bin2hex(random_bytes(12));
        $dbUser  = substr($dbName, 0, 32);

        // Create DB
        $this->provDb->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->provDb->exec("CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'");
        $this->provDb->exec("GRANT ALL ON `{$dbName}`.* TO '{$dbUser}'@'localhost'");

        // Download WP + install
        $sysUser = $account['system_user'] ?? 'www-data';
        $this->wp($docRoot, "core download --locale=en_US", $sysUser);
        $this->wp($docRoot, "config create --dbname={$dbName} --dbuser={$dbUser} --dbpass={$dbPass} --dbhost=localhost --skip-check", $sysUser);
        $this->wp($docRoot, sprintf(
            'core install --url=https://%s --title="%s" --admin_user=%s --admin_password=%s --admin_email=%s --skip-email',
            escapeshellarg($domain . $path), escapeshellarg($siteTitle),
            escapeshellarg($adminUser), escapeshellarg($adminPass), escapeshellarg($adminEmail)
        ), $sysUser);

        // Store in DB
        $stmt = $this->db->prepare("INSERT INTO wordpress_installs
            (account_id, domain, path, db_name, db_user, db_pass, admin_user, admin_email, wp_version, status)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$accountId, $domain, $path, $dbName, $dbUser, $dbPass, $adminUser, $adminEmail,
            $this->getVersion($docRoot, $sysUser), 'active']);
        $id = $this->db->lastInsertId();

        return ['id' => $id, 'db_name' => $dbName, 'admin_user' => $adminUser, 'admin_pass' => $adminPass];
    }

    // ── List ──────────────────────────────────────────────────────────────────
    public function list(int $accountId = 0): array {
        $sql = $accountId
            ? "SELECT w.*, a.domain as account_domain FROM wordpress_installs w JOIN accounts a ON w.account_id=a.id WHERE w.account_id=? ORDER BY w.created_at DESC"
            : "SELECT w.*, a.domain as account_domain FROM wordpress_installs w JOIN accounts a ON w.account_id=a.id ORDER BY w.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $accountId ? $stmt->execute([$accountId]) : $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Update ────────────────────────────────────────────────────────────────
    public function updateCore(int $id): string {
        [$install, $sysUser, $docRoot] = $this->resolve($id);
        $out = $this->wp($docRoot, 'core update', $sysUser);
        $ver = $this->getVersion($docRoot, $sysUser);
        $this->db->prepare("UPDATE wordpress_installs SET wp_version=? WHERE id=?")->execute([$ver, $id]);
        return $out;
    }

    public function updatePlugins(int $id): string {
        [$install, $sysUser, $docRoot] = $this->resolve($id);
        return $this->wp($docRoot, 'plugin update --all', $sysUser);
    }

    public function updateThemes(int $id): string {
        [$install, $sysUser, $docRoot] = $this->resolve($id);
        return $this->wp($docRoot, 'theme update --all', $sysUser);
    }

    // ── Staging clone ─────────────────────────────────────────────────────────
    public function cloneStaging(int $id): array {
        [$install, $sysUser, $docRoot] = $this->resolve($id);
        $stagingPath   = $install['path'] . '_staging';
        $stagingDomain = 'staging.' . $install['domain'];
        $stagingRoot   = dirname($docRoot) . rtrim($stagingPath, '/');

        // Copy files
        $this->exec("cp -r {$docRoot} {$stagingRoot}");

        // Clone DB
        $stagingDb   = $install['db_name'] . '_staging';
        $stagingDbPw = bin2hex(random_bytes(8));
        $this->provDb->exec("CREATE DATABASE IF NOT EXISTS `{$stagingDb}`");
        $this->provDb->exec("CREATE USER IF NOT EXISTS '{$stagingDb}'@'localhost' IDENTIFIED BY '{$stagingDbPw}'");
        $this->provDb->exec("GRANT ALL ON `{$stagingDb}`.* TO '{$stagingDb}'@'localhost'");
        $this->exec("mysqldump {$install['db_name']} | mysql {$stagingDb}");

        // Update staging wp-config
        $this->wp($stagingRoot, "config set DB_NAME {$stagingDb}", $sysUser);
        $this->wp($stagingRoot, "config set DB_USER {$stagingDb}", $sysUser);
        $this->wp($stagingRoot, "config set DB_PASSWORD {$stagingDbPw}", $sysUser);
        $this->wp($stagingRoot, "search-replace https://{$install['domain']} https://{$stagingDomain} --all-tables", $sysUser);

        // Record staging install
        $stmt = $this->db->prepare("INSERT INTO wordpress_installs
            (account_id,domain,path,db_name,db_user,db_pass,admin_user,admin_email,wp_version,status,staging_of)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$install['account_id'], $stagingDomain, $stagingPath,
            $stagingDb, $stagingDb, $stagingDbPw,
            $install['admin_user'], $install['admin_email'], $install['wp_version'], 'active', $id]);

        return ['domain' => $stagingDomain, 'path' => $stagingPath];
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    public function delete(int $id): bool {
        [$install, $sysUser, $docRoot] = $this->resolve($id);
        $this->exec("rm -rf {$docRoot}");
        $this->provDb->exec("DROP DATABASE IF EXISTS `{$install['db_name']}`");
        $this->provDb->exec("DROP USER IF EXISTS '{$install['db_user']}'@'localhost'");
        $this->db->prepare("DELETE FROM wordpress_installs WHERE id=?")->execute([$id]);
        return true;
    }

    // ── Info ──────────────────────────────────────────────────────────────────
    public function info(int $id): array {
        [$install, $sysUser, $docRoot] = $this->resolve($id);
        $plugins = $this->wp($docRoot, 'plugin list --format=json', $sysUser);
        $themes  = $this->wp($docRoot, 'theme list --format=json',  $sysUser);
        return [
            'install'  => $install,
            'plugins'  => json_decode($plugins,  true) ?? [],
            'themes'   => json_decode($themes,   true) ?? [],
            'version'  => $this->getVersion($docRoot, $sysUser),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function wp(string $path, string $cmd, string $user): string {
        $safe = escapeshellarg($path);
        $out  = []; $rc = 0;
        exec("sudo -u {$user} {$this->wpcli} --path={$safe} --allow-root {$cmd} 2>&1", $out, $rc);
        return implode("\n", $out);
    }

    private function exec(string $cmd): string {
        $out = []; exec($cmd . ' 2>&1', $out); return implode("\n", $out);
    }

    private function getVersion(string $path, string $user): string {
        $v = trim($this->wp($path, 'core version', $user));
        return $v ?: 'unknown';
    }

    private function resolve(int $id): array {
        $stmt = $this->db->prepare("SELECT w.*, a.document_root, a.system_user, a.username FROM wordpress_installs w JOIN accounts a ON w.account_id=a.id WHERE w.id=?");
        $stmt->execute([$id]);
        $install = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$install) throw new RuntimeException("WordPress install #{$id} not found");
        $docRoot = $install['document_root'] . rtrim($install['path'], '/');
        $sysUser = $install['system_user'] ?? 'www-data';
        return [$install, $sysUser, $docRoot];
    }

    private function getAccount(int $id): array {
        $stmt = $this->db->prepare("SELECT * FROM accounts WHERE id=?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException("Account #{$id} not found");
    }

    // ── Streaming install (yields progress lines for SSE) ─────────────────────
    public function installStream(int $accountId, string $domain, string $path,
                                  string $adminUser, string $adminEmail, string $adminPass,
                                  string $siteTitle): \Generator {
        yield "▶ Resolving account...\n";
        $account = $this->getAccount($accountId);
        $docRoot = $account['document_root'] . rtrim($path, '/');
        $dbName  = 'wp_' . preg_replace('/[^a-z0-9]/', '_', strtolower($account['username'])) . '_' . substr(md5($domain), 0, 6);
        $dbPass  = bin2hex(random_bytes(12));
        $dbUser  = substr($dbName, 0, 32);
        $sysUser = $account['system_user'] ?? 'www-data';

        yield "▶ Creating MySQL database ({$dbName})...\n";
        $this->provDb->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->provDb->exec("CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPass}'");
        $this->provDb->exec("GRANT ALL ON `{$dbName}`.* TO '{$dbUser}'@'localhost'");
        yield "  ✓ Database ready\n";

        yield "▶ Downloading WordPress core (this takes 20-40 seconds)...\n";
        $out = $this->wp($docRoot, "core download --locale=en_US", $sysUser);
        yield $out ? "  " . trim($out) . "\n" : "  ✓ Core downloaded\n";

        yield "▶ Creating wp-config.php...\n";
        $out = $this->wp($docRoot, "config create --dbname={$dbName} --dbuser={$dbUser} --dbpass={$dbPass} --dbhost=localhost --skip-check", $sysUser);
        yield $out ? "  " . trim($out) . "\n" : "  ✓ wp-config.php created\n";

        yield "▶ Running WordPress installer...\n";
        $out = $this->wp($docRoot, sprintf(
            'core install --url=https://%s --title=%s --admin_user=%s --admin_password=%s --admin_email=%s --skip-email',
            escapeshellarg($domain . $path),
            escapeshellarg($siteTitle),
            escapeshellarg($adminUser),
            escapeshellarg($adminPass),
            escapeshellarg($adminEmail)
        ), $sysUser);
        yield $out ? "  " . trim($out) . "\n" : "  ✓ WordPress installed\n";

        yield "▶ Saving installation record...\n";
        $stmt = $this->db->prepare("INSERT INTO wordpress_installs
            (account_id, domain, path, db_name, db_user, db_pass, admin_user, admin_email, wp_version, status)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$accountId, $domain, $path, $dbName, $dbUser, $dbPass, $adminUser, $adminEmail,
            $this->getVersion($docRoot, $sysUser), 'active']);
        $id = (int)$this->db->lastInsertId();

        yield "  ✓ Done — WordPress ID #{$id}\n";
        yield '__DONE__' . json_encode(['id' => $id, 'admin_user' => $adminUser, 'admin_pass' => $adminPass, 'domain' => $domain]) . "\n";
    }

    private function ensureWpCli(): void {
        if (!file_exists($this->wpcli)) {
            file_put_contents('/tmp/wp-cli.phar', file_get_contents('https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar'));
            rename('/tmp/wp-cli.phar', $this->wpcli);
            chmod($this->wpcli, 0755);
        }
    }
}
