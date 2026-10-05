# NovaCPX — API Reference

## Overview

All API endpoints are served under `/api/<resource>/<action>`.

**Base URL pattern:** `https://<server>:<port>/api/<resource>/<action>`

The same API is available on all three panel ports (8880, 8881, 8882). Which actions succeed depends on the role of the authenticated session.

### Authentication

All endpoints (except `auth/login`) require an active session cookie `ncpx_session`. Obtain it by calling `auth/login`.

```http
POST /api/auth/login
Content-Type: application/json

{"username": "admin", "password": "Nova2026!!"}
```

The response sets the `ncpx_session` cookie. Include it in all subsequent requests (`credentials: 'include'` in fetch, or `-b cookies.txt` in curl).

### Response format

Every response is JSON:

```json
{
  "success": true,
  "message": "OK",
  "data": { ... }
}
```

On error:

```json
{
  "success": false,
  "message": "Reason",
  "errors": []
}
```

Paginated responses include a `meta` block:

```json
{
  "success": true,
  "data": { "items": [...], "meta": { "total": 100, "page": 1, "per_page": 25, "pages": 4 } }
}
```

### Role access

| Role | Panels | Access |
|------|--------|--------|
| `admin` | 8882 | Everything |
| `reseller` | 8881 | Own accounts + reseller features |
| `user` | 8880 | Own account only |

### Rate limiting

| Scope | Limit |
|-------|-------|
| Login (`auth/login`) | 10 requests / minute |
| All other endpoints | 120 requests / minute |

Exceeded limits return HTTP 429 with `Retry-After` and `X-RateLimit-*` headers.

---

## auth

### `POST /api/auth/login`

Authenticate and create a session.

**Body:** `{username, password}`  
**Returns:** `{user: {id, username, role}, portal_url}`  
**Access:** Public

### `GET /api/auth/me`

Return the current session's user object.

**Returns:** `{id, username, email, role}`  
**Access:** Any authenticated user

### `POST /api/auth/logout`

Destroy the current session.

**Access:** Any authenticated user

### `POST /api/auth/change-password`

Change the current user's own password.

**Body:** `{current_password, new_password, confirm_password}`  
**Access:** Any authenticated user

---

## accounts

### `GET /api/accounts/list`

List hosting accounts.

**Query params:** `page`, `per_page`, `search`, `status`  
**Returns:** Paginated list with domain_count, email_count, db_count per account  
**Access:** Admin (all accounts), Reseller (own accounts)

### `GET /api/accounts/get?id=<id>`

Get a single account with its domains and disk usage.

**Access:** Admin, Reseller (own accounts)

### `POST /api/accounts/create`

Create a hosting account. Creates Linux user, home directory, vhost, DNS zone, and optionally sends a welcome email.

**Body:**

```json
{
  "username": "john",
  "domain": "example.com",
  "email": "john@example.com",
  "password": "secret",
  "package_id": 1,
  "php_version": "8.3"
}
```

**Access:** Admin, Reseller

### `POST /api/accounts/suspend`

Suspend an account (disables vhost, notifies user).

**Body:** `{id, reason?}`  
**Access:** Admin, Reseller (own accounts)

### `POST /api/accounts/unsuspend`

Re-enable a suspended account.

**Body:** `{id}`  
**Access:** Admin, Reseller (own accounts)

### `POST /api/accounts/terminate`

Permanently delete an account and all its data.

**Body:** `{id}`  
**Access:** Admin only

### `POST /api/accounts/change-password`

Reset an account's panel and system password.

**Body:** `{account_id, password}`  
**Access:** Admin only

### `GET /api/accounts/usage?id=<id>`

Return disk, email, database, domain, and FTP usage vs. package limits.

**Access:** Admin, Reseller (own accounts)

---

## domains

### `GET /api/domains/list?account_id=<id>`

List domains for an account.

**Access:** Admin, Reseller (own accounts), User (own account)

### `POST /api/domains/add`

Add a domain, subdomain, or redirect to an account.

**Body:**

```json
{
  "account_id": 1,
  "domain": "example.com",
  "type": "addon",
  "document_root": "/home/user/public_html/example",
  "php_version": "8.3"
}
```

`type` values: `addon`, `subdomain`, `redirect`

For redirects, include `redirect_to` (URL) and `redirect_code` (301 or 302).

**Access:** Admin, Reseller, User

### `POST /api/domains/remove`

Remove a domain (cannot remove the primary domain).

**Body:** `{account_id, domain_id}`  
**Access:** Admin, Reseller, User

---

## email

### `GET /api/email/list?account_id=<id>`

List email accounts for an account.

**Access:** Admin, Reseller, User

### `POST /api/email/create`

Create a mailbox.

**Body:** `{account_id, email, password, quota_mb?}`  
**Access:** Admin, Reseller, User (subject to `max_email` package limit)

