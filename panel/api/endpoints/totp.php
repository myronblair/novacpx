<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Two-factor authentication (TOTP) for the signed-in user.
 *   GET  /api/totp/status              {enabled}
 *   POST /api/totp/setup               new secret + otpauth link (not active until confirmed)
 *   POST /api/totp/enable {code}       confirm the first code, turn 2FA on, returns the backup codes (shown once)
 *   POST /api/totp/disable {password}  turn 2FA off
 *   POST /api/totp/regen-backup-codes {code}
 *   POST /api/totp/admin-status|admin-disable {user_id}   admin only (account recovery)
 */
require_once NOVACPX_LIB . '/TOTP.php';
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$uid  = (int)($currentUser['uid'] ?? $currentUser['id'] ?? 0);
$db   = DB::getInstance();

match ($action) {
    // Begin setup: generate a secret (not enabled until the first code is confirmed)
    'setup' => (function() use ($db, $uid, $currentUser) {
        $enabled = (int)($db->fetchOne("SELECT totp_enabled FROM users WHERE id = ?", [$uid])['totp_enabled'] ?? 0);
        if ($enabled) Response::error('2FA is already on - turn it off first to set it up again');
        $secret = TOTP::generateSecret();
        $db->execute("UPDATE users SET totp_secret = ? WHERE id = ?", [$secret, $uid]);
        Response::success([
            'secret'  => $secret,
            'otpauth' => TOTP::otpauthUri($secret, $currentUser['username']),
        ], 'Scan the QR code in your authenticator app, then confirm with a code');
    })(),

    // Confirm setup: verify the first code, enable TOTP and return backup codes
    'enable' => (function() use ($db, $uid, $body) {
        $code = preg_replace('/\s+/', '', (string)($body['code'] ?? ''));
        if (!preg_match('/^[0-9]{6}$/', $code)) Response::error('Enter the 6-digit code from your authenticator');
        $secret = $db->fetchOne("SELECT totp_secret FROM users WHERE id = ?", [$uid])['totp_secret'] ?? '';
        if (!$secret) Response::error('Start the setup first');
        if (!TOTP::verify($secret, $code)) Response::error('Code incorrect - try again');
        $backupCodes = TOTP::generateBackupCodes();
        $db->execute("UPDATE users SET totp_enabled = 1, totp_backup_codes = ? WHERE id = ?", [TOTP::hashBackupCodes($backupCodes), $uid]);
        audit('totp_enabled', 'security');
        Response::success(['backup_codes' => $backupCodes], '2FA enabled. Save your backup codes - they will not be shown again.');
    })(),

    // Turn 2FA off (needs the current password)
    'disable' => (function() use ($db, $uid, $body) {
        $hash = $db->fetchOne("SELECT password FROM users WHERE id = ?", [$uid])['password'] ?? '';
        if (!$hash || !password_verify((string)($body['password'] ?? ''), $hash)) Response::error('Password incorrect');
        $db->execute("UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_backup_codes = NULL WHERE id = ?", [$uid]);
        audit('totp_disabled', 'security');
        Response::success(null, '2FA disabled');
    })(),

    'status' => (function() use ($db, $uid) {
        $enabled = (int)($db->fetchOne("SELECT totp_enabled FROM users WHERE id = ?", [$uid])['totp_enabled'] ?? 0) === 1;
        Response::success(['enabled' => $enabled]);
    })(),

    // Regenerate backup codes (needs a current code)
    'regen-backup-codes' => (function() use ($db, $uid, $body) {
        $code = preg_replace('/\s+/', '', (string)($body['code'] ?? ''));
        $row  = $db->fetchOne("SELECT totp_secret, totp_enabled FROM users WHERE id = ?", [$uid]);
        if (!$row || !(int)$row['totp_enabled']) Response::error('2FA is not on');
        if (!TOTP::verify($row['totp_secret'], $code)) Response::error('Code incorrect');
        $backupCodes = TOTP::generateBackupCodes();
        $db->execute("UPDATE users SET totp_backup_codes = ? WHERE id = ?", [TOTP::hashBackupCodes($backupCodes), $uid]);
        audit('totp_backup_codes', 'security');
        Response::success(['backup_codes' => $backupCodes], 'Backup codes regenerated');
    })(),

    // Admin: 2FA status for any user
    'admin-status' => (function() use ($db, $body, $currentUser) {
        if ($currentUser['role'] !== 'admin') Response::error('Admin only', 403);
        $userId = (int)($body['user_id'] ?? 0);
        if (!$userId) Response::error('user_id required');
        Response::success($db->fetchOne("SELECT id, username, totp_enabled FROM users WHERE id = ?", [$userId]));
    })(),

    // Admin: force-disable 2FA for a user (account recovery)
    'admin-disable' => (function() use ($db, $body, $currentUser) {
        if ($currentUser['role'] !== 'admin') Response::error('Admin only', 403);
        $userId = (int)($body['user_id'] ?? 0);
        if (!$userId) Response::error('user_id required');
        $db->execute("UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_backup_codes = NULL WHERE id = ?", [$userId]);
        audit('totp_admin_disabled', 'security', ['user_id' => $userId]);
        Response::success(null, '2FA disabled for user');
    })(),

    default => Response::error('Unknown action', 404),
};
