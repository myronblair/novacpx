<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Account transfer API (admin).
 *   POST /api/transfer/export {account_id}  start packing the account (background job)
 *   POST /api/transfer/import {link}        start pulling an account from another NovaCPX server (background job)
 *   GET  /api/transfer/job?id=N             job status; result holds the single-use link (export) or the account summary (import)
 *   GET  /api/transfer/log                  recent exports and imports
 */
require_once NOVACPX_LIB . '/Transfer.php';
require_once NOVACPX_LIB . '/AccountManager.php';
Auth::getInstance()->require('admin');
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

match ($action) {
    'export' => (function() use ($db, $body) {
        $acct = $db->fetchOne("SELECT id, username FROM accounts WHERE id = ?", [(int)($body['account_id'] ?? 0)]);
        if (!$acct) Response::error('Account not found', 404);
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.-]+(:[0-9]{2,5})?$/', $host)) Response::error("Cannot work out this panel's address");
        $job = Transfer::startJob('export', ['account_id' => (int)$acct['id'], 'host' => $host]);
        audit('transfer.export', $acct['username']);
        Response::success(['job' => $job], 'Packing started');
    })(),

    'import' => (function() use ($body) {
        $link = trim((string)($body['link'] ?? ''));
        if (!preg_match('#^https://[^?\s]+/api/transferdl/get\?token=[0-9a-f]{64}(&sum=[0-9a-f]{64})?$#', $link)) Response::error('That is not a NovaCPX transfer link');
        $job = Transfer::startJob('import', ['link' => $link]);
        audit('transfer.import', 'started');
        Response::success(['job' => $job], 'Import started');
    })(),

    'job' => (function() use ($db) {
        $j = $db->fetchOne("SELECT id, kind, status, result, error, created_at FROM transfer_jobs WHERE id = ?", [(int)($_GET['id'] ?? 0)]);
        if (!$j) Response::error('Job not found', 404);
        $j['result'] = $j['result'] ? json_decode($j['result'], true) : null;
        if ($j['status'] === 'running' && strtotime($j['created_at'] . ' UTC') < time() - 4 * 3600) {
            $db->execute("UPDATE transfer_jobs SET status = 'failed', error = 'Timed out', args = '{}' WHERE id = ?", [$j['id']]);
            $j['status'] = 'failed'; $j['error'] = 'Timed out';
        }
        Response::success($j);
    })(),

    'log' => (function() use ($db) {
        Response::success($db->fetchAll("SELECT * FROM transfer_log ORDER BY id DESC LIMIT 50"));
    })(),

    default => Response::error("Unknown transfer action: $action", 404),
};
