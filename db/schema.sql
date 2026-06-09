-- NovaCPX Database Schema v1.1.0
-- Engine: SQLite 3.35+

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = OFF;

-- ── Version tracking ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS novacpx_version (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  version      TEXT NOT NULL,
  installed_at TEXT DEFAULT (datetime('now')),
  notes        TEXT,
  git_commit   TEXT
);

INSERT OR IGNORE INTO novacpx_version (version, notes, git_commit)
VALUES ('1.1.0', 'Initial installation', 'HEAD');

-- ── Audit log ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER,
  username   TEXT,
  action     TEXT NOT NULL,
  resource   TEXT,
  detail     TEXT,
  ip_address TEXT,
  user_agent TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_user    ON audit_log (user_id);
CREATE INDEX IF NOT EXISTS idx_audit_action  ON audit_log (action);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at);

-- ── Users ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  password      TEXT NOT NULL,
  email         TEXT NOT NULL UNIQUE,
  role          TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('admin','reseller','user')),
  status        TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended','pending')),
  reseller_id   INTEGER DEFAULT NULL,
  package_id    INTEGER DEFAULT NULL,
  theme         TEXT DEFAULT 'nova-dark',
  language      TEXT DEFAULT 'en',
  contact_name  TEXT,
  contact_phone TEXT,
  last_login          TEXT,
  created_at          TEXT DEFAULT (datetime('now')),
  updated_at          TEXT,
  totp_secret         TEXT,
  totp_enabled        INTEGER DEFAULT 0,
  totp_backup_codes   TEXT,
  FOREIGN KEY (reseller_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_users_role     ON users (role);
CREATE INDEX IF NOT EXISTS idx_users_status   ON users (status);
CREATE INDEX IF NOT EXISTS idx_users_reseller ON users (reseller_id);

-- ── Sessions ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sessions (
  id              TEXT PRIMARY KEY,
  user_id         INTEGER NOT NULL,
  ip_address      TEXT,
  user_agent      TEXT,
  data            TEXT,
  expires_at      TEXT NOT NULL,
  impersonator_id INTEGER DEFAULT NULL,
  created_at      TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions (expires_at);

-- ── Packages / Hosting Plans ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS packages (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  name                TEXT NOT NULL,
  owner_id            INTEGER DEFAULT NULL,
  disk_mb             INTEGER DEFAULT 1024,
  bandwidth_mb        INTEGER DEFAULT 10240,
  max_domains         INTEGER DEFAULT 1,
  max_subdomains      INTEGER DEFAULT 10,
  max_addon_domains   INTEGER DEFAULT 0,
  max_parked_domains  INTEGER DEFAULT 5,
  max_email           INTEGER DEFAULT 10,
  max_ftp             INTEGER DEFAULT 5,
  max_databases       INTEGER DEFAULT 5,
  php_version         TEXT DEFAULT '8.3',
  ssl_enabled         INTEGER DEFAULT 1,
  is_default          INTEGER DEFAULT 0,
  created_at          TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_packages_owner ON packages (owner_id);

INSERT OR IGNORE INTO packages (id, name, disk_mb, bandwidth_mb, max_domains, max_email, max_databases, is_default)
VALUES (1, 'Default', 5120, 51200, 5, 25, 10, 1);

-- ── Hosting Accounts ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS accounts (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id        INTEGER NOT NULL UNIQUE,
  username       TEXT NOT NULL UNIQUE,
  domain         TEXT NOT NULL,
  home_dir       TEXT NOT NULL,
  document_root  TEXT,
  system_user    TEXT DEFAULT 'www-data',
  package_id     INTEGER,
  disk_used_mb   INTEGER DEFAULT 0,
  bw_used_mb     INTEGER DEFAULT 0,
  php_version    TEXT DEFAULT '8.3',
  web_server     TEXT DEFAULT 'apache' CHECK(web_server IN ('apache','nginx')),
  status         TEXT DEFAULT 'active' CHECK(status IN ('active','suspended','terminated')),
  cf_api_key     TEXT,
  cf_api_email   TEXT,
  cf_zone_id     TEXT,
  created_at     TEXT DEFAULT (datetime('now')),
  suspended_at   TEXT DEFAULT NULL,
  FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_accounts_domain ON accounts (domain);
CREATE INDEX IF NOT EXISTS idx_accounts_status ON accounts (status);

-- ── Domains ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS domains (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id    INTEGER NOT NULL,
  domain        TEXT NOT NULL UNIQUE,
  type          TEXT DEFAULT 'main' CHECK(type IN ('main','addon','subdomain','parked','alias')),
  document_root TEXT,
  php_version   TEXT,
  ssl_enabled   INTEGER DEFAULT 0,
  ssl_cert      TEXT,
  ssl_key       TEXT,
  ssl_chain     TEXT,
  ssl_expires   TEXT,
  redirect_to   TEXT DEFAULT NULL,
  created_at    TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_domains_account ON domains (account_id);
CREATE INDEX IF NOT EXISTS idx_domains_type    ON domains (type);

-- ── DNS Zones & Records ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dns_zones (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id   INTEGER NOT NULL,
  domain       TEXT NOT NULL UNIQUE,
  serial       INTEGER DEFAULT 1,
  primary_ns   TEXT DEFAULT 'ns1.localhost',
  secondary_ns TEXT DEFAULT 'ns2.localhost',
  admin_email  TEXT DEFAULT 'hostmaster@localhost',
  ttl          INTEGER DEFAULT 3600,
  refresh      INTEGER DEFAULT 86400,
  retry        INTEGER DEFAULT 7200,
  expire       INTEGER DEFAULT 2419200,
  minimum      INTEGER DEFAULT 86400,
  status       TEXT DEFAULT 'active' CHECK(status IN ('active','disabled')),
  created_at   TEXT DEFAULT (datetime('now')),
  updated_at   TEXT,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_dns_zones_account ON dns_zones (account_id);

CREATE TABLE IF NOT EXISTS dns_records (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  zone_id    INTEGER NOT NULL,
  name       TEXT NOT NULL,
  type       TEXT NOT NULL CHECK(type IN ('A','AAAA','CNAME','MX','TXT','SRV','NS','PTR','CAA','DKIM','SPF','DMARC')),
  content    TEXT NOT NULL,
  ttl        INTEGER DEFAULT 3600,
  priority   INTEGER DEFAULT NULL,
  proxied    INTEGER DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (zone_id) REFERENCES dns_zones(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_dns_records_zone ON dns_records (zone_id);
CREATE INDEX IF NOT EXISTS idx_dns_records_type ON dns_records (type);

-- ── Email ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS email_accounts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  email      TEXT NOT NULL UNIQUE,
  password   TEXT NOT NULL,
  quota_mb   INTEGER DEFAULT 500,
  used_mb    INTEGER DEFAULT 0,
  status     TEXT DEFAULT 'active' CHECK(status IN ('active','suspended')),
  created_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_email_accounts_account ON email_accounts (account_id);

CREATE TABLE IF NOT EXISTS email_forwarders (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id  INTEGER NOT NULL,
  source      TEXT NOT NULL,
  destination TEXT NOT NULL,
  created_at  TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS email_autoresponders (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  email      TEXT NOT NULL,
  subject    TEXT,
  body       TEXT,
  is_active  INTEGER DEFAULT 1,
  start_at   TEXT,
  stop_at    TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- ── Databases ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS databases (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  db_name    TEXT NOT NULL,
  db_user    TEXT NOT NULL,
  db_pass    TEXT NOT NULL,
  db_type    TEXT DEFAULT 'mysql' CHECK(db_type IN ('mysql','postgresql')),
  size_mb    REAL DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_databases_account ON databases (account_id);

-- ── FTP Accounts ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ftp_accounts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  username   TEXT NOT NULL UNIQUE,
  password   TEXT NOT NULL,
  home_dir   TEXT NOT NULL,
  quota_mb   INTEGER DEFAULT 0,
  status     TEXT DEFAULT 'active' CHECK(status IN ('active','suspended')),
  created_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- ── SSL Certificates ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ssl_certs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id  INTEGER NOT NULL,
  domain      TEXT NOT NULL,
  type        TEXT DEFAULT 'lets_encrypt' CHECK(type IN ('lets_encrypt','self_signed','custom')),
  cert        TEXT,
  private_key TEXT,
  chain       TEXT,
  issued_at   TEXT,
  expires_at  TEXT,
  auto_renew  INTEGER DEFAULT 1,
  status      TEXT DEFAULT 'pending' CHECK(status IN ('active','expired','pending','failed')),
  created_at  TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_ssl_certs_domain  ON ssl_certs (domain);
CREATE INDEX IF NOT EXISTS idx_ssl_certs_expires ON ssl_certs (expires_at);

-- ── Cron Jobs ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cron_jobs (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  command    TEXT NOT NULL,
  minute     TEXT DEFAULT '*',
  hour       TEXT DEFAULT '*',
  day        TEXT DEFAULT '*',
  month      TEXT DEFAULT '*',
  weekday    TEXT DEFAULT '*',
  is_active  INTEGER DEFAULT 1,
  last_run   TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- ── PHP Configuration ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS php_configs (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id          INTEGER NOT NULL UNIQUE,
  php_version         TEXT DEFAULT '8.3',
  memory_limit        TEXT DEFAULT '256M',
  max_execution_time  INTEGER DEFAULT 30,
  upload_max_filesize TEXT DEFAULT '64M',
  post_max_size       TEXT DEFAULT '64M',
  max_input_vars      INTEGER DEFAULT 1000,
  display_errors      INTEGER DEFAULT 0,
  error_reporting     TEXT DEFAULT 'E_ALL & ~E_NOTICE',
  extensions          TEXT,
  updated_at          TEXT,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- ── Backups ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS backups (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id   INTEGER NOT NULL,
  filename     TEXT NOT NULL,
  size_mb      REAL DEFAULT 0,
  type         TEXT DEFAULT 'full' CHECK(type IN ('full','partial','db_only','files_only')),
  status       TEXT DEFAULT 'pending' CHECK(status IN ('pending','running','complete','failed')),
  storage      TEXT DEFAULT 'local' CHECK(storage IN ('local','s3','ftp','sftp')),
  created_at   TEXT DEFAULT (datetime('now')),
  completed_at TEXT,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_backups_account ON backups (account_id);
CREATE INDEX IF NOT EXISTS idx_backups_status  ON backups (status);

CREATE TABLE IF NOT EXISTS backup_schedules (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id   INTEGER NOT NULL UNIQUE,
  frequency    TEXT DEFAULT 'daily' CHECK(frequency IN ('hourly','daily','weekly','monthly')),
  type         TEXT DEFAULT 'full' CHECK(type IN ('full','files','database')),
  retain_count INTEGER DEFAULT 7,
  last_run     TEXT,
  created_at   TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- ── Server Stats ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS server_stats (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  cpu_usage   REAL,
  ram_usage   REAL,
  disk_usage  REAL,
  load_avg    REAL,
  load_5m     REAL,
  load_15m    REAL,
  net_in_kb   INTEGER DEFAULT 0,
  net_out_kb  INTEGER DEFAULT 0,
  recorded_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_server_stats_recorded ON server_stats (recorded_at);

-- ── Notifications ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER,
  title      TEXT NOT NULL,
  message    TEXT NOT NULL,
  type       TEXT DEFAULT 'info' CHECK(type IN ('info','success','warning','error')),
  is_read    INTEGER DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_notifications_user   ON notifications (user_id);
CREATE INDEX IF NOT EXISTS idx_notifications_unread ON notifications (is_read);

-- ── Email Templates ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS email_templates (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  trigger_key TEXT NOT NULL UNIQUE,
  label       TEXT NOT NULL,
  subject     TEXT NOT NULL,
  body_html   TEXT NOT NULL,
  body_text   TEXT,
  enabled     INTEGER DEFAULT 1,
  updated_at  TEXT DEFAULT (datetime('now'))
);
INSERT OR IGNORE INTO email_templates (trigger_key, label, subject, body_html, body_text) VALUES
  ('account_created', 'Account Created (to user)', 'Your NovaCPX hosting account is ready',
   '<h2>Welcome, {{name}}!</h2><p>Your hosting account for <strong>{{domain}}</strong> has been created.</p><p><b>Login:</b> {{username}}<br><b>Password:</b> {{password}}</p><p>Log in at <a href="{{panel_url}}">{{panel_url}}</a></p>',
   'Welcome, {{name}}! Your hosting account for {{domain}} has been created. Login: {{username}} / {{password}} at {{panel_url}}'),
  ('account_created_admin', 'Account Created (to admin)', 'New account created: {{domain}}',
   '<h2>New account created</h2><p><b>Domain:</b> {{domain}}<br><b>User:</b> {{username}}<br><b>Package:</b> {{package}}<br><b>Created by:</b> {{created_by}}</p>',
   'New account created: domain={{domain}} user={{username}} package={{package}}'),
  ('account_suspended', 'Account Suspended (to user)', 'Your hosting account has been suspended',
   '<h2>Account Suspended</h2><p>Your hosting account for <strong>{{domain}}</strong> has been suspended.</p><p>Reason: {{reason}}</p><p>Contact <a href="mailto:{{support_email}}">{{support_email}}</a> to resolve this.</p>',
   'Your account for {{domain}} has been suspended. Reason: {{reason}}. Contact {{support_email}}.'),
  ('account_terminated', 'Account Terminated (to user)', 'Your hosting account has been terminated',
   '<h2>Account Terminated</h2><p>Your hosting account for <strong>{{domain}}</strong> has been permanently terminated. All data has been deleted.</p>',
   'Your account for {{domain}} has been permanently terminated.'),
  ('password_reset', 'Password Reset', 'NovaCPX password reset request',
   '<h2>Password Reset</h2><p>A password reset was requested for your account. Click below to reset your password:</p><p><a href="{{reset_url}}">Reset Password</a></p><p>This link expires in 1 hour. If you did not request this, ignore this email.</p>',
   'Password reset requested. Visit {{reset_url}} to reset your password (expires in 1 hour).'),
  ('ssl_expiring', 'SSL Certificate Expiring', 'SSL certificate for {{domain}} expires in {{days}} days',
   '<h2>SSL Certificate Expiring Soon</h2><p>The SSL certificate for <strong>{{domain}}</strong> will expire in <strong>{{days}} days</strong> on {{expiry_date}}.</p><p>Log in to renew: <a href="{{panel_url}}">{{panel_url}}</a></p>',
   'SSL certificate for {{domain}} expires in {{days}} days on {{expiry_date}}.'),
  ('disk_warning', 'Disk Usage Warning', 'Disk usage warning for {{domain}}: {{usage}}% used',
   '<h2>Disk Usage Warning</h2><p>Your hosting account for <strong>{{domain}}</strong> is at <strong>{{usage}}%</strong> disk capacity ({{used}} of {{quota}} used).</p><p>Please free up space or upgrade your package.</p>',
   'Disk warning: {{domain}} is at {{usage}}% capacity ({{used}} of {{quota}}).'),
  ('smtp_test', 'SMTP Test', 'NovaCPX SMTP Test Email',
   '<h2>SMTP Test Successful</h2><p>This is a test email from <strong>NovaCPX</strong>. If you received this, your SMTP configuration is working correctly.</p>',
   'NovaCPX SMTP test email. SMTP is configured correctly.');

-- ── API Tokens ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS api_tokens (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL,
  name        TEXT NOT NULL,
  token       TEXT NOT NULL UNIQUE,
  permissions TEXT,
  last_used   TEXT,
  expires_at  TEXT,
  created_at  TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_api_tokens_token ON api_tokens (token);

-- ── Settings ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
  `key`      TEXT PRIMARY KEY,
  `value`    TEXT,
  updated_at TEXT
);

INSERT OR IGNORE INTO settings (`key`, `value`) VALUES
  ('panel_name',           'NovaCPX'),
  ('panel_version',        '1.1.0'),
  ('default_nameserver1',  'ns1.localhost'),
  ('default_nameserver2',  'ns2.localhost'),
  ('default_php',          '8.3'),
  ('mail_enabled',         '1'),
  ('ftp_enabled',          '1'),
  ('dns_enabled',          '1'),
  ('backup_dir',           '/var/novacpx/backups'),
  ('update_channel',       'stable'),
  ('git_remote',           'https://github.com/myronblair/novacpx.git'),
  ('proxy_mode',           'disabled'),
  ('proxy_apache_port',    '80');

-- ── DKIM Keys ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dkim_keys (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id       INTEGER NOT NULL,
  domain           TEXT NOT NULL UNIQUE,
  selector         TEXT NOT NULL DEFAULT 'mail',
  public_key       TEXT NOT NULL,
  private_key_path TEXT NOT NULL,
  created_at       TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- ── Rate Limits ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS api_rate_limits (
  ip           TEXT NOT NULL,
  endpoint     TEXT NOT NULL,
  hits         INTEGER NOT NULL DEFAULT 1,
  window_start INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (ip, endpoint)
);

-- ── Proxy Hosts ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS proxy_hosts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id    INTEGER,
  domain        TEXT NOT NULL UNIQUE,
  upstream      TEXT NOT NULL,
  ssl_enabled   INTEGER NOT NULL DEFAULT 0,
  enabled       INTEGER NOT NULL DEFAULT 1,
  custom_config TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
);

-- ── Reseller Branding ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reseller_branding (
  user_id         INTEGER PRIMARY KEY,
  panel_name      TEXT NOT NULL DEFAULT 'NovaCPX',
  logo_url        TEXT,
  favicon_url     TEXT,
  primary_color   TEXT NOT NULL DEFAULT '#6366f1',
  accent_color    TEXT NOT NULL DEFAULT '#0ea5e9',
  support_email   TEXT,
  support_url     TEXT,
  hide_powered_by INTEGER NOT NULL DEFAULT 0,
  custom_css      TEXT,
  updated_at      TEXT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── Webmail SSO Tokens ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS webmail_sso_tokens (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  token      TEXT NOT NULL UNIQUE,
  email      TEXT NOT NULL,
  enc_pass   TEXT NOT NULL,
  expires_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_webmail_sso_expires ON webmail_sso_tokens (expires_at);

-- ── WordPress Installs ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS wordpress_installs (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  domain     TEXT NOT NULL,
  path       TEXT DEFAULT '/',
  db_name    TEXT,
  db_user    TEXT,
  db_pass    TEXT,
  wp_version TEXT,
  admin_user TEXT,
  admin_email TEXT,
  status     TEXT DEFAULT 'active' CHECK(status IN ('active','updating','suspended')),
  staging_of INTEGER,
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_wp_installs_account ON wordpress_installs (account_id);

-- ── Docker ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS docker_containers (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id   INTEGER NOT NULL,
  container_id TEXT,
  name         TEXT NOT NULL,
  image        TEXT NOT NULL,
  app_key      TEXT,
  status       TEXT DEFAULT 'pending' CHECK(status IN ('running','stopped','error','pending')),
  ports        TEXT,
  memory_mb    INTEGER,
  cpus         REAL,
  created_at   TEXT DEFAULT (datetime('now')),
  updated_at   TEXT,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_docker_containers_account ON docker_containers (account_id);

CREATE TABLE IF NOT EXISTS docker_compose_stacks (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id   INTEGER,
  name         TEXT NOT NULL,
  stack_dir    TEXT NOT NULL,
  compose_file TEXT NOT NULL,
  status       TEXT DEFAULT 'pending' CHECK(status IN ('running','stopped','error','pending')),
  created_at   TEXT DEFAULT (datetime('now')),
  updated_at   TEXT,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS docker_quotas (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id        INTEGER NOT NULL UNIQUE,
  max_containers INTEGER DEFAULT 2,
  max_memory_mb  INTEGER DEFAULT 512,
  max_cpus       REAL DEFAULT 1.0,
  created_at     TEXT DEFAULT (datetime('now')),
  updated_at     TEXT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── Features ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS features (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  slug             TEXT NOT NULL UNIQUE,
  name             TEXT NOT NULL,
  description      TEXT,
  category         TEXT NOT NULL,
  enabled          INTEGER DEFAULT 0,
  installed        INTEGER DEFAULT 0,
  install_cmd      TEXT,
  uninstall_cmd    TEXT,
  config_keys      TEXT,
  install_pid      INTEGER,
  install_log      TEXT,
  requires         TEXT,
  requires_restart INTEGER DEFAULT 0,
  min_ram_mb       INTEGER DEFAULT 0,
  updated_at       TEXT
);
CREATE INDEX IF NOT EXISTS idx_features_category ON features (category);
CREATE INDEX IF NOT EXISTS idx_features_enabled  ON features (enabled);

PRAGMA foreign_keys = ON;
