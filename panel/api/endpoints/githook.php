<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Git Deploy webhook (public: it authenticates itself with the connection's secret).
 *   POST /api/githook/run?account=ID
 * Accepted proofs: an HMAC-SHA256 signature of the request body in X-Hub-Signature-256 ("sha256=<hex>"), X-Gitea-Signature or
 * X-Gogs-Signature, or ?key=<secret> for services that cannot sign. Pushes to other branches are acknowledged and ignored.
 * At most one deploy per 10 seconds per site.
 */
require_once NOVACPX_LIB . '/Root.php';
require_once NOVACPX_LIB . '/GitDeploy.php';

$db  = DB::getInstance();
$id  = (int)($_GET['account'] ?? 0);
$cfg = $id > 0 ? GitDeploy::get($id) : null;
$acct = $cfg ? $db->fetchOne("SELECT a.id, a.username, a.status FROM accounts a WHERE a.id = ?", [$id]) : null;
if (!$cfg || !$acct || $acct['status'] !== 'active') Response::error('Not found', 404);

$raw = file_get_contents('php://input') ?: '';
$ok  = false;
foreach (['HTTP_X_HUB_SIGNATURE_256', 'HTTP_X_GITEA_SIGNATURE', 'HTTP_X_GOGS_SIGNATURE'] as $h) {
    if (empty($_SERVER[$h])) continue;
    $sig = preg_replace('/^sha256=/', '', (string)$_SERVER[$h]);
    if (hash_equals(hash_hmac('sha256', $raw, $cfg['secret']), strtolower($sig))) { $ok = true; break; }
}
if (!$ok && isset($_GET['key']) && hash_equals((string)$cfg['secret'], (string)$_GET['key'])) $ok = true;
if (!$ok) Response::error('Invalid signature', 403);

$payload = json_decode($raw, true);
if (is_array($payload) && isset($payload['ref']) && $payload['ref'] !== 'refs/heads/' . $cfg['branch']) {
    Response::success(null, 'Ignored: push to ' . $payload['ref']);
}
if ($cfg['last_deploy'] && strtotime($cfg['last_deploy'] . ' UTC') > time() - 10) {
    http_response_code(429);
    Response::error('Deploy ran a moment ago, try again shortly', 429);
}

$r = GitDeploy::run($acct, $cfg);
GitDeploy::record($id, $r);
if (!$r['ok']) Response::error('Deploy failed', 500);
Response::success(['commit' => $r['commit']], 'Deployed');
