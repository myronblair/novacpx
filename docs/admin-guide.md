# NovaCPX — Administrator Guide

## Accessing the Admin Panel

The admin panel runs on port **8882**. Navigate to `https://<server-ip>:8882` and log in with your admin credentials.

The browser will show a self-signed certificate warning on a fresh install. Accept it, or replace the certificate at `/etc/novacpx/ssl/` with a trusted cert and restart Apache.

## Dashboard

The dashboard shows real-time server stats (CPU, RAM, disk, uptime), running services with restart/stop controls, and NovaCPX version information.

Click **Check for Updates** to see if a newer version is available.

## Accounts

**Accounts → All Accounts** lists every hosting account on the server. From this page you can:

- **Search** by domain or username
- **Suspend / Unsuspend** an account (suspending disables the web vhost and sends an email notification to the account holder)
- **Change Password** for any account
- **Terminate** an account permanently (deletes files, databases, DNS zone, email accounts — irreversible)

### Creating an account

**Accounts → Create Account**

| Field | Notes |
|-------|-------|
| Username | Lowercase, alphanumeric. Creates a Linux system user. |
| Domain | Primary domain for the account. A DNS zone and vhost are created automatically. |
| Email | Account holder's email. Receives the welcome notification. |
| Password | Min 8 characters. Also set as the Linux system password. |
| Package | Disk/resource limits applied to the account. |
| PHP version | Per-account PHP-FPM pool version. |
| Reseller | (Optional) Assign account to a reseller. |

After creation, the welcome email (if notifications are enabled) is sent to the account holder with login credentials.

## Resellers

**Accounts → Resellers** manages reseller sub-admin accounts. Resellers can create and manage their own customer accounts, set per-customer Docker quotas, and apply white-label branding.

To create a reseller, go to **Accounts → Create Account** and select the **Reseller** role.

## Packages

Define hosting plans. Each package sets limits on:

- Disk (MB)
- Email accounts
- MySQL databases
- FTP accounts
- Domains
- Subdomains

Accounts without a package have no enforced limits.

## DNS

### DNS Zones

Lists all DNS zones on the server. You can add, edit, and delete DNS records directly. Zones are managed by BIND9 and reloaded via `rndc`.

Supported record types: A, AAAA, CNAME, MX, TXT, NS, SRV, CAA.

### Nameservers

Set the global NS1/NS2 hostnames used when creating new zones. After saving, click **Check All** to verify the nameservers are resolving correctly.

## Services

### Web Server

Shows Apache or Nginx configuration. Switch between web servers. The switch script runs in the background — check the page again after ~30 seconds to confirm.

### PHP Manager

Install or remove PHP versions (7.4, 8.1, 8.2, 8.3). Each version gets its own PHP-FPM pool. Accounts can be assigned any installed version.

### MySQL Manager

Shows MySQL status and running databases. Provides a link to phpMyAdmin if installed.

### Mail Server

Shows Postfix/Dovecot status. Switch mail server stack (postfix-dovecot or postfix-dovecot-rspamd).

### FTP Server

Shows FTP daemon status. Switch between ProFTPD, vsftpd, and PureFTPD.

### Nginx Proxy Manager

Reverse proxy management for additional services. The Nginx Proxy Manager runs as a Docker container. Use **Setup** to configure it.

### WordPress Manager

One-click WordPress installs via WP-CLI. Actions: install, update, toggle maintenance mode, clone to staging.

### Docker

Full Docker Engine management:

- **Containers** — run, stop, start, restart, remove, view logs
- **Images** — pull, list, remove
- **Volumes** and **Networks** — list, remove
- **Compose Stacks** — create from YAML, bring up/down, view logs

## Security

### SSL Manager

