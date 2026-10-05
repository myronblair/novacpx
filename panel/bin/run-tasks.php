#!/usr/bin/env php
<?php
/**
 * NovaCPX background tasks, every 5 minutes (cron.d/novacpx-tasks):
 *   Traffic Meter  read new access-log lines, update usage, send allowance warnings
 *   Pulse          check every active site and mail on down/recovered
 * Scheduled backups run from run-schedules.php (their own, slower cron entry).
 */
define('NOVACPX_ROOT', '/srv/novacpx/public');
define('NOVACPX_LIB',  NOVACPX_ROOT . '/lib');
require NOVACPX_ROOT . '/lib/Core.php';
require NOVACPX_ROOT . '/lib/DB.php';
require NOVACPX_ROOT . '/lib/TrafficMeter.php';
require NOVACPX_ROOT . '/lib/Pulse.php';

$lock = fopen('/tmp/novacpx-run-tasks.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);      // previous run still going

$t = microtime(true);
try { $a = TrafficMeter::collect(); TrafficMeter::enforce(); if ((int)date('G') === 3 && (int)date('i') < 5) TrafficMeter::prune(); }
catch (Throwable $e) { error_log('[run-tasks] traffic: ' . $e->getMessage()); $a = ['accounts' => 0, 'lines' => 0]; }
try { $p = Pulse::run(); }
catch (Throwable $e) { error_log('[run-tasks] pulse: ' . $e->getMessage()); $p = ['checked' => 0, 'down' => 0]; }
printf("[%s] traffic: %d accounts, %d log lines; pulse: %d sites, %d down; %.1fs\n", date('Y-m-d H:i:s'), $a['accounts'], $a['lines'], $p['checked'], $p['down'], microtime(true) - $t);
