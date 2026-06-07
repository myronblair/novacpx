-- NovaCPX Feature Registry migration
-- All optional features that can be enabled/disabled/installed on the fly

CREATE TABLE IF NOT EXISTS features (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug         VARCHAR(80) NOT NULL UNIQUE,
  name         VARCHAR(120) NOT NULL,
  description  TEXT,
  category     VARCHAR(60) NOT NULL,
  enabled      TINYINT(1) DEFAULT 0,
  installed    TINYINT(1) DEFAULT 0,
  install_cmd  TEXT,
  uninstall_cmd TEXT,
  config_keys  JSON,
  install_pid  INT UNSIGNED DEFAULT NULL,
  install_log  VARCHAR(500) DEFAULT NULL,
  requires     JSON,
  requires_restart TINYINT(1) DEFAULT 0,
  min_ram_mb   INT UNSIGNED DEFAULT 0,
  updated_at   DATETIME ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_category (category),
  INDEX idx_enabled  (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO features (slug, name, description, category, install_cmd, requires_restart, min_ram_mb) VALUES

-- ── Web Server & Caching ───────────────────────────────────────────────────────
('nginx',           'nginx Web Server',           'High-performance reverse proxy and web server.', 'Web Server',
 'apt-get install -y nginx && systemctl enable nginx && systemctl start nginx', 1, 128),

('apache2',         'Apache2 Web Server',         'Full-featured HTTP server with .htaccess support.', 'Web Server',
 'apt-get install -y apache2 libapache2-mod-fcgid && a2enmod ssl rewrite headers && systemctl enable apache2', 1, 128),

('varnish',         'Varnish Cache',              'HTTP accelerator that caches responses to dramatically speed up sites.', 'Web Server',
 'apt-get install -y varnish && systemctl enable varnish', 1, 256),

('litespeed',       'OpenLiteSpeed',              'High-performance open source web server with QUIC/HTTP3 support.', 'Web Server',
 'wget -q https://rpms.litespeedtech.com/debian/enable_lst_debain_repo.sh | bash && apt-get install -y openlitespeed', 1, 512),

-- ── PHP ──────────────────────────────────────────────────────────────────────
('php74',           'PHP 7.4',                    'Legacy PHP 7.4 for older applications.', 'PHP',
 'add-apt-repository -y ppa:ondrej/php && apt-get update && apt-get install -y php7.4 php7.4-{fpm,cli,mysql,gd,curl,mbstring,xml,zip,bcmath,intl,opcache}', 0, 0),

('php81',           'PHP 8.1',                    'PHP 8.1 with JIT compiler.', 'PHP',
 'add-apt-repository -y ppa:ondrej/php && apt-get update && apt-get install -y php8.1 php8.1-{fpm,cli,mysql,gd,curl,mbstring,xml,zip,bcmath,intl,opcache,redis}', 0, 0),

('php82',           'PHP 8.2',                    'PHP 8.2 — latest stable.', 'PHP',
 'add-apt-repository -y ppa:ondrej/php && apt-get update && apt-get install -y php8.2 php8.2-{fpm,cli,mysql,gd,curl,mbstring,xml,zip,bcmath,intl,opcache,redis,imagick}', 0, 0),

('php83',           'PHP 8.3',                    'PHP 8.3 — latest with new features.', 'PHP',
 'add-apt-repository -y ppa:ondrej/php && apt-get update && apt-get install -y php8.3 php8.3-{fpm,cli,mysql,gd,curl,mbstring,xml,zip,bcmath,intl,opcache,redis,imagick}', 0, 0),

('composer',        'Composer',                   'PHP dependency manager.', 'PHP',
 'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer', 0, 0),

-- ── Databases ────────────────────────────────────────────────────────────────
('mysql8',          'MySQL 8',                    'Enterprise-grade relational database server.', 'Database',
 'apt-get install -y mysql-server && systemctl enable mysql && systemctl start mysql', 1, 512),

('postgresql',      'PostgreSQL',                 'Advanced open-source relational database.', 'Database',
 'apt-get install -y postgresql postgresql-contrib && systemctl enable postgresql', 1, 256),

('mariadb',         'MariaDB',                    'Community-developed MySQL fork with extra features.', 'Database',
 'apt-get install -y mariadb-server && systemctl enable mariadb && systemctl start mariadb', 1, 256),

('redis',           'Redis',                      'In-memory key-value store for caching and sessions.', 'Database',
 'apt-get install -y redis-server && systemctl enable redis-server && systemctl start redis-server', 0, 128),

('memcached',       'Memcached',                  'Distributed memory object caching system.', 'Database',
 'apt-get install -y memcached && systemctl enable memcached && systemctl start memcached', 0, 128),

('phpmyadmin',      'phpMyAdmin',                 'Web-based MySQL administration interface.', 'Database',
 'apt-get install -y phpmyadmin && ln -sf /usr/share/phpmyadmin /srv/novacpx/public/phpmyadmin', 0, 0),

('phppgadmin',      'phpPgAdmin',                 'Web-based PostgreSQL administration interface.', 'Database',
 'apt-get install -y phppgadmin && ln -sf /usr/share/phppgadmin /srv/novacpx/public/phppgadmin', 0, 0),

-- ── Email ─────────────────────────────────────────────────────────────────────
('postfix',         'Postfix MTA',                'Battle-tested mail transfer agent.', 'Email',
 'DEBIAN_FRONTEND=noninteractive apt-get install -y postfix postfix-mysql && systemctl enable postfix', 1, 128),

('dovecot',         'Dovecot IMAP/POP3',          'Secure IMAP and POP3 server for mail retrieval.', 'Email',
 'apt-get install -y dovecot-core dovecot-imapd dovecot-pop3d dovecot-mysql && systemctl enable dovecot', 1, 128),

('roundcube',       'Roundcube Webmail',          'Modern AJAX-based webmail client.', 'Email',
 'apt-get install -y roundcube roundcube-mysql && ln -sf /var/lib/roundcube /srv/novacpx/public/webmail', 0, 0),

('rainloop',        'RainLoop Webmail',           'Fast, lightweight modern webmail.', 'Email',
 'mkdir -p /srv/novacpx/public/rainloop && curl -sL https://repository.rainloop.net/installer.php -o /tmp/rl.php && php /tmp/rl.php /srv/novacpx/public/rainloop', 0, 0),

('spamassassin',    'SpamAssassin',               'Powerful anti-spam filter for Postfix.', 'Email',
 'apt-get install -y spamassassin spamc && systemctl enable spamassassin', 0, 256),

('rspamd',          'Rspamd',                     'Fast, free and open-source spam filtering system.', 'Email',
 'apt-get install -y rspamd && systemctl enable rspamd', 0, 256),

('dkim',            'DKIM (OpenDKIM)',             'DomainKeys Identified Mail signing for email authentication.', 'Email',
 'apt-get install -y opendkim opendkim-tools && systemctl enable opendkim', 0, 0),

-- ── DNS ───────────────────────────────────────────────────────────────────────
('bind9',           'BIND9 DNS Server',           'Internet standard authoritative DNS server.', 'DNS',
 'apt-get install -y bind9 bind9utils && systemctl enable named && systemctl start named', 1, 128),

('powerdns',        'PowerDNS',                   'High-performance DNS server with database backend.', 'DNS',
 'apt-get install -y pdns-server pdns-backend-mysql && systemctl enable pdns', 1, 256),

-- ── FTP ───────────────────────────────────────────────────────────────────────
('proftpd',         'ProFTPD',                    'Highly configurable FTP server.', 'FTP',
 'apt-get install -y proftpd-basic proftpd-mod-mysql && systemctl enable proftpd', 1, 0),

('vsftpd',          'vsftpd',                     'Very Secure FTP daemon.', 'FTP',
 'apt-get install -y vsftpd && systemctl enable vsftpd', 1, 0),

('pure-ftpd',       'Pure-FTPd',                  'Secure FTP server with virtual users and MySQL backend.', 'FTP',
 'apt-get install -y pure-ftpd pure-ftpd-mysql && systemctl enable pure-ftpd-mysql', 1, 0),

-- ── SSL ───────────────────────────────────────────────────────────────────────
('certbot',         'Certbot (Let\'s Encrypt)',   'Free SSL certificates from Let\'s Encrypt with auto-renewal.', 'SSL',
 'apt-get install -y certbot python3-certbot-apache python3-certbot-nginx && (crontab -l 2>/dev/null; echo "0 3 * * * certbot renew --quiet") | crontab -', 0, 0),

('acme-sh',         'acme.sh',                    'Shell script for automatic Let\'s Encrypt/ZeroSSL certificates.', 'SSL',
 'curl https://get.acme.sh | sh && acme.sh --set-default-ca --server letsencrypt', 0, 0),

-- ── Security ─────────────────────────────────────────────────────────────────
('fail2ban',        'Fail2Ban',                   'Bans IPs with too many failed login attempts.', 'Security',
 'apt-get install -y fail2ban && systemctl enable fail2ban', 0, 0),

('modsecurity',     'ModSecurity WAF',            'Web Application Firewall for Apache2/nginx.', 'Security',
 'apt-get install -y libapache2-mod-security2 && a2enmod security2 && systemctl restart apache2', 1, 128),

('imunifyav',       'ImunifyAV (free tier)',       'Malware scanner and antivirus for web files.', 'Security',
 'wget -q https://repo.imunify360.cloudlinux.com/defence360/imav-deploy.sh && bash imav-deploy.sh', 0, 256),

('clamav',          'ClamAV',                     'Open-source antivirus engine for detecting malware.', 'Security',
 'apt-get install -y clamav clamav-daemon && freshclam && systemctl enable clamav-daemon', 0, 512),

('ufw',             'UFW Firewall',               'Uncomplicated Firewall — simple iptables front-end.', 'Security',
 'apt-get install -y ufw && ufw --force enable', 0, 0),

('crowdsec',        'CrowdSec',                   'Collaborative intrusion detection and prevention.', 'Security',
 'curl -s https://install.crowdsec.net | sh && systemctl enable crowdsec', 0, 128),

-- ── Application Managers ──────────────────────────────────────────────────────
('wp-manager',      'WordPress Manager',          'One-click WordPress installs, updates, and plugin management.', 'Applications',
 'curl -sL https://wp.novacpx.io/install-manager.sh | bash', 0, 0),

('wp-cli',          'WP-CLI',                     'Command-line interface for managing WordPress installations.', 'Applications',
 'curl -sL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && chmod +x /usr/local/bin/wp', 0, 0),

('node-manager',    'Node.js / NVM Manager',      'Manage multiple Node.js versions and run Node apps.', 'Applications',
 'curl -fsSL https://fnm.vercel.app/install | bash && fnm install 20 && fnm use 20 && npm install -g pm2', 0, 256),

('python-manager',  'Python / uWSGI',             'Host Python (Flask/Django) apps via uWSGI.', 'Applications',
 'apt-get install -y python3 python3-pip python3-venv uwsgi uwsgi-plugin-python3 && pip3 install virtualenv', 0, 0),

('ruby-manager',    'Ruby / Passenger',           'Host Ruby on Rails apps via Phusion Passenger.', 'Applications',
 'apt-get install -y ruby ruby-dev && gem install passenger && passenger-install-apache2-module --auto', 1, 512),

('git-deploy',      'Git Auto-Deploy',            'Webhook-triggered git pull deployment for any repo.', 'Applications',
 'cp /opt/novacpx-src/extras/git-deploy/git-deploy.php /srv/novacpx/public/git-deploy.php', 0, 0),

-- ── Docker & Containers ───────────────────────────────────────────────────────
('docker',          'Docker Engine',              'Run containerized applications. Required for container hosting.', 'Containers',
 'curl -fsSL https://get.docker.com | sh && usermod -aG docker www-data && systemctl enable docker && systemctl start docker', 1, 1024),

('docker-compose',  'Docker Compose',             'Define and run multi-container apps with YAML configs.', 'Containers',
 'apt-get install -y docker-compose-plugin && docker compose version', 0, 0),

('portainer',       'Portainer CE',               'Visual Docker management UI — manage containers, images, volumes.', 'Containers',
 'docker volume create portainer_data && docker run -d -p 9000:9000 --name portainer --restart=always -v /var/run/docker.sock:/var/run/docker.sock -v portainer_data:/data portainer/portainer-ce:latest', 0, 512),

('ctrlpanel',       'Account Docker Hosting',     'Allow end-users to run Docker containers within their account limits.', 'Containers',
 'cp /opt/novacpx-src/extras/docker-hosting/setup.sh /tmp/ && bash /tmp/setup.sh', 0, 2048),

-- ── IP Management ─────────────────────────────────────────────────────────────
('shared-ips',      'Shared IP Management',       'Assign multiple accounts to shared IP addresses with SNI SSL.', 'IP Management',
 'cp /opt/novacpx-src/extras/ip-manager/shared.sh /usr/local/bin/novacpx-shared-ip && chmod +x /usr/local/bin/novacpx-shared-ip', 0, 0),

('dedicated-ips',   'Dedicated IP Addresses',     'Assign dedicated IPs to accounts for legacy SSL and isolation.', 'IP Management',
 'cp /opt/novacpx-src/extras/ip-manager/dedicated.sh /usr/local/bin/novacpx-dedicated-ip && chmod +x /usr/local/bin/novacpx-dedicated-ip', 0, 0),

('ipv6',            'IPv6 Support',               'Enable IPv6 addressing for hosted domains and services.', 'IP Management',
 'sysctl -w net.ipv6.conf.all.disable_ipv6=0 && echo "net.ipv6.conf.all.disable_ipv6=0" >> /etc/sysctl.conf', 0, 0),

-- ── Monitoring & Analytics ────────────────────────────────────────────────────
('netdata',         'Netdata Monitoring',         'Real-time server metrics with beautiful charts and alerts.', 'Monitoring',
 'curl -sL https://my-netdata.io/kickstart.sh | bash -- --dont-start-it && systemctl enable netdata && systemctl start netdata', 0, 512),

('awstats',         'AWStats',                    'Advanced web traffic statistics from server logs.', 'Monitoring',
 'apt-get install -y awstats && a2enmod cgi && systemctl restart apache2', 0, 0),

('goaccess',        'GoAccess',                   'Real-time web log analyzer and terminal dashboard.', 'Monitoring',
 'apt-get install -y goaccess', 0, 0),

('grafana',         'Grafana + Prometheus',        'Advanced metrics dashboards with Prometheus data collection.', 'Monitoring',
 'apt-get install -y prometheus && wget -q https://dl.grafana.com/oss/release/grafana_10.0.0_amd64.deb && dpkg -i grafana*.deb && systemctl enable grafana-server prometheus', 0, 1024),

-- ── Backup & Recovery ────────────────────────────────────────────────────────
('borgbackup',      'BorgBackup',                 'Deduplicating backup with compression and encryption.', 'Backup',
 'apt-get install -y borgbackup', 0, 0),

('rclone',          'Rclone (S3/GCS/B2)',         'Sync backups to S3, Google Cloud, Backblaze B2, and 40+ providers.', 'Backup',
 'curl https://rclone.org/install.sh | bash', 0, 0),

('duplicati',       'Duplicati',                  'Encrypted online backups to cloud storage with web UI.', 'Backup',
 'apt-get install -y duplicati && systemctl enable duplicati', 0, 256),

-- ── CDN & Performance ────────────────────────────────────────────────────────
('cloudflare-api',  'Cloudflare API Integration', 'Manage DNS records and purge cache via Cloudflare API.', 'CDN & Performance',
 'echo "Cloudflare integration enabled" && touch /etc/novacpx/cloudflare.enabled', 0, 0),

('pagespeed',       'PageSpeed Module',           'Google PageSpeed mod for Apache/nginx — auto-optimize assets.', 'CDN & Performance',
 'apt-get install -y libapache2-mod-pagespeed && a2enmod pagespeed && systemctl restart apache2', 1, 256),

-- ── Development Tools ─────────────────────────────────────────────────────────
('git-server',      'Gitea (Self-hosted Git)',    'Lightweight GitHub-like Git server for private repositories.', 'Development',
 'curl -sL https://dl.gitea.com/gitea/1.21.0/gitea-1.21.0-linux-amd64 -o /usr/local/bin/gitea && chmod +x /usr/local/bin/gitea', 0, 512),

('phusion-passenger','Phusion Passenger',         'Multi-language app server (Ruby, Python, Node.js) for nginx/Apache.', 'Development',
 'apt-get install -y libnginx-mod-http-passenger && systemctl restart nginx', 1, 256),

('jupyter',         'JupyterHub',                 'Web-based interactive notebooks for Python/data science users.', 'Development',
 'pip3 install jupyterhub && npm install -g configurable-http-proxy', 0, 1024),

-- ── CMS & Apps (One-Click) ────────────────────────────────────────────────────
('softaculous-like','Auto-Installer (50+ apps)', 'One-click install for WordPress, Joomla, Drupal, Magento, and 50+ apps.', 'One-Click Apps',
 'cp /opt/novacpx-src/extras/auto-installer/setup.sh /tmp/ && bash /tmp/setup.sh', 0, 0),

('softaculous-wp',  'WordPress (One-Click)',      'Instant WordPress deployment with auto-config.', 'One-Click Apps',
 'echo "WordPress one-click enabled"', 0, 0),

-- ── WHMCS / Billing Integration ───────────────────────────────────────────────
('whmcs-bridge',    'WHMCS Provisioning Bridge',  'Integrate with WHMCS for automated account provisioning via API.', 'Billing',
 'cp /opt/novacpx-src/extras/whmcs-bridge/init.php /etc/novacpx/whmcs-bridge.php', 0, 0),

('boxbilling',      'BoxBilling Integration',     'Open-source billing and client management integration.', 'Billing',
 'echo "BoxBilling bridge enabled"', 0, 0),

-- ── Reseller & Branding ───────────────────────────────────────────────────────
('white-label',     'White Label / Branding',     'Allow resellers to rebrand the panel with their own logo and colors.', 'Reseller',
 'echo "White label enabled"', 0, 0),

('reseller-dns',    'Reseller Custom Nameservers', 'Allow resellers to use their own nameservers (ns1.reseller.com).', 'Reseller',
 'echo "Reseller nameservers enabled"', 0, 0),

-- ── Messaging & Notifications ─────────────────────────────────────────────────
('email-notify',    'Email Notifications',        'Send alerts and reports via email using Postfix.', 'Notifications',
 'echo "Email notifications enabled"', 0, 0),

('slack-notify',    'Slack Webhooks',             'Send server alerts and events to a Slack channel.', 'Notifications',
 'echo "Slack notifications enabled"', 0, 0),

('telegram-notify', 'Telegram Bot Alerts',        'Push server alerts to a Telegram bot/channel.', 'Notifications',
 'echo "Telegram notifications enabled"', 0, 0),

-- ── Compliance ────────────────────────────────────────────────────────────────
('auditd',          'Auditd (System Audit)',       'Linux audit daemon for compliance and security monitoring.', 'Compliance',
 'apt-get install -y auditd audispd-plugins && systemctl enable auditd', 0, 0),

('ossec',           'OSSEC HIDS',                 'Host-based intrusion detection system.', 'Compliance',
 'apt-get install -y ossec-hids-server 2>/dev/null || wget -q https://updates.atomicorp.com/channels/atomic/fedora/x86_64/RPMS/ossec-hids-server-3.7.0-25506.el7.art.x86_64.rpm', 0, 256);
