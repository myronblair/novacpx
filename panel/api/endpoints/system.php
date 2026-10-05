<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * System endpoint — version info, updates, server stats, services
 * Admin-only actions gated with Auth::require('admin')
 */

require_once NOVACPX_LIB . '/Root.php';
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
        // Source repo is at /opt/novacpx-src — the web root is a deployed copy, not a git repo
        $srcDir = '/opt/novacpx-src';
        if (!is_dir($srcDir . '/.git')) Response::error('Source repository not found at ' . $srcDir . '. Re-run the installer to restore it.');

        $output  = shell_exec("git -C " . escapeshellarg($srcDir) . " fetch origin 2>&1 && git -C " . escapeshellarg($srcDir) . " log HEAD..origin/main --oneline 2>/dev/null");
        $updates = array_values(array_filter(explode("\n", trim($output ?: ''))));

        Response::success([
            'updates_available' => count($updates),
            'commits'           => $updates,
        ]);
    })(),

    // ── Apply update ──────────────────────────────────────────────────────────
    'apply-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $srcDir = '/opt/novacpx-src';
        if (!is_dir($srcDir . '/.git')) Response::error('Source repository not found at ' . $srcDir);

        $before = trim(shell_exec("git -C " . escapeshellarg($srcDir) . " rev-parse HEAD 2>/dev/null") ?: '');
        $pull   = shell_exec("git -C " . escapeshellarg($srcDir) . " pull origin main 2>&1");
        $after  = trim(shell_exec("git -C " . escapeshellarg($srcDir) . " rev-parse HEAD 2>/dev/null") ?: '');
        $changed = $before !== $after;

        if ($changed) {
            // Deploy updated files from source repo to web root
            $dst = rtrim(NOVACPX_ROOT, '/');
            shell_exec("cp -a " . escapeshellarg($srcDir . '/panel/public/.') . " {$dst}/");
            shell_exec("cp -a " . escapeshellarg($srcDir . '/panel/api/.') . " {$dst}/api/");
            shell_exec("cp -a " . escapeshellarg($srcDir . '/panel/lib/.') . " {$dst}/lib/");
            if (is_dir($srcDir . '/panel/bin')) {
                shell_exec("cp -a " . escapeshellarg($srcDir . '/panel/bin/.') . " {$dst}/bin/");
            }
            shell_exec("chown -R www-data:www-data " . escapeshellarg($dst) . " 2>/dev/null");

            // Run any pending DB migrations
            $migrDir = $srcDir . '/db/migrations';
            if (is_dir($migrDir)) {
                foreach (glob("$migrDir/*.sql") as $sql) {
                    $migName = basename($sql, '.sql');
                    $already = $db->fetchOne("SELECT 1 FROM settings WHERE `key` = 'migration_$migName'");
                    if (!$already) {
                        $db->pdo()->exec(file_get_contents($sql));
                        $db->execute("INSERT INTO settings (`key`,`value`,updated_at) VALUES (?,datetime('now'),datetime('now')) ON CONFLICT(`key`) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at", ["migration_$migName"]);
                        novacpx_log('info', "Migration applied: $migName");
                    }
                }
            }
            audit('system.update', "novacpx:$before→$after");
            novacpx_log('info', "NovaCPX updated $before → $after");
        }

        Response::success([
            'updated'     => $changed,
            'from_commit' => $before,
            'to_commit'   => $after,
            'pull_output' => $pull,
        ]);
    })(),

    // ── Check OS updates ─────────────────────────────────────────────────────
    'check-os-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $force  = !empty($_GET['force']);
        $cached = $db->fetchOne("SELECT value, updated_at FROM settings WHERE `key`='update_cache_os'");
        $age    = $cached ? (time() - strtotime($cached['updated_at'])) : PHP_INT_MAX;

        if (!$force && $cached && $age < 43200) {
            $data = json_decode($cached['value'], true) ?: [];
            $data['cached'] = true;
            $data['cached_at'] = $cached['updated_at'];
            Response::success($data);
        }

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
        $result = [
            'upgradable'       => count($packages),
            'security_updates' => count($security),
            'packages'         => $packages,
            'last_checked'     => date('Y-m-d H:i:s'),
        ];
        $db->execute("INSERT INTO settings(`key`,value,updated_at) VALUES('update_cache_os',?,datetime('now')) ON CONFLICT(`key`) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at", [json_encode($result)]);
        Response::success($result);
    })(),

    // ── Start OS update (background job) ─────────────────────────────────────
    'apply-os-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $jobId    = bin2hex(random_bytes(8));
        $logFile  = "/tmp/ncpx-os-update-{$jobId}.log";
        $doneFile = "/tmp/ncpx-os-update-{$jobId}.done";
        $script   = "/tmp/ncpx-os-update-{$jobId}.sh";
        $webSvc   = defined('WEB_SERVER') && WEB_SERVER === 'nginx' ? 'nginx' : 'apache2';
        $webRoot  = defined('WEB_ROOT') ? WEB_ROOT : '/srv/novacpx/public';
        $backupDir = '/tmp/novacpx-backup-' . date('YmdHis');

        $sh = <<<BASH
