<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Pulse API (site uptime monitor).
 *   GET /api/pulse/status[?account_id=N]   the account's sites: state, response time, 24h uptime, last 48 checks
 *   GET /api/pulse/overview                every monitored site the caller may see (admin: all, reseller: own customers)
 *   GET/POST /api/pulse/settings           admin: monitoring on/off and how many failed checks in a row count as down
 */
require_once NOVACPX_LIB . '/Pulse.php';

$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$cu   = Auth::getInstance()->user();

match ($action) {
    'status' => (function() use ($db, $cu, $body) {
        $accountId = $cu['role'] === 'user'
            ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$cu['uid']])['id'] ?? 0)
            : (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
        assert_account_access($accountId);
        Response::success(Pulse::forAccount($accountId));
    })(),

    'overview' => (function() use ($cu) {
        Auth::getInstance()->require('admin', 'reseller');
        Response::success(Pulse::overview($cu['role'] === 'reseller' ? (int)$cu['uid'] : null));
    })(),

    'settings' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $enabled = !empty($body['enabled']) ? '1' : '0';
            $fails   = max(1, min(10, (int)($body['fail_count'] ?? 2)));
            foreach (['pulse_enabled' => $enabled, 'pulse_fail_count' => (string)$fails] as $k => $v) {
                $db->execute("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", [$k, $v]);
            }
            audit('pulse.settings', "enabled={$enabled} fails={$fails}");
            Response::success(null, 'Saved');
        }
        Response::success([
            'enabled'    => ($db->fetchOne("SELECT value FROM settings WHERE key = 'pulse_enabled'")['value'] ?? '1') !== '0',
            'fail_count' => (int)($db->fetchOne("SELECT value FROM settings WHERE key = 'pulse_fail_count'")['value'] ?? 2),
        ]);
    })(),

    default => Response::error("Unknown pulse action: $action", 404),
};
