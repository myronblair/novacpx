# NovaCPX — Documentation

NovaCPX is a full-featured open-source Linux web hosting control panel. It replaces cPanel/Plesk with a modern three-tier architecture (Admin → Reseller → End User), runs entirely on your own server, and has no per-account licensing fees.

---

## Feature Overview

### Hosting Management
- **Multi-account architecture** — Admin, Reseller, and User tiers with strict isolation
- **Hosting packages** — disk, email, database, FTP, domain, and subdomain quotas per plan
- **Per-account PHP version** — PHP 7.4, 8.1, 8.2, 8.3 via PHP-FPM pools; custom php.ini overrides
- **Wildcard vhost support** — Apache and Nginx backends; per-account vhost files auto-generated
- **Account suspend / unsuspend** — disables vhost and notifies the account holder
- **WHMCS billing bridge** — provision, suspend, terminate, and change packages from WHMCS automatically

### Domains & DNS
- **Addon domains, subdomains, redirects** — unlimited per account (within package limits)
- **Full DNS manager** — BIND9 or PowerDNS backend; A, AAAA, CNAME, MX, TXT, NS, SRV, CAA records
- **Nameserver health checker** — verify NS1/NS2 resolve correctly after setup
- **Auto-provisioning** — DNS zone, vhost, and Linux user created automatically at account creation

### Email
- **Virtual mailboxes** — Postfix + Dovecot backend; SHA-512 hashed passwords; Maildir storage
- **IMAP/SMTP access** — IMAP :993 SSL/TLS, SMTP :587 STARTTLS
- **Webmail (Roundcube)** — built-in at port 8883 with single sign-on (SSO) from user panel
- **DKIM signing** — auto-provisioned per domain; OpenDKIM wired into Postfix milter
- **SPF/DMARC records** — added to DNS zone automatically on account creation
- **Optional Rspamd** — postfix-dovecot-rspamd stack available in Server Options
- **Domain dropdown** — email creation UI shows selectable domain list (no typos)

### Databases
- **MySQL / MariaDB** — per-account databases with isolated users; phpMyAdmin link
- **PostgreSQL** — optional; pgAdmin link when installed

### File Management
- **In-browser file manager** — browse, create, edit, upload, download, rename, delete, chmod
- **Path sandboxing** — users cannot access files outside their home directory
- **FTP accounts** — ProFTPD, vsftpd, or PureFTPD (swappable in Server Options); explicit TLS

### SSL Certificates
- **Let's Encrypt (Certbot)** — free certificates issued and auto-renewed per domain
- **Certificate status dashboard** — days remaining, expiry alerts at ≤14 days
- **Self-signed fallback** — panel runs on a self-signed cert with correct IP SAN by default

### Security
- **Fail2Ban** — 5 active jails: SSH, panel auth, API abuse, PHP errors, Postfix SMTP
- **UFW firewall manager** — allow/deny rules by port, protocol, and source IP from admin panel
- **API rate limiting** — 10 req/min on auth, 120 req/min on API; 429 with Retry-After header
- **Two-factor authentication (TOTP)** — admin/reseller login; admin can reset any user's 2FA
- **Session management** — view and revoke active sessions per user
- **Audit log** — every API action logged with user, IP, payload; filterable by user/action/date

### Docker
- **Docker Engine management** — install from panel; container/image/volume/network CRUD
- **Compose stacks** — create from YAML, start/stop/remove, live streaming logs
- **One-click app catalog** — 9 templates: WordPress, Ghost, Nextcloud, Gitea, Matomo, Vaultwarden, Node.js, Flask, Static Nginx
- **Per-user quotas** — admin sets max containers, CPU, and RAM per account
- **Reseller allocation** — resellers configure Docker limits for their own customers
- **Async launch** — image pulls run in background so PHP never times out

### Server Monitoring
- **Real-time stats** — CPU, RAM, disk, uptime on admin dashboard (polled via API)
- **Historical charts** — Chart.js graphs of CPU and RAM over time (5-minute cron samples)
- **Service health** — Apache/Nginx/MySQL/Postfix/Dovecot/FTP/DNS status with restart controls
- **JARVIS integration** — optional agent sends live metrics to the JARVIS AI dashboard

### Updates & Versioning
- **Update channels** — **Stable** (main branch, major/minor releases) or **Beta** (beta branch, patch/pre-release)
- **One-click update** — `git pull` → PHP syntax check → deploy → auto-restore if panel goes down
- **Version history** — every deploy recorded with version number, commit hash, and timestamp
- **Nightly cache** — update checks cached for 12 hours; nightly cron pre-warms cache at 2am
- **OS upgrades** — `apt-get upgrade` with pre-backup, service health check, and live log streaming
- **GitHub Actions** — pushes to `main` auto-bump PATCH version; pushes to `beta` auto-append `-beta.N`

### Reseller Features
- **White-label branding** — custom logo upload (PNG/SVG), accent color picker with live preview, custom CSS, support email/URL, hide "Powered by" toggle
- **Customer account CRUD** — create, suspend, unsuspend, terminate customer accounts
- **Docker quota management** — per-customer container/CPU/RAM limits
- **Strict isolation** — resellers only see their own accounts

### Panel Configuration
- **Settings page** — panel name, default PHP version, nameservers, update channel; all values loaded from DB, saved individually
- **Server Options** — swap web/mail/FTP/DNS backends without touching config files
- **Notifications** — CyberMail API for welcome emails, suspension notices, disk warnings, SSL expiry; test button in panel
- **Backups** — per-account file + database backup; download or restore; optional rclone/S3 remote destination
- **Cloudflare integration** — per-account API key; sync DNS records, toggle CDN proxy per record
- **Nginx Proxy Manager** — Docker-based reverse proxy for additional services

### Developer / Automation
- **REST API** — 25+ endpoints; all documented in [api-reference.md](api-reference.md)
- **Bearer token auth** — create API tokens for scripts and integrations
- **WHMCS module** — full billing bridge for automated provisioning
- **Auto-deploy webhook** — GitHub push → webhook → git pull + PHP syntax check + DB migrations
- **SQLite database** — no MySQL required for the panel itself; survives database server restarts

---

## Panels

| Panel | Port | Audience |
|-------|------|----------|
| Admin | 8882 | Server administrators |
| Reseller | 8881 | Reseller accounts |
| User | 8880 | End-user hosting accounts |
| Webmail (Roundcube) | 8883 | Email users (SSO from user panel) |

---

## Documentation

| Guide | Audience |
|-------|----------|
| [Installation Guide](install.md) | Server admins — requirements, installer, auto-deploy setup |
| [Admin Guide](admin-guide.md) | Full admin panel feature reference |
| [Reseller Guide](reseller-guide.md) | Reseller account and branding management |
| [User Guide](user-guide.md) | End-user features: files, email, databases, Docker, etc. |
| [API Reference](api-reference.md) | Full REST API with auth, rate limits, and all endpoints |

---

## Source

GitHub: [myronblair/novacpx](https://github.com/myronblair/novacpx) (private)
