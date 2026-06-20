-- Migration: replace global email UNIQUE with partial index (hosting accounts only)
-- Allows admin/reseller emails to be reused for hosting accounts
CREATE TABLE IF NOT EXISTS users_new (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  password      TEXT NOT NULL,
  email         TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('admin','reseller','user')),
  status        TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','suspended','pending')),
  reseller_id   INTEGER DEFAULT NULL,
  package_id    INTEGER DEFAULT NULL,
  theme         TEXT DEFAULT 'nova-dark',
  language      TEXT DEFAULT 'en',
  contact_name  TEXT,
  contact_phone TEXT,
  last_login    TEXT,
  created_at    TEXT DEFAULT (datetime('now')),
  updated_at    TEXT,
  totp_secret   TEXT,
  totp_enabled  INTEGER DEFAULT 0,
  totp_backup_codes TEXT,
  FOREIGN KEY (reseller_id) REFERENCES users(id) ON DELETE SET NULL
);
INSERT OR IGNORE INTO users_new SELECT id,username,password,email,role,status,reseller_id,package_id,theme,language,contact_name,contact_phone,last_login,created_at,updated_at,totp_secret,totp_enabled,totp_backup_codes FROM users;
DROP TABLE users;
ALTER TABLE users_new RENAME TO users;
CREATE INDEX IF NOT EXISTS idx_users_role     ON users (role);
CREATE INDEX IF NOT EXISTS idx_users_status   ON users (status);
CREATE INDEX IF NOT EXISTS idx_users_reseller ON users (reseller_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_user ON users (email) WHERE role = 'user';
