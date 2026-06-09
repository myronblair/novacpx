#!/usr/bin/env php
<?php
/**
 * NovaCPX nightly update cache warmer.
 * Runs as root via cron — populates update_cache_novacpx and update_cache_os
 * in the panel settings table so the Updates page loads instantly.
 */
$cfgFile = '/etc/novacpx/config.ini';
$cfg     = @parse_ini_file($cfgFile, true) ?: [];
$dbPath  = $cfg['database']['path'] ?? '/var/lib/novacpx/panel.db';
$srcDir  = '/opt/novacpx-src';

try {
    $pdo = new PDO("sqlite:{$dbPath}", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    fwrite(STDERR, "DB open failed: {$e->getMessage()}\n");
    exit(1);
}

function cache(PDO $pdo, string $key, array $data): void {
    $json = json_encode($data);
    $pdo->prepare("INSERT INTO settings(`key`,value,updated_at) VALUES(?,?,datetime('now'))
                   ON CONFLICT(`key`) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at")
        ->execute([$key, $json]);
}

// ── NovaCPX panel update check ────────────────────────────────────────────────
echo "[novacpx] Fetching remote commits…\n";
if (is_dir($srcDir . '/.git')) {
    shell_exec("git -C " . escapeshellarg($srcDir) . " fetch origin 2>/dev/null");
    $logOut  = shell_exec("git -C " . escapeshellarg($srcDir) . " log HEAD..origin/main --oneline 2>/dev/null") ?: '';
    $updates = array_values(array_filter(explode("\n", trim($logOut))));
    $branch  = trim(shell_exec("git -C " . escapeshellarg($srcDir) . " branch --show-current 2>/dev/null") ?: 'main');
    $commit  = trim(shell_exec("git -C " . escapeshellarg($srcDir) . " rev-parse --short HEAD 2>/dev/null") ?: '');
    cache($pdo, 'update_cache_novacpx', [
        'updates_available' => count($updates),
        'current_commit'    => $commit,
        'branch'            => $branch,
        'commits'           => $updates,
    ]);
    echo "[novacpx] Done — " . count($updates) . " commit(s) available.\n";
} else {
    echo "[novacpx] Skipped — source repo not found at {$srcDir}.\n";
}

// ── OS package update check ───────────────────────────────────────────────────
echo "[os] Running apt-get update…\n";
shell_exec('apt-get update -qq 2>/dev/null');
$out      = shell_exec('apt-get -s upgrade 2>/dev/null | grep "^Inst " | head -50') ?: '';
$packages = array_values(array_filter(array_map(function ($line) {
    if (preg_match('/^Inst (\S+).*\[(\S+)\].*\((\S+)/', $line, $m)) return ['name' => $m[1], 'from' => $m[2], 'to' => $m[3]];
    if (preg_match('/^Inst (\S+)\s+\((\S+)/',           $line, $m)) return ['name' => $m[1], 'from' => '',     'to' => $m[2]];
    return null;
}, explode("\n", trim($out)))));
$security = count(array_filter($packages, fn($p) => str_contains($p['name'] ?? '', 'security')));
cache($pdo, 'update_cache_os', [
    'upgradable'       => count($packages),
    'security_updates' => $security,
    'packages'         => $packages,
    'last_checked'     => date('Y-m-d H:i:s'),
]);
echo "[os] Done — " . count($packages) . " package(s) upgradable.\n";
