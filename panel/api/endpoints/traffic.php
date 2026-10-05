<?php
/**
 * Traffic Meter API.
 *   GET /api/traffic/summary[?account_id=N]   this month, allowance and the last 30 days for one account
 *   GET /api/traffic/overview                 all accounts the caller may see (admin: everyone, reseller: own customers)
 *   GET/POST /api/traffic/settings            admin: what happens at 100% (notify | suspend)
 */
require_once NOVACPX_LIB . '/TrafficMeter.php';

$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$cu   = Auth::getInstance()->user();

match ($action) {
    'summary' => (function() use ($db, $cu, $body) {
        $accountId = $cu['role'] === 'user'
            ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$cu['uid']])['id'] ?? 0)
            : (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
        assert_account_access($accountId);
        Response::success(TrafficMeter::summary($accountId));
    })(),

    'overview' => (function() use ($cu) {
        Auth::getInstance()->require('admin', 'reseller');
        Response::success(TrafficMeter::overview($cu['role'] === 'reseller' ? (int)$cu['uid'] : null));
    })(),

    'settings' => (function() use ($db, $body) {
        Auth::getInstance()->require('admin');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $act = (string)($body['bandwidth_action'] ?? '');
            if (!in_array($act, ['notify', 'suspend'], true)) Response::error('bandwidth_action must be notify or suspend');
            $db->execute("INSERT INTO settings (key, value) VALUES ('bandwidth_action', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", [$act]);
            audit('traffic.settings', $act);
            Response::success(null, 'Saved');
        }
        Response::success(['bandwidth_action' => $db->fetchOne("SELECT value FROM settings WHERE key = 'bandwidth_action'")['value'] ?? 'notify']);
    })(),

    default => Response::error("Unknown traffic action: $action", 404),
};
