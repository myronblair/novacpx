<?php
/**
 * ProxyManager — manages Nginx reverse proxy for NovaCPX hosted accounts.
 * Supports local nginx (on same VM) or remote nginx (separate proxy VM via SSH).
 *
 * ── INTENDED ENVIRONMENT ────────────────────────────────────────────────────
 * This proxy feature is designed for Proxmox VE (or similar Linux
 * hypervisor / LXC / KVM environments) where you run NovaCPX on one VM
 * and dedicate a lightweight container (Debian/Ubuntu LXC, 512MB RAM,
 * 1 vCPU) purely to nginx as a reverse proxy.
 *
 * Typical stack on Proxmox:
 *   CT 120  NovaCPX VM  10.48.200.110  — Apache + PHP, panel on :8880–8883
 *   CT 121  Proxy LXC   10.48.200.112  — nginx only, public ports 80/443
 *   FortiGate VIPs route 80/443 → 10.48.200.112
 *
 * The panel pushes nginx vhost configs to the proxy VM via SSH and can
 * start/stop/reload nginx remotely — all from Admin → Nginx Proxy.
 *
 * ── NON-PROXMOX / BARE-METAL / OTHER HYPERVISORS ────────────────────────────
 * The feature works on any Linux environment. Requirements:
 *
 *   1. A second Linux VM or server accessible by SSH from NovaCPX.
 *      (AWS EC2, DigitalOcean droplet, VMware VM, Hyper-V, bare-metal — all fine.)
 *   2. SSH password auth enabled for root (or another sudo user).
 *   3. The proxy VM must be able to reach NovaCPX's Apache (port 80) via LAN/VPN.
 *   4. sshpass must be installed on the NovaCPX server: apt-get install -y sshpass
 *
 *   Configuration (Admin → Nginx Proxy → Settings):
 *     Mode:        remote
 *     Remote host: <proxy VM IP or hostname>
 *     Remote user: root
 *     Remote pass: <root SSH password>
 *     Backend IP:  <this NovaCPX server's IP that Apache listens on>
 *
 *   One-time setup: click "Run Setup on Remote VM" — it installs nginx,
 *   removes the default site, and creates the NovaCPX catch-all.
 *   After that, add proxy hosts per domain from Admin → Nginx Proxy → Add Host.
 *
 * ── LOCAL MODE (nginx on same VM as NovaCPX) ────────────────────────────────
 * Apache must be moved off port 80/443 first:
 *   1. Edit /etc/apache2/ports.conf → change Listen 80 to Listen 8090
 *   2. Restart Apache: systemctl restart apache2
 *   3. Set proxy_backend_ip = 127.0.0.1 and all upstreams to http://127.0.0.1:8090
 *   4. Set Mode = local, click Install Nginx Locally
 *   5. Click Sync Accounts to populate proxy hosts
 *
 * ── Settings keys stored in `settings` table ────────────────────────────────
 *   proxy_mode          — 'disabled' | 'local' | 'remote'
 *   proxy_remote_host   — IP/hostname of remote nginx VM
 *   proxy_remote_user   — SSH user (default: root)
 *   proxy_remote_pass   — SSH password
 *   proxy_backend_ip    — IP of NovaCPX Apache (used when syncing proxy hosts)
 */
class ProxyManager {

    private static string $confDir    = '/etc/nginx/sites-available';
    private static string $enabledDir = '/etc/nginx/sites-enabled';
    private static string $confPrefix = 'novacpx-proxy-';

    // --- Remote helpers ---

    private static function isRemote(): bool {
        $db = DB::getInstance();
        return ($db->fetchOne("SELECT value FROM settings WHERE `key`='proxy_mode'")['value'] ?? '') === 'remote';
    }

    private static function getRemote(): array {
        $db = DB::getInstance();
        $get = fn(string $k, string $d = '') => $db->fetchOne("SELECT value FROM settings WHERE `key`=?", [$k])['value'] ?? $d;
        return [
            'host' => $get('proxy_remote_host'),
            'user' => $get('proxy_remote_user', 'root'),
            'pass' => $get('proxy_remote_pass'),
        ];
    }

    private static function remoteExec(string $cmd): string {
        $r = self::getRemote();
        if (!$r['host']) return 'no remote host configured';
        return shell_exec(
            'sshpass -p ' . escapeshellarg($r['pass']) .
            ' ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 ' .
            escapeshellarg($r['user'] . '@' . $r['host']) . ' ' .
            escapeshellarg($cmd) . ' 2>&1'
        ) ?? '';
    }

