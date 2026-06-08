<?php
/**
 * ProxyManager — manages Nginx reverse proxy for NovaCPX hosted accounts.
 * Supports local nginx (on same VM) or remote nginx (separate proxy VM via SSH).
 */
class ProxyManager {

    private static string $confDir    = '/etc/nginx/sites-available';
    private static string $enabledDir = '/etc/nginx/sites-enabled';
    private static string $confPrefix = 'novacpx-proxy-';

    // --- Status & Control ---

    public static function isInstalled(): bool {
        return file_exists('/usr/sbin/nginx') || !empty(shell_exec('which nginx 2>/dev/null'));
    }

    public static function isRunning(): bool {
        $out = shell_exec('systemctl is-active nginx 2>/dev/null');
        return trim($out ?? '') === 'active';
    }

    public static function status(): array {
        $installed = self::isInstalled();
        $running   = $installed && self::isRunning();
        $version   = $installed ? trim(shell_exec('nginx -v 2>&1') ?: '') : '';
        $db        = DB::getInstance();
        $row       = $db->fetchOne("SELECT value FROM settings WHERE `key` = 'proxy_mode'");
        $mode      = $row['value'] ?? 'disabled';
        return [
            'installed' => $installed,
            'running'   => $running,
            'version'   => $version,
            'mode'      => $mode,
        ];
    }

    public static function start(): string {
        return self::sysctl('start');
    }

    public static function stop(): string {
        return self::sysctl('stop');
    }

    public static function restart(): string {
        return self::sysctl('restart');
    }

    public static function reload(): string {
        if (!self::isInstalled()) return 'nginx not installed';
        $test = shell_exec('sudo nginx -t 2>&1');
        if (strpos($test ?? '', 'successful') === false) return 'Config test failed: ' . $test;
        shell_exec('sudo systemctl reload nginx 2>/dev/null');
        return 'reloaded';
    }

    public static function install(): string {
        if (self::isInstalled()) return 'already installed';
        shell_exec('sudo apt-get update -qq 2>/dev/null && sudo apt-get install -y nginx 2>&1');
        if (!self::isInstalled()) return 'install failed';
        // Disable default site
        @unlink('/etc/nginx/sites-enabled/default');
        shell_exec('sudo systemctl enable nginx 2>/dev/null');
        shell_exec('sudo systemctl start nginx 2>/dev/null');
        return 'installed';
    }

    // --- Proxy Hosts ---

    public static function listHosts(): array {
        $db = DB::getInstance();
        return $db->fetchAll("SELECT * FROM proxy_hosts ORDER BY domain") ?: [];
    }

    public static function syncFromAccounts(): int {
        $db       = DB::getInstance();
        $accounts = $db->fetchAll("SELECT a.*, d.domain FROM accounts a JOIN domains d ON d.account_id=a.id AND d.type='main' WHERE a.status='active'") ?: [];
        $count    = 0;
        foreach ($accounts as $acct) {
            $existing = $db->fetchOne("SELECT id FROM proxy_hosts WHERE domain=?", [$acct['domain']]);
            if (!$existing) {
                $db->insert(
                    "INSERT INTO proxy_hosts (account_id, domain, upstream, ssl_enabled, enabled, created_at) VALUES (?,?,?,0,1,NOW())",
                    [$acct['id'], $acct['domain'], 'http://127.0.0.1:80']
                );
                $count++;
            }
        }
        if ($count > 0) self::writeAllConfigs();
        return $count;
    }