### `DELETE /api/email/delete`

Delete a mailbox.

**Body:** `{id}`  
**Access:** Admin, Reseller, User

### `POST /api/email/suspend`

Suspend a mailbox.

**Body:** `{id}`  
**Access:** Admin, Reseller, User

### `GET /api/webmail/login-url?account_id=<id>&email=<email>`

Generate a single-sign-on webmail URL (valid 5 minutes).

**Access:** Admin, Reseller, User

---

## dns

### `GET /api/dns/list?account_id=<id>`

List DNS zones for an account.

**Access:** Admin, Reseller, User

### `GET /api/dns/records?zone_id=<id>`

List records in a zone.

### `POST /api/dns/add-record`

Add a DNS record.

**Body:**

```json
{
  "zone_id": 1,
  "type": "A",
  "name": "www",
  "value": "1.2.3.4",
  "ttl": 3600
}
```

**Access:** Admin, Reseller, User

### `POST /api/dns/update-record`

Update an existing record.

**Body:** `{record_id, name, value, ttl}`

### `POST /api/dns/delete-record`

Delete a record.

**Body:** `{record_id}`

### `POST /api/dkim/generate`

Generate DKIM key pair for a domain.

**Body:** `{account_id, domain}`

---

## databases

### `GET /api/databases/list?account_id=<id>`

List MySQL databases for an account.

**Access:** Admin, Reseller, User

### `POST /api/databases/create`

Create a database and MySQL user.

**Body:** `{account_id, db_name, db_user, db_pass}`  
**Subject to `max_databases` package limit.**

### `DELETE /api/databases/delete`

Drop a database.

**Body:** `{id}`

---

## ftp

### `GET /api/ftp/list?account_id=<id>`

List FTP accounts.

### `POST /api/ftp/create`

Create an FTP account.

**Body:** `{account_id, username, password, home_dir}`  
**Subject to `max_ftp` package limit.**

### `DELETE /api/ftp/delete`

Delete an FTP account.

**Body:** `{id}`

---

## ssl

### `GET /api/ssl/list?account_id=<id>`

List SSL certificates for an account.

### `POST /api/ssl/issue`

Issue a Let's Encrypt certificate. The domain must resolve publicly.

**Body:** `{account_id, domain}`

### `POST /api/ssl/delete`

Remove an SSL certificate record.

**Body:** `{id}`

---

## files

The file manager API mirrors the user-facing file manager. All paths are validated against the account's home directory; paths outside it are rejected.

### `GET /api/files/list?account_id=<id>&path=<path>`

List directory contents.

### `GET /api/files/read?account_id=<id>&path=<path>`

Read a text file (max 1 MB).

### `POST /api/files/write`

Write/create a file.

**Body:** `{account_id, path, content}`

### `POST /api/files/mkdir`

Create a directory.

**Body:** `{account_id, path}`

### `DELETE /api/files/delete`

Delete a file or directory.

**Body:** `{account_id, path}`

### `POST /api/files/rename`

Rename or move a file.

**Body:** `{account_id, from, to}`

### `POST /api/files/chmod`

Change file permissions (clamped to 0777 max).

**Body:** `{account_id, path, mode}` (e.g. `"mode": "0755"`)

### `POST /api/files/upload`

Upload a file. Multipart form data: `account_id`, `path`, `file`.

---

## cron

### `GET /api/cron/list?account_id=<id>`

List cron jobs for an account.

### `POST /api/cron/save`

Create or update a cron job.

**Body:** `{account_id, id?, schedule, command, enabled}`

### `DELETE /api/cron/delete`

Delete a cron job.

**Body:** `{id}`

---

## packages

### `GET /api/packages/list`

List all hosting packages.

**Access:** Admin, Reseller

### `POST /api/packages/create`

Create a package.

**Body:**

```json
{
  "name": "Starter",
  "disk_mb": 5120,
  "max_email": 10,
  "max_databases": 5,
  "max_ftp": 5,
  "max_domains": 10,
  "max_subdomains": 20
}
```

### `POST /api/packages/update`

Update a package.

**Body:** `{id, ...same fields as create}`

### `DELETE /api/packages/delete`

Delete a package (accounts using it are unaffected).

**Body:** `{id}`

---

## stats

### `GET /api/stats/account?account_id=<id>`

Return usage stats for an account. For user role, `account_id` is inferred automatically.

### `GET /api/stats/server`

Return historical server stats (CPU, RAM, disk) from the last 24 hours. Populated by the `collect-stats.php` cron every 5 minutes.

**Access:** Admin only

---

## sessions

### `GET /api/sessions/list`

List all active sessions (admin sees all, users see their own).

### `DELETE /api/sessions/revoke`

Revoke a specific session.

