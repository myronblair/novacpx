<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Web terminal API (admin only). The shell itself is ttyd behind the admin panel's nginx at /terminal/;
 * nginx asks authcheck before letting any request through.
 *   GET  /api/terminal/status      state, 2FA, unlock, recordings
 *   POST /api/terminal/enable      install + start (needs 2FA turned on)
 *   POST /api/terminal/disable     stop
 *   POST /api/terminal/unlock      {code} fresh authenticator code, opens the terminal for 5 minutes
 *   GET  /api/terminal/authcheck   204 when the caller may use /terminal/ (called by nginx)
 *   GET  /api/terminal/log         {name} one recording
 */
require_once NOVACPX_LIB . '/Root.php';
require_once NOVACPX_LIB . '/TOTP.php';

const TERM_UNLOCK_SECONDS = 300;
const TERM_MAX_FAILS      = 5;     // wrong codes allowed ...
const TERM_FAIL_WINDOW    = 600;   // ... per this many seconds

$db   = DB::getInstance();
$me   = $currentUser;
$uid  = (int)($me['uid'] ?? $me['id'] ?? 0);
$sid  = hash('sha256', $_COOKIE['ncpx_session'] ?? '');

/** Admin portal, real admin session (not an API token, not an impersonation). */
$isAdminSession = ($me['role'] ?? '') === 'admin'
    && empty($me['impersonator_id'])
    && isset($_COOKIE['ncpx_session'])
    && !str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer ');

$enabled = fn() => ($db->fetchOne("SELECT value FROM settings WHERE `key`='terminal_enabled'")['value'] ?? '0') === '1';
$twofa   = fn() => (int)($db->fetchOne("SELECT totp_enabled FROM users WHERE id = ?", [$uid])['totp_enabled'] ?? 0) === 1;
$unlockedUntil = function() use ($db, $sid, $uid): int {
    $r = $db->fetchOne("SELECT expires_at FROM terminal_unlocks WHERE session_hash = ? AND user_id = ? AND expires_at > datetime('now')", [$sid, $uid]);
    return $r ? (int)strtotime($r['expires_at'] . ' UTC') : 0;
};

if ($action === 'authcheck') {
    // Everything has to hold at once; any miss is a plain 403 (nginx auth_request treats 401/403 as "no").
    if (!$isAdminSession || !$enabled() || !$twofa() || !$unlockedUntil()) { http_response_code(403); exit; }
    if (str_ends_with(parse_url($_SERVER['HTTP_X_ORIGINAL_URI'] ?? '', PHP_URL_PATH) ?? '', '/ws')) {
        audit('terminal.session', 'connect', ['ip' => $_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')]);
    }
    http_response_code(204);
    exit;
}

if (!$isAdminSession) Response::error('Forbidden', 403);
$body = json_decode(file_get_contents('php://input'), true) ?? [];

match ($action) {
    'status' => (function() use ($enabled, $twofa, $unlockedUntil) {
        $svc = ['installed' => false, 'active' => false, 'webserver' => '?'];
        try { $svc = Root::json('terminal.status'); } catch (RuntimeException $e) {}
        $logs = [];
        try { $logs = Root::json('terminal.log.list'); } catch (RuntimeException $e) {}
        $until = $unlockedUntil();
        Response::success([
            'enabled'        => $enabled(),
            'twofa'          => $twofa(),
            'service'        => $svc,
            'unlocked_for'   => $until ? max(0, $until - time()) : 0,
            'unlock_seconds' => TERM_UNLOCK_SECONDS,
            'recordings'     => $logs,
        ]);
    })(),

    'enable' => (function() use ($db, $twofa) {
        if (!$twofa()) Response::error('Turn on two-factor authentication for your admin login first (Security > 2FA).');
        try { Root::ok('terminal.enable'); }
        catch (RuntimeException $e) { Response::error($e->getMessage()); }
        $db->execute("INSERT INTO settings (`key`, `value`, updated_at) VALUES ('terminal_enabled', '1', datetime('now'))
                      ON CONFLICT(`key`) DO UPDATE SET value = '1', updated_at = datetime('now')");
        audit('terminal.enable', 'security');
        Response::success(null, 'Web terminal enabled');
    })(),

    'disable' => (function() use ($db) {
        $db->execute("INSERT INTO settings (`key`, `value`, updated_at) VALUES ('terminal_enabled', '0', datetime('now'))
                      ON CONFLICT(`key`) DO UPDATE SET value = '0', updated_at = datetime('now')");
        $db->execute("DELETE FROM terminal_unlocks");
        try { Root::ok('terminal.disable'); } catch (RuntimeException $e) {}
        audit('terminal.disable', 'security');
        Response::success(null, 'Web terminal disabled');
    })(),

    'unlock' => (function() use ($db, $uid, $sid, $body, $enabled, $twofa) {
        if (!$enabled()) Response::error('The web terminal is switched off');
        if (!$twofa())   Response::error('Two-factor authentication is not enabled for this login');
        $recent = (int)($db->fetchOne("SELECT COUNT(*) c FROM terminal_attempts WHERE user_id = ? AND at > datetime('now', ?)", [$uid, '-' . TERM_FAIL_WINDOW . ' seconds'])['c'] ?? 0);
        if ($recent >= TERM_MAX_FAILS) { audit('terminal.unlock_locked', 'security'); Response::error('Too many wrong codes. Wait ten minutes and try again.', 429); }
        $code = preg_replace('/\s+/', '', (string)($body['code'] ?? ''));
        $secret = $db->fetchOne("SELECT totp_secret FROM users WHERE id = ?", [$uid])['totp_secret'] ?? '';
        if (!preg_match('/^[0-9]{6}$/', $code) || !$secret || !TOTP::verify($secret, $code)) {
            $db->execute("INSERT INTO terminal_attempts (user_id) VALUES (?)", [$uid]);
            audit('terminal.unlock_failed', 'security');
            Response::error('That code is not right');
        }
        $db->execute("DELETE FROM terminal_attempts WHERE user_id = ?", [$uid]);
        $db->execute("DELETE FROM terminal_unlocks WHERE expires_at <= datetime('now')");
        $db->execute("INSERT OR REPLACE INTO terminal_unlocks (session_hash, user_id, expires_at) VALUES (?, ?, datetime('now', ?))",
                     [$sid, $uid, '+' . TERM_UNLOCK_SECONDS . ' seconds']);
        audit('terminal.unlock', 'security');
        Response::success(['unlocked_for' => TERM_UNLOCK_SECONDS], 'Unlocked');
    })(),

    'log' => (function() {
        $name = (string)($_GET['name'] ?? '');
        try { $d = Root::json('terminal.log.read', ['name' => $name]); }
        catch (RuntimeException $e) { Response::error($e->getMessage(), 404); }
        audit('terminal.log_view', $name);
        Response::success($d);
    })(),

    default => Response::error("Unknown terminal action: $action", 404),
};