    public static function addHost(array $data): int {
        $db  = DB::getInstance();
        $id  = (int)$db->insert(
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
            @unlink(self::$confDir . '/' . self::$confPrefix . $host['domain'] . '.conf');
            @unlink(self::$enabledDir . '/' . self::$confPrefix . $host['domain'] . '.conf');
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
        // Remove old novacpx proxy configs
        foreach (glob(self::$confDir . '/' . self::$confPrefix . '*.conf') ?: [] as $f) @unlink($f);
        foreach (glob(self::$enabledDir . '/' . self::$confPrefix . '*.conf') ?: [] as $f) @unlink($f);
        foreach ($hosts as $host) {
            if (!$host['enabled']) continue;
            self::writeHostConfig($host);
        }
        self::reload();
    }

    private static function writeHostConfig(array $host): void {
        $safe     = preg_replace('/[^a-z0-9._-]/', '', strtolower($host['domain']));
        $confPath = self::$confDir . '/' . self::$confPrefix . $safe . '.conf';
        $linkPath = self::$enabledDir . '/' . self::$confPrefix . $safe . '.conf';

        if ($host['custom_config']) {
            file_put_contents($confPath, $host['custom_config']);
        } else {
            $upstream = rtrim($host['upstream'], '/');
            $ssl      = !empty($host['ssl_enabled']);
            $certDir  = "/etc/novacpx/ssl/accounts/" . preg_replace('/[^a-z0-9._-]/', '', $host['domain']);

            $conf = "server {\n";
            $conf .= "    listen 80;\n";
            if ($ssl) $conf .= "    listen 443 ssl http2;\n";
            $conf .= "    server_name {$host['domain']} www.{$host['domain']};\n";
            if ($ssl) {
                $conf .= "    ssl_certificate {$certDir}/cert.pem;\n";
                $conf .= "    ssl_certificate_key {$certDir}/key.pem;\n";
                $conf .= "    ssl_protocols TLSv1.2 TLSv1.3;\n";
                $conf .= "    ssl_ciphers HIGH:!aNULL:!MD5;\n";
            }
            $conf .= "    location / {\n";
            $conf .= "        proxy_pass {$upstream};\n";
            $conf .= "        proxy_http_version 1.1;\n";
            $conf .= "        proxy_set_header Host \$host;\n";
            $conf .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
            $conf .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
            $conf .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
            $conf .= "        proxy_set_header Upgrade \$http_upgrade;\n";
            $conf .= "        proxy_set_header Connection 'upgrade';\n";
            $conf .= "        proxy_cache_bypass \$http_upgrade;\n";
            $conf .= "        proxy_read_timeout 86400;\n";
            $conf .= "    }\n";
            $conf .= "}\n";
            file_put_contents($confPath, $conf);
        }
        @symlink($confPath, $linkPath);
    }

    // --- Setup Script ---

    public static function setupScript(): string {
        $serverIp = trim(shell_exec("hostname -I | awk '{print $1}'") ?: '127.0.0.1');
        return <<<BASH
#!/bin/bash
# NovaCPX Nginx Reverse Proxy Setup Script
# Run as root on the proxy VM (or this VM for local proxy)
set -e

echo "[NovaCPX] Installing Nginx reverse proxy..."
apt-get update -qq
apt-get install -y nginx certbot python3-certbot-nginx

# Disable default site
rm -f /etc/nginx/sites-enabled/default

# Create NovaCPX proxy conf directory
mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled

# Main nginx.conf tuning
cat > /etc/nginx/conf.d/novacpx-proxy.conf << 'EOF'
client_max_body_size 256M;
proxy_buffers 16 16k;
proxy_buffer_size 16k;
gzip on;
gzip_types text/plain text/css application/json application/javascript text/xml application/xml;
EOF

# Point proxy back to NovaCPX Apache backend (update SERVER_IP below)
BACKEND_IP={$serverIp}

# Generic catch-all for testing
cat > /etc/nginx/sites-available/novacpx-default.conf << EOF
server {
    listen 80 default_server;
    server_name _;
    return 444;
}
EOF
ln -sf /etc/nginx/sites-available/novacpx-default.conf /etc/nginx/sites-enabled/

# Test and reload
nginx -t && systemctl reload nginx
systemctl enable nginx

echo "[NovaCPX] Nginx proxy installed and running."
echo "  Backend IP: \$BACKEND_IP"
echo "  Add proxy hosts from the NovaCPX admin panel → Nginx Proxy"
BASH;
    }

    // --- Helpers ---

    private static function sysctl(string $action): string {
        if (!self::isInstalled()) return 'nginx not installed';
        shell_exec("sudo systemctl {$action} nginx 2>/dev/null");
        sleep(1);
        return self::isRunning() ? 'running' : 'stopped';
    }
}
