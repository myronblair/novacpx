#!/usr/bin/env php
<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Runs one account-transfer job in the background: `run-transfer.php <job_id>` (started by the panel's Account Transfer page).
 */
define('NOVACPX_ROOT', '/srv/novacpx/public');
define('NOVACPX_LIB',  NOVACPX_ROOT . '/lib');
require NOVACPX_ROOT . '/lib/Core.php';
require NOVACPX_ROOT . '/lib/DB.php';
require NOVACPX_ROOT . '/lib/Transfer.php';
require NOVACPX_ROOT . '/lib/AccountManager.php';
foreach (['VhostManager', 'DNSManager', 'PHPManager'] as $c) require_once NOVACPX_ROOT . "/lib/{$c}.php";

set_time_limit(0);
$id = (int)($argv[1] ?? 0);
if ($id > 0) Transfer::runJob($id);
