-- NovaCPX Database Schema v1.0.0
-- Engine: MySQL 8+ | Charset: utf8mb4_unicode_ci

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── Version tracking ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS novacpx_version (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version      VARCHAR(20) NOT NULL,
  installed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  notes        TEXT,
  git_commit   VARCHAR(64),
  INDEX idx_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO novacpx_version (version, notes, git_commit)
VALUES ('1.0.0', 'Initial installation', 'HEAD');

-- ── Audit log (every action tracked) ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED,
  username   VARCHAR(100),
  action     VARCHAR(100) NOT NULL,
  resource   VARCHAR(200),
  detail     JSON,
  ip_address VARCHAR(45),
  user_agent TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user    (user_id),
  INDEX idx_action  (action),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Users (admin, resellers, end-users) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(100) NOT NULL UNIQUE,
  password      VARCHAR(255) NOT NULL,
  email         VARCHAR(255) NOT NULL UNIQUE,
  role          ENUM('admin','reseller','user') DEFAULT 'user',
  status        ENUM('active','suspended','pending') DEFAULT 'active',
  reseller_id   INT UNSIGNED DEFAULT NULL,
  package_id    INT UNSIGNED DEFAULT NULL,
  theme         VARCHAR(50) DEFAULT 'nova-dark',
  language      VARCHAR(10) DEFAULT 'en',
  contact_name  VARCHAR(200),
  contact_phone VARCHAR(50),
  last_login    DATETIME,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (reseller_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_role     (role),
  INDEX idx_status   (status),
  INDEX idx_reseller (reseller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sessions ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sessions (
  id         VARCHAR(128) PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  ip_address VARCHAR(45),
  user_agent TEXT,
  data       JSON,
  expires_at DATETIME NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Packages / Hosting Plans ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS packages (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(100) NOT NULL,
  owner_id         INT UNSIGNED DEFAULT NULL,  -- NULL = system, or reseller
  disk_mb          INT UNSIGNED DEFAULT 1024,
  bandwidth_mb     BIGINT UNSIGNED DEFAULT 10240,
  max_domains      SMALLINT UNSIGNED DEFAULT 1,
  max_subdomains   SMALLINT UNSIGNED DEFAULT 10,
  max_addon_domains SMALLINT UNSIGNED DEFAULT 0,
  max_parked_domains SMALLINT UNSIGNED DEFAULT 5,
  max_email        SMALLINT UNSIGNED DEFAULT 10,
  max_ftp          SMALLINT UNSIGNED DEFAULT 5,
  max_databases    SMALLINT UNSIGNED DEFAULT 5,
  php_version      VARCHAR(10) DEFAULT '8.3',
  ssl_enabled      TINYINT(1) DEFAULT 1,
  is_default       TINYINT(1) DEFAULT 0,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO packages (name, disk_mb, bandwidth_mb, max_domains, max_email, max_databases, is_default)
VALUES ('Default', 5120, 51200, 5, 25, 10, 1);

-- ── Hosting Accounts ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS accounts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL UNIQUE,
  username     VARCHAR(32) NOT NULL UNIQUE,
  domain       VARCHAR(253) NOT NULL,
  home_dir     VARCHAR(500) NOT NULL,
  package_id   INT UNSIGNED,
  disk_used_mb INT UNSIGNED DEFAULT 0,
  bw_used_mb   BIGINT UNSIGNED DEFAULT 0,
  php_version  VARCHAR(10) DEFAULT '8.3',
  web_server   ENUM('apache','nginx') DEFAULT 'apache',
  status       ENUM('active','suspended','terminated') DEFAULT 'active',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  suspended_at DATETIME DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL,
  INDEX idx_domain (domain),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Domains ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS domains (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  domain      VARCHAR(253) NOT NULL,
  type        ENUM('main','addon','subdomain','parked','alias') DEFAULT 'main',
  document_root VARCHAR(500),
  php_version VARCHAR(10),
  ssl_enabled TINYINT(1) DEFAULT 0,
  ssl_cert    TEXT,
  ssl_key     TEXT,
  ssl_chain   TEXT,
  ssl_expires DATE,
  redirect_to VARCHAR(500) DEFAULT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  UNIQUE KEY  uq_domain (domain),
  INDEX idx_account (account_id),
  INDEX idx_type    (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── DNS Zones & Records ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS dns_zones (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  domain      VARCHAR(253) NOT NULL UNIQUE,
  serial      BIGINT UNSIGNED DEFAULT 1,
  primary_ns  VARCHAR(253) DEFAULT 'ns1.localhost',
  secondary_ns VARCHAR(253) DEFAULT 'ns2.localhost',
  admin_email VARCHAR(255) DEFAULT 'hostmaster@localhost',
  ttl         INT UNSIGNED DEFAULT 3600,
  refresh     INT UNSIGNED DEFAULT 86400,
  retry       INT UNSIGNED DEFAULT 7200,
  expire      INT UNSIGNED DEFAULT 2419200,
  minimum     INT UNSIGNED DEFAULT 86400,
  status      ENUM('active','disabled') DEFAULT 'active',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  INDEX idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dns_records (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(253) NOT NULL,
  type       ENUM('A','AAAA','CNAME','MX','TXT','SRV','NS','PTR','CAA','DKIM','SPF','DMARC') NOT NULL,
  content    TEXT NOT NULL,
  ttl        INT UNSIGNED DEFAULT 3600,
  priority   SMALLINT UNSIGNED DEFAULT NULL,
  proxied    TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (zone_id) REFERENCES dns_zones(id) ON DELETE CASCADE,
  INDEX idx_zone (zone_id),
  INDEX idx_type (type),
  INDEX idx_name (name(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Email Accounts & Forwarders ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS email_accounts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  email       VARCHAR(320) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  quota_mb    INT UNSIGNED DEFAULT 500,
  used_mb     INT UNSIGNED DEFAULT 0,
  status      ENUM('active','suspended') DEFAULT 'active',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  INDEX idx_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_forwarders (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  source      VARCHAR(320) NOT NULL,
  destination VARCHAR(320) NOT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_autoresponders (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  email       VARCHAR(320) NOT NULL,
  subject     VARCHAR(255),
  body        TEXT,
  is_active   TINYINT(1) DEFAULT 1,
  start_at    DATETIME,
  stop_at     DATETIME,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Databases ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS databases (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  db_name     VARCHAR(100) NOT NULL,
  db_user     VARCHAR(100) NOT NULL,
  db_pass     VARCHAR(255) NOT NULL,
  db_type     ENUM('mysql','postgresql') DEFAULT 'mysql',
  size_mb     FLOAT DEFAULT 0,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  INDEX idx_account (account_id),
  INDEX idx_type    (db_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── FTP Accounts ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ftp_accounts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  username    VARCHAR(100) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  home_dir    VARCHAR(500) NOT NULL,
  quota_mb    INT UNSIGNED DEFAULT 0,
  status      ENUM('active','suspended') DEFAULT 'active',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SSL Certificates ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ssl_certs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  domain      VARCHAR(253) NOT NULL,
  type        ENUM('lets_encrypt','self_signed','custom') DEFAULT 'lets_encrypt',
  cert        TEXT,
  private_key TEXT,
  chain       TEXT,
  issued_at   DATETIME,
  expires_at  DATETIME,
  auto_renew  TINYINT(1) DEFAULT 1,
  status      ENUM('active','expired','pending','failed') DEFAULT 'pending',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  INDEX idx_domain  (domain),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Cron Jobs ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS cron_jobs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  command     TEXT NOT NULL,
  minute      VARCHAR(20) DEFAULT '*',
  hour        VARCHAR(20) DEFAULT '*',
  day         VARCHAR(20) DEFAULT '*',
  month       VARCHAR(20) DEFAULT '*',
  weekday     VARCHAR(20) DEFAULT '*',
  is_active   TINYINT(1) DEFAULT 1,
  last_run    DATETIME,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PHP Configuration ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS php_configs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL UNIQUE,
  php_version VARCHAR(10) DEFAULT '8.3',
  memory_limit VARCHAR(20) DEFAULT '256M',
  max_execution_time INT DEFAULT 30,
  upload_max_filesize VARCHAR(20) DEFAULT '64M',
  post_max_size VARCHAR(20) DEFAULT '64M',
  max_input_vars INT DEFAULT 1000,
  display_errors TINYINT(1) DEFAULT 0,
  error_reporting VARCHAR(50) DEFAULT 'E_ALL & ~E_NOTICE',
  extensions  JSON,
  updated_at  DATETIME ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Backups ───────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS backups (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  filename    VARCHAR(500) NOT NULL,
  size_mb     FLOAT DEFAULT 0,
  type        ENUM('full','partial','db_only','files_only') DEFAULT 'full',
  status      ENUM('pending','running','complete','failed') DEFAULT 'pending',
  storage     ENUM('local','s3','ftp','sftp') DEFAULT 'local',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  INDEX idx_account (account_id),
  INDEX idx_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Server Stats / Monitoring ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS server_stats (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cpu_pct     FLOAT,
  ram_pct     FLOAT,
  disk_pct    FLOAT,
  load_1m     FLOAT,
  load_5m     FLOAT,
  load_15m    FLOAT,
  net_in_kb   BIGINT UNSIGNED DEFAULT 0,
  net_out_kb  BIGINT UNSIGNED DEFAULT 0,
  recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Notifications ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED,
  title       VARCHAR(255) NOT NULL,
  message     TEXT NOT NULL,
  type        ENUM('info','success','warning','error') DEFAULT 'info',
  is_read     TINYINT(1) DEFAULT 0,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user   (user_id),
  INDEX idx_unread (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── API Tokens ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS api_tokens (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  token       VARCHAR(128) NOT NULL UNIQUE,
  permissions JSON,
  last_used   DATETIME,
  expires_at  DATETIME,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Settings ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
  `key`     VARCHAR(100) PRIMARY KEY,
  `value`   TEXT,
  updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES
  ('panel_name',       'NovaCPX'),
  ('panel_version',    '1.0.0'),
  ('default_nameserver1', 'ns1.localhost'),
  ('default_nameserver2', 'ns2.localhost'),
  ('default_php',      '8.3'),
  ('mail_enabled',     '1'),
  ('ftp_enabled',      '1'),
  ('dns_enabled',      '1'),
  ('backup_dir',       '/var/novacpx/backups'),
  ('update_channel',   'stable'),
  ('git_remote',       'https://github.com/myronblair/novacpx.git')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

CREATE TABLE IF NOT EXISTS dkim_keys (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `account_id`       INT UNSIGNED NOT NULL,
  `domain`           VARCHAR(253) NOT NULL,
  `selector`         VARCHAR(63)  NOT NULL DEFAULT 'mail',
  `public_key`       TEXT         NOT NULL,
  `private_key_path` VARCHAR(500) NOT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_domain (domain),
  CONSTRAINT fk_dkim_acct FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