    private static function remotePush(string $content, string $remotePath): void {
        $encoded = base64_encode($content);
        self::remoteExec('echo ' . escapeshellarg($encoded) . ' | base64 -d > ' . escapeshellarg($remotePath));
    }

    // --- Status & Control ---

    public static function isInstalled(): bool {
        if (self::isRemote()) {
            return trim(self::remoteExec('which nginx')) !== '';
        }
        return file_exists('/usr/sbin/nginx') || !empty(shell_exec('which nginx 2>/dev/null'));
    }

    public static function isRunning(): bool {
        if (self::isRemote()) {
            return trim(self::remoteExec('systemctl is-active nginx')) === 'active';
        }
        return trim(shell_exec('systemctl is-active nginx 2>/dev/null') ?? '') === 'active';
    }

    public static function status(): array {
        $db      = DB::getInstance();
        $get     = fn(string $k, string $d = '') => $db->fetchOne("SELECT value FROM settings WHERE `key`=?", [$k])['value'] ?? $d;
        $mode    = $get('proxy_mode', 'disabled');
        $remote  = self::isRemote();

        $installed = self::isInstalled();
        $running   = $installed && self::isRunning();
        $version   = '';
        if ($installed) {
            $raw     = $remote ? self::remoteExec('nginx -v') : (shell_exec('nginx -v 2>&1') ?: '');
            $version = trim($raw);
        }

        $data = [
            'installed'    => $installed,
            'running'      => $running,
            'version'      => $version,
            'mode'         => $mode,
        ];
        if ($remote) {
            $data['remote_host'] = $get('proxy_remote_host');
            $data['remote_user'] = $get('proxy_remote_user', 'root');
        }
        return $data;
    }

    public static function start(): string   { return self::sysctl('start'); }
    public static function stop(): string    { return self::sysctl('stop'); }
    public static function restart(): string { return self::sysctl('restart'); }

    public static function reload(): string {
        if (self::isRemote()) {
            $test = self::remoteExec('nginx -t');
            if (strpos($test, 'successful') === false) return 'Config test failed: ' . $test;
            self::remoteExec('systemctl reload nginx');
            return 'reloaded';
        }
        $test = shell_exec('nginx -t 2>&1');
        if (strpos($test ?? '', 'successful') === false) return 'Config test failed: ' . $test;
        shell_exec('systemctl reload nginx 2>/dev/null');
        return 'reloaded';
    }

    public static function install(): string {
        if (self::isRemote()) return 'Use the setup script to install nginx on the remote proxy VM';
        if (self::isInstalled()) return 'already installed';
        shell_exec('apt-get update -qq 2>/dev/null && apt-get install -y nginx 2>&1');
        if (!self::isInstalled()) return 'install failed';
        @unlink('/etc/nginx/sites-enabled/default');
        shell_exec('systemctl enable nginx 2>/dev/null');
        shell_exec('systemctl start nginx 2>/dev/null');
        return 'installed';
    }

    // --- Proxy Hosts ---

    public static function listHosts(): array {
        $db = DB::getInstance();
        return $db->fetchAll("SELECT * FROM proxy_hosts ORDER BY domain") ?: [];
    }

    public static function syncFromAccounts(): int {
        $db         = DB::getInstance();
        $backendIp  = $db->fetchOne("SELECT value FROM settings WHERE `key`='proxy_backend_ip'")['value'] ?? '127.0.0.1';
        $accounts   = $db->fetchAll(
            "SELECT a.*, d.domain FROM accounts a JOIN domains d ON d.account_id=a.id AND d.type='main' WHERE a.status='active'"
        ) ?: [];
        $count = 0;
        foreach ($accounts as $acct) {
            if (!$db->fetchOne("SELECT id FROM proxy_hosts WHERE domain=?", [$acct['domain']])) {
                $db->insert(
                    "INSERT INTO proxy_hosts (account_id, domain, upstream, ssl_enabled, enabled, created_at) VALUES (?,?,?,0,1,NOW())",
                    [$acct['id'], $acct['domain'], "http://{$backendIp}:80"]
                );
                $count++;
            }
        }
        if ($count > 0) self::writeAllConfigs();
        return $count;
    }

