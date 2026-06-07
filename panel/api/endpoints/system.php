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
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;
        $total   = $db->fetchOne("SELECT COUNT(*) as c FROM audit_log")['c'] ?? 0;
        $rows    = $db->fetchAll("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ? OFFSET ?", [$perPage, $offset]);
        Response::paginate($rows, (int)$total, $page, $perPage);
    })(),

    default => Response::error("Unknown system action: $action", 404),
};
