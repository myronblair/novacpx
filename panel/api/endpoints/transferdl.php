<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Transfer download (public: the link's token is the credential). One download per link, then the bundle is deleted.
 *   GET /api/transferdl/get?token=<64 hex>
 */
require_once NOVACPX_LIB . '/Root.php';
if ($action !== 'get') Response::error('Not found', 404);
$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $token)) Response::error('Not found', 404);
$db = DB::getInstance();
$t = $db->fetchOne("SELECT * FROM transfer_tokens WHERE token_hash = ? AND used = 0 AND expires_at > datetime('now')", [hash('sha256', $token)]);
if (!$t) Response::error('This link has expired or was already used', 404);
$path = '/var/lib/novacpx/transfers/' . $t['bundle_id'] . '.tar';
if (!is_file($path)) Response::error('Not found', 404);
// single use: burn the link before sending anything
$db->execute("UPDATE transfer_tokens SET used = 1 WHERE token_hash = ?", [$t['token_hash']]);
$db->execute("INSERT INTO transfer_log (direction, username, status) VALUES ('export', ?, 'downloaded')", [$t['username']]);
set_time_limit(0);
header_remove('Content-Type');
header('Content-Type: application/x-tar');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
while (ob_get_level()) ob_end_clean();
readfile($path);
register_shutdown_function(function () use ($t) {
    try { Root::ok('transfer.cleanup', ['id' => $t['bundle_id']]); } catch (Throwable $e) { /* the next export purges leftovers */ }
});
exit;