#!/bin/bash
exec > {$logFile} 2>&1
ts() { date -u +"%H:%M:%S UTC"; }
echo "[\$(ts)] Preparing backup..."
mkdir -p {$backupDir}
cp -a {$webRoot} {$backupDir}/public 2>/dev/null
echo "[\$(ts)] Updating package lists..."
printf '%s' '{"op":"update"}' | sudo -n /usr/local/sbin/novacpx-root pkg.apt
echo "[\$(ts)] Running upgrade (non-interactive)..."
printf '%s' '{"op":"upgrade"}' | sudo -n /usr/local/sbin/novacpx-root pkg.apt
UPGRADE_EXIT=\$?
echo "[\$(ts)] Checking services..."
for SVC in {$webSvc} mysql postfix dovecot; do
  if systemctl is-active --quiet \$SVC 2>/dev/null; then :; else
    echo "[\$(ts)] Restarting \$SVC..."
    sudo systemctl restart \$SVC 2>/dev/null && echo "  \$SVC restarted OK" || echo "  \$SVC restart FAILED"
  fi
done
if [ \$UPGRADE_EXIT -eq 0 ]; then
  echo "[\$(ts)] Upgrade complete."
else
  echo "[\$(ts)] Upgrade finished with errors (exit code \$UPGRADE_EXIT)."
