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

define('JAIL_LOCAL', '/etc/fail2ban/jail.local');

/** Detect all local IPs for this server (loopback + all interface IPs + private subnets) */
function local_ips(): array {
    $ips = ['127.0.0.0/8', '::1'];
    $raw = shell_exec("ip -4 addr show 2>/dev/null | grep 'inet ' | awk '{print $2}'") ?: '';
    foreach (array_filter(explode("\n", trim($raw))) as $cidr) {
        $cidr = trim($cidr);
        if (!$cidr) continue;
        // Add the specific IP
        $ip = explode('/', $cidr)[0];
        if (!in_array($ip, $ips)) $ips[] = $ip;
        // If it's a private range, add the /24 subnet too
        if (preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $ip)) {
            $parts = explode('.', $ip);
            $subnet = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
            if (!in_array($subnet, $ips)) $ips[] = $subnet;
        }
    }
    return $ips;
}

/** Read ignoreip list from jail.local [DEFAULT] */
function f2b_get_ignoreip(): array {
    if (!file_exists(JAIL_LOCAL)) return local_ips();
    $content = file_get_contents(JAIL_LOCAL);
    if (preg_match('/^\s*ignoreip\s*=\s*(.+)$/m', $content, $m)) {
        $raw = preg_replace('/\s+/', ' ', trim($m[1]));
        return array_values(array_filter(explode(' ', $raw)));
    }
    return local_ips();
}

/** Write ignoreip list to jail.local and reload fail2ban */
function f2b_set_ignoreip(array $ips): void {
    $ips  = array_values(array_unique(array_filter($ips)));
    $line = 'ignoreip = ' . implode(' ', $ips);

    if (!file_exists(JAIL_LOCAL)) {
        // Create jail.local with [DEFAULT] section
        file_put_contents(JAIL_LOCAL, "[DEFAULT]\n{$line}\n\n[sshd]\nenabled = true\n");
    } else {
        $content = file_get_contents(JAIL_LOCAL);
        if (preg_match('/^\s*ignoreip\s*=/m', $content)) {
            $content = preg_replace('/^\s*ignoreip\s*=.+$/m', $line, $content);
        } elseif (preg_match('/^\[DEFAULT\]/m', $content)) {
            $content = preg_replace('/(\[DEFAULT\][^\[]*)/s', "$1{$line}\n", $content, 1);
        } else {
            $content = "[DEFAULT]\n{$line}\n\n" . $content;
        }
        file_put_contents(JAIL_LOCAL, $content);
    }
    shell_exec('sudo fail2ban-client reload 2>/dev/null');
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

    // ── Fail2Ban ignoreip: list ───────────────────────────────────────────
    case 'f2b-ignoreip-list':
        Response::success([
            'ignoreip'  => f2b_get_ignoreip(),
            'detected'  => local_ips(),
        ]);
        break;

    // ── Fail2Ban ignoreip: add ────────────────────────────────────────────
    case 'f2b-ignoreip-add':
        $ip = trim($body['ip'] ?? '');
        if (!$ip) Response::error('ip required');
        // Validate IP or CIDR
        $base = explode('/', $ip)[0];
        if (!filter_var($base, FILTER_VALIDATE_IP)) Response::error('Invalid IP or CIDR');
        $list = f2b_get_ignoreip();
        if (!in_array($ip, $list)) {
            $list[] = $ip;
            f2b_set_ignoreip($list);
        }
        audit('firewall.f2b-ignoreip-add', $ip);
        novacpx_log('info', "Fail2Ban ignoreip added: $ip");
        Response::success(['ignoreip' => f2b_get_ignoreip()], "$ip added to Fail2Ban whitelist");
        break;

    // ── Fail2Ban ignoreip: remove ─────────────────────────────────────────
    case 'f2b-ignoreip-remove':
        $ip = trim($body['ip'] ?? '');
        if (!$ip) Response::error('ip required');
        // Never remove loopback
        if ($ip === '127.0.0.0/8' || $ip === '127.0.0.1' || $ip === '::1') {
            Response::error('Cannot remove loopback address from whitelist');
        }
        $list = array_values(array_filter(f2b_get_ignoreip(), fn($i) => $i !== $ip));
        f2b_set_ignoreip($list);
        audit('firewall.f2b-ignoreip-remove', $ip);
        novacpx_log('info', "Fail2Ban ignoreip removed: $ip");
        Response::success(['ignoreip' => f2b_get_ignoreip()], "$ip removed from Fail2Ban whitelist");
        break;

    // ── Fail2Ban ignoreip: reset to server defaults ────────────────────────
    case 'f2b-ignoreip-reset':
        $defaults = local_ips();
        f2b_set_ignoreip($defaults);
        audit('firewall.f2b-ignoreip-reset', implode(' ', $defaults));
        Response::success(['ignoreip' => $defaults], 'Whitelist reset to server defaults');
        break;

    // ── Fail2Ban: get global config (bantime/findtime/maxretry) ──────────────
    case 'f2b-config-get':
        $content = file_exists(JAIL_LOCAL) ? file_get_contents(JAIL_LOCAL) : '';
        $get = function(string $key, string $default) use ($content): string {
            return preg_match('/^\s*' . preg_quote($key) . '\s*=\s*(.+)$/m', $content, $m) ? trim($m[1]) : $default;
        };
        Response::success([
            'bantime'  => $get('bantime',  '3600'),
            'findtime' => $get('findtime', '600'),
            'maxretry' => $get('maxretry', '5'),
        ]);
        break;

    // ── Fail2Ban: save global config ──────────────────────────────────────
    case 'f2b-config-save':
        $bantime  = (int)($body['bantime']  ?? 3600);
        $findtime = (int)($body['findtime'] ?? 600);
        $maxretry = (int)($body['maxretry'] ?? 5);
        if ($bantime < 60 || $findtime < 60 || $maxretry < 1) Response::error('Invalid values');
        $content = file_exists(JAIL_LOCAL) ? file_get_contents(JAIL_LOCAL) : '';
        $set = function(string $key, string $val) use (&$content): void {
            if (preg_match('/^\s*' . preg_quote($key) . '\s*=/m', $content)) {
                $content = preg_replace('/^(\s*' . preg_quote($key) . '\s*=\s*).+$/m', '${1}' . $val, $content);
            } else {
                // Insert into [DEFAULT] section
                $content = preg_replace('/(\[DEFAULT\][^\[]*)/s', '$1' . "$key   = $val\n", $content, 1);
            }
        };
        $set('bantime',  (string)$bantime);
        $set('findtime', (string)$findtime);
        $set('maxretry', (string)$maxretry);
        file_put_contents(JAIL_LOCAL, $content);
        fw_exec('fail2ban-client reload');
        audit('firewall.f2b-config', "bantime=$bantime findtime=$findtime maxretry=$maxretry");
        Response::success(null, 'Fail2Ban configuration saved');
        break;

    // ── Fail2Ban: tail log ────────────────────────────────────────────────
    case 'f2b-log':
        $lines = max(50, min(500, (int)($body['lines'] ?? 100)));
        $log   = '/var/log/fail2ban.log';
        if (!file_exists($log)) Response::error('Fail2Ban log not found');
        $raw   = trim(shell_exec("tail -n {$lines} " . escapeshellarg($log)) ?: '');
        Response::success(['log' => $raw, 'lines' => $lines]);
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