    public static function addHost(array $data): int {
        $db = DB::getInstance();
        $id = (int)$db->insert(
            "INSERT INTO proxy_hosts (account_id, domain, upstream, ssl_enabled, enabled, custom_config, created_at) VALUES (?,?,?,?,1,?,NOW())",
            [
                $data['account_id'] ?? null,
                $data['domain'],
                $data['upstream'] ?? 'http://127.0.0.1:80',
                (int)($data['ssl_enabled'] ?? 0),
                $data['custom_config'] ?? null,
            ]
        );
        self::writeAllConfigs();
        return $id;
    }

    public static function updateHost(int $id, array $data): void {
        $db = DB::getInstance();
        $db->execute(
            "UPDATE proxy_hosts SET domain=?, upstream=?, ssl_enabled=?, enabled=?, custom_config=? WHERE id=?",
            [$data['domain'], $data['upstream'], (int)($data['ssl_enabled'] ?? 0), (int)($data['enabled'] ?? 1), $data['custom_config'] ?? null, $id]
        );
        self::writeAllConfigs();
    }

    public static function deleteHost(int $id): void {
        $db   = DB::getInstance();
        $host = $db->fetchOne("SELECT domain FROM proxy_hosts WHERE id=?", [$id]);
        $db->execute("DELETE FROM proxy_hosts WHERE id=?", [$id]);
        if ($host) {
            $safe = preg_replace('/[^a-z0-9._-]/', '', strtolower($host['domain']));
            if (self::isRemote()) {
                self::remoteExec('rm -f ' .
                    escapeshellarg(self::$confDir    . '/' . self::$confPrefix . $safe . '.conf') . ' ' .
                    escapeshellarg(self::$enabledDir . '/' . self::$confPrefix . $safe . '.conf')
                );
            } else {
                @unlink(self::$confDir    . '/' . self::$confPrefix . $safe . '.conf');
                @unlink(self::$enabledDir . '/' . self::$confPrefix . $safe . '.conf');
            }
        }
        self::reload();
    }

    public static function toggleHost(int $id, bool $enable): void {
        $db = DB::getInstance();
        $db->execute("UPDATE proxy_hosts SET enabled=? WHERE id=?", [(int)$enable, $id]);
        self::writeAllConfigs();
    }

    // --- Config Generation ---

    public static function writeAllConfigs(): void {
        if (!self::isInstalled()) return;
        $db    = DB::getInstance();
        $hosts = $db->fetchAll("SELECT * FROM proxy_hosts") ?: [];

        if (self::isRemote()) {
            // Remove old proxy configs on remote
            self::remoteExec('rm -f ' .
                escapeshellarg(self::$confDir    . '/' . self::$confPrefix . '*.conf') . ' ' .
                escapeshellarg(self::$enabledDir . '/' . self::$confPrefix . '*.conf')
            );
            // Use glob expansion via shell, not escaped
            self::remoteExec('rm -f ' . self::$confDir . '/' . self::$confPrefix . '*.conf ' .
                self::$enabledDir . '/' . self::$confPrefix . '*.conf');
        } else {
            foreach (glob(self::$confDir    . '/' . self::$confPrefix . '*.conf') ?: [] as $f) @unlink($f);
            foreach (glob(self::$enabledDir . '/' . self::$confPrefix . '*.conf') ?: [] as $f) @unlink($f);
        }

        foreach ($hosts as $host) {
            if (!$host['enabled']) continue;
            self::writeHostConfig($host);
        }
        self::reload();
    }

    private static function writeHostConfig(array $host): void {
        $safe     = preg_replace('/[^a-z0-9._-]/', '', strtolower($host['domain']));
        $confPath = self::$confDir    . '/' . self::$confPrefix . $safe . '.conf';
        $linkPath = self::$enabledDir . '/' . self::$confPrefix . $safe . '.conf';

        $content = $host['custom_config'] ?: self::buildConf($host);

        if (self::isRemote()) {
            self::remotePush($content, $confPath);
            self::remoteExec('ln -sf ' . escapeshellarg($confPath) . ' ' . escapeshellarg($linkPath));
        } else {
            file_put_contents($confPath, $content);
            @symlink($confPath, $linkPath);
        }
    }

