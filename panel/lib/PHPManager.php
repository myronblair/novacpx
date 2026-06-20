<?php
/**
 * PHPManager — per-account PHP-FPM pools + version switching
 */
if (!class_exists('VhostManager')) require_once __DIR__ . '/VhostManager.php';

class PHPManager {

    private static string $poolDir = '/etc/php/{ver}/fpm/pool.d';

    private static function writeFile(string $path, string $content): void {
        $tmp = tempnam('/tmp', 'ncpx_pool_');
        file_put_contents($tmp, $content);
        shell_exec("sudo tee " . escapeshellarg($path) . " > /dev/null < " . escapeshellarg($tmp));
        @unlink($tmp);
    }

    public static function createPool(string $username, string $phpVer): void {
        $poolFile = str_replace('{ver}', $phpVer, self::$poolDir) . "/{$username}.conf";
        $homeDir  = "/home/{$username}";
        $sock     = "/run/php/php{$phpVer}-fpm-{$username}.sock";

        self::writeFile($poolFile, "[{$username}]
user  = {$username}
group = www-data
listen = {$sock}
listen.owner = www-data
listen.group = www-data
listen.mode  = 0660

pm = ondemand
pm.max_children     = 5
pm.process_idle_timeout = 10s
pm.max_requests     = 500

php_admin_value[error_log]     = {$homeDir}/logs/php.log
php_admin_value[open_basedir]  = {$homeDir}/:/tmp/
php_admin_flag[log_errors]     = on
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
php_value[upload_max_filesize] = 64M
php_value[post_max_size]       = 64M
php_value[memory_limit]        = 256M
php_value[max_execution_time]  = 30
");
        self::reloadFPM($phpVer);
    }

    public static function removePool(string $username): void {
        foreach (['7.4','8.1','8.2','8.3'] as $ver) {
            $file = str_replace('{ver}', $ver, self::$poolDir) . "/{$username}.conf";
            shell_exec("sudo /bin/rm -f " . escapeshellarg($file) . " 2>/dev/null");
            self::reloadFPM($ver);
        }
    }

    public static function switchVersion(int $accountId, string $newVer): void {
        $db   = DB::getInstance();
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$accountId]);
        if (!$acct) throw new RuntimeException("Account not found");

        $oldVer = $acct['php_version'];
        if ($oldVer === $newVer) return;

        // Remove old pool, create new one
        $oldPool = str_replace('{ver}', $oldVer, self::$poolDir) . "/{$acct['username']}.conf";
        shell_exec("sudo rm -f " . escapeshellarg($oldPool) . " 2>/dev/null");
        self::reloadFPM($oldVer);

        self::createPool($acct['username'], $newVer);

        // Update vhost to use new socket
        VhostManager::create($acct['username'], $acct['domain'], $acct['home_dir'] . '/public_html', $newVer);

        $db->execute("UPDATE accounts SET php_version = ? WHERE id = ?", [$newVer, $accountId]);
        $db->execute("UPDATE php_configs SET php_version = ?, updated_at = NOW() WHERE account_id = ?", [$newVer, $accountId]);
    }

    public static function updateConfig(int $accountId, array $cfg): void {
        $db   = DB::getInstance();
        $acct = $db->fetchOne("SELECT username, php_version FROM accounts WHERE id = ?", [$accountId]);
        if (!$acct) throw new RuntimeException("Account not found");

        $poolFile = str_replace('{ver}', $acct['php_version'], self::$poolDir) . "/{$acct['username']}.conf";
        if (!file_exists($poolFile)) self::createPool($acct['username'], $acct['php_version']);

        $content = file_get_contents($poolFile) ?: '';
        $map = [
            'memory_limit'        => 'php_value[memory_limit]',
            'max_execution_time'  => 'php_value[max_execution_time]',
            'upload_max_filesize' => 'php_value[upload_max_filesize]',
            'post_max_size'       => 'php_value[post_max_size]',
        ];
        foreach ($map as $key => $iniKey) {
            if (isset($cfg[$key])) {
                $content = preg_replace("/{$iniKey}\s*=.*/", "{$iniKey} = {$cfg[$key]}", $content);
            }
        }
        self::writeFile($poolFile, $content);
        self::reloadFPM($acct['php_version']);

        $db->execute(
            "INSERT INTO php_configs (account_id, php_version, memory_limit, max_execution_time, upload_max_filesize, post_max_size, updated_at)
             VALUES (?,?,?,?,?,?,datetime('now'))
             ON CONFLICT(account_id) DO UPDATE SET php_version=excluded.php_version,
             memory_limit=excluded.memory_limit, max_execution_time=excluded.max_execution_time,
             upload_max_filesize=excluded.upload_max_filesize, post_max_size=excluded.post_max_size,
             updated_at=excluded.updated_at",
            [$accountId, $acct['php_version'], $cfg['memory_limit'] ?? '256M', $cfg['max_execution_time'] ?? 30,
             $cfg['upload_max_filesize'] ?? '64M', $cfg['post_max_size'] ?? '64M']
        );
    }

    public static function listExtensions(string $phpVer): array {
        $out = shell_exec("php{$phpVer} -m 2>/dev/null") ?: '';
        return array_values(array_filter(explode("\n", $out), fn($l) => $l && !str_starts_with($l, '[')));
    }

    private static function reloadFPM(string $ver): void {
        // Write a flag file instead of reloading inline — the cron runner picks this up
        // within 60s and reloads fpm outside of any HTTP request, avoiding 502s.
        @file_put_contents('/tmp/novacpx-fpm-reload-' . $ver, '1');
    }
}
