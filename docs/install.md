# NovaCPX — Installation Guide

## Requirements

| Item | Minimum |
|------|---------|
| OS | Ubuntu 20.04 / 22.04 / 24.04, Debian 11 / 12 |
| RAM | 1 GB (2 GB recommended) |
| Disk | 10 GB free |
| CPU | 1 vCPU |
| Access | Root or sudo |
| Ports | 80, 443, 8880, 8881, 8882, 8883, 21, 22, 25, 143, 993, 53 |

## Quick Install

```bash
curl -fsSL https://raw.githubusercontent.com/myronblair/novacpx/main/install.sh | bash
```

Or download and run manually:

```bash
wget https://raw.githubusercontent.com/myronblair/novacpx/main/install.sh
bash install.sh
```

### Installer flags

| Flag | Effect |
|------|--------|
| `--nginx` | Use Nginx instead of Apache (default: Apache) |
| `--apache` | Force Apache (default) |
| `--no-mysql` | Skip MySQL installation |
| `--no-postgres` | Skip PostgreSQL installation |

The installer is **idempotent** — safe to re-run on an existing install. Completed steps are skipped automatically.

## What the installer does

1. Detects OS and verifies minimum requirements
2. Installs PHP 7.4, 8.1, 8.2, and 8.3 (ondrej/php PPA on Ubuntu; sury on Debian)
3. Installs and configures the web server (Apache or Nginx) on ports 8880/8881/8882
4. Installs MySQL and creates the `novacpx` database and `novacpx_user` account
5. Installs PostgreSQL (optional, for customer databases)
6. Installs and configures BIND9 for DNS zone management
7. Installs Postfix + Dovecot for virtual mail hosting
8. Installs ProFTPD for FTP account management
9. Installs OpenDKIM and wires it into Postfix
10. Installs Roundcube webmail on port 8883
11. Installs Certbot for Let's Encrypt SSL
12. Installs Fail2Ban with 5 jails (sshd + 4 NovaCPX-specific jails)
13. Configures UFW firewall rules
14. Copies panel files to `/srv/novacpx/public/`
15. Sets up systemd services and cron jobs
16. Generates a self-signed SSL certificate for the panel ports
17. Creates the admin user and prints credentials

## Post-install

After the installer completes it prints:

```
NovaCPX installed successfully!

Admin panel:  https://<server-ip>:8882
Username:     admin
Password:     <generated>

User panel:   https://<server-ip>:8880
Reseller panel: https://<server-ip>:8881
Webmail:      https://<server-ip>:8883
```

Log in to the admin panel and:

1. Set your nameservers under **DNS → Nameservers**
2. Configure your server IP in **Settings**
3. Create your first hosting package under **Packages**
4. Create your first hosting account under **Accounts → Create**

## File layout

```
/srv/novacpx/public/    Web root (all panel files)
  admin/                Admin panel frontend
  reseller/             Reseller panel frontend
  user/                 User panel frontend
  api/                  API backend (PHP)
    endpoints/          One file per resource
  lib/                  Shared PHP classes
  assets/               CSS, JS, images
  errors/               Custom error pages

/opt/novacpx/           Binaries and runtime libs
  bin/                  Cron scripts
  lib/                  Symlink to /srv/novacpx/public/lib

/opt/novacpx-src/       Git repository clone
/etc/novacpx/           Config files
  config.ini            Main config (DB creds, panel secret, ports)
  ssl/                  Panel TLS certificate
/var/log/novacpx/       Log files
```

## Configuration file

`/etc/novacpx/config.ini`:

```ini
[database]
host     = localhost
name     = novacpx
user     = novacpx_user
pass     = <generated>

[panel]
secret   = <generated>      ; HMAC key for session tokens
port_user     = 8880
port_reseller = 8881
port_admin    = 8882
port_webmail  = 8883
webroot  = /srv/novacpx/public
version  = 1.0.0

[web]
server       = apache        ; apache | nginx
php_default  = 8.3

[deploy]
webhook_secret = <generated>
repo_path      = /opt/novacpx-src
web_root       = /srv/novacpx/public
branch         = main
```

## Auto-deploy (GitHub webhook)

The installer sets up an auto-deploy pipeline so pushes to `main` go live within one minute.

1. The webhook handler lives at `https://<server>:8882/deploy/webhook.php`
2. Add a GitHub webhook: **Settings → Webhooks → Add webhook**
   - Payload URL: `https://<server>:8882/deploy/webhook.php`
   - Content type: `application/json`
   - Secret: value of `webhook_secret` in `config.ini`
   - Events: **Just the push event**
3. The cron runner `/usr/local/bin/novacpx-deploy` runs every minute and processes the deploy queue
4. PHP syntax is validated before any files go live; bad commits are auto-rejected

## Upgrading

```bash
cd /opt/novacpx-src
git pull origin main
```

The deploy runner handles everything else (file sync, DB migrations, PHP-FPM reload). Or just push to your GitHub remote if the webhook is configured.

## Uninstalling

There is no automated uninstaller. To remove NovaCPX:

```bash
rm -rf /srv/novacpx /opt/novacpx /opt/novacpx-src /etc/novacpx
mysql -e "DROP DATABASE novacpx; DROP USER 'novacpx_user'@'localhost';"
rm /etc/apache2/sites-enabled/novacpx.conf   # or nginx equivalent
rm /etc/cron.d/novacpx
```

Service packages (Apache, Postfix, Dovecot, BIND9, etc.) are shared system services and are left in place.
