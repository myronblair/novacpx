<?php
/**
 * DKIM key management endpoint
 * Actions: list, rotate, view
 */

require_once NOVACPX_LIB . '/AccountManager.php';
require_once NOVACPX_LIB . '/DNSManager.php';

$db   = DB::getInstance();
$user = $currentUser;

switch ($action) {

    case 'list':
        // Admin: all domains. User/reseller: their own accounts
        if ($user['role'] === 'admin') {
            $keys = $db->fetchAll(
                "SELECT dk.*, a.username FROM dkim_keys dk JOIN accounts a ON dk.account_id = a.id ORDER BY dk.domain"
            );
        } else {
            $keys = $db->fetchAll(
                "SELECT dk.* FROM dkim_keys dk
                 JOIN accounts a ON dk.account_id = a.id
                 WHERE a.user_id = ?
                 ORDER BY dk.domain",
                [$user['id']]
            );
        }
        foreach ($keys as &$k) { unset($k['private_key_path']); }
        Response::json(['keys' => $keys]);
        break;

    case 'view':
        $domain = trim($body['domain'] ?? '');
        if (!$domain) Response::error('domain required', 400);

        $row = $db->fetchOne("SELECT * FROM dkim_keys WHERE domain = ?", [$domain]);
        if (!$row) Response::error('No DKIM key found for domain', 404);

        // Access control
        if ($user['role'] !== 'admin') {
            $acct = $db->fetchOne("SELECT id FROM accounts WHERE id = ? AND user_id = ?", [$row['account_id'], $user['id']]);
            if (!$acct) Response::error('Forbidden', 403);
        }

        unset($row['private_key_path']);
        // Also return the full DNS TXT value
        $row['dns_record_name']  = "mail._domainkey.{$domain}";
        $row['dns_record_value'] = "v=DKIM1; k=rsa; p={$row['public_key']}";
        Response::json(['key' => $row]);
        break;

    case 'rotate':
        $domain = trim($body['domain'] ?? '');
        if (!$domain) Response::error('domain required', 400);

        $acct = $db->fetchOne("SELECT a.id, a.user_id FROM accounts a JOIN domains d ON d.account_id = a.id WHERE d.domain = ? AND d.type = 'main'", [$domain]);
        if (!$acct) Response::error('Domain not found', 404);

        if ($user['role'] !== 'admin' && (int)$acct['user_id'] !== (int)$user['id']) {
            Response::error('Forbidden', 403);
        }

        $selector = AccountManager::rotateDKIM((int)$acct['id'], $domain);
        Response::json(['ok' => true, 'selector' => $selector, 'message' => "DKIM rotated. New selector: {$selector}._domainkey.{$domain}"]);
        break;

    case 'provision':
        // Re-provision SPF/DKIM/DMARC for a domain (admin only or own account)
        $domain = trim($body['domain'] ?? '');
        if (!$domain) Response::error('domain required', 400);

        $acct = $db->fetchOne("SELECT a.id, a.user_id FROM accounts a JOIN domains d ON d.account_id = a.id WHERE d.domain = ?", [$domain]);
        if (!$acct) Response::error('Domain not found', 404);

        if ($user['role'] !== 'admin' && (int)$acct['user_id'] !== (int)$user['id']) {
            Response::error('Forbidden', 403);
        }

        AccountManager::provisionEmailDNS((int)$acct['id'], $domain);
        Response::json(['ok' => true, 'message' => "SPF, DKIM, and DMARC records provisioned for {$domain}"]);
        break;

    default:
        Response::error("Unknown action: $action", 404);
}
