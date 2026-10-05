<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Traffic Meter: bandwidth accounting per hosting account.
 *
 * collect()  reads each account's web access log from where it stopped last time (inode + offset, so log rotation is
 *            detected) and adds bytes served and requests to usage_daily.
 * enforce()  compares the month's total with the package allowance (packages.bandwidth_mb, 0 = unlimited), sends one warning
 *            at 80% and one at 100% per account and month, and - only when the admin chose it (settings.bandwidth_action =
 *            suspend) - suspends the account at 100%.
 */
class TrafficMeter {

    private const MONTHS = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
                            'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];
    private const MAX_BYTES_PER_RUN = 200 * 1048576;   // never spend more than ~200 MB of log reading per account and run

    public static function logPath(string $username): string {
        return "/home/{$username}/logs/access.log";
    }

    /** @return array{accounts:int,lines:int} */
    public static function collect(): array {
        $db = DB::getInstance();
        $lines = 0; $n = 0;
        foreach ($db->fetchAll("SELECT id, username FROM accounts WHERE status != 'terminated'") as $a) {
            $n++;
            $lines += self::collectAccount((int)$a['id'], (string)$a['username']);
        }
        return ['accounts' => $n, 'lines' => $lines];
    }

    private static function collectAccount(int $accountId, string $username): int {
        $db   = DB::getInstance();
        $path = self::logPath($username);
        $st   = @stat($path);
        if (!$st || !is_readable($path)) return 0;

        $cur = $db->fetchOne("SELECT inode, pos FROM usage_cursor WHERE account_id = ?", [$accountId]);
        $pos = 0;
        if ($cur && (int)$cur['inode'] === (int)$st['ino'] && (int)$cur['pos'] <= (int)$st['size']) $pos = (int)$cur['pos'];
        if (!$cur) $pos = 0;                         // first run: count everything the log still holds
        if ($pos >= $st['size']) { self::saveCursor($accountId, (int)$st['ino'], (int)$st['size']); return 0; }

        $fh = @fopen($path, 'rb');
        if (!$fh) return 0;
        fseek($fh, $pos);
        $perDay = []; $read = 0; $count = 0; $good = $pos;
        while (($line = fgets($fh)) !== false) {
            if (substr($line, -1) !== "\n") break;   // partial line still being written: pick it up next run
            $read += strlen($line);
            $good  = $pos + $read;
            if (preg_match('/\[(\d{2})\/([A-Z][a-z]{2})\/(\d{4}):[^\]]*\] "[^"]*" (\d{3}) (\d+|-)/', $line, $m) && isset(self::MONTHS[$m[2]])) {
                $day = sprintf('%04d-%02d-%02d', (int)$m[3], self::MONTHS[$m[2]], (int)$m[1]);
                $perDay[$day][0] = ($perDay[$day][0] ?? 0) + ($m[5] === '-' ? 0 : (int)$m[5]);
                $perDay[$day][1] = ($perDay[$day][1] ?? 0) + 1;
                $count++;
            }
            if ($read >= self::MAX_BYTES_PER_RUN) break;
        }
        fclose($fh);

        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare("INSERT INTO usage_daily (account_id, day, bytes_out, requests) VALUES (?,?,?,?)
                                 ON CONFLICT(account_id, day) DO UPDATE SET bytes_out = bytes_out + excluded.bytes_out, requests = requests + excluded.requests");
            foreach ($perDay as $day => [$bytes, $reqs]) $up->execute([$accountId, $day, $bytes, $reqs]);
            self::saveCursor($accountId, (int)$st['ino'], $good);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[TrafficMeter] ' . $username . ': ' . $e->getMessage());
            return 0;
        }
        $total = self::monthBytes($accountId);
        $db->execute("UPDATE accounts SET bw_used_mb = ? WHERE id = ?", [(int)round($total / 1048576), $accountId]);
        return $count;
    }

    private static function saveCursor(int $accountId, int $inode, int $pos): void {
        DB::getInstance()->execute(
            "INSERT INTO usage_cursor (account_id, inode, pos) VALUES (?,?,?)
             ON CONFLICT(account_id) DO UPDATE SET inode = excluded.inode, pos = excluded.pos",
            [$accountId, $inode, $pos]);
    }