**Body:** `{session_id}`  
**Access:** Admin

### `DELETE /api/sessions/revoke-user`

Revoke all sessions for a specific user.

**Body:** `{user_id}`  
**Access:** Admin

### `DELETE /api/sessions/revoke-all`

Revoke all active sessions on the panel.

**Access:** Admin

---

## system

All system actions require Admin unless noted.

### `GET /api/system/version`

Return panel version, git commit, PHP version, OS. No auth required.

### `GET /api/system/stats`

Return live CPU, RAM, disk, uptime, and service status.

### `GET /api/system/check-update`

Check GitHub for newer NovaCPX commits.

### `POST /api/system/apply-update`

Pull the latest code and trigger a deploy.

### `GET /api/system/check-os-update`

List available apt package upgrades.

### `POST /api/system/apply-os-update`

Run `apt-get upgrade` and restart any services that went down.

### `GET /api/system/audit-log`

Query the audit log.

**Query params:** `page`, `per_page`, `user`, `action`, `date_from`, `date_to`

### `GET /api/system/server-options`

Return current web/mail/FTP/DNS server selections and detection state.

### `POST /api/system/save-option`

Save a server option.

**Body:** `{key, value}` — allowed keys: `web_server`, `mail_server`, `ftp_server`, `dns_server`, `whmcs_api_key`, `whmcs_enabled`, `ns1_hostname`, `ns2_hostname`

### `GET /api/system/notify-settings`

Return notification settings (API key masked).

### `POST /api/system/save-notify-settings`

Save notification settings.

**Body:** `{gmail_sender?, gmail_client_id?, gmail_client_secret?, gmail_refresh_token?, notify_from_email?, notify_from_name?, notify_admin_email?, notifications_enabled?}`

### `POST /api/system/test-notify`

Send a test email.

**Body:** `{to}`

### `POST /api/system/service-action`

Start, stop, or restart a system service.

**Body:** `{service, action}` — action: `start`, `stop`, `restart`

---

## branding

### `GET /api/branding/get`

Return the branding settings for the current reseller.

**Access:** Reseller (own branding), Admin (pass `reseller_id` param)

### `POST /api/branding/save`

Save branding settings.

**Body:** `{panel_name?, primary_color?, accent_color?, support_email?, support_url?, hide_powered_by?, custom_css?}`

### `POST /api/branding/upload-logo`

Upload a logo image. Multipart: `logo` file field.  
Accepts PNG, JPG, SVG, WEBP (max 512 KB).

### `POST /api/branding/delete-logo`

Remove the current logo and revert to the default SVG.

---

## whmcs

WHMCS billing bridge. Authenticate with `X-WHMCS-Key: <key>` header (configured in Server Options).

### `POST /api/whmcs/create`

Provision a new hosting account.

**Body:** `{domain, username, password, email, package_id?}`

### `POST /api/whmcs/suspend`

Suspend an account by domain.

**Body:** `{domain, reason?}`

### `POST /api/whmcs/unsuspend`

Unsuspend an account by domain.

**Body:** `{domain}`

### `POST /api/whmcs/terminate`

Terminate an account by domain.

**Body:** `{domain}`

### `POST /api/whmcs/changepackage`

Switch an account to a different package.

**Body:** `{domain, package_id}`

### `GET /api/whmcs/info?domain=<domain>`

Return account details for a domain.

---

## docker

### `GET /api/docker/status`

Engine status and container/image counts.

### `GET /api/docker/containers?account_id=<id>`

List containers for an account (or all containers for admin with no account_id).

### `POST /api/docker/container-action`

**Body:** `{container_id, action}` — action: `start`, `stop`, `restart`, `remove`

### `GET /api/docker/logs?container_id=<id>`

Return the last 100 log lines from a container.

### `GET /api/docker/images`

List pulled images.

### `POST /api/docker/pull`

Pull an image.

**Body:** `{image}` e.g. `"image": "nginx:latest"`

### `POST /api/docker/run`

Run a new container.

**Body:** `{account_id, name, image, ports?, env?, volumes?}`  
Container names are namespaced as `novacpx-<username>-<name>`.

### `GET /api/docker/catalog`

Return the one-click app catalog (9 apps: WordPress, Ghost, Nextcloud, Gitea, Matomo, Vaultwarden, Node.js, Flask, static).

### `POST /api/docker/launch-app`

Deploy an app from the catalog.

**Body:** `{account_id, app_id, ...app-specific config}`

---

## firewall

### `GET /api/firewall/rules`

List UFW rules.

**Access:** Admin

### `POST /api/firewall/add-rule`

Add a UFW rule.

**Body:** `{port, protocol, action, from_ip?}`

### `POST /api/firewall/delete-rule`

Remove a rule by number.

**Body:** `{rule_number}`

### `GET /api/firewall/fail2ban`

