#!/usr/bin/env php
<?php
/**
 * NovaCPX stats collector — runs every 5 minutes via cron
 * Cron: *\/5 * * * * root /usr/bin/php /opt/novacpx/bin/collect-stats.php
 */
define('NOVACPX_ROOT', '/srv/novacpx/public');
define('NOVACPX_LIB',  NOVACPX_ROOT . '/lib');
require NOVACPX_ROOT . '/lib/Core.php';
require NOVACPX_ROOT . '/lib/DB.php';

// CPU usage (idle from /proc/stat)
$stat1 = file_get_contents('/proc/stat');
usleep(200000); // 200ms sample
$stat2 = file_get_contents('/proc/stat');

$line1 = explode(' ', trim(explode("\n", $stat1)[0]));
$line2 = explode(' ', trim(explode("\n", $stat2)[0]));
array_shift($line1); array_shift($line2);
$line1 = array_filter($line1, 'strlen'); $line2 = array_filter($line2, 'strlen');
$line1 = array_values($line1); $line2 = array_values($line2);
$total1 = array_sum($line1); $total2 = array_sum($line2);
$idle1 = (int)($line1[3] ?? 0); $idle2 = (int)($line2[3] ?? 0);
$cpuPct = $total1 === $total2 ? 0 : round((1 - ($idle2 - $idle1) / ($total2 - $total1)) * 100, 2);

// RAM
$meminfo = [];
foreach (file('/proc/meminfo') as $line) {
    if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) $meminfo[$m[1]] = (int)$m[2];
}
$ramPct = isset($meminfo['MemTotal'], $meminfo['MemAvailable'])
    ? round((1 - $meminfo['MemAvailable'] / $meminfo['MemTotal']) * 100, 2)
    : 0;

// Disk
$dfLine  = trim(shell_exec("df / | tail -1") ?: '');
$dfParts = preg_split('/\s+/', $dfLine);
$diskPct = isset($dfParts[4]) ? (float) rtrim($dfParts[4], '%') : 0;

// Load
$load = sys_getloadavg();

// Network I/O (eth0 or first interface)
$netIn = 0; $netOut = 0;
$netData = @file_get_contents('/proc/net/dev');
if ($netData) {
    foreach (explode("\n", $netData) as $line) {
        if (preg_match('/^\s*(eth0|ens\w+|enp\w+|eno\w+):\s*(\d+)(?:\s+\d+){7}\s+(\d+)/', $line, $m)) {
            $netIn  = (int)($m[2] / 1024);
            $netOut = (int)($m[3] / 1024);
            break;
        }
    }
}

// Insert
$db = DB::getInstance();
$db->execute(
    "INSERT INTO server_stats (cpu_usage, ram_usage, disk_usage, load_avg, net_in_kb, net_out_kb)
     VALUES (?,?,?,?,?,?)",
    [$cpuPct, $ramPct, $diskPct, $load[0], $netIn, $netOut]
);

// Prune rows older than 30 days
$db->execute("DELETE FROM server_stats WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

// Proxy health check — restart nginx on remote proxy VM if it's stopped
require_once NOVACPX_LIB . '/ProxyManager.php';
ProxyManager::healthCheck();
