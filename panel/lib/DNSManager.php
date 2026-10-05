<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * DNSManager — BIND9 zone file generation and management
 */
require_once __DIR__ . '/Root.php';

class DNSManager {

    private static string $zonesDir   = '/etc/bind/novacpx-zones';
    private static string $namedConf  = '/etc/bind/named.conf.novacpx';

    public static function createZone(int $accountId, string $domain): void {
        $db     = DB::getInstance();
        $serial = (int)date('Ymd') * 100 + 1;
        $ns1    = self::getSetting('ns1_hostname', 'ns1.localhost');
        $ns2    = self::getSetting('ns2_hostname', 'ns2.localhost');
        $email  = 'hostmaster.' . $domain;
        $ip     = self::serverIp();

        $zoneId = (int)$db->insert(
            "INSERT INTO dns_zones (account_id, domain, serial, primary_ns, secondary_ns, admin_email) VALUES (?,?,?,?,?,?)",
            [$accountId, $domain, $serial, $ns1, $ns2, $email]
        );

        // Default records
        $defaults = [
            ['@',    'A',   $ip,          3600, null],
            ['www',  'A',   $ip,          3600, null],
            ['mail', 'A',   $ip,          3600, null],
            ['@',    'MX',  "mail.{$domain}.", 3600, 10],
        ];
        foreach ($defaults as [$name, $type, $content, $ttl, $prio]) {
            $db->execute(
                "INSERT INTO dns_records (zone_id, name, type, content, ttl, priority) VALUES (?,?,?,?,?,?)",
                [$zoneId, $name, $type, $content, $ttl, $prio]
            );
        }

        self::writeZoneFile($zoneId);
        self::reloadBind();
    }

    public static function removeZone(string $domain): void {
        $db   = DB::getInstance();
        $zone = $db->fetchOne("SELECT id FROM dns_zones WHERE domain = ?", [$domain]);
        if ($zone) {
            $db->execute("DELETE FROM dns_zones WHERE id = ?", [$zone['id']]);
        }
        Root::run('dns.remove', ['domain' => $domain]);   // removes the zone file, rebuilds the zone list, reloads BIND
    }

    public static function addRecord(int $zoneId, string $name, string $type, string $content, int $ttl = 3600, ?int $priority = null): int {
        $db = DB::getInstance();
        $id = (int)$db->insert(
            "INSERT INTO dns_records (zone_id, name, type, content, ttl, priority) VALUES (?,?,?,?,?,?)",
            [$zoneId, $name, $type, $content, $ttl, $priority]
        );
        $db->execute("UPDATE dns_zones SET serial = serial + 1, updated_at = NOW() WHERE id = ?", [$zoneId]);
        self::writeZoneFile($zoneId);
        self::reloadBind();
        return $id;
    }

    public static function updateRecord(int $recordId, array $data): void {
        $db = DB::getInstance();
        $rec = $db->fetchOne("SELECT zone_id FROM dns_records WHERE id = ?", [$recordId]);
        if (!$rec) throw new RuntimeException("Record not found");
        $db->execute(
            "UPDATE dns_records SET name=?, type=?, content=?, ttl=?, priority=? WHERE id=?",
            [$data['name'], $data['type'], $data['content'], $data['ttl'] ?? 3600, $data['priority'] ?? null, $recordId]
        );
        $db->execute("UPDATE dns_zones SET serial = serial + 1, updated_at = NOW() WHERE id = ?", [$rec['zone_id']]);
        self::writeZoneFile($rec['zone_id']);
        self::reloadBind();
    }

    public static function deleteRecord(int $recordId): void {
        $db  = DB::getInstance();
        $rec = $db->fetchOne("SELECT zone_id FROM dns_records WHERE id = ?", [$recordId]);
        if (!$rec) throw new RuntimeException("Record not found");
        $db->execute("DELETE FROM dns_records WHERE id = ?", [$recordId]);
        $db->execute("UPDATE dns_zones SET serial = serial + 1 WHERE id = ?", [$rec['zone_id']]);
        self::writeZoneFile($rec['zone_id']);
        self::reloadBind();
    }

    public static function writeZoneFile(int $zoneId): void {
        $db   = DB::getInstance();
        $zone = $db->fetchOne("SELECT * FROM dns_zones WHERE id = ?", [$zoneId]);
        if (!$zone) return;
        $records = $db->fetchAll("SELECT * FROM dns_records WHERE zone_id = ? ORDER BY type, name", [$zoneId]);

        $domain  = $zone['domain'];
        $content = "\$ORIGIN {$domain}.\n\$TTL {$zone['ttl']}\n\n";
        $content .= "@ IN SOA {$zone['primary_ns']}. {$zone['admin_email']}. (\n";
        $content .= "    {$zone['serial']} ; serial\n";
        $content .= "    {$zone['refresh']} ; refresh\n";
        $content .= "    {$zone['retry']}   ; retry\n";
        $content .= "    {$zone['expire']}  ; expire\n";
        $content .= "    {$zone['minimum']} ; minimum\n)\n\n";
        $content .= "@ IN NS {$zone['primary_ns']}.\n";
        $content .= "@ IN NS {$zone['secondary_ns']}.\n\n";

        foreach ($records as $r) {
            $name = $r['name'] === '@' ? '@' : $r['name'];
            $prio = $r['priority'] !== null ? "{$r['priority']} " : '';
            $val  = in_array($r['type'], ['TXT','SPF','DMARC','DKIM'])
                ? implode(' ', array_map(fn($chunk) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $chunk) . '"', str_split((string)$r['content'], 255) ?: ['']))
                : $r['content'];
            $content .= "{$name} {$r['ttl']} IN {$r['type']} {$prio}{$val}\n";
        }

        // The privileged helper checks every line (plain resource records only), validates the zone with named-checkzone,
        // installs it, rebuilds the zone list and reloads BIND.
        $r = Root::run('dns.zone', ['domain' => $domain, 'content' => $content]);
        if ($r['rc'] !== 0) throw new RuntimeException('DNS zone rejected: ' . trim($r['out']));
    }

    private static function reloadBind(): void {
        // Nothing to do: the helper reloads BIND whenever it installs or removes a zone.
    }

    private static function serverIp(): string {
        return trim(shell_exec("hostname -I | awk '{print $1}'") ?: '127.0.0.1');
    }

    private static function getSetting(string $key, string $default): string {
        $row = DB::getInstance()->fetchOne("SELECT value FROM settings WHERE `key` = ?", [$key]);
        return $row['value'] ?? $default;
    }
}
