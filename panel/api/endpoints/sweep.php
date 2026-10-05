<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Sweep API (malware scanner).
 *   GET  /api/sweep/status[?account_id=N]       last scan + open findings
 *   POST /api/sweep/scan        {account_id?}   start a scan in the background
 *   POST /api/sweep/quarantine  {finding_id, account_id?}   move the file out of the web root (restorable)
 *   POST /api/sweep/restore     {finding_id, account_id?}
 *   POST /api/sweep/ignore      {finding_id, account_id?}   mark as harmless (stays quiet in later scans)
 *   GET  /api/sweep/overview    admin/reseller: findings per account
 *   GET|POST /api/sweep/settings  admin: use ClamAV in addition (when installed)
 */
require_once NOVACPX_LIB . '/Root.php';
require_once NOVACPX_LIB . '/Sweep.php';

$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$cu   = Auth::getInstance()->user();

$resolveAccount = function () use ($db, $cu, $body): array {
    $id = $cu['role'] === 'user'
        ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$cu['uid']])['id'] ?? 0)
        : (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
    return assert_account_access($id);
};

match ($action) {
    'status' => (function() use ($resolveAccount) {
        $a = $resolveAccount();
        Response::success(Sweep::status((int)$a['id']));
    })(),

    'scan' => (function() use ($resolveAccount, $db) {
        $a = $resolveAccount();
        $id = (int)$a['id'];
        $run = $db->fetchOne("SELECT id FROM sweep_runs WHERE account_id = ? AND status = 'running' AND started_at > datetime('now','-1 hour')", [$id]);
        if ($run) Response::error('A scan is already running for this account');
        exec('nohup /usr/bin/php ' . escapeshellarg('/opt/novacpx/bin/run-sweep.php') . ' ' . $id . ' >/dev/null 2>&1 &');
        audit('sweep.scan', $a['username']);
        Response::success(null, 'Scan started - results appear in a minute or two');
    })(),

    'quarantine' => (function() use ($resolveAccount, $db, $body) {
        $a = $resolveAccount();
        $f = $db->fetchOne("SELECT * FROM sweep_findings WHERE id = ? AND account_id = ?", [(int)($body['finding_id'] ?? 0), $a['id']]);
        if (!$f) Response::error('Finding not found', 404);
        if ($f['status'] !== 'open') Response::error('This finding is not open');
        try { Root::ok('sweep.quarantine', ['username' => $a['username'], 'id' => (int)$f['id'], 'path' => $f['path']]); }
        catch (RuntimeException $e) { Response::error($e->getMessage()); }
        $db->execute("UPDATE sweep_findings SET status = 'quarantined' WHERE id = ?", [$f['id']]);
        audit('sweep.quarantine', $a['username'], ['path' => $f['path']]);
        Response::success(null, 'File moved to quarantine');
    })(),

    'restore' => (function() use ($resolveAccount, $db, $body) {
        $a = $resolveAccount();
        $f = $db->fetchOne("SELECT * FROM sweep_findings WHERE id = ? AND account_id = ?", [(int)($body['finding_id'] ?? 0), $a['id']]);
        if (!$f) Response::error('Finding not found', 404);
        if ($f['status'] !== 'quarantined') Response::error('This file is not in quarantine');
        try { Root::ok('sweep.restore', ['username' => $a['username'], 'id' => (int)$f['id'], 'path' => $f['path']]); }
        catch (RuntimeException $e) { Response::error($e->getMessage()); }
        $db->execute("UPDATE sweep_findings SET status = 'ignored' WHERE id = ?", [$f['id']]);   // restored on purpose: stay quiet
        audit('sweep.restore', $a['username'], ['path' => $f['path']]);
        Response::success(null, 'File restored');
    })(),

    'ignore' => (function() use ($resolveAccount, $db, $body) {
        $a = $resolveAccount();
        $n = $db->execute("UPDATE sweep_findings SET status = 'ignored' WHERE id = ? AND account_id = ? AND status = 'open'", [(int)($body['finding_id'] ?? 0), $a['id']]);
        audit('sweep.ignore', $a['username'], ['finding' => (int)($body['finding_id'] ?? 0)]);
        Response::success(null, 'Marked as harmless');
    })(),

    'overview' => (function() use ($cu) {
        Auth::getInstance()->require('admin', 'reseller');
        Response::success(Sweep::overview($cu['role'] === 'reseller' ? (int)$cu['uid'] : null));
    })(),

    'settings' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $v = !empty($body['clamav']) ? '1' : '0';
            $db->execute("INSERT INTO settings (key, value) VALUES ('sweep_clamav', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", [$v]);
            audit('sweep.settings', "clamav={$v}");
            Response::success(null, 'Saved');
        }
        Response::success(['clamav' => ($db->fetchOne("SELECT value FROM settings WHERE key = 'sweep_clamav'")['value'] ?? '0') === '1', 'clamav_installed' => Sweep::clamAvailable()]);
    })(),

    default => Response::error("Unknown sweep action: $action", 404),
};
