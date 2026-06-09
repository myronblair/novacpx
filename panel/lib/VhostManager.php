<?php
/**
 * VhostManager — creates/removes Apache2 and nginx virtual host configs
 */
class VhostManager {

    public static function create(string $username, string $domain, string $docRoot, string $phpVer): void {
        $logDir = "/home/{$username}/logs";
        if (WEB_SERVER === 'nginx') {
            self::writeNginx($username, $domain, $docRoot, $phpVer, $logDir);
        } else {
            self::writeApache($username, $domain, $docRoot, $phpVer, $logDir);
        }
        self::reload();
    }

    public static function createSubdomain(string $username, string $subdomain, string $docRoot, string $phpVer): void {
        self::create($username, $subdomain, $docRoot, $phpVer);
    }

    public static function suspend(string $username, string $domain): void {
        $suspendedRoot = "/var/novacpx/suspended";
        @mkdir($suspendedRoot, 0755, true);
        $suspendPage = "{$suspendedRoot}/{$domain}.html";
        file_put_contents($suspendPage,
            "<html><body style='font-family:sans-serif;text-align:center;padding:4rem;background:#0d0f17;color:#e2e4f0'>"
            . "<h1 style='color:#ef4444'>Account Suspended</h1>"
            . "<p>This account has been suspended. Please contact support.</p></body></html>"
        );
        // Rewrite vhost to serve suspension page
        if (WEB_SERVER === 'nginx') {
            $conf = "/etc/nginx/sites-available/novacpx-{$username}.conf";
            if (file_exists($conf)) {
                $content = file_get_contents($conf);
                $content = preg_replace('/root\s+[^;]+;/', "root {$suspendedRoot};", $content);
                file_put_contents($conf, $content);
            }
        } else {
            $conf = "/etc/apache2/sites-available/novacpx-{$username}.conf";
            if (file_exists($conf)) {
                $content = file_get_contents($conf);
                $content = preg_replace('/DocumentRoot\s+\S+/', "DocumentRoot {$suspendedRoot}", $content);
                file_put_contents($conf, $content);
            }
        }
        self::reload();
    }

    public static function unsuspend(string $username, string $domain): void {
        // Re-create from DB
        $db   = DB::getInstance();
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE username = ?", [$username]);
        if ($acct) {
            self::create($username, $domain, $acct['home_dir'] . '/public_html', $acct['php_version']);
        }
    }

    public static function remove(string $username, string $domain): void {
        if (WEB_SERVER === 'nginx') {
            $conf = "/etc/nginx/sites-available/novacpx-{$username}.conf";
            $link = "/etc/nginx/sites-enabled/novacpx-{$username}.conf";
            @unlink($conf); @unlink($link);
        } else {
            $conf = "/etc/apache2/sites-available/novacpx-{$username}.conf";
            shell_exec("a2dissite novacpx-{$username} 2>/dev/null");
            @unlink($conf);
        }
        self::reload();
    }

    public static function enableSSL(string $username, string $domain, string $cert, string $key, string $chain = ''): void {
        $certDir = "/etc/novacpx/ssl/accounts/{$username}";
        @mkdir($certDir, 0700, true);
        file_put_contents("{$certDir}/cert.pem", $cert);
        file_put_contents("{$certDir}/key.pem", $key);
        if ($chain) file_put_contents("{$certDir}/chain.pem", $chain);

        if (WEB_SERVER === 'nginx') {
            $conf = file_get_contents("/etc/nginx/sites-available/novacpx-{$username}.conf") ?: '';
            if (!str_contains($conf, 'ssl_certificate')) {
                $conf = str_replace('listen 80;', "listen 443 ssl http2;\n    listen 80;\n    ssl_certificate {$certDir}/cert.pem;\n    ssl_certificate_key {$certDir}/key.pem;", $conf);
                file_put_contents("/etc/nginx/sites-available/novacpx-{$username}.conf", $conf);
            }
        } else {
            $conf = file_get_contents("/etc/apache2/sites-available/novacpx-{$username}.conf") ?: '';
            if (!str_contains($conf, 'SSLEngine')) {
                $conf = str_replace('<VirtualHost *:80>', "<VirtualHost *:443>\n    SSLEngine on\n    SSLCertificateFile {$certDir}/cert.pem\n    SSLCertificateKeyFile {$certDir}/key.pem", $conf);
                $conf .= "\n<VirtualHost *:80>\n    ServerName {$domain}\n    Redirect permanent / https://{$domain}/\n</VirtualHost>";
                file_put_contents("/etc/apache2/sites-available/novacpx-{$username}.conf", $conf);
            }
        }
        self::reload();
    }

