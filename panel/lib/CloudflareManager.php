<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
class CloudflareManager {
    private const API = 'https://api.cloudflare.com/client/v4/';
    private DB $db;

    public function __construct() {
        $this->db = DB::getInstance();
    }

    // ── Credential management ─────────────────────────────────────────────────
    public function saveCredentials(int $accountId, string $apiKey, string $email): bool {
        $this->db->execute("UPDATE accounts SET cf_api_key=?, cf_api_email=? WHERE id=?", [$apiKey, $email, $accountId]);
        return true;
    }

    public function testCredentials(string $apiKey, string $email): bool {
        $r = $this->req('GET', 'user/tokens/verify', [], $apiKey, $email);
        return $r['success'] ?? false;
    }

    public function getCredentials(int $accountId): ?array {
        $row = $this->db->fetchOne("SELECT cf_api_key, cf_api_email, cf_zone_id FROM accounts WHERE id=?", [$accountId]);
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
        $zone = $this->db->fetchOne("SELECT id FROM dns_zones WHERE domain = ?", [$domain]);
        if (!$zone) throw new RuntimeException("{$domain} has no DNS zone in this panel");
        $count = 0;
        foreach ($cfRecords as $rec) {
            if (!in_array($rec['type'], ['A','AAAA','CNAME','MX','TXT','SRV','NS','PTR','CAA'], true)) continue;
            $name = rtrim(str_replace('.' . $domain, '', $rec['name']), '.');
            if ($name === $domain) $name = '@';
            $name = $name ?: '@';
            $ttl  = (int)($rec['ttl'] ?? 300); if ($ttl < 1) $ttl = 300;
            $have = $this->db->fetchOne("SELECT id FROM dns_records WHERE zone_id = ? AND name = ? AND type = ? AND content = ?", [$zone['id'], $name, $rec['type'], $rec['content']]);
            if ($have) {
                $this->db->execute("UPDATE dns_records SET ttl = ?, proxied = ? WHERE id = ?", [$ttl, !empty($rec['proxied']) ? 1 : 0, $have['id']]);
            } else {
                $this->db->execute("INSERT INTO dns_records (zone_id, name, type, content, ttl, priority, proxied) VALUES (?,?,?,?,?,?,?)",
                    [$zone['id'], $name, $rec['type'], $rec['content'], $ttl, $rec['priority'] ?? null, !empty($rec['proxied']) ? 1 : 0]);
            }
            $count++;
        }
        // Remember the Cloudflare zone for this domain
        $this->db->execute("UPDATE dns_zones SET cf_zone_id = ? WHERE domain = ?", [$zoneId, $domain]);
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
        return $this->db->fetchAll("SELECT r.* FROM dns_records r JOIN dns_zones z ON z.id = r.zone_id WHERE z.domain = ?", [$domain]);
    }
}
