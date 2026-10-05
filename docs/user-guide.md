# NovaCPX — User Guide

## Accessing the User Panel

The user panel runs on port **8880**. Navigate to `https://<server-ip>:8880` and log in with the username and password provided by your hosting provider.

Your browser may warn about the self-signed certificate — accept it to continue.

## Dashboard

The dashboard shows a summary of your account:

- Disk usage vs. your plan limit
- Number of email accounts, databases, domains, and FTP accounts in use
- Recent activity

## Domains

**Domains** shows all domains and subdomains on your account.

### Adding a domain or subdomain

Click **Add Domain** and choose:

- **Addon domain** — a fully separate website hosted on your account
- **Subdomain** — a subdomain of your main domain (e.g. `blog.example.com`)
- **Redirect** — forwards visitors to another URL

Each domain or subdomain gets its own document root directory.

### Removing a domain

You can remove addon domains and subdomains. The primary domain of your account cannot be removed.

## File Manager

The file manager lets you browse, create, edit, upload, and delete files in your account's home directory.

### Navigation

Click any folder to open it. Use the breadcrumb trail at the top to go back up.

### File operations

| Action | How |
|--------|-----|
| Edit a file | Click the filename |
| Create a file | Click **New File** |
| Create a folder | Click **New Folder** |
| Upload | Click **Upload** or drag files onto the panel |
| Download | Select a file → **Download** |
| Rename | Select a file → **Rename** |
| Delete | Select a file → **Delete** |
| Change permissions | Select a file → **chmod** |

Files outside your home directory cannot be accessed.

## Email

**Email** manages mailboxes for your domains.

### Creating a mailbox

Click **Add Email Account**. Enter the local part (the part before `@`) in the text field, then select your domain from the dropdown. Set a password. An optional storage quota limits how much mail the mailbox can hold.

Only domains on your account appear in the dropdown, preventing typos in the address.

### Accessing your email

- **Webmail** — click **Webmail** in the panel sidebar to open Roundcube in a new tab. You are logged in automatically (single sign-on).
- **Email client (Thunderbird, Outlook, Apple Mail, etc.)** — use these settings:
  - Incoming (IMAP): `<server-hostname>`, port 993, SSL/TLS
  - Outgoing (SMTP): `<server-hostname>`, port 587, STARTTLS
  - Username: your full email address (e.g. `you@example.com`)
  - Password: the mailbox password you set in the panel

### Suspending a mailbox

Suspending stops new mail from being delivered but does not delete any messages.

## Databases

**Databases** manages MySQL databases for your account.

### Creating a database

Click **Create Database**. Enter a database name and password. The username is automatically created to match the database name (prefixed with your account username).

Connection details:

| Field | Value |
|-------|-------|
| Host | localhost (from PHP/scripts on the server) |
| Database | as shown in the panel |
| Username | as shown in the panel |
| Password | what you set |
| Port | 3306 |

## FTP

**FTP** creates FTP user accounts for uploading files.

### Creating an FTP account

Click **Add FTP Account**. Set a username, password, and the directory this account can access. The directory must be within your home directory.

### Connecting

Use any FTP client (FileZilla, Cyberduck, etc.):

- Host: `<server-hostname>`
- Port: 21
- Username: the FTP username you created
- Password: the FTP password
- Protocol: FTP with explicit TLS (FTPES)

## DNS

**DNS** lets you manage the DNS records for your domains.

Common tasks:

- **Point a domain to a different IP** — edit or add an A record
- **Set up email (Google Workspace, etc.)** — add or change MX records
- **Verify domain ownership** — add a TXT record
- **Set up a CNAME** — point one name to another

Changes are applied immediately (BIND9 reloads after each change). DNS propagation to the wider internet takes up to 24-48 hours depending on TTL.

## SSL Certificates

**SSL** manages HTTPS certificates for your domains.

Click **Issue Certificate** next to a domain to request a free Let's Encrypt certificate. The domain must be publicly reachable (DNS must resolve to this server's IP) for the certificate to be issued.

Certificates are renewed automatically 30 days before expiry. You will receive an email warning if a certificate is about to expire and has not been renewed.