    private static function buildConf(array $host): string {
        $upstream = rtrim($host['upstream'], '/');
        $ssl      = !empty($host['ssl_enabled']);
        $certDir  = '/etc/novacpx/ssl/accounts/' . preg_replace('/[^a-z0-9._-]/', '', $host['domain']);

        $c  = "server {\n";
        $c .= "    listen 80;\n";
        if ($ssl) $c .= "    listen 443 ssl http2;\n";
        $c .= "    server_name {$host['domain']} www.{$host['domain']};\n";
        if ($ssl) {
            $c .= "    ssl_certificate {$certDir}/cert.pem;\n";
            $c .= "    ssl_certificate_key {$certDir}/key.pem;\n";
            $c .= "    ssl_protocols TLSv1.2 TLSv1.3;\n";
            $c .= "    ssl_ciphers HIGH:!aNULL:!MD5;\n";
        }
        $c .= "    location / {\n";
        $c .= "        proxy_pass {$upstream};\n";
        $c .= "        proxy_http_version 1.1;\n";
        $c .= "        proxy_set_header Host \$host;\n";
        $c .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
        $c .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
        $c .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
        $c .= "        proxy_set_header Upgrade \$http_upgrade;\n";
        $c .= "        proxy_set_header Connection 'upgrade';\n";
        $c .= "        proxy_cache_bypass \$http_upgrade;\n";
        $c .= "        proxy_read_timeout 86400;\n";
        $c .= "    }\n";
        $c .= "}\n";
        return $c;
    }

    // --- Remote connectivity test ---

    public static function testRemote(): array {
        $r = self::getRemote();
        if (!$r['host']) return ['ok' => false, 'message' => 'No remote host configured'];
        $out = self::remoteExec('nginx -v');
        if (strpos($out, 'nginx') === false) {
            return ['ok' => false, 'message' => 'Connected but nginx not found: ' . trim($out)];
        }
        return ['ok' => true, 'message' => 'Connected — ' . trim($out)];
    }

    // --- Local mode switch ---

