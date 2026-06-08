<?php
/**
 * Stats endpoint — resource usage history, charts, per-account usage
 */
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$user = Auth::getInstance()->user();

match ($action) {
    'server' => (function() use ($db) {
        // Last 24 hours of 5-min samples
        $rows = $db->fetchAll(
            "SELECT cpu_usage, ram_usage, disk_usage, load_avg, recorded_at
             FROM server_stats ORDER BY recorded_at DESC LIMIT 288"
        );
        $rows = array_reverse($rows);

        // Current live snapshot
        $cpu  = (float) trim(shell_exec("top -bn1 | grep 'Cpu(s)' | awk '{print $2+$4}'") ?: 0);
        $ram  = [];
        foreach (file('/proc/meminfo') as $line) {
            [$k, $v] = preg_split('/\s+/', trim($line), 2);
            $ram[$k] = (int) $v;
        }
        $ramPct = isset($ram['MemTotal:'], $ram['MemAvailable:']) && $ram['MemTotal:'] > 0
            ? round((1 - $ram['MemAvailable:'] / $ram['MemTotal:']) * 100, 1)
            : 0;

        $disk    = [];
        $dfLine  = trim(shell_exec("df / | tail -1") ?: '');
        $dfParts = preg_split('/\s+/', $dfLine);
        $diskPct = isset($dfParts[4]) ? (int) $dfParts[4] : 0;
        $diskUsed  = isset($dfParts[2]) ? round($dfParts[2]/1024/1024, 1) : 0;
        $diskTotal = isset($dfParts[1]) ? round($dfParts[1]/1024/1024, 1) : 0;

        $load = sys_getloadavg();
        Response::success([
            'current' => [
                'cpu'        => $cpu,
                'ram'        => $ramPct,
                'disk_pct'   => $diskPct,
                'disk_used'  => $diskUsed,
                'disk_total' => $diskTotal,
                'load'       => $load[0],
            ],
            'history' => $rows,
        ]);
    })(),

    'account' => (function() use ($db, $body) {
        $user = Auth::getInstance()->user();
        if ($user['role'] === 'user') {
            $acctRow = $db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$user['uid']]);
            $accountId = $acctRow ? (int)$acctRow['id'] : 0;
        } else {
            $accountId = (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
        }
        if (!$accountId) Response::error("account_id required");
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$accountId]);
        if (!$acct) Response::error("Account not found", 404);

        // Disk
        $diskKB  = (int)trim(shell_exec("du -sk " . escapeshellarg($acct['home_dir']) . " 2>/dev/null | awk '{print $1}'") ?: 0);
        $diskMB  = round($diskKB / 1024, 1);

        // Inodes
        $inodes = (int)trim(shell_exec("find " . escapeshellarg($acct['home_dir']) . " 2>/dev/null | wc -l") ?: 0);

        // DB count & size
        $dbs     = $db->fetchAll("SELECT id FROM `databases` WHERE account_id = ?", [$accountId]);
        $dbCount = count($dbs);

        // Email count
        $emailCount = (int)($db->fetchOne("SELECT COUNT(*) c FROM email_accounts WHERE account_id = ?", [$accountId])['c'] ?? 0);
        // FTP count
        $ftpCount = (int)($db->fetchOne("SELECT COUNT(*) c FROM ftp_accounts WHERE account_id = ?", [$accountId])['c'] ?? 0);
        // Domain count
        $domCount = (int)($db->fetchOne("SELECT COUNT(*) c FROM domains WHERE account_id = ?", [$accountId])['c'] ?? 0);

        // Package limits
        $pkg = $db->fetchOne("SELECT * FROM packages WHERE id = ?", [$acct['package_id'] ?? 0]);

        Response::success([
            'disk_mb'         => $diskMB,
            'disk_limit'      => $pkg['disk_mb']         ?? 0,
            'inodes'          => $inodes,
            'databases'       => $dbCount,
            'db_limit'        => $pkg['max_databases']   ?? 0,
            'emails'          => $emailCount,
            'email_limit'     => $pkg['max_email']       ?? 0,
            'ftp'             => $ftpCount,
            'ftp_limit'       => $pkg['max_ftp']         ?? 0,
            'domains'         => $domCount,
            'subdomain_limit' => $pkg['max_subdomains']  ?? 0,
        ]);
    })(),

    'bandwidth' => (function() use ($db, $body) {
        $accountId = (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
        // Read nginx/apache access log and sum bytes for account's domains
        $acct = $db->fetchOne("SELECT username FROM accounts WHERE id = ?", [$accountId]);
        if (!$acct) Response::error("Account not found");
        $logFile = "/var/log/novacpx/{$acct['username']}-access.log";
        $daily = [];
        if (file_exists($logFile)) {
            $lines = explode("\n", trim(shell_exec("tail -50000 " . escapeshellarg($logFile)) ?: ''));
            foreach ($lines as $line) {
                if (preg_match('/\[(\d{2}\/\w+\/\d{4})/', $line, $m) &&
                    preg_match('/" \d+ (\d+)/', $line, $b)) {
                    $day = $m[1];
                    $daily[$day] = ($daily[$day] ?? 0) + (int)$b[1];
                }
            }
        }
        Response::success(array_map(fn($d,$b) => ['date'=>$d,'bytes'=>$b], array_keys($daily), $daily));
    })(),

    default => Response::error("Unknown stats action: $action", 404),
};
