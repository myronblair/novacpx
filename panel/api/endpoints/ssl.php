<?php
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
require_once NOVACPX_LIB . '/SSLManager.php';
require_once NOVACPX_LIB . '/VhostManager.php';

$user      = Auth::getInstance()->user();
$accountId = $user['role'] === 'user'
    ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$user['uid']])['id'] ?? 0)
    : (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);

match ($action) {
    'list' => (function() use ($db, $accountId) {
        $rows = $db->fetchAll("SELECT id, domain, type, issued_at, expires_at, auto_renew, status FROM ssl_certs WHERE account_id = ? ORDER BY domain", [$accountId]);
        foreach ($rows as &$r) {
            $r['days_remaining'] = $r['expires_at'] ? (int)floor((strtotime($r['expires_at']) - time()) / 86400) : null;
        }
        Response::success($rows);
    })(),

    'issue' => (function() use ($body, $accountId) {
        $domain = trim($body['domain'] ?? '');
        $email  = trim($body['email'] ?? '');
        if (!$domain) Response::error("domain required");
        if (!$accountId) Response::error("account_id required");
        $result = SSLManager::issueLetsEncrypt($accountId, $domain, $email);
        audit('ssl.issue', $domain);
        Response::success($result, "SSL certificate issued for $domain");
    })(),

    'install-custom' => (function() use ($body, $accountId) {
        $domain = trim($body['domain'] ?? '');
        $cert   = trim($body['cert'] ?? '');
        $key    = trim($body['key'] ?? '');
        $chain  = trim($body['chain'] ?? '');
        if (!$domain || !$cert || !$key) Response::error("domain, cert, and key required");
        $id = SSLManager::installCustom($accountId, $domain, $cert, $key, $chain);
        audit('ssl.install-custom', $domain);
        Response::success(['id' => $id], 'Custom certificate installed');
    })(),

    'renew' => (function() use ($db, $body, $accountId) {
        $certId = (int)($body['cert_id'] ?? 0);
        $cert   = $db->fetchOne("SELECT * FROM ssl_certs WHERE id = ? AND account_id = ?", [$certId, $accountId]);
        if (!$cert) Response::error("Certificate not found", 404);
        $result = SSLManager::issueLetsEncrypt($accountId, $cert['domain']);
        audit('ssl.renew', $cert['domain']);
        Response::success($result, 'Certificate renewed');
    })(),

    'delete' => (function() use ($db, $body, $accountId) {
        $certId = (int)($body['cert_id'] ?? 0);
        $cert   = $db->fetchOne("SELECT * FROM ssl_certs WHERE id = ? AND account_id = ?", [$certId, $accountId]);
        if (!$cert) Response::error("Certificate not found", 404);
        $db->execute("DELETE FROM ssl_certs WHERE id = ?", [$certId]);
        $db->execute("UPDATE domains SET ssl_enabled = 0 WHERE domain = ?", [$cert['domain']]);
        audit('ssl.delete', $cert['domain']);
        Response::success(null, 'Certificate removed');
    })(),

    default => Response::error("Unknown ssl action: $action", 404),
};
