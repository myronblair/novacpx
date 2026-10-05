#!/usr/bin/env php
<?php
/**
 * Runs the backup schedules that are due (cron.d/novacpx-tasks, every 15 minutes). One run at a time: a long backup simply makes the
 * next tick exit immediately.
 */
define('NOVACPX_ROOT', '/srv/novacpx/public');
define('NOVACPX_LIB',  NOVACPX_ROOT . '/lib');
require NOVACPX_ROOT . '/lib/Core.php';
require NOVACPX_ROOT . '/lib/DB.php';
require NOVACPX_ROOT . '/lib/ScheduleRunner.php';

$lock = fopen('/tmp/novacpx-run-schedules.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

set_time_limit(0);
$r = ScheduleRunner::runDue();
if ($r['ran'] || $r['failed']) printf("[%s] scheduled backups: %d done, %d failed\n", date('Y-m-d H:i:s'), $r['ran'], $r['failed']);
