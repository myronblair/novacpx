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
 *   GET  /api/sweep/access      admin: resellers + end users with their on/off switch; reseller: their end users
 *   POST /api/sweep/set-access  {user_id, enabled}   switch the scanner on/off for a reseller (admin) or an end user (admin, or their reseller)
 *   GET  /api/sweep/clamav-status                admin: ClamAV install state
 *   POST /api/sweep/clamav-install|clamav-update|clamav-remove   admin: server-wide ClamAV installer (runs in the background)
 */
require_once NOVACPX_LIB . '/Root.php';
require_once NOVACPX_LIB . '/Sweep.php';

$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$cu   = Auth::getInstance()->user();

// A reseller whose own switch is off cannot use Sweep (or manage anyone's switches).
if ($cu['role'] === 'reseller' && !Sweep::allowedFor((int)$cu['uid'])) Response::error('Malware sweep is switched off for your reseller account', 403);
// An end user can still open the page (it explains why), but every action that scans or changes files is refused while off.
$mustBeOn = function (array $a): void {
    if (!Sweep::accountAllowed((int)$a['id'])) Response::error('Malware scanning is switched off for this account', 403);
};

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

    'scan' => (function() use ($mustBeOn, $resolveAccount, $db) {
        $a = $resolveAccount();
        $mustBeOn($a);
        $id = (int)$a['id'];
        $run = $db->fetchOne("SELECT id FROM sweep_runs WHERE account_id = ? AND status = 'running' AND started_at > datetime('now','-1 hour')", [$id]);
        if ($run) Response::error('A scan is already running for this account');
        exec('nohup /usr/bin/php ' . escapeshellarg('/opt/novacpx/bin/run-sweep.php') . ' ' . $id . ' >/dev/null 2>&1 &');
        audit('sweep.scan', $a['username']);
        Response::success(null, 'Scan started - results appear in a minute or two');
    })(),

    'quarantine' => (function() use ($mustBeOn, $resolveAccount, $db, $body) {
        $a = $resolveAccount();
        $mustBeOn($a);
        $f = $db->fetchOne("SELECT * FROM sweep_findings WHERE id = ? AND account_id = ?", [(int)($body['finding_id'] ?? 0), $a['id']]);
        if (!$f) Response::error('Finding not found', 404);
        if ($f['status'] !== 'open') Response::error('This finding is not open');
        try { Root::ok('sweep.quarantine', ['username' => $a['username'], 'id' => (int)$f['id'], 'path' => $f['path']]); }
        catch (RuntimeException $e) { Response::error($e->getMessage()); }
        $db->execute("UPDATE sweep_findings SET status = 'quarantined' WHERE id = ?", [$f['id']]);
        audit('sweep.quarantine', $a['username'], ['path' => $f['path']]);
        Response::success(null, 'File moved to quarantine');
    })(),

    'restore' => (function() use ($mustBeOn, $resolveAccount, $db, $body) {
        $a = $resolveAccount();
        $mustBeOn($a);
        $f = $db->fetchOne("SELECT * FROM sweep_findings WHERE id = ? AND account_id = ?", [(int)($body['finding_id'] ?? 0), $a['id']]);
        if (!$f) Response::error('Finding not found', 404);
        if ($f['status'] !== 'quarantined') Response::error('This file is not in quarantine');
        try { Root::ok('sweep.restore', ['username' => $a['username'], 'id' => (int)$f['id'], 'path' => $f['path']]); }
        catch (RuntimeException $e) { Response::error($e->getMessage()); }
        $db->execute("UPDATE sweep_findings SET status = 'ignored' WHERE id = ?", [$f['id']]);   // restored on purpose: stay quiet
        audit('sweep.restore', $a['username'], ['path' => $f['path']]);
        Response::success(null, 'File restored');
    })(),

    'ignore' => (function() use ($mustBeOn, $resolveAccount, $db, $body) {
        $a = $resolveAccount();
        $mustBeOn($a);
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

    'access' => (function() use ($cu) {
        Auth::getInstance()->require('admin', 'reseller');
        Response::success(Sweep::access($cu['role'] === 'reseller' ? (int)$cu['uid'] : null));
    })(),

    'set-access' => (function() use ($db, $cu, $body) {
        Auth::getInstance()->require('admin', 'reseller');
        $target = $db->fetchOne("SELECT id, username, role, reseller_id FROM users WHERE id = ?", [(int)($body['user_id'] ?? 0)]);
        if (!$target || !in_array($target['role'], ['reseller', 'user'], true)) Response::error('User not found', 404);
        if ($cu['role'] === 'reseller' && ($target['role'] !== 'user' || (int)$target['reseller_id'] !== (int)$cu['uid'])) Response::error('Access denied', 403);
        $on = !empty($body['enabled']) ? 1 : 0;
        $db->execute("UPDATE users SET sweep_enabled = ? WHERE id = ?", [$on, $target['id']]);
        audit('sweep.access', $target['username'], ['enabled' => $on]);
        Response::success(null, 'Malware sweep ' . ($on ? 'switched on' : 'switched off') . ' for ' . $target['username']);
    })(),

    'clamav-status' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        try { $st = Root::json('clamav.status'); } catch (RuntimeException $e) { Response::error($e->getMessage()); }
        $st['enabled'] = ($db->fetchOne("SELECT value FROM settings WHERE key = 'sweep_clamav'")['value'] ?? '0') === '1';
        Response::success($st);
    })(),

    'clamav-install' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        try { $st = Root::json('clamav.status'); } catch (RuntimeException $e) { Response::error($e->getMessage()); }
        if (($st['state'] ?? '') === 'running') Response::error('An install is already running');
        if (!empty($st['installed'])) Response::error('ClamAV is already installed');
        @unlink('/tmp/novacpx-clamav-install.log');
        Root::background('clamav.install', [], '/tmp/novacpx-clamav-install.log');
        // use it as soon as it is there
        $db->execute("INSERT INTO settings (key, value) VALUES ('sweep_clamav', '1') ON CONFLICT(key) DO UPDATE SET value = '1'");
        audit('sweep.clamav', 'install');
        Response::success(null, 'Installing ClamAV in the background - this takes a few minutes');
    })(),

    'clamav-update' => (function() {
        Auth::getInstance()->require('admin');
        Root::background('clamav.update', [], '/tmp/novacpx-clamav-install.log');
        audit('sweep.clamav', 'update');
        Response::success(null, 'Updating the virus signatures');
    })(),

    'clamav-remove' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        Root::background('clamav.remove', [], '/tmp/novacpx-clamav-install.log');
        $db->execute("INSERT INTO settings (key, value) VALUES ('sweep_clamav', '0') ON CONFLICT(key) DO UPDATE SET value = '0'");
        audit('sweep.clamav', 'remove');
        Response::success(null, 'Removing ClamAV in the background');
    })(),

    default => Response::error("Unknown sweep action: $action", 404),
};