    public static function monthBytes(int $accountId, ?string $month = null): int {
        $month = $month ?: date('Y-m');
        $r = DB::getInstance()->fetchOne("SELECT COALESCE(SUM(bytes_out),0) AS b FROM usage_daily WHERE account_id = ? AND day LIKE ?", [$accountId, $month . '-%']);
        return (int)($r['b'] ?? 0);
    }

    /** Allowance in MB for the account's package (0 = unlimited). */
    public static function allowanceMb(int $accountId): int {
        $r = DB::getInstance()->fetchOne("SELECT p.bandwidth_mb AS bw FROM accounts a LEFT JOIN packages p ON p.id = a.package_id WHERE a.id = ?", [$accountId]);
        return (int)($r['bw'] ?? 0);
    }

    /** Month total, allowance, percentage and the last 30 days for one account. */
    public static function summary(int $accountId): array {
        $db   = DB::getInstance();
        $used = self::monthBytes($accountId);
        $allow = self::allowanceMb($accountId);
        $daily = $db->fetchAll("SELECT day, bytes_out, requests FROM usage_daily WHERE account_id = ? AND day >= date('now','-29 day') ORDER BY day", [$accountId]);
        return [
            'month'        => date('Y-m'),
            'used_mb'      => round($used / 1048576, 1),
            'allowance_mb' => $allow,
            'percent'      => $allow > 0 ? round($used / 1048576 / $allow * 100, 1) : null,
            'daily'        => array_map(fn($d) => ['day' => $d['day'], 'mb' => round($d['bytes_out'] / 1048576, 2), 'requests' => (int)$d['requests']], $daily),
        ];
    }

    /** Everything one account uses: bandwidth (this month, 30 days, month history) and what it holds against its package limits. */
    public static function accountDetail(int $accountId): array {
        $db  = DB::getInstance();
        $a   = $db->fetchOne("SELECT a.id, a.username, a.domain, a.status, a.home_dir, a.disk_used_mb, p.name AS package, p.disk_mb, p.max_domains, p.max_email, p.max_ftp, p.max_databases
                              FROM accounts a LEFT JOIN packages p ON p.id = a.package_id WHERE a.id = ?", [$accountId]);
        if (!$a) throw new RuntimeException('Account not found');
        $months = $db->fetchAll("SELECT substr(day,1,7) AS month, SUM(bytes_out) AS b, SUM(requests) AS r FROM usage_daily WHERE account_id = ? GROUP BY 1 ORDER BY 1 DESC LIMIT 6", [$accountId]);
        // live disk use of the home folder (cheap enough for a one-account view); the stored figure is the fallback
        $diskMb = (int)$a['disk_used_mb'];
        $home = '/home/' . $a['username'];
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/', $a['username']) && is_dir($home)) {
            $out = trim((string)@shell_exec('timeout 20 du -sm ' . escapeshellarg($home) . ' 2>/dev/null | cut -f1'));
            if ($out !== '' && ctype_digit($out)) $diskMb = (int)$out;
        }
        $count = fn(string $sql) => (int)($db->fetchOne($sql, [$accountId])['c'] ?? 0);
        $resources = [
            ['key' => 'disk',      'label' => 'Disk space',     'used' => $diskMb, 'unit' => 'MB', 'limit' => (int)$a['disk_mb']],
            ['key' => 'domains',   'label' => 'Domains',        'used' => $count("SELECT COUNT(*) AS c FROM domains WHERE account_id = ?"), 'unit' => '', 'limit' => (int)$a['max_domains']],
            ['key' => 'email',     'label' => 'Email accounts', 'used' => $count("SELECT COUNT(*) AS c FROM email_accounts WHERE account_id = ?"), 'unit' => '', 'limit' => (int)$a['max_email']],
            ['key' => 'ftp',       'label' => 'FTP accounts',   'used' => $count("SELECT COUNT(*) AS c FROM ftp_accounts WHERE account_id = ?"), 'unit' => '', 'limit' => (int)$a['max_ftp']],
            ['key' => 'databases', 'label' => 'Databases',      'used' => $count("SELECT COUNT(*) AS c FROM `databases` WHERE account_id = ?"), 'unit' => '', 'limit' => (int)$a['max_databases']],
            ['key' => 'backups',   'label' => 'Backups stored', 'used' => (int)round((float)($db->fetchOne("SELECT COALESCE(SUM(size_mb),0) AS c FROM backups WHERE account_id = ? AND status = 'complete'", [$accountId])['c'] ?? 0)), 'unit' => 'MB', 'limit' => 0],
            ['key' => 'docker',    'label' => 'Docker containers', 'used' => $count("SELECT COUNT(*) AS c FROM docker_containers WHERE account_id = ?"), 'unit' => '', 'limit' => 0],
        ];
        return [
            'account'   => ['id' => (int)$a['id'], 'username' => $a['username'], 'domain' => $a['domain'], 'status' => $a['status'], 'package' => $a['package']],
            'bandwidth' => self::summary($accountId),
            'months'    => array_map(fn($m) => ['month' => $m['month'], 'mb' => round($m['b'] / 1048576, 1), 'requests' => (int)$m['r']], $months),
            'resources' => $resources,
        ];
    }

