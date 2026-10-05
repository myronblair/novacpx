<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Account transfer API (admin).
 *   POST /api/transfer/export {account_id}  pack the account and return a single-use download link (valid 2 hours)
 *   POST /api/transfer/import {link}        pull an account from another NovaCPX server and create it here
 *   GET  /api/transfer/log                  recent exports and imports
 */
require_once NOVACPX_LIB . '/Transfer.php';
require_once NOVACPX_LIB . '/AccountManager.php';
Auth::getInstance()->require('admin');
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

match ($action) {
    'export' => (function() use ($db, $body) {
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [(int)($body['account_id'] ?? 0)]);
        if (!$acct) Response::error('Account not found', 404);
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.-]+(:[0-9]{2,5})?$/', $host)) Response::error("Cannot work out this panel's address");
        set_time_limit(0);
        try { $r = Transfer::export($acct, $host); }
        catch (RuntimeException $e) { Response::error($e->getMessage()); }
        audit('transfer.export', $acct['username']);
        Response::success($r, 'Transfer link ready');
    })(),

    'import' => (function() use ($body) {
        set_time_limit(0);
        try { $r = Transfer::import((string)($body['link'] ?? '')); }
        catch (RuntimeException $e) { Response::error($e->getMessage()); }
        audit('transfer.import', $r['username']);
        Response::success($r, 'Account imported');
    })(),

    'log' => (function() use ($db) {
        Response::success($db->fetchAll("SELECT * FROM transfer_log ORDER BY id DESC LIMIT 50"));
    })(),

    default => Response::error("Unknown transfer action: $action", 404),
};
