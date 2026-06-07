<?php
/**
 * Firewall endpoint — UFW rules + Fail2Ban jail management
 * All actions require admin role.
 */

Auth::getInstance()->require('admin');

$db = DB::getInstance();

// ── Helpers ────────────────────────────────────────────────────────────────

function fw_exec(string $cmd): string {
    // Prefix ufw and fail2ban-client with sudo (www-data has NOPASSWD via sudoers.d/novacpx-firewall)
    $cmd = preg_replace('/^(ufw|fail2ban-client|systemctl (restart|reload|start|stop) fail2ban)\b/', 'sudo $1', $cmd);
    $out = shell_exec($cmd . ' 2>&1');
    return trim($out ?: '');
}

/** Parse `ufw status verbose` into structured data */
function ufw_status(): array {
    $raw    = fw_exec('ufw status verbose');
    $active = str_contains($raw, 'Status: active');

    // Parse numbered rules from `ufw status numbered`
    $numbered = fw_exec('ufw status numbered');
    $rules = [];
    foreach (explode("\n", $numbered) as $line) {
        if (preg_match('/^\[\s*(\d+)\]\s+(.+?)\s{2,}(.+?)\s{2,}(.+)$/', $line, $m)) {
            $rules[] = [
                'num'     => (int)$m[1],
                'to'      => trim($m[2]),
                'action'  => trim($m[3]),
                'from'    => trim($m[4]),
            ];
        } elseif (preg_match('/^\[\s*(\d+)\]\s+(\S+)\s+(\S+)\s+(.+)$/', $line, $m)) {
            $rules[] = [
                'num'    => (int)$m[1],
                'to'     => trim($m[2]),
                'action' => trim($m[3]),
                'from'   => trim($m[4]),
            ];
        }
    }

    // Default policy
    preg_match('/Default:\s+(\w+) \(incoming\),\s+(\w+) \(outgoing\)/', $raw, $pol);
    return [
        'active'           => $active,
        'default_incoming' => $pol[1] ?? 'deny',
        'default_outgoing' => $pol[2] ?? 'allow',
        'rules'            => $rules,
        'raw'              => $raw,
    ];
}

/** Parse fail2ban-client status output into jail list */
function f2b_jails(): array {
    $raw   = fw_exec('fail2ban-client status');
    preg_match('/Jail list:\s*(.+)/', $raw, $m);
    if (!$m[1]) return [];
    return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
}

/** Get details for a single jail */
function f2b_jail_detail(string $jail): array {
    $raw = fw_exec('fail2ban-client status ' . escapeshellarg($jail));
    preg_match('/Currently banned:\s*(\d+)/', $raw, $banned);
    preg_match('/Total banned:\s*(\d+)/', $raw, $total);
    preg_match('/Currently failed:\s*(\d+)/', $raw, $failed);
    preg_match('/Banned IP list:\s*(.*)/', $raw, $ips);
    $ipList = $ips[1] ? array_values(array_filter(explode(' ', trim($ips[1])))) : [];
    preg_match('/`- Filter\s*\|-- Currently failed:\s*(\d+)/s', $raw, $cf);
    return [
        'jail'            => $jail,
        'currently_banned'=> (int)($banned[1] ?? 0),
        'total_banned'    => (int)($total[1] ?? 0),
        'currently_failed'=> (int)($failed[1] ?? 0),
        'banned_ips'      => $ipList,
        'raw'             => $raw,
    ];
}

// ── Dispatch ───────────────────────────────────────────────────────────────

