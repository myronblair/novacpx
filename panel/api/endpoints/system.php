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
                shell_exec("systemctl restart $svc 2>/dev/null");
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
            $resp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 3);
            $panelOk[$port] = (bool)$resp;
            if ($resp) fclose($resp);
        }
        $panelDown = array_keys(array_filter($panelOk, fn($ok) => !$ok));

        // If panel ports down, restore from backup and restart web server
        if ($panelDown) {
            shell_exec("cp -a " . escapeshellarg("$backupDir/public") . " " . escapeshellarg($webRoot) . " 2>&1");
            shell_exec("systemctl restart $webSvc 2>/dev/null");
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
        $out = shell_exec("git -C " . escapeshellarg($srcDir) . " fetch origin 2>&1 && git -C " . escapeshellarg($srcDir) . " log HEAD..origin/main --oneline 2>/dev/null");
        $updates = array_values(array_filter(explode("\n", trim($out ?: ''))));
        $branch  = trim(shell_exec("git -C " . escapeshellarg($srcDir) . " branch --show-current 2>/dev/null") ?: 'main');
        $commit  = trim(shell_exec("git -C " . escapeshellarg($srcDir) . " rev-parse --short HEAD 2>/dev/null") ?: '');
        Response::success([
            'updates_available' => count($updates),
            'current_commit'    => $commit,
            'branch'            => $branch,
            'commits'           => $updates,
        ]);
    })(),

    // ── Apply NovaCPX update ─────────────────────────────────────────────────
    'apply-novacpx-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $srcDir  = '/opt/novacpx-src';
        $webRoot = defined('WEB_ROOT') ? WEB_ROOT : '/srv/novacpx/public';
        $webSvc  = defined('WEB_SERVER') && WEB_SERVER === 'nginx' ? 'nginx' : 'apache2';

        if (!is_dir($srcDir)) Response::error('Source repo not found at /opt/novacpx-src');

        $before = trim(shell_exec("git -C " . escapeshellarg($srcDir) . " rev-parse HEAD 2>/dev/null") ?: '');

        // Backup current web root
        $backupDir = '/var/novacpx/backups/pre-novacpx-update-' . date('YmdHis');
        shell_exec("mkdir -p " . escapeshellarg($backupDir));
        shell_exec("cp -a " . escapeshellarg($webRoot) . " " . escapeshellarg("$backupDir/public") . " 2>&1");

        // Pull new code
        $pull = shell_exec("git -C " . escapeshellarg($srcDir) . " pull origin main 2>&1");
        $after = trim(shell_exec("git -C " . escapeshellarg($srcDir) . " rev-parse HEAD 2>/dev/null") ?: '');
        $changed = $before !== $after;

        if ($changed) {
            // Validate PHP syntax before deploying
            $phpFiles = glob($srcDir . '/panel/**/*.php', GLOB_BRACE) ?: [];
            $syntaxErr = [];
            foreach ($phpFiles as $f) {
                $check = shell_exec("php -l " . escapeshellarg($f) . " 2>&1");
                if (!str_contains($check, 'No syntax errors')) {
                    $syntaxErr[] = basename($f) . ': ' . trim($check);
                }
            }

            if ($syntaxErr) {
                // Syntax errors — abort, restore
                shell_exec("git -C " . escapeshellarg($srcDir) . " reset --hard " . escapeshellarg($before) . " 2>&1");
                Response::error('Update aborted — PHP syntax errors: ' . implode('; ', $syntaxErr));
            }

            // Deploy files to web root
            shell_exec("rsync -a --delete " . escapeshellarg("$srcDir/panel/public/") . " " . escapeshellarg("$webRoot/") . " 2>&1");
            shell_exec("rsync -a " . escapeshellarg("$srcDir/panel/lib/") . " " . escapeshellarg("$webRoot/lib/") . " 2>&1");
            shell_exec("rsync -a " . escapeshellarg("$srcDir/panel/api/") . " " . escapeshellarg("$webRoot/api/") . " 2>&1");
            shell_exec("cp " . escapeshellarg("$srcDir/VERSION") . " " . escapeshellarg("$webRoot/VERSION") . " 2>/dev/null");
            shell_exec("chown -R www-data:www-data " . escapeshellarg($webRoot));

            // Run pending DB migrations
            $migrDir = "$srcDir/db/migrations";
            if (is_dir($migrDir)) {
                foreach (glob("$migrDir/*.sql") as $sql) {
                    $migName = basename($sql, '.sql');
                    $already = $db->fetchOne("SELECT 1 FROM settings WHERE `key` = ?", ["migration_$migName"]);
                    if (!$already) {
                        $db->pdo()->exec(file_get_contents($sql));
                        $db->execute("INSERT INTO settings (`key`,`value`) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE `value`=NOW()", ["migration_$migName"]);
                    }
                }
            }

            // Reload PHP-FPM to pick up new code
            shell_exec("systemctl reload php8.3-fpm 2>/dev/null || true");

            // Verify panel is still up
            sleep(2);
            $panelOk = @fsockopen('127.0.0.1', PORT_ADMIN, $e, $es, 3);
            if (!$panelOk) {
                // Restore backup and reload
                shell_exec("rsync -a --delete " . escapeshellarg("$backupDir/public/") . " " . escapeshellarg("$webRoot/") . " 2>&1");
                shell_exec("systemctl reload $webSvc 2>/dev/null");
                novacpx_log('error', "NovaCPX update failed — panel down after deploy; restored from backup");
                Response::error('Update deployed but panel went down — auto-restored from backup. Check logs.');
            } else {
                fclose($panelOk);
            }

            audit('system.novacpx-update', "novacpx:$before→$after");
            novacpx_log('info', "NovaCPX updated $before → $after");
        }

        Response::success([
            'updated'     => $changed,
            'from_commit' => $before,
            'to_commit'   => $after,
            'pull_output' => $pull,
            'backup_path' => $backupDir,
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

        // Services
        $services = [];
        foreach (['apache2','nginx','mysql','postfix','dovecot','proftpd','named','fail2ban'] as $svc) {
            $active = trim(shell_exec("systemctl is-active $svc 2>/dev/null") ?: '');
            if ($active) $services[$svc] = $active;
        }

        // Persist to DB for history
        $db->execute(
            "INSERT INTO server_stats (cpu_pct,ram_pct,disk_pct,load_1m,load_5m,load_15m) VALUES (?,?,?,?,?,?)",
            [$cpuPct, $ramPct, $diskPct, $load[0], $load[1], $load[2]]
        );

        Response::success([
            'cpu'       => ['pct' => $cpuPct, 'load' => $load],
            'ram'       => ['total_kb' => $ramTotal, 'used_kb' => $ramTotal - $ramAvail, 'pct' => $ramPct],
            'disk'      => ['total' => $diskTotal, 'free' => $diskFree, 'pct' => $diskPct],
            'services'  => $services,
            'uptime'    => trim(shell_exec('uptime -p') ?: ''),
        ]);
    })(),

    // ── Service control (start/stop/restart) ──────────────────────────────────
    'service' => (function() use ($body, $db) {
        Auth::getInstance()->require('admin');
        $svc    = preg_replace('/[^a-z0-9\-_]/', '', $body['service'] ?? '');
        $cmd    = $body['command'] ?? 'status';
        $allowed = ['apache2','nginx','mysql','postfix','dovecot','proftpd','named','fail2ban','php7.4-fpm','php8.1-fpm','php8.2-fpm','php8.3-fpm'];
        if (!in_array($svc, $allowed)) Response::error("Service not managed: $svc");
        if (!in_array($cmd, ['start','stop','restart','reload','status'])) Response::error("Invalid command");

        $out = shell_exec("systemctl $cmd " . escapeshellarg($svc) . " 2>&1");
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
        $db->execute("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$key, $value]);

        // For server switches, run install/reload scripts
        if (in_array($key, ['web_server','ftp_server','dns_server','mail_server'])) {
            $script = "/opt/novacpx/bin/switch-{$key}.sh";
            if (is_executable($script)) {
                shell_exec("sudo {$script} " . escapeshellarg($value) . " > /var/log/novacpx/switch-{$key}.log 2>&1 &");
            }
        }
        audit("settings.{$key}", $value);
        Response::success(null, "Setting saved: {$key} = {$value}");
    })(),

    default => Response::error("Unknown system action: $action", 404),
};