fi
echo \$UPGRADE_EXIT > {$doneFile}
BASH;
        file_put_contents($script, $sh);
        chmod($script, 0755);
        shell_exec("nohup " . escapeshellarg($script) . " > /dev/null 2>&1 &");

        audit('system.os-update-start', $jobId);
        Response::success(['job_id' => $jobId]);
    })(),

    // ── Poll OS update job status ─────────────────────────────────────────────
    'os-update-status' => (function() {
        Auth::getInstance()->require('admin');
        $jobId = preg_replace('/[^a-f0-9]/', '', $_GET['job_id'] ?? '');
        if (!$jobId) Response::error('job_id required');

        $logFile  = "/tmp/ncpx-os-update-{$jobId}.log";
        $doneFile = "/tmp/ncpx-os-update-{$jobId}.done";

        $content  = @file_get_contents($logFile) ?: '';
        $lines    = $content !== '' ? explode("\n", rtrim($content)) : [];
        $done     = file_exists($doneFile);
        $exitCode = $done ? (int)trim(@file_get_contents($doneFile) ?: '1') : null;

        if ($done) {
            @unlink($logFile);
            @unlink($doneFile);
            @unlink("/tmp/ncpx-os-update-{$jobId}.sh");
        }

        Response::success(['lines' => $lines, 'done' => $done, 'exit_code' => $exitCode]);
    })(),

    // ── Check NovaCPX update ─────────────────────────────────────────────────
    'check-novacpx-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $force  = !empty($_GET['force']);
        $cached = $db->fetchOne("SELECT value, updated_at FROM settings WHERE `key`='update_cache_novacpx'");
        $age    = $cached ? (time() - strtotime($cached['updated_at'])) : PHP_INT_MAX;

        if (!$force && $cached && $age < 43200) {
            $data = json_decode($cached['value'], true) ?: [];
            $data['cached'] = true;
            $data['cached_at'] = $cached['updated_at'];
            Response::success($data);
        }

        $channelRow   = $db->fetchOne("SELECT value FROM settings WHERE `key`='update_channel'");
        $channel      = in_array($channelRow['value'] ?? '', ['stable', 'beta']) ? $channelRow['value'] : 'stable';
        $r = Root::run('panel.update.check', ['channel' => $channel]);
        if ($r['rc'] !== 0) Response::error('Update check failed: ' . trim($r['out']));
        $info    = json_decode($r['out'], true) ?: [];
        $updates = $info['updates'] ?? [];
        $result  = ['updates_available' => count($updates), 'current_commit' => $info['current_commit'] ?? '', 'branch' => $info['branch'] ?? 'main', 'channel' => $channel,
                    'remote_version' => $info['remote_version'] ?? '', 'commits' => $updates];
        $db->execute("INSERT INTO settings(`key`,value,updated_at) VALUES('update_cache_novacpx',?,datetime('now')) ON CONFLICT(`key`) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at", [json_encode($result)]);
        Response::success($result);
    })(),

    // ── Apply NovaCPX update ─────────────────────────────────────────────────
    'apply-novacpx-update' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        set_time_limit(240);
        $channelRow = $db->fetchOne("SELECT value FROM settings WHERE `key`='update_channel'");
        $channel    = in_array($channelRow['value'] ?? '', ['stable', 'beta']) ? $channelRow['value'] : 'stable';

        // The privileged helper runs the deploy runner: pull, PHP syntax check with rollback, rsync, migrations, version record, FPM reload.
        $r = Root::run('panel.update.apply', ['channel' => $channel]);
        if ($r['rc'] !== 0) Response::error('Update failed: ' . trim($r['out']));
        $res = json_decode($r['out'], true) ?: [];
        if (!empty($res['updated'])) {
            audit('system.novacpx-update', "novacpx:{$res['before']}→{$res['after']} (channel:{$channel})");
            novacpx_log('info', "NovaCPX updated {$res['before']} → {$res['after']} via $channel channel");
        }
        Response::success([
            'updated'     => !empty($res['updated']),
            'from_commit' => $res['before'] ?? '',
            'to_commit'   => $res['after'] ?? '',
            'channel'     => $channel,
            'pull_output' => '',
            'backup_path' => '',
            'steps'       => array_values(array_filter(explode("\n", trim($res['log'] ?? '')))),
        ]);
    })(),

    // ── Server Stats ──────────────────────────────────────────────────────────
    'stats' => (function() use ($db) {
        Auth::getInstance()->require('admin');
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

        // Persist to DB for history (non-fatal — don't let lock errors kill the stats response)
        try {
            $db->execute(
                "INSERT INTO server_stats (cpu_usage,ram_usage,disk_usage,load_avg) VALUES (?,?,?,?)",
                [$cpuPct, $ramPct, $diskPct, $load[0]]
            );
        } catch (Throwable $_) {}

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

    // ── Installed service versions with latest available ──────────────────────
    'service-versions' => (function() {
        Auth::getInstance()->require('admin');

        // pkg => [label, description, systemd service name]
        $catalog = [
            'apache2'           => ['Apache 2',        'Web server — serves all hosted websites on ports 80/443',           'apache2'],
            'nginx'             => ['Nginx',            'High-performance web server / reverse proxy',                       'nginx'],
            'php8.3'            => ['PHP 8.3',          'Server-side scripting runtime (CLI + FPM)',                         'php8.3-fpm'],
            'php8.2'            => ['PHP 8.2',          'PHP 8.2 runtime (legacy support)',                                  'php8.2-fpm'],
            'php8.1'            => ['PHP 8.1',          'PHP 8.1 runtime (legacy support)',                                  'php8.1-fpm'],
            'php7.4'            => ['PHP 7.4',          'PHP 7.4 runtime (end-of-life compatibility)',                       'php7.4-fpm'],
            'mysql-server'      => ['MySQL',            'Relational database server (MariaDB/MySQL) for hosted sites',       'mysql'],
            'mariadb-server'    => ['MariaDB',          'MySQL-compatible database server fork',                             'mariadb'],
            'postfix'           => ['Postfix',          'MTA — routes and delivers email for hosted domains',                'postfix'],
            'dovecot-core'      => ['Dovecot',          'IMAP/POP3 server — lets users retrieve email via mail clients',     'dovecot'],
            'rspamd'            => ['Rspamd',           'Spam filter that scores and rejects unwanted email',                'rspamd'],
            'proftpd'           => ['ProFTPD',          'FTP server for account file transfers',                             'proftpd'],
            'vsftpd'            => ['vsftpd',           'Lightweight FTP server',                                            'vsftpd'],
            'bind9'             => ['BIND9',            'Authoritative DNS server — serves zone files for hosted domains',   'named'],
            'fail2ban'          => ['Fail2Ban',         'Intrusion prevention — bans IPs after repeated failed logins',      'fail2ban'],
            'ufw'               => ['UFW',              'Uncomplicated Firewall — iptables front-end for port management',   null],
            'certbot'           => ['Certbot',          "Let's Encrypt client — automates SSL certificate issuance",         null],
            'sqlite3'           => ['SQLite3',          'Embedded SQL database — stores NovaCPX panel data',                 null],
            'redis-server'      => ['Redis',            'In-memory data store — used for caching and queuing',               'redis'],
            'memcached'         => ['Memcached',        'Memory object cache',                                               'memcached'],
            'nodejs'            => ['Node.js',          'JavaScript runtime — used by some web applications',                null],
            'git'               => ['Git',              'Version control system — used for site deployments',                null],
            'curl'              => ['curl',             'HTTP client — used by health checks and API calls',                 null],
        ];

        $dpkgOut = shell_exec("dpkg-query -W -f='\${Package} \${Version}\\n' 2>/dev/null") ?: '';
        $installed = [];
        foreach (explode("\n", trim($dpkgOut)) as $line) {
            [$pkg, $ver] = array_pad(explode(' ', $line, 2), 2, '');
            if ($pkg) $installed[$pkg] = trim($ver);
        }

        $aptOut = shell_exec("apt-cache policy " . implode(' ', array_keys($catalog)) . " 2>/dev/null") ?: '';
        $latest = [];
        $curPkg = null;
        foreach (explode("\n", $aptOut) as $line) {
            if (preg_match('/^(\S+):$/', trim($line), $m)) { $curPkg = $m[1]; continue; }
            if ($curPkg && preg_match('/Candidate:\s+(.+)/', $line, $m)) {
                $latest[$curPkg] = trim($m[1]);
                $curPkg = null;
            }
        }

        $services = [];
        foreach ($catalog as $pkg => [$label, $desc, $svc]) {
            $ver = $installed[$pkg] ?? null;
            if (!$ver) continue; // skip not-installed
            $latestVer = $latest[$pkg] ?? 'unknown';
            $status = $svc ? trim(shell_exec("systemctl is-active $svc 2>/dev/null") ?: 'inactive') : null;
            $upToDate = ($latestVer === 'unknown' || $latestVer === '(none)') ? null
                      : (version_compare($ver, $latestVer, '>='));
            $services[] = [
                'pkg'        => $pkg,
                'label'      => $label,
                'desc'       => $desc,
                'installed'  => $ver,
                'latest'     => $latestVer,
                'up_to_date' => $upToDate,
                'status'     => $status,
            ];
        }

        Response::success(['services' => $services]);
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
        $keys = ['web_server','mail_server','ftp_server','dns_server','whmcs_api_key','whmcs_enabled','ns1_hostname','ns2_hostname',
                 'panel_name','default_php','default_nameserver1','default_nameserver2','update_channel'];
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
        $allowed = ['web_server','mail_server','ftp_server','dns_server','whmcs_api_key','whmcs_enabled','ns1_hostname','ns2_hostname',
                    'panel_name','default_php','default_nameserver1','default_nameserver2','update_channel'];
        if (!in_array($key, $allowed)) Response::error("Invalid setting key: $key");
        $db->execute("INSERT INTO settings (`key`,`value`,updated_at) VALUES (?,?,datetime('now')) ON CONFLICT(`key`) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at", [$key, $value]);
        audit("settings.{$key}", $value);
        Response::success(null, "Setting saved: {$key} = {$value}");
    })(),

    // Streaming service switch for web/mail/ftp/dns server changes
    'service-switch' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        $key   = $body['key']   ?? '';
        $value = $body['value'] ?? '';
        $serviceKeys = ['web_server','mail_server','ftp_server','dns_server'];
        if (!in_array($key, $serviceKeys)) Response::error("Invalid service key: $key");

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level()) ob_end_clean();

        $sse = function(string $line) { echo 'data: ' . json_encode(['line' => $line]) . "\n\n"; flush(); };
        $run = function(string $cmd) use ($sse): int {
            $proc = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (!$proc) { $sse("  [failed to start]\n"); return 1; }
            while (!feof($pipes[1])) {
                $line = fgets($pipes[1]);
                if ($line !== false && $line !== '') $sse($line);
            }
            while (!feof($pipes[2])) {
                $line = fgets($pipes[2]);
                if ($line !== false && $line !== '') $sse("  " . $line);
            }
            fclose($pipes[1]); fclose($pipes[2]);
            return proc_close($proc);
        };

        // Persist selection
        $db->execute("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value=excluded.value", [$key, $value]);

        // Sync config.ini
        $configFile = '/etc/novacpx/config.ini';
        if (file_exists($configFile)) {
            $ini = file_get_contents($configFile);
            if ($key === 'web_server') $ini = preg_replace('/^server\s*=\s*.*/m', "server = $value", $ini);
            file_put_contents($configFile, $ini);
        }

        if ($key === 'web_server') {
            $target = ($value === 'nginx') ? 'nginx' : 'apache';
            $sse("▶ Switching web server to {$target}…\n");
            if (file_exists('/usr/local/bin/novacpx-webserver-switch')) {
                $run("sudo /usr/local/bin/novacpx-webserver-switch " . escapeshellarg($target) . " 2>&1");
            } else {
                // Fallback: manage services directly
                if ($target === 'nginx') {
                    $sse("  Stopping Apache on ports 80/443…\n");
                    $run("sudo systemctl stop apache2 2>&1");
                    $sse("  Starting Nginx…\n");
                    $run("sudo systemctl enable nginx 2>&1 && sudo systemctl start nginx 2>&1");
                } else {
                    $sse("  Stopping Nginx…\n");
                    $run("sudo systemctl stop nginx 2>&1");
                    $sse("  Starting Apache…\n");
                    $run("sudo systemctl enable apache2 2>&1 && sudo systemctl start apache2 2>&1");
                }
            }
            $sse("  ✓ Web server switched to {$target}\n");

        } elseif ($key === 'mail_server') {
            $sse("▶ Updating mail server config to {$value}…\n");
            if ($value === 'postfix-dovecot-rspamd') {
                $installed = trim(shell_exec("dpkg -l rspamd 2>/dev/null | grep -c '^ii'") ?: '0');
                if ($installed === '0') {
                    $sse("  Installing Rspamd (this may take 1–2 minutes)…\n");
                    $run(Root::shellCommand('pkg.apt', ['op' => 'install', 'packages' => ['rspamd']]) . ' 2>&1');
                }
                $sse("  Enabling Rspamd…\n");
                $run("sudo systemctl enable rspamd 2>&1 && sudo systemctl start rspamd 2>&1");
                $sse("  ✓ Rspamd enabled\n");
            } else {
                $run("sudo systemctl stop rspamd 2>/dev/null; sudo systemctl disable rspamd 2>/dev/null; true");
                $sse("  ✓ Rspamd disabled\n");
            }
            // Postfix + Dovecot always run
            $run("sudo systemctl is-active postfix || sudo systemctl start postfix 2>&1");
            $run("sudo systemctl is-active dovecot || sudo systemctl start dovecot 2>&1");
            $sse("  ✓ Mail stack: postfix + dovecot running\n");

        } elseif ($key === 'ftp_server') {
            $sse("▶ Switching FTP server to {$value}…\n");
            foreach (['proftpd','vsftpd','pure-ftpd'] as $s) {
                $run("sudo systemctl stop $s 2>/dev/null; sudo systemctl disable $s 2>/dev/null; true");
            }
            $startSvc = match($value) { 'vsftpd' => 'vsftpd', 'pureftpd' => 'pure-ftpd', default => 'proftpd' };
            $installed = trim(shell_exec("dpkg -l $startSvc 2>/dev/null | grep -c '^ii'") ?: '0');
            if ($installed === '0') {
                $sse("  Installing {$startSvc}…\n");
                $run(Root::shellCommand('pkg.apt', ['op' => 'install', 'packages' => [$startSvc]]) . ' 2>&1');
            }
            $sse("  Starting {$startSvc}…\n");
            $run("sudo systemctl enable $startSvc 2>&1 && sudo systemctl start $startSvc 2>&1");
            $sse("  ✓ FTP server switched to {$startSvc}\n");

        } elseif ($key === 'dns_server') {
            $sse("▶ Switching DNS server to {$value}…\n");
            foreach (['named','bind9','pdns','nsd'] as $s) {
                $run("sudo systemctl stop $s 2>/dev/null; sudo systemctl disable $s 2>/dev/null; true");
            }
            if ($value !== 'none') {
                $startSvc = match($value) { 'powerdns' => 'pdns', 'nsd' => 'nsd', default => 'named' };
                $installed = trim(shell_exec("dpkg -l $startSvc 2>/dev/null | grep -c '^ii'") ?: '0');
                if ($installed === '0') {
                    $sse("  Installing {$startSvc}…\n");
                    $run(Root::shellCommand('pkg.apt', ['op' => 'install', 'packages' => [$startSvc]]) . ' 2>&1');
                }
                $sse("  Starting {$startSvc}…\n");
                $run("sudo systemctl enable $startSvc 2>&1 && sudo systemctl start $startSvc 2>&1");
                $sse("  ✓ DNS server switched to {$startSvc}\n");
            } else {
                $sse("  ✓ All local DNS servers stopped\n");
            }
        }

        audit("settings.{$key}", $value);
        echo 'data: ' . json_encode(['done' => true]) . "\n\n"; flush();
        exit;
    })(),

    // ── Notification settings (#25) ───────────────────────────────────────────
    'notify-settings' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $keys = ['gmail_sender','gmail_client_id','gmail_client_secret','gmail_refresh_token','notify_from_email','notify_from_name','notify_admin_email','notifications_enabled'];
        $out  = [];
        foreach ($db->fetchAll("SELECT `key`,`value` FROM settings WHERE `key` IN ('" . implode("','", $keys) . "')") as $r) {
            $out[$r['key']] = $r['value'];
        }
        // Mask secrets for display
        foreach (['gmail_client_secret', 'gmail_refresh_token'] as $sk) {
            if (!empty($out[$sk])) {
                $k = $out[$sk];
                $out[$sk . '_masked'] = substr($k, 0, 6) . str_repeat('*', max(0, strlen($k) - 10)) . substr($k, -4);
                unset($out[$sk]);
            }
        }
        Response::success($out);
    })(),

    'save-notify-settings' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        $allowed = ['gmail_sender','gmail_client_id','gmail_client_secret','gmail_refresh_token','notify_from_email','notify_from_name','notify_admin_email','notifications_enabled'];
        $saved   = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $body)) continue;
            $value = trim($body[$key]);
            if (in_array($key, ['gmail_client_secret', 'gmail_refresh_token'], true) && ($value === '' || str_contains($value, '***'))) continue; // keep the stored secret
            $db->execute(
                "INSERT INTO settings (`key`,`value`) VALUES (?,?) ON CONFLICT(`key`) DO UPDATE SET value=excluded.value",
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
        require_once NOVACPX_LIB . '/GmailMailer.php';
        $to = trim($body['to'] ?? '');
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) Response::error("Valid email address required");
        // Send a test email directly through the Gmail API
        $r = GmailMailer::send($to, 'NovaCPX — test notification',
            '<h2>Test Notification</h2><p>Email notifications are working correctly from your NovaCPX panel.</p>',
            'Test Notification: Email notifications are working correctly from your NovaCPX panel.');
        if ($r['ok']) Response::success(null, "Test email sent to {$to}");
        else Response::error($r['error']);
    })(),

    // ── Email template CRUD ───────────────────────────────────────────────────
    'email-templates' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $templates = $db->fetchAll("SELECT id,trigger_key,label,subject,enabled,updated_at FROM email_templates ORDER BY trigger_key");
        Response::success(['templates' => $templates]);
    })(),

    'email-template-get' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
        $row = $db->fetchOne("SELECT * FROM email_templates WHERE id = ?", [$id]);
        if (!$row) Response::error("Template not found", 404);
        Response::success($row);
    })(),

    'email-template-save' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        $id       = (int)($body['id'] ?? 0);
        $subject  = trim($body['subject'] ?? '');
        $bodyHtml = trim($body['body_html'] ?? '');
        $bodyText = trim($body['body_text'] ?? '');
        $enabled  = isset($body['enabled']) ? (int)(bool)$body['enabled'] : 1;
        if (!$subject || !$bodyHtml) Response::error("Subject and HTML body required");

        if ($id) {
            $db->execute("UPDATE email_templates SET subject=?,body_html=?,body_text=?,enabled=?,updated_at=datetime('now') WHERE id=?",
                [$subject, $bodyHtml, $bodyText, $enabled, $id]);
        } else {
            $triggerKey = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($body['trigger_key'] ?? '')));
            $label      = trim($body['label'] ?? $triggerKey);
            if (!$triggerKey) Response::error("trigger_key required for new template");
            $id = (int)$db->insert("INSERT INTO email_templates (trigger_key,label,subject,body_html,body_text,enabled) VALUES (?,?,?,?,?,?)",
                [$triggerKey, $label, $subject, $bodyHtml, $bodyText, $enabled]);
        }
        audit("email_template.save", (string)$id);
        Response::success(['id' => $id], 'Template saved');
    })(),

    'email-template-delete' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        $id = (int)($body['id'] ?? 0);
        $row = $db->fetchOne("SELECT trigger_key FROM email_templates WHERE id = ?", [$id]);
        if (!$row) Response::error("Template not found", 404);
        $db->execute("DELETE FROM email_templates WHERE id = ?", [$id]);
        audit("email_template.delete", $row['trigger_key']);
        Response::success(null, 'Template deleted');
    })(),

    'email-template-test' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        $id = (int)($body['id'] ?? 0);
        $to = trim($body['to'] ?? '');
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) Response::error("Valid email address required");
        $tmpl = $id ? $db->fetchOne("SELECT * FROM email_templates WHERE id = ?", [$id]) : null;
        if (!$tmpl && $id) Response::error("Template not found", 404);

        require_once NOVACPX_LIB . '/GmailMailer.php';
        $fromEmail = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'notify_from_email'")['value'] ?: 'noreply@novacpx.local';

        // Replace placeholders with sample values for test
        $samples = [
            '{{name}}' => 'Test User', '{{domain}}' => 'example.com', '{{username}}' => 'testuser',
            '{{password}}' => '••••••••', '{{panel_url}}' => 'https://panel.yourdomain.com',
            '{{reason}}' => 'Non-payment', '{{support_email}}' => $fromEmail,
            '{{days}}' => '14', '{{expiry_date}}' => date('Y-m-d', strtotime('+14 days')),
            '{{usage}}' => '87', '{{used}}' => '8.7 GB', '{{quota}}' => '10 GB',
            '{{package}}' => 'Basic', '{{created_by}}' => 'admin',
            '{{reset_url}}' => 'https://panel.yourdomain.com/reset?token=EXAMPLE',
        ];
        $subject = $tmpl ? strtr($tmpl['subject'], $samples) : 'NovaCPX Test';
        $html    = $tmpl ? strtr($tmpl['body_html'], $samples) : '<p>Test</p>';
        $text    = $tmpl ? strtr($tmpl['body_text'] ?? '', $samples) : 'Test';

        $r = GmailMailer::send($to, '[TEST] ' . $subject, $html, $text);
        if ($r['ok']) Response::success(null, "Test email sent to {$to}");
        else Response::error($r['error']);
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

        // Panel DB is SQLite — no MySQL engine hosts it, so any MySQL/MariaDB/PG can be removed freely

        $out = '';
        if ($action === 'set-active') {
            $db->execute("INSERT INTO settings (`key`,`value`) VALUES ('active_db_engine',?) ON CONFLICT(`key`) DO UPDATE SET value=excluded.value", [$engine]);
            audit('settings.active_db_engine', $engine);
            Response::success(null, "Active database engine set to $engine");
        }
        // install / remove / start / stop / restart run in the privileged helper (fixed package lists, fixed units)
        $out = Root::run('dbengine', ['engine' => $engine, 'action' => $action])['out'];
        audit("db-engine.$action", $engine);
        Response::success(['output' => substr($out ?: '', -1000)], ucfirst($action) . " $engine done");
    })(),

    'db-tools' => (function() {
        Auth::getInstance()->require('admin');
        $pmaInstalled  = (int)trim(shell_exec("dpkg -l phpmyadmin 2>/dev/null | grep -c '^ii'") ?: '0') > 0
                      || is_dir('/usr/share/phpmyadmin');
        $pgaInstalled  = (int)trim(shell_exec("dpkg -l pgadmin4 2>/dev/null | grep -c '^ii'") ?: '0') > 0
                      || is_file('/usr/pgadmin4/bin/pgadmin4') || is_dir('/usr/pgadmin4');
        $adminerInstalled = is_file(NOVACPX_ROOT . '/adminer.php');
        $adminerVer       = $adminerInstalled ? 'bundled' : '';
        $pmaVer = $pmaInstalled
            ? trim(shell_exec("dpkg -l phpmyadmin 2>/dev/null | awk '/^ii/{print $3}' | head -1") ?: '')
            : '';
        $pgaVer = $pgaInstalled
            ? trim(shell_exec("pgadmin4 --version 2>/dev/null | grep -oP '[0-9]+\\.[0-9]+' | head -1") ?: '')
            : '';
        Response::success([
            'phpmyadmin' => ['installed' => $pmaInstalled, 'version' => $pmaVer, 'url' => '/phpmyadmin'],
            'pgadmin'    => ['installed' => $pgaInstalled, 'version' => $pgaVer],
            'adminer'    => ['installed' => $adminerInstalled, 'version' => $adminerVer, 'url' => '/adminer.php'],
        ]);
    })(),

    'db-tools-stream' => (function() use ($body) {
        Auth::getInstance()->require('admin');
        $tool   = $body['tool']   ?? '';
        $action = $body['action'] ?? '';
        if (!in_array($tool,   ['phpmyadmin','pgadmin','adminer'])) { echo 'data:'.json_encode(['error'=>'Invalid tool'])."\n\n"; exit; }
        if (!in_array($action, ['install','reinstall','remove'])) { echo 'data:'.json_encode(['error'=>'Invalid action'])."\n\n"; exit; }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        ob_implicit_flush(true);
        while (ob_get_level() > 0) ob_end_flush();
        set_time_limit(300);

        $sse = function(string $line) { echo 'data: ' . json_encode(['line' => $line]) . "\n\n"; flush(); };
        $run = function(string $cmd) use ($sse): int {
            $proc = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (!$proc) { $sse("  [failed to start process]\n"); return 1; }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            while (true) {
                $r = [$pipes[1], $pipes[2]]; $w = $e = [];
                if (stream_select($r, $w, $e, 1) > 0) {
                    foreach ($r as $s) {
                        $line = fgets($s, 4096);
                        if ($line !== false && $line !== '') $sse($line);
                    }
                }
                if (feof($pipes[1]) && feof($pipes[2])) break;
            }
            fclose($pipes[1]); fclose($pipes[2]);
            return proc_close($proc);
        };

        // The whole install/reinstall/remove runs in the privileged helper (fixed repository, fixed packages, fixed config text);
        // its output streams straight into the page.
        $rc = Root::stream('db.tool', ['tool' => $tool, 'action' => $action, 'email' => $body['pga_email'] ?? '', 'password' => $body['pga_pass'] ?? ''], $sse);
        if ($rc !== 0) { echo 'data:' . json_encode(['error' => 'The installer reported a problem (see the output above)']) . "\n\n"; flush(); exit; }

        audit("db-tools.$action", $tool);
        $verb = ['install'=>'Installed','reinstall'=>'Reinstalled','remove'=>'Removed'][$action];
        $sse("  ✓ {$verb}!\n");
        echo 'data: ' . json_encode(['done' => true, 'message' => "$verb $tool"]) . "\n\n";
        flush();
        exit;
    })(),


    'read-log' => (function() {
        Auth::getInstance()->require('admin');
        $log  = preg_replace('/[^a-z0-9-]/', '', $_GET['log'] ?? 'panel');
        $map  = [
            'panel'        => '/var/log/novacpx/panel.log',
            'deploy'       => '/var/log/novacpx/deploy.log',
            'nginx-error'  => '/var/log/novacpx/nginx-error.log',
            'nginx-access' => '/var/log/novacpx/nginx-access.log',
            'mail'         => '/var/log/mail.log',
            'stats'        => '/var/log/novacpx/stats-collector.log',
        ];
        $path = $map[$log] ?? '/var/log/novacpx/panel.log';
        $raw  = file_exists($path) ? trim(shell_exec('tail -100 ' . escapeshellarg($path)) ?: '') : '';
        Response::success(['content' => $raw, 'log' => $log]);
    })(),

        default => Response::error("Unknown system action: $action", 404),
};
