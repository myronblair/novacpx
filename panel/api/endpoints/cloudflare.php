<?php
require_once NOVACPX_LIB . '/CloudflareManager.php';
if (!in_array($currentUser['role'], ['admin','reseller','user'])) Response::error('Forbidden', 403);

$cf      = new CloudflareManager();
$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$isAdmin = $currentUser['role'] === 'admin';

$accountId = (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
if ($currentUser['role'] === 'user') $accountId = $currentUser['account_id'] ?? 0;

// Resolve credentials — from body (for test) or from stored account creds
$apiKey = $body['api_key'] ?? null;
$email  = $body['email']   ?? null;
if (!$apiKey && $accountId) {
    $creds  = $cf->getCredentials($accountId);
    $apiKey = $creds['cf_api_key']   ?? null;
    $email  = $creds['cf_api_email'] ?? null;
}

match ($action) {
    'test-key' => (function() use ($cf, $body) {
        $key   = trim($body['api_key'] ?? '');
        $email = trim($body['email']   ?? '');
        if (!$key || !$email) Response::error('api_key and email required');
        $ok = $cf->testCredentials($key, $email);
        Response::success(['valid' => $ok], $ok ? 'API key is valid' : 'Invalid API key');
    })(),

    'save-credentials' => (function() use ($cf, $body, $accountId) {
        if (!$accountId) Response::error('account_id required');
        $key   = trim($body['api_key'] ?? '');
        $email = trim($body['email']   ?? '');
        if (!$key || !$email) Response::error('api_key and email required');
        $cf->saveCredentials($accountId, $key, $email);
        audit('cf_credentials_saved', 'cloudflare', ['account_id' => $accountId]);
        Response::success(null, 'Cloudflare credentials saved');
    })(),

    'get-credentials' => (function() use ($cf, $accountId) {
        if (!$accountId) Response::error('account_id required');
        $creds = $cf->getCredentials($accountId);
        // Mask the key in the response
        if ($creds) $creds['cf_api_key'] = substr($creds['cf_api_key'], 0, 6) . str_repeat('*', 30);
        Response::success($creds);
    })(),

    'list-zones' => (function() use ($cf, $apiKey, $email) {
        if (!$apiKey || !$email) Response::error('No Cloudflare credentials configured');
        Response::success($cf->listZones($apiKey, $email));
    })(),

    'list-records' => (function() use ($cf, $body, $apiKey, $email) {
        $zoneId = trim($body['zone_id'] ?? '');
        if (!$zoneId) Response::error('zone_id required');
        if (!$apiKey || !$email) Response::error('No Cloudflare credentials configured');
        Response::success($cf->listRecords($zoneId, $apiKey, $email));
    })(),

    'toggle-proxy' => (function() use ($cf, $body, $apiKey, $email) {
        $zoneId   = trim($body['zone_id']   ?? '');
        $recordId = trim($body['record_id'] ?? '');
        $proxied  = (bool)($body['proxied'] ?? false);
        if (!$zoneId || !$recordId) Response::error('zone_id and record_id required');
        if (!$apiKey || !$email) Response::error('No credentials');
        $result = $cf->toggleProxy($zoneId, $recordId, $proxied, $apiKey, $email);
        Response::success($result, 'Proxy status updated');
    })(),

    'sync-to-cf' => (function() use ($cf, $body, $apiKey, $email) {
        $domain = trim($body['domain']  ?? '');
        $zoneId = trim($body['zone_id'] ?? '');
        if (!$domain || !$zoneId) Response::error('domain and zone_id required');
        if (!$apiKey || !$email) Response::error('No credentials');
        $result = $cf->syncToCloudflare($domain, $zoneId, $apiKey, $email);
        audit('cf_sync_to', 'cloudflare', ['domain' => $domain]);
        Response::success($result, "Pushed to Cloudflare: {$result['created']} created, {$result['updated']} updated");
    })(),

    'sync-from-cf' => (function() use ($cf, $body, $apiKey, $email) {
        $domain = trim($body['domain']  ?? '');
        $zoneId = trim($body['zone_id'] ?? '');
        if (!$domain || !$zoneId) Response::error('domain and zone_id required');
        if (!$apiKey || !$email) Response::error('No credentials');
        $count = $cf->syncFromCloudflare($domain, $zoneId, $apiKey, $email);
        audit('cf_sync_from', 'cloudflare', ['domain' => $domain, 'records' => $count]);
        Response::success(['count' => $count], "Pulled {$count} records from Cloudflare");
    })(),

    'purge-cache' => (function() use ($cf, $body, $apiKey, $email) {
        $zoneId = trim($body['zone_id'] ?? '');
        if (!$zoneId) Response::error('zone_id required');
        if (!$apiKey || !$email) Response::error('No credentials');
        $ok = $cf->purgeCache($zoneId, $apiKey, $email);
        Response::success(['success' => $ok], $ok ? 'Cache purged' : 'Purge failed');
    })(),

    default => Response::error('Unknown action', 404),
};