Return Fail2Ban jail status and banned IPs.

### `POST /api/firewall/unban`

Unban an IP from a jail.

**Body:** `{jail, ip}`

---

## Site Shield

### `GET /api/shield/get`
Current rules for the caller's account (admins/resellers pass `account_id`). Password hashes are never returned.

### `POST /api/shield/save`
Replace the rules. **Body:** `{account_id?, blocked_ips:[], hotlink:{enabled, allowed:[]}, folders:[{path, realm, users:[{name, password?}]}], error_pages:{"404":"/404.html"}}`. A user without `password` keeps the stored one. nginx only.

## Traffic Meter

### `GET /api/traffic/summary`
This month's usage, the package allowance and the last 30 days. Admins/resellers pass `account_id`.

### `GET /api/traffic/overview`
Admin/reseller: month usage for every account they may see.

### `GET|POST /api/traffic/settings`
Admin: `bandwidth_action` = `notify` or `suspend` (what happens at 100%).

## Pulse (uptime)

### `GET /api/pulse/status`
State, response time, 24h uptime and the last 48 checks for each site of the caller's account.

### `GET /api/pulse/overview`
Admin/reseller: every monitored site they may see.

### `GET|POST /api/pulse/settings`
Admin: `{enabled, fail_count}`.

## Sweep (malware scan)

- `GET /api/sweep/status[?account_id=N]` last scan and open findings
- `POST /api/sweep/scan {account_id?}` start a scan in the background
- `POST /api/sweep/quarantine|restore|ignore {finding_id, account_id?}`
- `GET /api/sweep/overview` admin/reseller: findings per account
- `GET|POST /api/sweep/settings` admin: `{clamav}`

## Git Deploy

- `GET /api/gitdeploy/get[?account_id=N]` connection, last deploy, webhook URL and secret
- `POST /api/gitdeploy/save {repo_url, branch?, subdir?, token?, clear_token?, overwrite?}` connect and deploy once
- `POST /api/gitdeploy/deploy` pull the latest now; `POST /api/gitdeploy/secret` new webhook secret; `POST /api/gitdeploy/disconnect`
- `POST /api/githook/run?account=ID` public webhook. Authenticate with an HMAC-SHA256 signature of the body in `X-Hub-Signature-256` (`sha256=<hex>`), `X-Gitea-Signature` or `X-Gogs-Signature`, or `?key=<secret>`. Pushes to other branches are ignored; at most one deploy per 10 seconds.

## Mail queue (admin)

- `GET /api/mailqueue/list`
- `POST /api/mailqueue/action {action: flush|retry|delete|hold|release|purge, id?, confirm_all?}`

## Packages

`POST /api/packages/create|update` also accept `php_max_children` (1-100) and `php_memory_mb` (32-8192). `update` changes only the fields that are sent.

## Firewall (WAF)

- `GET /api/waf/get[?account_id=N]` current mode, enabled rules, exempt paths and the rule catalogue
- `POST /api/waf/save {account_id?, mode: "off"|"block", rules:[slug], exempt:[path]}` rules: `sqli`, `xss`, `traversal`, `inclusion`, `scanners`, `sensitive`, `methods`, `xmlrpc`

## Package tools

`POST /api/packages/create|update` accept `tools: [slug]` (`shield`, `traffic`, `pulse`, `sweep`, `gitdeploy`, `docker`, `wordpress`, `cron`, `backups`); omitting the field leaves it unchanged and sending all slugs means no restriction. `GET /api/auth/me` returns `tools` for customers (`null` = everything). Endpoints of an excluded tool answer 403 to that customer.

## Account transfer (admin)

- `POST /api/transfer/export {account_id}` starts packing; returns `{job}`
- `POST /api/transfer/import {link}` starts an import from a transfer link; returns `{job}`
- `GET /api/transfer/job?id=N` `{status: running|done|failed, result, error}`; an export's result holds `url`, `expires`, `size`; an import's holds `username`, `domain`, `databases`, `notes`
- `GET /api/transfer/log` recent exports and imports
- `GET /api/transferdl/get?token=...` public, single-use download of a bundle (the token is the credential)

## Web terminal (admin)

Only a real admin session works (no API tokens, no impersonation).

- `GET /api/terminal/status` `{enabled, twofa, service, unlocked_for, unlock_seconds, recordings}`
- `POST /api/terminal/enable` installs and starts the terminal; refused until the admin has 2FA on
- `POST /api/terminal/disable`
- `POST /api/terminal/unlock {code}` a fresh authenticator code opens `/terminal/` for 5 minutes; 5 wrong codes lock for 10 minutes
- `GET /api/terminal/log?name=...` one recorded session (text, the last 200 KB)
- `GET /api/terminal/authcheck` 204/403, called by nginx for every `/terminal/` request