    private static function writeNginx(string $username, string $domain, string $docRoot, string $phpVer, string $logDir): void {
        $sock = "/run/php/php{$phpVer}-fpm-{$username}.sock";
        $conf = "/etc/nginx/sites-available/novacpx-{$username}.conf";
        file_put_contents($conf, "server {
    listen 80;
    server_name {$domain} www.{$domain};
    root {$docRoot};
    index index.php index.html index.htm;
    access_log {$logDir}/access.log;
    error_log  {$logDir}/error.log;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:{$sock};
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PHP_VALUE \"error_log={$logDir}/php.log\";
    }
    location ~ /\\.ht { deny all; }
    location ~* \\.(jpg|jpeg|png|gif|ico|css|js|svg|woff2)$ { expires 30d; add_header Cache-Control public; }
}
");
        @symlink($conf, "/etc/nginx/sites-enabled/novacpx-{$username}.conf");
    }

    // Returns the port Apache listens on for customer vhosts.
    // 80 normally; changes to an internal port (e.g. 8090) in local proxy mode.
    public static function getApachePort(): int {
        $db = DB::getInstance();
        return (int)($db->fetchOne("SELECT value FROM settings WHERE `key`='proxy_apache_port'")['value'] ?? 80);
    }

    // Re-write all existing novacpx-*.conf vhosts to use $to instead of $from,
    // and update /etc/apache2/ports.conf accordingly.  Returns count of files changed.
    public static function migrateApachePort(int $from, int $to): int {
        $count = 0;
        foreach (glob('/etc/apache2/sites-available/novacpx-*.conf') ?: [] as $f) {
            $orig    = file_get_contents($f);
            $updated = str_replace(
                ["<VirtualHost *:{$from}>", "VirtualHost *:{$from}"],
                ["<VirtualHost *:{$to}>",   "VirtualHost *:{$to}"],
                $orig
            );
            if ($updated !== $orig) { file_put_contents($f, $updated); $count++; }
        }
        // Update ports.conf: swap Listen $from → Listen $to, drop Listen 443 (nginx handles SSL)
        $ports = file_get_contents('/etc/apache2/ports.conf') ?: '';
        $ports = preg_replace('/^Listen\s+' . $from . '\b/m', "Listen {$to}", $ports);
        $ports = preg_replace('/^Listen\s+443\b/m', '', $ports);
        $ports = preg_replace('/<IfModule ssl_module>.*?<\/IfModule>/s', '', $ports);
        file_put_contents('/etc/apache2/ports.conf', $ports);
        return $count;
    }

    // Reverse migration: move Apache back from proxy port to standard 80/443.
    public static function restoreApachePort(int $from, int $to = 80): int {
        $count = 0;
        foreach (glob('/etc/apache2/sites-available/novacpx-*.conf') ?: [] as $f) {
            $orig    = file_get_contents($f);
            $updated = str_replace(
                ["<VirtualHost *:{$from}>", "VirtualHost *:{$from}"],
                ["<VirtualHost *:{$to}>",   "VirtualHost *:{$to}"],
                $orig
            );
            if ($updated !== $orig) { file_put_contents($f, $updated); $count++; }
        }
        $ports = file_get_contents('/etc/apache2/ports.conf') ?: '';
        $ports = preg_replace('/^Listen\s+' . $from . '\b/m', "Listen {$to}", $ports);
        if (!str_contains($ports, 'Listen 443')) {
            $ports .= "\n<IfModule ssl_module>\n    Listen 443\n</IfModule>\n";
        }
        file_put_contents('/etc/apache2/ports.conf', $ports);
        return $count;
    }

    private static function writeApache(string $username, string $domain, string $docRoot, string $phpVer, string $logDir): void {
        $port = self::getApachePort();
        $sock = "/run/php/php{$phpVer}-fpm-{$username}.sock";
        $conf = "/etc/apache2/sites-available/novacpx-{$username}.conf";
        file_put_contents($conf, "<VirtualHost *:{$port}>
    ServerName {$domain}
    ServerAlias www.{$domain}
    DocumentRoot {$docRoot}
    ErrorLog  {$logDir}/error.log
    CustomLog {$logDir}/access.log combined

    <Directory {$docRoot}>
        Options -Indexes +FollowSymLinks +MultiViews
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch \\.php$>
        SetHandler \"proxy:unix:{$sock}|fcgi://localhost/\"
    </FilesMatch>
</VirtualHost>
");
        shell_exec("a2ensite novacpx-{$username} 2>/dev/null");
    }

    private static function reload(): void {
        if (WEB_SERVER === 'nginx') {
            shell_exec("nginx -t 2>/dev/null && systemctl reload nginx 2>/dev/null");
        } else {
            shell_exec("apache2ctl configtest 2>/dev/null && systemctl reload apache2 2>/dev/null");
        }
    }
}
