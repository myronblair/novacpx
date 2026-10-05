#!/usr/bin/env php
<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Runs Sweep scans: `run-sweep.php <account_id>` for one account (started by the panel's "Scan now" button), `run-sweep.php all` for
 * every active account (nightly, cron.d/novacpx-tasks). One scan per account at a time.
 */
define('NOVACPX_ROOT', '/srv/novacpx/public');
define('NOVACPX_LIB',  NOVACPX_ROOT . '/lib');
require NOVACPX_ROOT . '/lib/Core.php';
require NOVACPX_ROOT . '/lib/DB.php';
require NOVACPX_ROOT . '/lib/Sweep.php';

set_time_limit(0);
$arg = $argv[1] ?? '';
$db  = DB::getInstance();
$ids = $arg === 'all'
    ? array_column($db->fetchAll("SELECT id FROM accounts WHERE status = 'active' ORDER BY id"), 'id')
    : (ctype_digit($arg) ? [(int)$arg] : []);

foreach ($ids as $id) {
    $lock = fopen('/tmp/novacpx-sweep-' . (int)$id . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) continue;            // already scanning this account
    try {
        $r = Sweep::scanAccount((int)$id);
        printf("[%s] sweep account %d: %d files, %d findings\n", date('Y-m-d H:i:s'), $id, $r['files'], $r['findings']);
    } catch (Throwable $e) {
        fwrite(STDERR, "sweep account {$id}: " . $e->getMessage() . "\n");
    }
    flock($lock, LOCK_UN);
}