switch ($action) {

    // ── UFW status + rules ────────────────────────────────────────────────
    case 'status':
        Response::json(['status' => 'ok', 'data' => ufw_status()]);
        break;

    // ── UFW enable/disable ────────────────────────────────────────────────
    case 'enable':
        fw_exec('ufw --force enable');
        novacpx_log('info', 'Firewall enabled');
        audit('firewall.enable', 'ufw');
        Response::success(['active' => true], 'Firewall enabled');
        break;

    case 'disable':
        fw_exec('ufw --force disable');
        novacpx_log('info', 'Firewall disabled');
        audit('firewall.disable', 'ufw');
        Response::success(['active' => false], 'Firewall disabled');
        break;

    // ── Add rule ──────────────────────────────────────────────────────────
    case 'add-rule':
        /*
         * body: action (allow|deny|reject|limit),
         *       direction (in|out),
         *       port (number or range like 8000:9000),
         *       proto (tcp|udp|any),
         *       from_ip (IP/CIDR or "any"),
         *       to_ip (IP/CIDR or "any"),
         *       comment
         */
        $rAction   = strtolower(trim($body['action']   ?? 'allow'));
        $direction = strtolower(trim($body['direction'] ?? 'in'));
        $port      = trim($body['port']    ?? '');
        $proto     = strtolower(trim($body['proto']    ?? 'tcp'));
        $fromIp    = trim($body['from_ip'] ?? 'any');
        $toIp      = trim($body['to_ip']   ?? 'any');
        $comment   = preg_replace('/[^a-zA-Z0-9 _\-.]/', '', trim($body['comment'] ?? ''));

        if (!in_array($rAction, ['allow','deny','reject','limit'])) Response::error('Invalid action');
        if (!in_array($direction, ['in','out',''])) Response::error('Invalid direction');

        // Build ufw command
        $cmd = 'ufw';
        if ($direction) $cmd .= " {$direction}";
        $cmd .= " {$rAction}";
        if ($fromIp && $fromIp !== 'any') {
            $cmd .= " from " . escapeshellarg($fromIp);
            if ($toIp && $toIp !== 'any') $cmd .= " to " . escapeshellarg($toIp);
        }
        if ($port) {
            $protoStr = ($proto && $proto !== 'any') ? "/{$proto}" : '';
            $cmd .= " port " . escapeshellarg($port) . $protoStr;
        }
        if ($comment) $cmd .= " comment " . escapeshellarg($comment);

        $out = fw_exec($cmd);
        novacpx_log('info', "Firewall rule added: $cmd");
        audit('firewall.add-rule', $cmd);
        Response::success(['output' => $out, 'cmd' => $cmd], 'Rule added');
        break;

    // ── Quick allow/deny (simple port shortcut) ───────────────────────────
    case 'allow-port':
        $portProto = trim($body['port'] ?? '');
        $fromIp    = trim($body['from_ip'] ?? '');
        if (!$portProto) Response::error('port required');
        $fromPart  = $fromIp ? " from " . escapeshellarg($fromIp) : '';
        $out = fw_exec("ufw allow{$fromPart} " . escapeshellarg($portProto));
        audit('firewall.allow-port', $portProto);
        Response::success(['output' => $out], "Allowed $portProto");
        break;

    case 'deny-port':
        $portProto = trim($body['port'] ?? '');
        $fromIp    = trim($body['from_ip'] ?? '');
        if (!$portProto) Response::error('port required');
        $fromPart  = $fromIp ? " from " . escapeshellarg($fromIp) : '';
        $out = fw_exec("ufw deny{$fromPart} " . escapeshellarg($portProto));
        audit('firewall.deny-port', $portProto);
        Response::success(['output' => $out], "Denied $portProto");
        break;

    // ── Delete rule by number ─────────────────────────────────────────────
    case 'delete-rule':
        $num = (int)($body['num'] ?? 0);
        if ($num < 1) Response::error('Invalid rule number');
        $out = fw_exec("echo y | ufw delete {$num}");
        novacpx_log('info', "Firewall rule #{$num} deleted");
        audit('firewall.delete-rule', "rule:{$num}");
        Response::success(['output' => $out], "Rule #$num deleted");
        break;

    // ── Set default policy ────────────────────────────────────────────────
    case 'default-policy':
        $direction = strtolower(trim($body['direction'] ?? 'incoming'));
        $policy    = strtolower(trim($body['policy']    ?? 'deny'));
        if (!in_array($direction, ['incoming','outgoing','routed'])) Response::error('Invalid direction');
        if (!in_array($policy, ['allow','deny','reject'])) Response::error('Invalid policy');
        $out = fw_exec("ufw default {$policy} {$direction}");
        audit('firewall.default-policy', "{$direction}:{$policy}");
        Response::success(['output' => $out], "Default $direction set to $policy");
        break;

    // ── Reset UFW to defaults ─────────────────────────────────────────────
    case 'reset':
        $out = fw_exec('echo y | ufw reset');
        // Re-apply essentials
        fw_exec('ufw allow ssh');
        fw_exec('ufw allow 80/tcp');
        fw_exec('ufw allow 443/tcp');
        foreach ([PORT_USER, PORT_RESELLER, PORT_ADMIN, PORT_WEBMAIL] as $p) {
            fw_exec("ufw allow {$p}/tcp");
        }
        fw_exec('ufw --force enable');
        novacpx_log('warn', 'Firewall reset to defaults');
        audit('firewall.reset', 'ufw');
        Response::success(['output' => $out], 'Firewall reset — SSH, HTTP, HTTPS, and panel ports re-applied');
        break;

    // ── Allow/block IP ────────────────────────────────────────────────────
    case 'allow-ip':
        $ip = trim($body['ip'] ?? '');
        if (!$ip) Response::error('ip required');
        if (!filter_var(explode('/', $ip)[0], FILTER_VALIDATE_IP)) Response::error('Invalid IP');
        $out = fw_exec("ufw allow from " . escapeshellarg($ip));
        // Store in trusted_ips setting
        $existing = json_decode($db->fetchOne("SELECT value FROM settings WHERE `key`='trusted_ips'")['value'] ?? '[]', true) ?: [];
        if (!in_array($ip, $existing)) {
            $existing[] = $ip;
            $db->execute("INSERT INTO settings (`key`,`value`) VALUES ('trusted_ips',?) ON DUPLICATE KEY UPDATE `value`=?", [json_encode($existing), json_encode($existing)]);
        }
        audit('firewall.allow-ip', $ip);
        Response::success(['output' => $out], "IP $ip allowed");
        break;

    case 'block-ip':
        $ip = trim($body['ip'] ?? '');
        if (!$ip) Response::error('ip required');
        if (!filter_var(explode('/', $ip)[0], FILTER_VALIDATE_IP)) Response::error('Invalid IP');
        $out = fw_exec("ufw deny from " . escapeshellarg($ip));
        // Store in blocked_ips setting
        $existing = json_decode($db->fetchOne("SELECT value FROM settings WHERE `key`='blocked_ips'")['value'] ?? '[]', true) ?: [];
        if (!in_array($ip, $existing)) {
            $existing[] = $ip;
            $db->execute("INSERT INTO settings (`key`,`value`) VALUES ('blocked_ips',?) ON DUPLICATE KEY UPDATE `value`=?", [json_encode($existing), json_encode($existing)]);
        }
        audit('firewall.block-ip', $ip);
        Response::success(['output' => $out], "IP $ip blocked");
        break;

    case 'remove-ip':
        $ip  = trim($body['ip'] ?? '');
        $act = trim($body['action'] ?? 'allow');
        if (!$ip) Response::error('ip required');
        $out = fw_exec("ufw delete {$act} from " . escapeshellarg($ip));
        // Remove from DB list
        foreach (['trusted_ips','blocked_ips'] as $key) {
            $existing = json_decode($db->fetchOne("SELECT value FROM settings WHERE `key`=?", [$key])['value'] ?? '[]', true) ?: [];
            $existing = array_values(array_filter($existing, fn($i) => $i !== $ip));
            $db->execute("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?", [$key, json_encode($existing), json_encode($existing)]);
        }
        audit('firewall.remove-ip', $ip);
        Response::success(['output' => $out], "IP $ip rule removed");
        break;

    // ── Trusted/blocked IP lists ──────────────────────────────────────────
    case 'ip-lists':
        $trusted = json_decode($db->fetchOne("SELECT value FROM settings WHERE `key`='trusted_ips'")['value'] ?? '[]', true) ?: [];
        $blocked = json_decode($db->fetchOne("SELECT value FROM settings WHERE `key`='blocked_ips'")['value'] ?? '[]', true) ?: [];
        Response::success(['trusted' => $trusted, 'blocked' => $blocked]);
        break;

    // ── Fail2Ban: list jails with stats ───────────────────────────────────
    case 'f2b-status':
        $jails = f2b_jails();
        $result = [];
        foreach ($jails as $j) {
            $result[] = f2b_jail_detail($j);
        }
        Response::success(['jails' => $result]);
        break;

    // ── Fail2Ban: single jail details ─────────────────────────────────────
    case 'f2b-jail':
        $jail = preg_replace('/[^a-z0-9_\-]/', '', trim($body['jail'] ?? ''));
        if (!$jail) Response::error('jail required');
        Response::success(f2b_jail_detail($jail));
        break;

    // ── Fail2Ban: unban IP from all or specific jail ──────────────────────
    case 'f2b-unban':
        $ip   = trim($body['ip']   ?? '');
        $jail = preg_replace('/[^a-z0-9_\-]/', '', trim($body['jail'] ?? ''));
        if (!$ip) Response::error('ip required');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) Response::error('Invalid IP');

        $results = [];
        $targets = $jail ? [$jail] : f2b_jails();
        foreach ($targets as $j) {
            $out = fw_exec("fail2ban-client set " . escapeshellarg($j) . " unbanip " . escapeshellarg($ip));
            $results[$j] = $out;
        }
        audit('firewall.f2b-unban', "$ip from " . ($jail ?: 'all'));
        Response::success(['results' => $results], "Unbanned $ip");
        break;

    // ── Fail2Ban: manually ban IP ─────────────────────────────────────────
    case 'f2b-ban':
        $ip   = trim($body['ip']   ?? '');
        $jail = preg_replace('/[^a-z0-9_\-]/', '', trim($body['jail'] ?? 'sshd'));
        if (!$ip) Response::error('ip required');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) Response::error('Invalid IP');
        $out = fw_exec("fail2ban-client set " . escapeshellarg($jail) . " banip " . escapeshellarg($ip));
        audit('firewall.f2b-ban', "$ip in $jail");
        Response::success(['output' => $out], "Banned $ip in $jail");
        break;

    // ── Fail2Ban: reload config ───────────────────────────────────────────
    case 'f2b-reload':
        $out = fw_exec('fail2ban-client reload');
        audit('firewall.f2b-reload', 'fail2ban');
        Response::success(['output' => $out], 'Fail2Ban reloaded');
        break;

    // ── Fail2Ban: restart ─────────────────────────────────────────────────
    case 'f2b-restart':
        $out = fw_exec('sudo systemctl restart fail2ban 2>&1');
        audit('firewall.f2b-restart', 'fail2ban');
        Response::success(['output' => $out], 'Fail2Ban restarted');
        break;

    // ── UFW: raw command (admin escape hatch) ─────────────────────────────
    case 'raw':
        $cmd = trim($body['cmd'] ?? '');
        if (!$cmd) Response::error('cmd required');
        // Whitelist safe ufw sub-commands only
        if (!preg_match('/^ufw\s+(status|show|logging|allow|deny|reject|limit|delete|insert|prepend|route|default)\s/', "ufw $cmd")) {
            Response::error('Command not permitted');
        }
        $out = fw_exec('ufw ' . $cmd);
        audit('firewall.raw', $cmd);
        Response::success(['output' => $out]);
        break;

    // ── UFW logging level ─────────────────────────────────────────────────
    case 'set-logging':
        $level = strtolower(trim($body['level'] ?? 'on'));
        if (!in_array($level, ['off','on','low','medium','high','full'])) Response::error('Invalid level');
        $out = fw_exec("ufw logging {$level}");
        audit('firewall.logging', $level);
        Response::success(['output' => $out], "Logging set to $level");
        break;

    default:
        Response::error("Unknown firewall action: $action", 404);
}