View SSL certificates for all accounts. Certificates issued via Certbot (Let's Encrypt). The domain must resolve publicly for issuance to succeed.

### Firewall / Fail2Ban

Manage UFW rules (allow/deny by port, protocol, and IP). View and unban Fail2Ban jail entries. NovaCPX jails monitor:

- SSH brute-force (`sshd`)
- Panel login failures (`novacpx-auth`)
- API abuse (`novacpx-api`)
- PHP error flooding (`novacpx-php`)
- Postfix SMTP auth (`postfix-auth`)

### Audit Log

Full log of all admin, reseller, and user actions. Filter by username, action type, and date range. Click any row to expand the raw JSON detail payload.

### 2FA Manager

Manage TOTP two-factor authentication. Admins can view which accounts have 2FA enabled and reset (revoke) 2FA for a user if they lose their authenticator.

### Sessions

View all active login sessions. Revoke individual sessions or all sessions for a specific user. Useful for forcing a logout after a password reset.

## System

### Updates

Check for NovaCPX and OS updates. Results are cached for 12 hours so the page loads instantly; click **↻ Refresh now** to force a live check.

**Update channels** (set in Settings):

| Channel | GitHub branch | Versioning |
|---------|--------------|------------|
| Stable | `main` | Major/minor releases (e.g. 1.1.0) |
| Beta | `beta` | Patch and pre-release (e.g. 1.1.1-beta.3) |

The Updates page shows your installed version, the latest available version for your channel, and pending commits. Click **Update NovaCPX** to pull and deploy. PHP syntax is validated before deploy; if the panel goes down after update it auto-restores from a backup.

**OS Upgrade** streams `apt-get upgrade` output in real time. A backup of the web root is made before upgrading.

### Backups

Schedule and manage per-account backups:

- **Backup now** — immediate backup of files + databases
- **Download** — download a backup archive
- **Restore** — restore files and databases from a backup
- **Schedule** — set automatic backup frequency per account
- Optional rclone/S3 remote destination

### Cloudflare

Per-account Cloudflare API key management. Pull/push DNS zone records, toggle the CDN proxy per record.

### Server Options

Configure which services NovaCPX manages:

| Setting | Options |
|---------|---------|
| Web server | apache, nginx, openlitespeed, caddy |
| Mail server | postfix-dovecot, postfix-dovecot-rspamd |
| FTP server | proftpd, vsftpd, pureftpd |
| DNS server | bind9, powerdns, nsd, none |
| WHMCS | Enable and configure the billing bridge API key |

### Notifications

Configure email alerts sent via CyberMail:

| Field | Notes |
|-------|-------|
| CyberMail API Key | From platform.cyberpersons.com |
| From Email | Sender address (must be a verified sender domain) |
| From Name | Display name shown in email clients |
| Admin Alert Email | Receives admin copies of all notifications |
| Notifications | Enable or disable all outbound notifications |

Click **Send Test Email** to verify the configuration.

Notification triggers:

- Account created → welcome email to new user + admin alert
- Account suspended → notification to account holder + admin alert
- Disk quota ≥ 85% → daily warning (cron, 06:00)
- SSL certificate expiring ≤ 14 days → expiry notice (cron, 06:00)

### Settings

Panel-wide settings. All values are loaded from the database when the page opens and saved individually.

| Setting | Description |
|---------|-------------|
| Panel Name | Name shown in the browser title and sidebar |
| Default PHP Version | PHP version applied to new accounts (7.4, 8.1, 8.2, 8.3) |
| Primary Nameserver | NS1 hostname shown to users when setting up DNS |
| Secondary Nameserver | NS2 hostname |
| Update Channel | **Stable** (main branch) or **Beta** (beta branch) — controls which GitHub branch the Updates page checks and deploys from |

## WHMCS Billing Bridge

NovaCPX exposes a WHMCS-compatible server module API at `/api/whmcs/<action>`. Enable it in **Server Options** and set the API key. The WHMCS module calls these endpoints to provision, suspend, and terminate accounts automatically.

Supported actions: `create`, `suspend`, `unsuspend`, `terminate`, `changepackage`, `info`.

Authenticate with the `X-WHMCS-Key: <api_key>` header.

## Log files

| File | Contents |
|------|----------|
| `/var/log/novacpx/deploy.log` | Auto-deploy activity |
| `/var/log/novacpx/stats-collector.log` | Server stats cron output |
| `/var/log/novacpx/notify-checks.log` | Disk/SSL notification cron output |
| `/var/log/novacpx/switch-*.log` | Service switch script output |
