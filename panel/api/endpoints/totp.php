<?php
require_once NOVACPX_LIB . '/TOTP.php';
// TOTP / 2FA management — all actions require authentication
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$uid  = $currentUser['id'] ?? $currentUser['uid'] ?? 0;
$db   = Database::getInstance()->getPDO();

match ($action) {
    // Begin setup: generate secret + return QR URL (not yet enabled)
    'setup' => (function() use ($db, $uid, $currentUser) {
        $secret  = TOTP::generateSecret();
        // Store pending secret (not enabled yet until verified)
        $db->prepare("UPDATE users SET totp_secret=? WHERE id=?")->execute([$secret, $uid]);
        Response::success([
            'secret'  => $secret,
            'qr_url'  => TOTP::qrUrl($secret, $currentUser['username']),
        ], 'Scan QR code in your authenticator app, then confirm with a code');
    })(),

    // Confirm setup: verify first code, then enable TOTP and return backup codes
    'enable' => (function() use ($db, $uid, $body) {
        $code = trim($body['code'] ?? '');
        if (strlen($code) !== 6) Response::error('Enter the 6-digit code from your authenticator');
        $stmt = $db->prepare("SELECT totp_secret FROM users WHERE id=?");
        $stmt->execute([$uid]);
        $secret = $stmt->fetchColumn();
        if (!$secret) Response::error('Run setup first');
        if (!TOTP::verify($secret, $code)) Response::error('Code incorrect — try again');
        $backupCodes  = TOTP::generateBackupCodes();
        $hashedCodes  = TOTP::hashBackupCodes($backupCodes);
        $db->prepare("UPDATE users SET totp_enabled=1, totp_backup_codes=? WHERE id=?")->execute([$hashedCodes, $uid]);
        audit('totp_enabled', 'security');
        Response::success(['backup_codes' => $backupCodes], '2FA enabled. Save your backup codes — they will not be shown again.');
    })(),

    // Disable TOTP (requires current password confirmation)
    'disable' => (function() use ($db, $uid, $body) {
        $pass = $body['password'] ?? '';
        $stmt = $db->prepare("SELECT password FROM users WHERE id=?");
        $stmt->execute([$uid]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($pass, $hash)) Response::error('Password incorrect');
        $db->prepare("UPDATE users SET totp_enabled=0, totp_secret=NULL, totp_backup_codes=NULL WHERE id=?")->execute([$uid]);
        audit('totp_disabled', 'security');
        Response::success(null, '2FA disabled');
    })(),

    // Get status (is 2FA on?)
    'status' => (function() use ($db, $uid) {
        $stmt = $db->prepare("SELECT totp_enabled FROM users WHERE id=?");
        $stmt->execute([$uid]);
        $enabled = (bool)$stmt->fetchColumn();
        Response::success(['enabled' => $enabled]);
    })(),

    // Regenerate backup codes
    'regen-backup-codes' => (function() use ($db, $uid, $body) {
        $code = trim($body['code'] ?? '');
        $stmt = $db->prepare("SELECT totp_secret, totp_enabled FROM users WHERE id=?");
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row['totp_enabled']) Response::error('2FA not enabled');
        if (!TOTP::verify($row['totp_secret'], $code)) Response::error('Code incorrect');
        $backupCodes = TOTP::generateBackupCodes();
        $db->prepare("UPDATE users SET totp_backup_codes=? WHERE id=?")->execute([TOTP::hashBackupCodes($backupCodes), $uid]);
        Response::success(['backup_codes' => $backupCodes], 'Backup codes regenerated');
    })(),

    // Admin: get 2FA status for any user
    'admin-status' => (function() use ($db, $body, $currentUser) {
        if ($currentUser['role'] !== 'admin') Response::error('Admin only', 403);
        $userId = (int)($body['user_id'] ?? 0);
        if (!$userId) Response::error('user_id required');
        $stmt = $db->prepare("SELECT id, username, totp_enabled FROM users WHERE id=?");
        $stmt->execute([$userId]);
        Response::success($stmt->fetch(PDO::FETCH_ASSOC));
    })(),

    // Admin: force-disable 2FA for a user (account recovery)
    'admin-disable' => (function() use ($db, $body, $currentUser) {
        if ($currentUser['role'] !== 'admin') Response::error('Admin only', 403);
        $userId = (int)($body['user_id'] ?? 0);
        if (!$userId) Response::error('user_id required');
        $db->prepare("UPDATE users SET totp_enabled=0, totp_secret=NULL, totp_backup_codes=NULL WHERE id=?")->execute([$userId]);
        audit('totp_admin_disabled', 'security', ['user_id' => $userId]);
        Response::success(null, '2FA disabled for user');
    })(),

    default => Response::error('Unknown action', 404),
};
