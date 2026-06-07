<?php
/**
 * Server Setup endpoint — hostname, contact info, nameservers, branding
 */
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
Auth::getInstance()->requireRole(['admin']);

function getSetting(string $key, $db): ?string {
    return $db->fetchOne("SELECT value FROM settings WHERE `key` = ?", [$key])['value'] ?? null;
}
function setSetting(string $key, string $value, $db): void {
    $db->execute(
        "INSERT INTO settings (`key`, value, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE value=?, updated_at=NOW()",
        [$key, $value, $value]
    );
}

match ($action) {
    'get' => (function() use ($db) {
        $keys = ['hostname','contact_name','contact_email','contact_phone','company_name',
                 'nameserver1','nameserver2','server_ip','ipv6_enabled','panel_theme',
                 'panel_title','smtp_host','smtp_port','smtp_user','admin_email',
                 'two_fa_required','registration_enabled','max_accounts','timezone'];
        $data = [];
        foreach ($keys as $k) {
            $data[$k] = getSetting($k, $db) ?? '';
        }
        // Live hostname
        $data['system_hostname'] = trim(shell_exec('hostname -f') ?: '');
        Response::success($data);
    })(),

    'save' => (function() use ($body, $db) {
        $allowed = ['contact_name','contact_email','contact_phone','company_name',
                    'nameserver1','nameserver2','panel_theme','panel_title',
                    'smtp_host','smtp_port','smtp_user','smtp_pass','admin_email',
                    'two_fa_required','registration_enabled','max_accounts','timezone'];
        foreach ($allowed as $k) {
            if (isset($body[$k])) setSetting($k, (string)$body[$k], $db);
        }
        audit('server_setup.save', 'settings updated');
        Response::success(null, 'Settings saved');
    })(),

    'set-hostname' => (function() use ($body, $db) {
        $host = trim($body['hostname'] ?? '');
        if (!$host || !preg_match('/^[a-z0-9][a-z0-9\-\.]+$/', $host)) Response::error("Invalid hostname");
        shell_exec("hostnamectl set-hostname " . escapeshellarg($host));
        // Update /etc/hosts
        $hosts = file_get_contents('/etc/hosts');
        $ip = trim(shell_exec("hostname -I | awk '{print $1}'") ?: '127.0.0.1');
        $hosts = preg_replace('/^127\.0\.1\.1.*/m', "127.0.1.1\t$host", $hosts);
        if (!str_contains($hosts, '127.0.1.1')) $hosts .= "\n127.0.1.1\t$host\n";
        file_put_contents('/etc/hosts', $hosts);
        setSetting('hostname', $host, $db);
        audit('server_setup.hostname', $host);
        Response::success(['hostname' => $host], 'Hostname updated');
    })(),

    'nameservers' => (function() use ($body, $db) {
        $ns1 = trim($body['ns1'] ?? '');
        $ns2 = trim($body['ns2'] ?? '');
        if (!$ns1) Response::error("ns1 required");
        setSetting('nameserver1', $ns1, $db);
        if ($ns2) setSetting('nameserver2', $ns2, $db);
        // Update BIND options
        $named = file_get_contents('/etc/bind/named.conf.options') ?: '';
        if (str_contains($named, 'forwarders')) {
            $named = preg_replace('/forwarders\s*\{[^}]*\}/', "forwarders { 8.8.8.8; 1.1.1.1; }", $named);
            file_put_contents('/etc/bind/named.conf.options', $named);
            shell_exec('systemctl reload bind9 2>/dev/null || systemctl reload named 2>/dev/null');
        }
        audit('server_setup.nameservers', "$ns1 / $ns2");
        Response::success(null, 'Nameservers updated');
    })(),

    'smtp-test' => (function() use ($body, $db) {
        $to = $body['to'] ?? getSetting('admin_email', $db);
        if (!$to) Response::error("email address required");
        $host  = getSetting('smtp_host', $db) ?: 'localhost';
        $port  = (int)(getSetting('smtp_port', $db) ?: 587);
        $user  = getSetting('smtp_user', $db) ?: '';
        $pass  = getSetting('smtp_pass', $db) ?: '';
        // Simple test using PHP mail() for localhost
        $sent = mail($to, 'NovaCPX SMTP Test', 'SMTP is working correctly from NovaCPX.');
        Response::success(['sent' => $sent], $sent ? 'Test email sent' : 'mail() returned false — check SMTP config');
    })(),

    'system-info' => (function() {
        $os       = trim(shell_exec("lsb_release -d 2>/dev/null | cut -d: -f2 | xargs") ?: php_uname('s'));
        $kernel   = trim(shell_exec("uname -r") ?: '');
        $uptime   = trim(shell_exec("uptime -p") ?: '');
        $cpu      = trim(shell_exec("grep 'model name' /proc/cpuinfo | head -1 | cut -d: -f2 | xargs") ?: '');
        $cpuCores = (int)trim(shell_exec("nproc") ?: 1);
        $memTotal = (int)trim(shell_exec("grep MemTotal /proc/meminfo | awk '{print $2}'") ?: 0);
        Response::success([
            'os'        => $os,
            'kernel'    => $kernel,
            'uptime'    => $uptime,
            'cpu'       => $cpu,
            'cores'     => $cpuCores,
            'ram_gb'    => round($memTotal / 1048576, 1),
            'hostname'  => trim(shell_exec('hostname -f') ?: ''),
            'ip'        => trim(shell_exec("hostname -I | awk '{print $1}'") ?: ''),
        ]);
    })(),

    default => Response::error("Unknown server_setup action: $action", 404),
};
