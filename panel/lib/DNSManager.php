<?php
/**
 * DNSManager — BIND9 zone file generation and management
 */
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
        $file = self::$zonesDir . '/' . $domain . '.zone';
        @unlink($file);
        self::rebuildNamedConf();
        self::reloadBind();
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

        @mkdir(self::$zonesDir, 0755, true);
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
            $val  = in_array($r['type'], ['TXT','SPF','DMARC','DKIM']) ? "\"{$r['content']}\"" : $r['content'];
            $content .= "{$name} {$r['ttl']} IN {$r['type']} {$prio}{$val}\n";
        }

        file_put_contents(self::$zonesDir . '/' . $domain . '.zone', $content);
        self::rebuildNamedConf();
    }

    private static function rebuildNamedConf(): void {
        @mkdir(self::$zonesDir, 0755, true);
        $zones = glob(self::$zonesDir . '/*.zone') ?: [];
        $conf  = "// NovaCPX auto-generated zone list\n";
        foreach ($zones as $zf) {
            $domain = basename($zf, '.zone');
            $conf  .= "zone \"{$domain}\" { type master; file \"" . self::$zonesDir . "/{$domain}.zone\"; };\n";
        }
        file_put_contents(self::$namedConf, $conf);

        // Include in main named.conf if not already there
        $mainConf = '/etc/bind/named.conf';
        if (file_exists($mainConf) && !str_contains(file_get_contents($mainConf) ?: '', 'named.conf.novacpx')) {
            $line = "\ninclude \"" . self::$namedConf . "\";\n";
            shell_exec("echo " . escapeshellarg($line) . " | sudo tee -a {$mainConf} > /dev/null 2>&1");
        }
    }

    private static function reloadBind(): void {
        shell_exec("sudo rndc reload 2>/dev/null || sudo systemctl reload named 2>/dev/null || sudo systemctl reload bind9 2>/dev/null || true");
    }

    private static function serverIp(): string {
        return trim(shell_exec("hostname -I | awk '{print $1}'") ?: '127.0.0.1');
    }

    private static function getSetting(string $key, string $default): string {
        $row = DB::getInstance()->fetchOne("SELECT value FROM settings WHERE `key` = ?", [$key]);
        return $row['value'] ?? $default;
    }
}
