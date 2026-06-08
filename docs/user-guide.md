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

Click **Add Email Account**. Enter the local part (the part before `@`), select the domain, and set a password. An optional storage quota limits how much mail the mailbox can hold.

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
