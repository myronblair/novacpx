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

Configure email alerts sent through the Gmail API:

| Field | Notes |
|-------|-------|
| Gmail sender / OAuth client id / secret / refresh token | The Gmail account the panel sends as |
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

## Usage & Health

### Traffic & Usage
Shows every account's month-to-date traffic against its package allowance. Choose what happens at 100%: send a warning only, or warn and suspend the account. Warnings at 80% and 100% are sent once per account and month.

### Site Uptime
Shows every monitored site with its state, last answer and 24-hour uptime. Settings: monitoring on/off and how many failed checks in a row count as down (default 2).

### Malware Sweep
Shows the last scan and the number of open findings per account. The built-in pattern scan is always on (nightly at 03:30); when ClamAV is installed you can switch it on as a second engine.

### Mail Queue
Lists the messages Postfix has not delivered yet with the reason for each. Retry or hold or delete one message, retry the whole queue, or empty it (asks for confirmation).

### Package limits
Packages carry two PHP limits: **PHP requests at once** (pool `pm.max_children`) and a **PHP memory ceiling** (an account's `memory_limit` is never set above it). Saving a package applies the limits to every account on it straight away.

### Background tasks
`/etc/cron.d/novacpx-tasks` (installed on every deploy by `deploy/install-tasks.sh`) runs the stats collector, the traffic meter and uptime monitor (every 5 minutes), scheduled backups (every 15 minutes) and the nightly notification checks.

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

### Package tools

In **Packages**, the *Tools included* tick-boxes choose which optional tools (Site Shield and firewall, Traffic, Uptime, Sweep, Git Deploy, Docker, WordPress, Cron, Backups) customers on that package can use. A package with every box ticked has no restriction, and existing packages keep everything until you change them. Customers cannot reach a tool that is not included, even through the API. Admins and resellers are never restricted. Resellers can change only their own packages.

### Account Transfer

**Account Transfer** moves a hosting account between two NovaCPX servers.

1. On the old server choose the account and press **Create transfer link**. The panel packs the website files and MySQL databases in the background and shows a link that works once, for 2 hours.
2. On the new server paste the link under **Receive an account** and press **Import account**. The new server downloads the bundle (it must be able to reach the old server's panel over the internet; private addresses are refused), creates the account with the same user name, domain, e-mail, login and PHP version, and loads the files and databases. The package is matched by name.
3. Add the customer's mailboxes, addon domains, cron jobs, FTP users and SSL certificate on the new server, then point DNS at it. These are listed after the import because they are not carried over; mail is not moved.

If anything fails the half-built account is removed again, so the import can simply be repeated. The bundle contains the customer's database passwords, which is why the link is single-use and the bundle is deleted after the download or when the link expires. Very large sites (many gigabytes) are capped at 10 GB per transfer.

### Web Terminal

**Web Terminal** gives you a root shell inside the admin panel (nginx servers only). It is off until you switch it on, and it is built to be hard to misuse:

- You must have two-factor authentication on for your admin login (Security > 2FA) before it can be enabled.
- **Enable web terminal** installs the `ttyd` program, starts it, and adds `/terminal/` to the admin panel (port 8882). The shell program only listens on a private socket that nginx can reach; nothing is exposed on the network.
- Each time, enter a fresh authenticator code and press **Unlock & open terminal**. That opens the terminal for 5 minutes (enough to connect); five wrong codes lock you out for ten minutes.
- One session at a time. A session ends after 15 idle minutes or 4 hours. Only a real admin login works: API tokens and "Login as" sessions are refused.
- Every session is recorded under **Recorded sessions** (kept 90 days) and the sign-ins appear in the Audit Log.

**Disable** stops the service and cancels any unlock. Recordings are kept in `/var/log/novacpx/terminal/` (root only).