    /**
     * Switch to local proxy mode:
     *   Apache moves to $apachePort (default 8090), nginx takes 80/443.
     *   All existing vhosts are re-written; proxy hosts synced automatically.
     *   Yields progress lines suitable for SSE streaming.
     */
    public static function switchToLocalMode(int $apachePort = 8090): \Generator {
        require_once NOVACPX_LIB . '/VhostManager.php';
        $db   = DB::getInstance();
        $save = function(string $k, string $v) use ($db) {
            $db->execute("INSERT INTO settings (`key`, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$k, $v]);
        };

        yield "» Checking nginx installation...\n";
        if (!file_exists('/usr/sbin/nginx') && empty(shell_exec('which nginx 2>/dev/null'))) {
            yield "» Installing nginx (apt-get install -y nginx)...\n";
            $out = shell_exec('apt-get update -qq 2>&1 && apt-get install -y nginx 2>&1');
            if ($out) yield trim($out) . "\n";
            if (!file_exists('/usr/sbin/nginx')) { yield "ERROR: nginx install failed. Aborting.\n"; return; }
            yield "  nginx installed\n";
        } else {
            yield "  nginx already installed\n";
        }

        yield "» Stopping nginx to avoid config conflicts...\n";
        shell_exec('systemctl stop nginx 2>/dev/null');

        yield "» Migrating Apache from port 80 → {$apachePort}...\n";
        $changed = VhostManager::migrateApachePort(80, $apachePort);
        yield "  Updated {$changed} vhost(s) and ports.conf\n";

        yield "» Restarting Apache on port {$apachePort}...\n";
        $apacheTest = shell_exec('apache2ctl configtest 2>&1');
        if (strpos($apacheTest ?? '', 'Syntax OK') === false) {
            yield "ERROR: Apache config test failed:\n{$apacheTest}\nRolling back...\n";
            VhostManager::restoreApachePort($apachePort, 80);
            shell_exec('systemctl restart apache2 2>/dev/null');
            yield "  Apache restored to port 80. Aborting.\n";
            return;
        }
        shell_exec('systemctl restart apache2 2>/dev/null');
        yield "  Apache is up on port {$apachePort}\n";

        yield "» Configuring nginx (remove default site, add catch-all)...\n";
        @unlink('/etc/nginx/sites-enabled/default');
        $catchAll = "server {\n    listen 80 default_server;\n    server_name _;\n    return 444;\n}\n";
        file_put_contents('/etc/nginx/sites-available/novacpx-default.conf', $catchAll);
        if (!file_exists('/etc/nginx/sites-enabled/novacpx-default.conf')) {
            @symlink('/etc/nginx/sites-available/novacpx-default.conf', '/etc/nginx/sites-enabled/novacpx-default.conf');
        }
        if (!file_exists('/etc/nginx/conf.d/novacpx-proxy.conf')) {
            file_put_contents('/etc/nginx/conf.d/novacpx-proxy.conf',
                "client_max_body_size 256M;\nproxy_buffers 16 16k;\nproxy_buffer_size 16k;\n");
        }

        yield "» Saving proxy settings...\n";
        $save('proxy_mode', 'local');
        $save('proxy_backend_ip', '127.0.0.1');
        $save('proxy_apache_port', (string)$apachePort);

        yield "» Starting nginx on port 80/443...\n";
        shell_exec('systemctl enable nginx 2>/dev/null && systemctl start nginx 2>/dev/null');
        sleep(1);
        if (!self::isRunning()) {
            $err = shell_exec('nginx -t 2>&1');
            yield "ERROR: nginx failed to start:\n{$err}\n";
            yield "Apache is running on port {$apachePort}. Fix nginx config and try again.\n";
            return;
        }
        yield "  nginx is running\n";

        yield "» Syncing proxy hosts from all active accounts...\n";
        $added = self::syncFromAccounts();
        yield "  Added {$added} proxy host(s)\n";
        self::writeAllConfigs();

        yield "✓ Local proxy mode active!\n";
        yield "  Apache: 127.0.0.1:{$apachePort} (PHP, file serving)\n";
        yield "  Nginx:  0.0.0.0:80/443 (public, proxies to Apache)\n";
        yield "  All accounts route through nginx → Apache automatically.\n";
    }

    /**
     * Revert local mode: move Apache back to 80/443, stop nginx, disable proxy.
     */
    public static function disableLocalMode(): \Generator {
        require_once NOVACPX_LIB . '/VhostManager.php';
        $db         = DB::getInstance();
        $apachePort = (int)($db->fetchOne("SELECT value FROM settings WHERE `key`='proxy_apache_port'")['value'] ?? 8090);

        yield "» Stopping nginx...\n";
        shell_exec('systemctl stop nginx 2>/dev/null && systemctl disable nginx 2>/dev/null');

        yield "» Migrating Apache from port {$apachePort} → 80...\n";
        $changed = VhostManager::restoreApachePort($apachePort);
        yield "  Updated {$changed} vhost(s) and ports.conf\n";

        yield "» Restarting Apache on port 80...\n";
        shell_exec('systemctl restart apache2 2>/dev/null');

        yield "» Saving settings...\n";
        $db->execute("INSERT INTO settings (`key`, value) VALUES ('proxy_mode','disabled') ON DUPLICATE KEY UPDATE value='disabled'");
        $db->execute("UPDATE settings SET value='80' WHERE `key`='proxy_apache_port'");

        yield "✓ Proxy disabled. Apache is back on port 80/443.\n";
    }

    // --- Remote setup & uninstall ---

    public static function runSetupOnRemote(): \Generator {
        $r = self::getRemote();
        if (!$r['host']) { yield "ERROR: No remote host configured\n"; return; }

        $steps = [
            'Updating package lists'      => 'apt-get update -qq 2>&1',
            'Installing nginx'            => 'apt-get install -y nginx 2>&1',
            'Disabling default site'      => 'rm -f /etc/nginx/sites-enabled/default',
            'Creating conf directories'   => 'mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled',
            'Writing tune config'         => 'printf "client_max_body_size 256M;\nproxy_buffers 16 16k;\nproxy_buffer_size 16k;\n" > /etc/nginx/conf.d/novacpx-proxy.conf',
            'Writing catch-all vhost'     => 'printf "server {\n    listen 80 default_server;\n    server_name _;\n    return 444;\n}\n" > /etc/nginx/sites-available/novacpx-default.conf && ln -sf /etc/nginx/sites-available/novacpx-default.conf /etc/nginx/sites-enabled/',
            'Testing nginx config'        => 'nginx -t 2>&1',
            'Enabling and starting nginx' => 'systemctl enable nginx 2>/dev/null && systemctl restart nginx 2>&1 && systemctl is-active nginx',
        ];

        foreach ($steps as $label => $cmd) {
            yield "» {$label}...\n";
            $out = self::remoteExec($cmd);
            if ($out) yield trim($out) . "\n";
            // Bail on critical failures
            if (str_contains($label, 'install') && !str_contains($out ?? '', 'nginx')) {
                $chk = self::remoteExec('which nginx 2>/dev/null');
                if (!trim($chk)) { yield "ERROR: nginx install failed\n"; return; }
            }
        }
        yield "✓ Nginx proxy setup complete on {$r['host']}\n";
    }

    public static function uninstall(bool $removeNginx = false): string {
        if (self::isRemote()) {
            // Remove all NovaCPX proxy configs from remote
            self::remoteExec('rm -f /etc/nginx/sites-available/novacpx-proxy-*.conf /etc/nginx/sites-enabled/novacpx-proxy-*.conf /etc/nginx/conf.d/novacpx-proxy.conf');
            self::remoteExec('rm -f /etc/nginx/sites-available/novacpx-default.conf /etc/nginx/sites-enabled/novacpx-default.conf');
            if ($removeNginx) {
                self::remoteExec('systemctl stop nginx 2>/dev/null; apt-get remove -y nginx nginx-common 2>/dev/null');
                return 'nginx removed from remote VM';
            }
            // Just reload to apply config removal
            self::remoteExec('nginx -t 2>/dev/null && systemctl reload nginx 2>/dev/null || true');
            return 'proxy configs removed from remote VM';
        }
        // Local uninstall
        foreach (glob(self::$confDir    . '/' . self::$confPrefix . '*.conf') ?: [] as $f) @unlink($f);
        foreach (glob(self::$enabledDir . '/' . self::$confPrefix . '*.conf') ?: [] as $f) @unlink($f);
        if ($removeNginx) {
            shell_exec('systemctl stop nginx 2>/dev/null; apt-get remove -y nginx nginx-common 2>/dev/null');
            return 'nginx removed';
        }
        shell_exec('systemctl reload nginx 2>/dev/null');
        return 'proxy configs removed';
    }

    // --- Health check (called from cron / watchdog) ---

    public static function healthCheck(): string {
        $db   = DB::getInstance();
        $mode = $db->fetchOne("SELECT value FROM settings WHERE `key`='proxy_mode'")['value'] ?? 'disabled';
        if ($mode === 'disabled') return 'disabled';
        if (!self::isRunning()) {
            $result = self::sysctl('start');
            novacpx_log('warn', "ProxyManager: nginx was stopped, attempted restart: $result");
            return "restarted: $result";
        }
        return 'ok';
    }

    // --- Setup Script ---

    public static function setupScript(): string {
        $serverIp = trim(shell_exec("hostname -I | awk '{print \$1}'") ?: '127.0.0.1');
        return <<<BASH
#!/bin/bash
# NovaCPX Nginx Reverse Proxy Setup Script
# Run as root on the dedicated proxy VM
set -e

echo "[NovaCPX] Installing Nginx reverse proxy..."
apt-get update -qq
apt-get install -y nginx openssh-server

# Disable default site
rm -f /etc/nginx/sites-enabled/default

# Create NovaCPX proxy conf directories
mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled

# Tune nginx for proxying
cat > /etc/nginx/conf.d/novacpx-proxy.conf << 'EOF'
client_max_body_size 256M;
proxy_buffers 16 16k;
proxy_buffer_size 16k;
gzip on;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml;
EOF

# Catch-all that drops unrecognised hosts
cat > /etc/nginx/sites-available/novacpx-default.conf << 'EOF'
server {
    listen 80 default_server;
    server_name _;
    return 444;
}
EOF
ln -sf /etc/nginx/sites-available/novacpx-default.conf /etc/nginx/sites-enabled/

nginx -t && systemctl reload nginx
systemctl enable nginx

echo "[NovaCPX] Nginx proxy installed and running."
echo "  NovaCPX backend (Apache): {$serverIp}"
echo ""
echo "  Now go to NovaCPX Admin -> Nginx Proxy -> Settings and set:"
echo "    Mode:        remote"
echo "    Remote host: <this VM's IP>"
echo "    Remote user: root"
echo "    Remote pass: <this VM's root password>"
echo "    Backend IP:  {$serverIp}"
BASH;
    }

    // --- Helpers ---

    private static function sysctl(string $action): string {
        if (self::isRemote()) {
            self::remoteExec("systemctl {$action} nginx");
            sleep(1);
            return self::isRunning() ? 'running' : 'stopped';
        }
        shell_exec("systemctl {$action} nginx 2>/dev/null");
        sleep(1);
        return self::isRunning() ? 'running' : 'stopped';
    }
}
