<?php
class CloudflareManager {
    private const API = 'https://api.cloudflare.com/client/v4/';
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getPDO();
    }

    // ── Credential management ─────────────────────────────────────────────────
    public function saveCredentials(int $accountId, string $apiKey, string $email): bool {
        $stmt = $this->db->prepare("UPDATE accounts SET cf_api_key=?, cf_api_email=? WHERE id=?");
        return $stmt->execute([$apiKey, $email, $accountId]);
    }

    public function testCredentials(string $apiKey, string $email): bool {
        $r = $this->req('GET', 'user/tokens/verify', [], $apiKey, $email);
        return $r['success'] ?? false;
    }

    public function getCredentials(int $accountId): ?array {
        $stmt = $this->db->prepare("SELECT cf_api_key, cf_api_email, cf_zone_id FROM accounts WHERE id=?");
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && $row['cf_api_key']) ? $row : null;
    }

    // ── Zones ─────────────────────────────────────────────────────────────────
    public function listZones(string $apiKey, string $email): array {
        $r = $this->req('GET', 'zones?per_page=200&status=active', [], $apiKey, $email);
        return $r['result'] ?? [];
    }

    public function getZoneId(string $domain, string $apiKey, string $email): ?string {
        $r = $this->req('GET', 'zones?name=' . urlencode($domain), [], $apiKey, $email);
        return $r['result'][0]['id'] ?? null;
    }

    // ── DNS records ───────────────────────────────────────────────────────────
    public function listRecords(string $zoneId, string $apiKey, string $email): array {
        $r = $this->req('GET', "zones/{$zoneId}/dns_records?per_page=200", [], $apiKey, $email);
        return $r['result'] ?? [];
    }

    public function createRecord(string $zoneId, array $record, string $apiKey, string $email): array {
        return $this->req('POST', "zones/{$zoneId}/dns_records", $record, $apiKey, $email);
    }

    public function updateRecord(string $zoneId, string $recordId, array $record, string $apiKey, string $email): array {
        return $this->req('PUT', "zones/{$zoneId}/dns_records/{$recordId}", $record, $apiKey, $email);
    }

    public function deleteRecord(string $zoneId, string $recordId, string $apiKey, string $email): bool {
        $r = $this->req('DELETE', "zones/{$zoneId}/dns_records/{$recordId}", [], $apiKey, $email);
        return $r['success'] ?? false;
    }

    public function toggleProxy(string $zoneId, string $recordId, bool $proxied, string $apiKey, string $email): array {
        // Fetch existing record first
        $r   = $this->req('GET', "zones/{$zoneId}/dns_records/{$recordId}", [], $apiKey, $email);
        $rec = $r['result'] ?? [];
        $rec['proxied'] = $proxied;
        unset($rec['id'], $rec['zone_id'], $rec['zone_name'], $rec['created_on'], $rec['modified_on'], $rec['meta']);
        return $this->req('PUT', "zones/{$zoneId}/dns_records/{$recordId}", $rec, $apiKey, $email);
    }

    // ── Sync: push local DNS records to Cloudflare ────────────────────────────
    public function syncToCloudflare(string $domain, string $zoneId, string $apiKey, string $email): array {
        $localRecords  = $this->getLocalRecords($domain);
        $cfRecords     = $this->listRecords($zoneId, $apiKey, $email);
        $cfIndex       = [];
        foreach ($cfRecords as $r) $cfIndex[$r['type'] . '_' . $r['name']] = $r;

        $created = 0; $updated = 0;
        foreach ($localRecords as $local) {
            $key = $local['type'] . '_' . $local['name'] . '.' . $domain . '.';
            if (isset($cfIndex[$key])) {
                $this->updateRecord($zoneId, $cfIndex[$key]['id'], [
                    'type'    => $local['type'],
                    'name'    => $local['name'],
                    'content' => $local['content'],
                    'ttl'     => (int)($local['ttl'] ?? 1),
                    'proxied' => in_array($local['type'], ['A','AAAA','CNAME']),
                ], $apiKey, $email);
                $updated++;
            } else {
                $this->createRecord($zoneId, [
                    'type'    => $local['type'],
                    'name'    => $local['name'],
                    'content' => $local['content'],
                    'ttl'     => (int)($local['ttl'] ?? 1),
                    'proxied' => in_array($local['type'], ['A','AAAA','CNAME']),
                ], $apiKey, $email);
                $created++;
            }
        }
        return ['created' => $created, 'updated' => $updated];
    }

    // ── Sync: pull Cloudflare records into local DB ───────────────────────────
    public function syncFromCloudflare(string $domain, string $zoneId, string $apiKey, string $email): int {
        $cfRecords = $this->listRecords($zoneId, $apiKey, $email);
        $count     = 0;
        foreach ($cfRecords as $rec) {
            $name = rtrim(str_replace('.' . $domain, '', $rec['name']), '.');
            $this->db->prepare("INSERT INTO dns_records (domain, name, type, content, ttl, priority)
                VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE content=VALUES(content), ttl=VALUES(ttl)")
                ->execute([$domain, $name ?: '@', $rec['type'], $rec['content'], $rec['ttl'] ?? 300, $rec['priority'] ?? 0]);
            $count++;
        }
        // Store zone ID on domain
        $this->db->prepare("UPDATE dns_zones SET cf_zone_id=? WHERE domain=?")->execute([$zoneId, $domain]);
        return $count;
    }

    // ── Purge cache ───────────────────────────────────────────────────────────
    public function purgeCache(string $zoneId, string $apiKey, string $email): bool {
        $r = $this->req('POST', "zones/{$zoneId}/purge_cache", ['purge_everything' => true], $apiKey, $email);
        return $r['success'] ?? false;
    }

    // ── HTTP helper ───────────────────────────────────────────────────────────
    private function req(string $method, string $path, array $body, string $apiKey, string $email): array {
        $ch = curl_init(self::API . ltrim($path, '/'));
        $headers = [
            "X-Auth-Email: {$email}",
            "X-Auth-Key: {$apiKey}",
            "Content-Type: application/json",
        ];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body && in_array($method, ['POST','PUT','PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $result = curl_exec($ch);
        curl_close($ch);
        return json_decode($result, true) ?? ['success' => false, 'errors' => ['curl failed']];
    }

    private function getLocalRecords(string $domain): array {
        $stmt = $this->db->prepare("SELECT * FROM dns_records WHERE domain=?");
        $stmt->execute([$domain]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