    /** Month usage for every account the caller may see (admin: all, reseller: own customers). */
    public static function overview(?int $resellerId = null): array {
        $db = DB::getInstance();
        $sql = "SELECT a.id, a.username, a.domain, a.status, p.name AS package, COALESCE(p.bandwidth_mb,0) AS allowance_mb
                FROM accounts a JOIN users u ON u.id = a.user_id LEFT JOIN packages p ON p.id = a.package_id
                WHERE a.status != 'terminated'" . ($resellerId ? " AND u.reseller_id = " . (int)$resellerId : '') . " ORDER BY a.username";
        $out = [];
        foreach ($db->fetchAll($sql) as $a) {
            $mb = self::monthBytes((int)$a['id']) / 1048576;
            $allow = (int)$a['allowance_mb'];
            $out[] = ['account_id' => (int)$a['id'], 'username' => $a['username'], 'domain' => $a['domain'], 'status' => $a['status'],
                      'package' => $a['package'], 'used_mb' => round($mb, 1), 'allowance_mb' => $allow,
                      'percent' => $allow > 0 ? round($mb / $allow * 100, 1) : null];
        }
        return $out;
    }

    public static function enforce(): void {
        $db = DB::getInstance();
        $month = date('Y-m');
        $action = $db->fetchOne("SELECT value FROM settings WHERE key = 'bandwidth_action'")['value'] ?? 'notify';
        foreach ($db->fetchAll("SELECT a.id, a.username, a.domain, a.status, u.email FROM accounts a JOIN users u ON u.id = a.user_id WHERE a.status = 'active'") as $a) {
            $id = (int)$a['id'];
            $allow = self::allowanceMb($id);
            if ($allow <= 0) continue;
            $pct = self::monthBytes($id) / 1048576 / $allow * 100;
            foreach ([100, 80] as $level) {
                if ($pct < $level) continue;
                $done = $db->fetchOne("SELECT 1 AS x FROM usage_alerts WHERE account_id = ? AND month = ? AND level = ?", [$id, $month, $level]);
                if ($done) break;                       // already warned at this (or the higher) level this month
                $db->execute("INSERT OR IGNORE INTO usage_alerts (account_id, month, level) VALUES (?,?,?)", [$id, $month, $level]);
                require_once __DIR__ . '/Notifier.php';
                Notifier::bandwidthWarning($a, (int)round($pct), $allow, $level);
                if ($level === 100 && $action === 'suspend') {
                    require_once __DIR__ . '/AccountManager.php';
                    AccountManager::suspend($id, 'Monthly bandwidth allowance used up');
                }
                break;
            }
        }
    }

    /** Drop daily rows older than 13 months. */
    public static function prune(): void {
        DB::getInstance()->execute("DELETE FROM usage_daily WHERE day < date('now','-13 months')");
        DB::getInstance()->execute("DELETE FROM usage_alerts WHERE month < strftime('%Y-%m', date('now','-13 months'))");
    }
}
