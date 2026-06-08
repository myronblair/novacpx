<?php
/**
 * System endpoint — version info, updates, server stats, services
 * Admin-only actions gated with Auth::require('admin')
 */

Auth::getInstance()->require('admin', 'reseller', 'user');
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

match ($action) {

    // ── Version & Update Info ─────────────────────────────────────────────────
    'version' => (function() use ($db) {
        $installed = $db->fetchOne("SELECT version, installed_at, git_commit FROM novacpx_version ORDER BY id DESC LIMIT 1");
        $gitDir    = NOVACPX_ROOT . '/.git';
        $gitCommit = null;
        $gitBranch = null;
        $gitDirty  = false;

        if (is_dir($gitDir)) {
            $gitCommit = trim(shell_exec("git -C " . escapeshellarg(NOVACPX_ROOT) . " rev-parse --short HEAD 2>/dev/null") ?: '');
            $gitBranch = trim(shell_exec("git -C " . escapeshellarg(NOVACPX_ROOT) . " rev-parse --abbrev-ref HEAD 2>/dev/null") ?: '');
            $status    = shell_exec("git -C " . escapeshellarg(NOVACPX_ROOT) . " status --porcelain 2>/dev/null");
            $gitDirty  = !empty(trim($status));
        }

        Response::success([
            'installed_version' => $installed['version'] ?? NOVACPX_VERSION,
            'installed_at'      => $installed['installed_at'],
            'git_commit'        => $gitCommit ?: ($installed['git_commit'] ?? null),
            'git_branch'        => $gitBranch,
            'git_dirty'         => $gitDirty,
            'php_version'       => PHP_VERSION,
            'os'                => php_uname('s') . ' ' . php_uname('r'),
        ]);
    })(),

    // ── Check for updates ─────────────────────────────────────────────────────
    'check-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $remote = $db->fetchOne("SELECT value FROM settings WHERE `key` = 'git_remote'");
        $gitRemote = $remote['value'] ?? '';
        if (!$gitRemote) Response::error('No git remote configured');

        $output = shell_exec("git -C " . escapeshellarg(NOVACPX_ROOT) . " fetch origin 2>&1 && git -C " . escapeshellarg(NOVACPX_ROOT) . " log HEAD..origin/main --oneline 2>/dev/null");
        $updates = array_values(array_filter(explode("\n", trim($output ?: ''))));

        Response::success([
            'updates_available' => count($updates),
            'commits'           => $updates,
        ]);
    })(),

    // ── Apply update ──────────────────────────────────────────────────────────
    'apply-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $before = trim(shell_exec("git -C " . escapeshellarg(NOVACPX_ROOT) . " rev-parse HEAD 2>/dev/null") ?: '');

        $pull = shell_exec("git -C " . escapeshellarg(NOVACPX_ROOT) . " pull origin main 2>&1");

        $after  = trim(shell_exec("git -C " . escapeshellarg(NOVACPX_ROOT) . " rev-parse HEAD 2>/dev/null") ?: '');
        $changed = $before !== $after;

        if ($changed) {
            // Run any pending DB migrations
            $migrDir = NOVACPX_ROOT . '/db/migrations';
            if (is_dir($migrDir)) {
                foreach (glob("$migrDir/*.sql") as $sql) {
                    $migName = basename($sql, '.sql');
                    $already = $db->fetchOne("SELECT 1 FROM settings WHERE `key` = 'migration_$migName'");
                    if (!$already) {
                        $db->pdo()->exec(file_get_contents($sql));
                        $db->execute("INSERT INTO settings (`key`,`value`) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE `value`=NOW()", ["migration_$migName"]);
                        novacpx_log('info', "Migration applied: $migName");
                    }
                }
            }
            audit('system.update', "novacpx:$before→$after");
            novacpx_log('info', "NovaCPX updated $before → $after");
        }

        Response::success([
            'updated'      => $changed,
            'from_commit'  => $before,
            'to_commit'    => $after,
            'pull_output'  => $pull,
        ]);
    })(),

    // ── Check OS updates ─────────────────────────────────────────────────────
    'check-os-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        shell_exec('apt-get update -qq 2>/dev/null');
        $out = shell_exec('apt-get -s upgrade 2>/dev/null | grep "^Inst " | head -50') ?: '';
        $packages = array_values(array_filter(array_map(function($line) {
            if (preg_match('/^Inst (\S+).*\[(\S+)\].*\((\S+)/', $line, $m)) {
                return ['name' => $m[1], 'from' => $m[2], 'to' => $m[3]];
            } elseif (preg_match('/^Inst (\S+)\s+\((\S+)/', $line, $m)) {
                return ['name' => $m[1], 'from' => '', 'to' => $m[2]];
            }
            return null;
        }, explode("\n", trim($out)))));
        $security = array_filter($packages, fn($p) => str_contains($p['name'] ?? '', 'security') ||
            (bool)shell_exec("apt-get -s upgrade 2>/dev/null | grep -c \"^Inst {$p['name']}.*security\" 2>/dev/null"));
        Response::success([
            'upgradable'       => count($packages),
            'security_updates' => count($security),
            'packages'         => $packages,
            'last_checked'     => date('Y-m-d H:i:s'),
        ]);
    })(),

    // ── Apply OS update ───────────────────────────────────────────────────────
    'apply-os-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        set_time_limit(300);

        $panelPorts = [PORT_USER, PORT_RESELLER, PORT_ADMIN];
        $webSvc = defined('WEB_SERVER') && WEB_SERVER === 'nginx' ? 'nginx' : 'apache2';

        // Snapshot service states before upgrade
        $beforeServices = [];
        foreach ([$webSvc, 'mysql', 'postfix', 'dovecot', 'proftpd', 'named'] as $svc) {
            $beforeServices[$svc] = trim(shell_exec("systemctl is-active $svc 2>/dev/null") ?: 'unknown');
        }

        // Backup panel web root
        $backupDir  = '/var/novacpx/backups/pre-os-update-' . date('YmdHis');
        $webRoot    = defined('WEB_ROOT') ? WEB_ROOT : '/srv/novacpx/public';
        shell_exec("mkdir -p " . escapeshellarg($backupDir));
        shell_exec("cp -a " . escapeshellarg($webRoot) . " " . escapeshellarg("$backupDir/public") . " 2>&1");

        // Run upgrade (non-interactive, hold back kernel packages to avoid reboot surprise)
        $env  = 'DEBIAN_FRONTEND=noninteractive';
        $opts = '-o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"';
        $out  = shell_exec("$env apt-get upgrade -y -q $opts 2>&1");

        // Self-healing: restart any service that went down
        $healed = [];
        sleep(3);
        foreach ($beforeServices as $svc => $wasBefore) {
            if ($wasBefore !== 'active') continue;
            $nowState = trim(shell_exec("systemctl is-active $svc 2>/dev/null") ?: '');
            if ($nowState !== 'active') {
                shell_exec("sudo systemctl restart $svc 2>/dev/null");
                sleep(2);
                $afterHeal = trim(shell_exec("systemctl is-active $svc 2>/dev/null") ?: '');
                $healed[$svc] = $afterHeal === 'active' ? 'restarted' : 'FAILED';
                if ($afterHeal !== 'active') {
                    novacpx_log('error', "Self-heal FAILED for $svc after OS upgrade");
                }
            }
        }

        // Verify panel ports respond
        $panelOk = [];
        foreach ($panelPorts as $port) {
            $proto = in_array($port, [PORT_ADMIN, PORT_RESELLER, PORT_USER]) ? 'https' : 'http';
            $ch = curl_init("{$proto}://127.0.0.1:{$port}/");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 5]);
            curl_exec($ch);
            $panelOk[$port] = curl_getinfo($ch, CURLINFO_HTTP_CODE) > 0;
            curl_close($ch);
        }
        $panelDown = array_keys(array_filter($panelOk, fn($ok) => !$ok));

        // If panel ports down, restore from backup and restart web server
        if ($panelDown) {
            shell_exec("cp -a " . escapeshellarg("$backupDir/public") . " " . escapeshellarg($webRoot) . " 2>&1");
            shell_exec("sudo systemctl restart $webSvc 2>/dev/null");
            novacpx_log('error', 'Panel ports down after OS upgrade — restored from backup');
        }

        audit('system.os-update', "upgraded; healed:" . implode(',', array_keys($healed)));
        Response::success([
            'upgraded'         => true,
            'panel_ports_ok'   => empty($panelDown),
            'panel_ports_down' => $panelDown,
            'services_healed'  => $healed,
            'backup_path'      => $backupDir,
            'upgrade_output'   => substr($out ?: '', -2000),
        ]);
    })(),

    // ── Check NovaCPX update ─────────────────────────────────────────────────
    'check-novacpx-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $srcDir = '/opt/novacpx-src';
        if (!is_dir($srcDir)) Response::error('Source repo not found at /opt/novacpx-src');
        // Use sudo git so www-data can access root-owned repo
        $fetchOut = shell_exec("sudo git -C " . escapeshellarg($srcDir) . " fetch origin 2>&1");
        $logOut   = shell_exec("sudo git -C " . escapeshellarg($srcDir) . " log HEAD..origin/main --oneline 2>/dev/null") ?: '';
        $updates  = array_values(array_filter(explode("\n", trim($logOut))));
        $branch   = trim(shell_exec("sudo git -C " . escapeshellarg($srcDir) . " branch --show-current 2>/dev/null") ?: 'main');
        $commit   = trim(shell_exec("sudo git -C " . escapeshellarg($srcDir) . " rev-parse --short HEAD 2>/dev/null") ?: '');
        Response::success([
            'updates_available' => count($updates),
            'current_commit'    => $commit,
            'branch'            => $branch,
            'commits'           => $updates,
            'fetch_output'      => trim($fetchOut ?: ''),
        ]);
    })(),

    // ── Apply NovaCPX update ─────────────────────────────────────────────────
    'apply-novacpx-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        set_time_limit(180);
        $srcDir  = '/opt/novacpx-src';
        $webRoot = defined('WEB_ROOT') ? WEB_ROOT : '/srv/novacpx/public';
        $webSvc  = defined('WEB_SERVER') && WEB_SERVER === 'nginx' ? 'nginx' : 'apache2';
        $steps   = [];

        if (!is_dir($srcDir)) Response::error('Source repo not found at /opt/novacpx-src');

        $before = trim(shell_exec("sudo git -C " . escapeshellarg($srcDir) . " rev-parse HEAD 2>/dev/null") ?: '');
        $steps[] = "Before: $before";

        // Backup current web root
        $backupDir = '/var/novacpx/backups/pre-novacpx-update-' . date('YmdHis');
        shell_exec("mkdir -p " . escapeshellarg($backupDir));
        shell_exec("cp -a " . escapeshellarg($webRoot) . " " . escapeshellarg("$backupDir/public") . " 2>&1");
        $steps[] = "Backup: $backupDir";

        // Pull new code (sudo so www-data can write root-owned repo)
        $pull = shell_exec("sudo git -C " . escapeshellarg($srcDir) . " pull origin main 2>&1");
        $steps[] = "Pull: " . trim($pull ?: '(no output)');

        $after   = trim(shell_exec("sudo git -C " . escapeshellarg($srcDir) . " rev-parse HEAD 2>/dev/null") ?: '');
        $changed = $before !== $after;
        $steps[] = "After: $after" . ($changed ? " (changed)" : " (no change)");

        if ($changed) {
            // Validate PHP syntax (use php8.3; find all .php files recursively)
            $phpFiles = [];
            $found = shell_exec("find " . escapeshellarg("$srcDir/panel") . " -name '*.php' 2>/dev/null") ?: '';
            foreach (array_filter(explode("\n", trim($found))) as $f) { $phpFiles[] = trim($f); }

            $syntaxErr = [];
            foreach ($phpFiles as $f) {
                $check = shell_exec("php8.3 -l " . escapeshellarg($f) . " 2>&1");
                if (!str_contains($check, 'No syntax errors')) {
                    $syntaxErr[] = basename($f) . ': ' . trim($check);
                }
            }
            $steps[] = "Syntax check: " . count($phpFiles) . " files, " . count($syntaxErr) . " errors";

            if ($syntaxErr) {
                shell_exec("sudo git -C " . escapeshellarg($srcDir) . " reset --hard " . escapeshellarg($before) . " 2>&1");
                Response::error('Update aborted — PHP syntax errors: ' . implode('; ', $syntaxErr));
            }

            // Deploy files to web root (sudo rsync)
            shell_exec("sudo rsync -a --delete " . escapeshellarg("$srcDir/panel/public/") . " " . escapeshellarg("$webRoot/") . " 2>&1");
            shell_exec("sudo rsync -a " . escapeshellarg("$srcDir/panel/lib/") . " " . escapeshellarg("$webRoot/lib/") . " 2>&1");
            shell_exec("sudo rsync -a " . escapeshellarg("$srcDir/panel/api/") . " " . escapeshellarg("$webRoot/api/") . " 2>&1");
            shell_exec("cp " . escapeshellarg("$srcDir/VERSION") . " " . escapeshellarg("$webRoot/VERSION") . " 2>/dev/null");
            shell_exec("sudo chown -R www-data:www-data " . escapeshellarg($webRoot));
            $steps[] = "Deploy: rsync complete";

            // Run pending DB migrations
            $migrDir = "$srcDir/db/migrations";
            if (is_dir($migrDir)) {
                foreach (glob("$migrDir/*.sql") as $sql) {
                    $migName = basename($sql, '.sql');
                    $already = $db->fetchOne("SELECT 1 FROM settings WHERE `key` = ?", ["migration_$migName"]);
                    if (!$already) {
                        try { $db->pdo()->exec(file_get_contents($sql)); } catch (\Throwable $e) { /* skip dupes */ }
                        $db->execute("INSERT INTO settings (`key`,`value`) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE `value`=NOW()", ["migration_$migName"]);
                        $steps[] = "Migration: $migName applied";
                    }
                }
            }

            // Reload PHP-FPM to pick up new code
            shell_exec("sudo systemctl reload php8.3-fpm 2>/dev/null || sudo systemctl reload php8.2-fpm 2>/dev/null || true");
            $steps[] = "PHP-FPM reloaded";

            // Verify panel is still up
            sleep(2);
            $port    = defined('PORT_ADMIN') ? PORT_ADMIN : 8882;
            $panelOk = false;
            foreach (['https','http'] as $scheme) {
                $code = trim(shell_exec("curl -sk -o /dev/null -w '%{http_code}' {$scheme}://127.0.0.1:{$port}/api/system/version --max-time 5 2>/dev/null") ?: '');
                if (in_array($code, ['200','401','302','301'])) { $panelOk = true; break; }
            }
            if (!$panelOk) {
                shell_exec("sudo rsync -a --delete " . escapeshellarg("$backupDir/public/") . " " . escapeshellarg("$webRoot/") . " 2>&1");
                shell_exec("sudo systemctl reload $webSvc 2>/dev/null");
                novacpx_log('error', "NovaCPX update failed — panel down after deploy; restored from backup");
                Response::error('Update deployed but panel went down — auto-restored from backup. Check logs.');
            }

            audit('system.novacpx-update', "novacpx:$before→$after");
            novacpx_log('info', "NovaCPX updated $before → $after");
        }

        Response::success([
            'updated'     => $changed,
            'from_commit' => $before,
            'to_commit'   => $after,
            'pull_output' => trim($pull ?? ''),
            'backup_path' => $backupDir,
            'steps'       => $steps,
        ]);
    })(),

    // ── Server Stats ──────────────────────────────────────────────────────────
    'stats' => (function() use ($db) {
        // CPU/load
        $load   = sys_getloadavg();
        $cpuPct = round(($load[0] / max(1, (int)shell_exec('nproc'))) * 100, 1);

        // RAM
        $memRaw = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $memRaw, $mt);
        preg_match('/MemAvailable:\s+(\d+)/', $memRaw, $ma);
        $ramTotal = (int)($mt[1] ?? 0);
        $ramAvail = (int)($ma[1] ?? 0);
        $ramPct   = $ramTotal > 0 ? round((($ramTotal - $ramAvail) / $ramTotal) * 100, 1) : 0;

        // Disk
        $diskTotal = disk_total_space('/');
        $diskFree  = disk_free_space('/');
        $diskPct   = $diskTotal > 0 ? round((($diskTotal - $diskFree) / $diskTotal) * 100, 1) : 0;

        // Services — dynamic list based on configured servers
        $webSetting  = $db->fetchOne("SELECT `value` FROM settings WHERE `key`='web_server'")['value']  ?? 'apache';
        $ftpSetting  = $db->fetchOne("SELECT `value` FROM settings WHERE `key`='ftp_server'")['value']  ?? 'proftpd';
        $dnsSetting  = $db->fetchOne("SELECT `value` FROM settings WHERE `key`='dns_server'")['value']  ?? 'bind9';
        $webSvc      = match($webSetting) { 'nginx' => 'nginx', 'apache' => 'apache2', default => 'apache2' };
        $ftpSvc      = match($ftpSetting) { 'vsftpd' => 'vsftpd', 'pureftpd' => 'pure-ftpd', default => 'proftpd' };
        $dnsSvc      = match($dnsSetting) { 'powerdns' => 'pdns', 'nsd' => 'nsd', 'none' => null, default => 'named' };
        $svcList     = array_filter([$webSvc,'mysql','postfix','dovecot',$ftpSvc,$dnsSvc,'fail2ban']);
        $services = [];
        foreach ($svcList as $svc) {
            $active = trim(shell_exec("systemctl is-active $svc 2>/dev/null") ?: '');
            if ($active) $services[$svc] = $active;
        }

        // Persist to DB for history
        $db->execute(
            "INSERT INTO server_stats (cpu_usage,ram_usage,disk_usage,load_avg) VALUES (?,?,?,?)",
            [$cpuPct, $ramPct, $diskPct, $load[0]]
        );

        Response::success([
            'cpu'       => ['pct' => $cpuPct, 'load' => $load],
            'ram'       => ['total_kb' => $ramTotal, 'used_kb' => $ramTotal - $ramAvail, 'pct' => $ramPct],
            'disk'      => ['total' => $diskTotal, 'free' => $diskFree, 'pct' => $diskPct],
            'services'  => $services,
            'uptime'    => trim(shell_exec('uptime -p') ?: ''),
        ]);
    })(),

    // ── Single-service status check (lightweight) ─────────────────────────────
    'svc-check' => (function() {
        Auth::getInstance()->require('admin');
        $svc    = preg_replace('/[^a-z0-9\-_.]/', '', $_GET['service'] ?? '');
        $status = $svc ? trim(shell_exec("systemctl is-active " . escapeshellarg($svc) . " 2>/dev/null") ?: 'unknown') : 'unknown';
        Response::success(['service' => $svc, 'status' => $status]);
    })(),

    // ── Service control (start/stop/restart) ──────────────────────────────────
    'service' => (function() use ($body, $db) {
        Auth::getInstance()->require('admin');
        $svc    = preg_replace('/[^a-z0-9\-_]/', '', $body['service'] ?? '');
        $cmd    = $body['command'] ?? 'status';
        $allowed = ['apache2','nginx','lighttpd','caddy','mysql','mariadb','postgresql','postfix','dovecot',
                    'proftpd','vsftpd','pure-ftpd','named','bind9','pdns','nsd','fail2ban',
                    'php7.4-fpm','php8.1-fpm','php8.2-fpm','php8.3-fpm'];
        if (!in_array($svc, $allowed)) Response::error("Service not managed: $svc");
        if (!in_array($cmd, ['start','stop','restart','reload','status','flush'])) Response::error("Invalid command");

        if ($cmd === 'flush' && $svc === 'postfix') {
            $out = shell_exec("sudo postqueue -f 2>&1");
        } else {
            $out = shell_exec("sudo systemctl $cmd " . escapeshellarg($svc) . " 2>&1");
        }
        audit("service.$cmd", $svc);
        Response::success(['output' => $out]);
    })(),

    // ── Audit log ─────────────────────────────────────────────────────────────
    'audit-log' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $page      = max(1, (int)($_GET['page'] ?? 1));
        $perPage   = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
        $offset    = ($page - 1) * $perPage;
        $user      = trim($_GET['user']      ?? '');
        $action    = trim($_GET['action']    ?? '');
        $dateFrom  = trim($_GET['date_from'] ?? '');
        $dateTo    = trim($_GET['date_to']   ?? '');

        $where  = 'WHERE 1=1';
        $params = [];
        if ($user)     { $where .= ' AND username LIKE ?';      $params[] = "%$user%"; }
        if ($action)   { $where .= ' AND action LIKE ?';        $params[] = "%$action%"; }
        if ($dateFrom) { $where .= ' AND created_at >= ?';      $params[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo)   { $where .= ' AND created_at <= ?';      $params[] = $dateTo   . ' 23:59:59'; }

        $total = $db->fetchOne("SELECT COUNT(*) as c FROM audit_log $where", $params)['c'] ?? 0;
        $rows  = $db->fetchAll(
            "SELECT id, user_id, username, action, resource, ip_address, detail, created_at
             FROM audit_log $where ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );
        Response::paginate($rows, (int)$total, $page, $perPage);
    })(),

    // ── Server Options (#22a-e) ───────────────────────────────────────────────
    'server-options' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $keys = ['web_server','mail_server','ftp_server','dns_server','whmcs_api_key','whmcs_enabled','ns1_hostname','ns2_hostname'];
        $opts = [];
        foreach ($db->fetchAll("SELECT `key`,`value` FROM settings WHERE `key` IN ('" . implode("','", $keys) . "')") as $r) {
            $opts[$r['key']] = $r['value'];
        }
        // Detect actually-running services
        $opts['apache_active']   = !empty(trim(shell_exec('systemctl is-active apache2 2>/dev/null') ?: '')) && trim(shell_exec('systemctl is-active apache2 2>/dev/null')) === 'active';
        $opts['nginx_active']    = trim(shell_exec('systemctl is-active nginx 2>/dev/null') ?: '') === 'active';
        $opts['proftpd_active']  = trim(shell_exec('systemctl is-active proftpd 2>/dev/null') ?: '') === 'active';
        $opts['vsftpd_active']   = trim(shell_exec('systemctl is-active vsftpd 2>/dev/null') ?: '') === 'active';
        $opts['pureftpd_active'] = trim(shell_exec('systemctl is-active pure-ftpd 2>/dev/null') ?: '') === 'active';
        $opts['bind9_active']    = trim(shell_exec('systemctl is-active named 2>/dev/null || systemctl is-active bind9 2>/dev/null') ?: '') === 'active';
        $opts['powerdns_active'] = trim(shell_exec('systemctl is-active pdns 2>/dev/null') ?: '') === 'active';
        Response::success($opts);
    })(),

    'save-option' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        $key   = $body['key']   ?? '';
        $value = $body['value'] ?? '';
        $allowed = ['web_server','mail_server','ftp_server','dns_server','whmcs_api_key','whmcs_enabled','ns1_hostname','ns2_hostname'];
        if (!in_array($key, $allowed)) Response::error("Invalid setting key: $key");

        // Save before switching so the new value is in DB
        $db->execute("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$key, $value]);

        // Sync config.ini so PHP constants reflect the change immediately on next request
        $configFile = '/etc/novacpx/config.ini';
        if (in_array($key, ['web_server','ftp_server','dns_server']) && file_exists($configFile)) {
            $ini = file_get_contents($configFile);
            if ($key === 'web_server') {
                $ini = preg_replace('/^server\s*=\s*.*/m', "server = $value", $ini);
            }
            file_put_contents($configFile, $ini);
        }

        // Inline service switching
        // NOTE: Apache is NEVER stopped — it always serves the panel on ports 8880-8883.
        // The web_server switch only controls which server owns ports 80/443 for customer hosting.
        if ($key === 'web_server') {
            $target = in_array($value, ['nginx']) ? 'nginx' : 'apache';
            $out = shell_exec("sudo /usr/local/bin/novacpx-webserver-switch " . escapeshellarg($target) . " 2>&1");
            novacpx_log('info', "web_server switched to $target: $out");
        } elseif ($key === 'ftp_server') {
            foreach (['proftpd','vsftpd','pure-ftpd'] as $s) { shell_exec("sudo systemctl stop $s 2>/dev/null; sudo systemctl disable $s 2>/dev/null"); }
            $startSvc = match($value) { 'vsftpd' => 'vsftpd', 'pureftpd' => 'pure-ftpd', default => 'proftpd' };
            if (trim(shell_exec("dpkg -l $startSvc 2>/dev/null | grep -c '^ii'") ?: '0') > 0) {
                shell_exec("sudo systemctl enable $startSvc 2>/dev/null && sudo systemctl start $startSvc 2>/dev/null");
            }
        } elseif ($key === 'dns_server') {
            foreach (['named','bind9','pdns','nsd'] as $s) { shell_exec("sudo systemctl stop $s 2>/dev/null; sudo systemctl disable $s 2>/dev/null"); }
            if ($value !== 'none') {
                $startSvc = match($value) { 'powerdns' => 'pdns', 'nsd' => 'nsd', default => 'named' };
                shell_exec("sudo systemctl enable $startSvc 2>/dev/null && sudo systemctl start $startSvc 2>/dev/null");
            }
        }
        // mail_server: postfix + dovecot are always running; mail_server setting controls config template only

        audit("settings.{$key}", $value);
        Response::success(null, "Setting saved: {$key} = {$value}");
    })(),

    // ── Notification settings (#25) ───────────────────────────────────────────
    'notify-settings' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $keys = ['cybermail_api_key','notify_from_email','notify_from_name','notify_admin_email','notifications_enabled'];
        $out  = [];
        foreach ($db->fetchAll("SELECT `key`,`value` FROM settings WHERE `key` IN ('" . implode("','", $keys) . "')") as $r) {
            $out[$r['key']] = $r['value'];
        }
        // Mask API key for display
        if (!empty($out['cybermail_api_key'])) {
            $k = $out['cybermail_api_key'];
            $out['cybermail_api_key_masked'] = substr($k, 0, 10) . str_repeat('*', max(0, strlen($k) - 14)) . substr($k, -4);
        }
        Response::success($out);
    })(),

    'save-notify-settings' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        $allowed = ['cybermail_api_key','notify_from_email','notify_from_name','notify_admin_email','notifications_enabled'];
        $saved   = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $body)) continue;
            $value = trim($body[$key]);
            if ($key === 'cybermail_api_key' && str_contains($value, '***')) continue; // skip masked placeholder
            $db->execute(
                "INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
                [$key, $value]
            );
            $saved[] = $key;
        }
        audit('settings.notify', implode(',', $saved));
        Response::success(null, 'Notification settings saved');
    })(),

    'test-notify' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        require_once NOVACPX_LIB . '/Notifier.php';
        $to = trim($body['to'] ?? '');
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) Response::error("Valid email address required");
        // Send a test email directly
        $apiKey    = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'cybermail_api_key'")['value'] ?? '';
        $fromEmail = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'notify_from_email'")['value'] ?: 'noreply@novacpx.local';
        $fromName  = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'notify_from_name'")['value']  ?: 'NovaCPX Panel';
        if (!$apiKey) Response::error("No CyberMail API key configured");

        $payload = json_encode([
            'from'    => $fromEmail,
            'to'      => $to,
            'subject' => 'NovaCPX — test notification',
            'html'    => '<h2>Test Notification</h2><p>Email notifications are working correctly from your NovaCPX panel.</p>',
            'text'    => 'Test Notification: Email notifications are working correctly from your NovaCPX panel.',
        ]);
        $ch = curl_init('https://platform.cyberpersons.com/email/v1/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 202) Response::success(null, "Test email sent to {$to}");
        else Response::error("CyberMail returned HTTP {$code}: " . substr($resp, 0, 200));
    })(),

    // ── Database engine management ────────────────────────────────────────────
    'db-engines' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $engines = [];
        foreach (['mysql','mariadb','postgresql'] as $eng) {
            $svc = match($eng) {
                'mysql'      => 'mysql',
                'mariadb'    => 'mariadb',
                'postgresql' => 'postgresql',
            };
            $active   = trim(shell_exec("systemctl is-active $svc 2>/dev/null") ?: '');
            $enabled  = trim(shell_exec("systemctl is-enabled $svc 2>/dev/null") ?: '');
            $pkgCheck = match($eng) {
                'mysql'      => 'dpkg -l mysql-server 2>/dev/null | grep -c "^ii"',
                'mariadb'    => 'dpkg -l mariadb-server 2>/dev/null | grep -c "^ii"',
                'postgresql' => 'dpkg -l postgresql 2>/dev/null | grep -c "^ii"',
            };
            $installed = (int)trim(shell_exec($pkgCheck) ?: '0') > 0;
            $version   = '';
            if ($installed) {
                $version = match($eng) {
                    'mysql'      => trim(shell_exec("mysql --version 2>/dev/null | grep -oP '[0-9]+\.[0-9]+\.[0-9]+'") ?: ''),
                    'mariadb'    => trim(shell_exec("mariadb --version 2>/dev/null | grep -oP '[0-9]+\.[0-9]+\.[0-9]+'") ?: ''),
                    'postgresql' => trim(shell_exec("psql --version 2>/dev/null | grep -oP '[0-9]+\.[0-9]+'") ?: ''),
                };
            }
            $engines[$eng] = [
                'installed' => $installed,
                'active'    => $active === 'active',
                'enabled'   => $enabled === 'enabled',
                'version'   => $version,
            ];
        }
        $active_db = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'active_db_engine'")['value'] ?? 'mysql';
        Response::success(['engines' => $engines, 'active_engine' => $active_db]);
    })(),

    'db-engine-action' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        $engine = preg_replace('/[^a-z]/', '', $body['engine'] ?? '');
        $action = $body['action'] ?? '';
        if (!in_array($engine, ['mysql','mariadb','postgresql'])) Response::error("Invalid engine");
        if (!in_array($action, ['install','remove','start','stop','restart','set-active'])) Response::error("Invalid action");

        // Safety: never remove the engine that is currently hosting the NovaCPX panel DB
        if ($action === 'remove') {
            $activeConn = strtolower(DB_HOST === 'localhost' ? 'mysql' : '');
            $isMariaDB  = str_contains(strtolower(shell_exec("mysql --version 2>/dev/null") ?: ''), 'mariadb');
            $runningEngine = $isMariaDB ? 'mariadb' : 'mysql';
            if ($engine === $runningEngine || ($engine === 'mysql' && $isMariaDB) || ($engine === 'mariadb' && !$isMariaDB)) {
                Response::error("Cannot remove $engine — it is currently hosting the NovaCPX panel database. Migrate first.", 409);
            }
        }

        $out = '';
        if ($action === 'install') {
            $pkg = match($engine) {
                'mysql'      => 'mysql-server',
                'mariadb'    => 'mariadb-server',
                'postgresql' => 'postgresql postgresql-contrib',
            };
            $out = shell_exec("DEBIAN_FRONTEND=noninteractive sudo apt-get install -y $pkg 2>&1");
            shell_exec("sudo systemctl enable $engine 2>/dev/null && sudo systemctl start $engine 2>/dev/null");
        } elseif ($action === 'remove') {
            $pkg = match($engine) {
                'mysql'      => 'mysql-server mysql-client',
                'mariadb'    => 'mariadb-server mariadb-client',
                'postgresql' => 'postgresql postgresql-contrib',
            };
            shell_exec("sudo systemctl stop $engine 2>/dev/null || true");
            $out = shell_exec("DEBIAN_FRONTEND=noninteractive sudo apt-get remove -y $pkg 2>&1");
        } elseif ($action === 'set-active') {
            $db->execute("INSERT INTO settings (`key`,`value`) VALUES ('active_db_engine',?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$engine]);
            audit('settings.active_db_engine', $engine);
            Response::success(null, "Active database engine set to $engine");
        } else {
            shell_exec("sudo systemctl $action $engine 2>/dev/null");
        }
        audit("db-engine.$action", $engine);
        Response::success(['output' => substr($out ?: '', -1000)], ucfirst($action) . " $engine done");
    })(),

    default => Response::error("Unknown system action: $action", 404),
};
