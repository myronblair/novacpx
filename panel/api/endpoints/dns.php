<?php
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

require_once NOVACPX_LIB . '/DNSManager.php';

// Resolve account_id from current user or from query param (admin)
$user      = Auth::getInstance()->user();
$accountId = null;
if ($user['role'] === 'user') {
    $acct = $db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$user['uid']]);
    $accountId = $acct ? (int)$acct['id'] : null;
} else {
    $accountId = (int)($_GET['account_id'] ?? $body['account_id'] ?? 0) ?: null;
    if ($accountId && $user['role'] === 'reseller') assert_account_access($accountId);
}

match ($action) {

    'zones' => (function() use ($db, $accountId, $user) {
        $where = $accountId ? "WHERE account_id = $accountId" : ($user['role'] !== 'admin' ? "WHERE 1=0" : '');
        $rows  = $db->fetchAll("SELECT z.*, (SELECT COUNT(*) FROM dns_records WHERE zone_id = z.id) as record_count FROM dns_zones z $where ORDER BY z.domain");
        Response::success($rows);
    })(),

    'records' => (function() use ($db, $body) {
        $zoneId = (int)($_GET['zone_id'] ?? $body['zone_id'] ?? 0);
        if (!$zoneId) Response::error("zone_id required");
        $records = $db->fetchAll("SELECT * FROM dns_records WHERE zone_id = ? ORDER BY type, name", [$zoneId]);
        Response::success($records);
    })(),

    'add-record' => (function() use ($db, $body, $accountId) {
        $zoneId   = (int)($body['zone_id'] ?? 0);
        $name     = trim($body['name'] ?? '@');
        $type     = strtoupper($body['type'] ?? 'A');
        $content  = trim($body['content'] ?? '');
        $ttl      = (int)($body['ttl'] ?? 3600);
        $priority = isset($body['priority']) ? (int)$body['priority'] : null;

        if (!$content) Response::error("Record content required");
        $allowed = ['A','AAAA','CNAME','MX','TXT','SRV','NS','CAA','DKIM','SPF','DMARC'];
        if (!in_array($type, $allowed)) Response::error("Invalid record type");

        // Verify zone belongs to this account
        $zone = $db->fetchOne("SELECT id FROM dns_zones WHERE id = ?" . ($accountId ? " AND account_id = $accountId" : ''), [$zoneId]);
        if (!$zone) Response::error("Zone not found", 404);

        $id = DNSManager::addRecord($zoneId, $name, $type, $content, $ttl, $priority);
        audit('dns.add-record', "{$type}:{$name}", ['zone_id' => $zoneId]);
        Response::success(['id' => $id], 'Record added');
    })(),

    'update-record' => (function() use ($db, $body) {
        $id = (int)($body['id'] ?? 0);
        DNSManager::updateRecord($id, $body);
        audit('dns.update-record', "record:$id");
        Response::success(null, 'Record updated');
    })(),

    'delete-record' => (function() use ($db, $body) {
        $id = (int)($body['id'] ?? 0);
        DNSManager::deleteRecord($id);
        audit('dns.delete-record', "record:$id");
        Response::success(null, 'Record deleted');
    })(),

    'create-zone' => (function() use ($db, $body, $accountId, $user) {
        Auth::getInstance()->require('admin', 'reseller');
        $domain = strtolower(trim($body['domain'] ?? ''));
        $acctId = (int)($body['account_id'] ?? $accountId ?? 0);
        if (!$domain) Response::error("Domain required");
        // Admins can create zones not tied to any account (acctId = 0)
        if (!$acctId && $user['role'] !== 'admin') Response::error("account_id required");
        DNSManager::createZone($acctId, $domain);
        audit('dns.create-zone', $domain);
        Response::success(null, "DNS zone created for $domain");
    })(),

    'delete-zone' => (function() use ($db, $body, $user) {
        Auth::getInstance()->require('admin');
        $domain = trim($body['domain'] ?? '');
        if (!$domain && isset($body['zone_id'])) {
            $row = $db->fetchOne("SELECT domain FROM dns_zones WHERE id = ?", [(int)$body['zone_id']]);
            $domain = $row['domain'] ?? '';
        }
        if (!$domain) Response::error("Domain required");
        DNSManager::removeZone($domain);
        audit('dns.delete-zone', $domain);
        Response::success(null, "DNS zone removed for $domain");
    })(),

    'check-propagation' => (function() use ($db) {
        $domain = trim($_GET['domain'] ?? '');
        if (!$domain) Response::error("Domain required");
        $results = [];
        $nameservers = ['8.8.8.8', '1.1.1.1', '9.9.9.9'];
        foreach ($nameservers as $ns) {
            $out = trim(shell_exec("dig +short A " . escapeshellarg($domain) . " @{$ns} 2>/dev/null") ?: '');
            $results[$ns] = $out ?: 'no answer';
        }
        Response::success(['domain' => $domain, 'results' => $results]);
    })(),

    // ── NS Health Checker (#22e) ─────────────────────────────────────────────
    'ns-health' => (function() use ($db) {
        Auth::getInstance()->require('admin');
        $ns1 = $db->fetchOne("SELECT value FROM settings WHERE `key`='ns1_hostname'")['value'] ?? '';
        $ns2 = $db->fetchOne("SELECT value FROM settings WHERE `key`='ns2_hostname'")['value'] ?? '';
        $zones = $db->fetchAll("SELECT domain FROM dns_zones ORDER BY domain LIMIT 200");
        $results = [];
        foreach ($zones as $z) {
            $domain = $z['domain'];
            $raw = shell_exec("dig +short NS " . escapeshellarg($domain) . " 2>/dev/null") ?? '';
            $nsRecords = array_filter(array_map('trim', explode("\n", $raw)));
            $foundNs1 = $ns1 ? in_array(rtrim($ns1,'.').'.', array_map(fn($n)=>rtrim($n,'.').'.', $nsRecords)) : null;
            $foundNs2 = $ns2 ? in_array(rtrim($ns2,'.').'.', array_map(fn($n)=>rtrim($n,'.').'.', $nsRecords)) : null;
            $results[] = [
                'domain' => $domain,
                'ns1'    => $nsRecords[0] ?? null,
                'ns2'    => $nsRecords[1] ?? null,
                'ok'     => ($ns1 ? $foundNs1 : true) && ($ns2 ? $foundNs2 : true),
                'ns_raw' => $nsRecords,
            ];
        }
        Response::success(['results' => $results, 'expected_ns1' => $ns1, 'expected_ns2' => $ns2]);
    })(),

    default => Response::error("Unknown dns action: $action", 404),
};