## Cron Jobs

**Cron** schedules recurring tasks on the server.

### Adding a cron job

Click **Add Cron Job**. Enter:

- **Schedule** — standard cron expression (e.g. `0 * * * *` for every hour)
- **Command** — the shell command or PHP script to run
- **Enabled** — toggle on/off without deleting

Common schedules:

| Expression | Runs |
|------------|------|
| `* * * * *` | Every minute |
| `0 * * * *` | Every hour |
| `0 0 * * *` | Daily at midnight |
| `0 0 * * 0` | Weekly on Sunday |
| `0 0 1 * *` | Monthly on the 1st |

## PHP

If your account has access to multiple PHP versions, the **PHP** section lets you switch the PHP version used for your account and configure per-account `php.ini` overrides (memory limit, upload size, execution time, etc.).

## Docker

If Docker is enabled for your account, **Docker** shows your containers.

From this page you can:

- **Start / stop / restart** containers
- **View logs** from a container
- **Launch an app** from the one-click catalog:
  - WordPress
  - Ghost
  - Nextcloud
  - Gitea
  - Matomo (analytics)
  - Vaultwarden (password manager)
  - Node.js app
  - Flask app
  - Static website (Nginx)

Your Docker quota (max containers, RAM, CPU) is set by your hosting provider.

## Account Settings

**Settings** lets you change your own panel password.

To change your password:

1. Go to **Settings**
2. Enter your current password
3. Enter and confirm your new password
4. Click **Save**

Your new password takes effect immediately. If you also use FTP or SSH with this account, those passwords are updated as well.

## Site Shield

Open **Site Shield** in the sidebar to protect your site without editing any files.

- **Blocked addresses** — one IP address or range (for example `203.0.113.0/24`) per line; those visitors get an error page.
- **Hotlink guard** — stops other websites from embedding your images, video, audio, PDFs and zip files. Add partner domains that may still use them.
- **Password-locked folders** — pick a folder (for example `/members`), a prompt text, and one or more users with passwords (8+ characters). Visitors must sign in to open it.
- **Custom error pages** — point 404, 403, 500 and 503 at a page inside your site.

Press **Save & apply**. If the web server rejects a change, nothing is changed and the reason is shown.

## Traffic

**Traffic** shows how much your site served this month, your package allowance, and a 30-day chart. You get an email at 80% and 100% of the allowance. Depending on the server settings, reaching 100% can also suspend the account.

## Uptime

**Uptime** shows whether each of your sites is answering, its 24-hour uptime, response times and the last 48 checks. You get an email when a site stops answering and another when it recovers.

## Scheduled backups

Under **Backups** you can set how often a backup runs (hourly, daily, weekly, monthly), what it contains (full, files, database) and how many copies to keep.

## Sweep

**Sweep** checks your site's PHP files for the usual signs of web shells and backdoors. It runs every night and when you press **Scan now**. Each finding shows the file, what was found and a severity. Choose **Quarantine** to move the file out of your website (you can **Restore** it later) or **Harmless** if you know the file is fine; harmless findings stay quiet in later scans.

## Git Deploy

**Git Deploy** keeps your site in step with a Git repository.

1. Enter the repository address (https), the branch, and optionally a folder inside `public_html`. For a private repository add an access token (it is stored where only the server can read it).
2. If the folder already has files, tick **Replace existing files** to connect anyway (files tracked by the repository are overwritten, other files stay).
3. Press **Connect & deploy**. Later, press **Deploy now**, or add the shown webhook (JSON, push events) to your repository so every push deploys automatically.

## Firewall

**Firewall** refuses requests that look like attacks before they reach your site: database commands hidden in a web address, script tags, attempts to read system files, well-known attack scanners, requests for files such as `.env` or `.git`, and the WordPress XML-RPC door. Tick **Block suspicious requests**, choose the rules you want and press **Save & apply**. If a rule blocks something legitimate (for example an editor that posts code), list that page under **Pages the rules skip**. The firewall is a first line of defence; keep your site's software up to date as well.

Tools that your hosting package does not include are hidden from the menu.
