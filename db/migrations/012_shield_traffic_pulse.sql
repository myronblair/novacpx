-- Migration 012: Site Shield rules, Traffic Meter (bandwidth accounting), Pulse (uptime monitor), neutral feature slugs.

-- Per-account web rules (blocked addresses, hotlink guard, locked folders, error pages) as one JSON document.
CREATE TABLE IF NOT EXISTS site_rules (
  account_id INTEGER PRIMARY KEY,
  rules      TEXT NOT NULL DEFAULT '{}',
  updated_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- Bytes served per account and day, read incrementally from each account's access log.
CREATE TABLE IF NOT EXISTS usage_daily (
  account_id INTEGER NOT NULL,
  day        TEXT NOT NULL,
  bytes_out  INTEGER NOT NULL DEFAULT 0,
  requests   INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (account_id, day),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_usage_daily_day ON usage_daily (day);

-- Where the collector stopped reading in each log (inode detects rotation).
CREATE TABLE IF NOT EXISTS usage_cursor (
  account_id INTEGER PRIMARY KEY,
  inode      INTEGER NOT NULL DEFAULT 0,
  pos        INTEGER NOT NULL DEFAULT 0
);

-- One warning per account, month and threshold.
CREATE TABLE IF NOT EXISTS usage_alerts (
  account_id INTEGER NOT NULL,
  month      TEXT NOT NULL,
  level      INTEGER NOT NULL,
  sent_at    TEXT DEFAULT (datetime('now')),
  PRIMARY KEY (account_id, month, level)
);

-- Pulse: current state per monitored site, plus the recent check history.
CREATE TABLE IF NOT EXISTS pulse_state (
  domain       TEXT PRIMARY KEY,
  account_id   INTEGER NOT NULL,
  up           INTEGER NOT NULL DEFAULT 1,
  fails        INTEGER NOT NULL DEFAULT 0,
  since        TEXT,
  first_fail   TEXT,
  last_code    INTEGER,
  last_ms      INTEGER,
  last_checked TEXT
);
CREATE TABLE IF NOT EXISTS pulse_checks (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  domain     TEXT NOT NULL,
  ok         INTEGER NOT NULL,
  code       INTEGER,
  ms         INTEGER,
  checked_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_pulse_checks_domain ON pulse_checks (domain, checked_at);

INSERT OR IGNORE INTO settings (key, value) VALUES
  ('bandwidth_action',   'notify'),   -- notify | suspend (what happens at 100% of the package allowance)
  ('pulse_enabled',      '1'),
  ('pulse_fail_count',   '2');        -- consecutive failures before a site counts as down

-- Neutral names for the two app-installer entries (the slugs used to carry another vendor's product name).
UPDATE features SET slug = 'app-installer'       WHERE slug = 'softaculous-like';
UPDATE features SET slug = 'wordpress-oneclick'  WHERE slug = 'softaculous-wp';
