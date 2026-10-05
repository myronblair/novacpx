-- Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
-- Migration 013: Sweep (malware scan), Git Deploy, per-package PHP pool limits.

-- Pool limits: how many PHP requests an account may run at once, and the largest memory_limit its pool may be given (MB).
ALTER TABLE packages ADD COLUMN php_max_children INTEGER DEFAULT 5;
ALTER TABLE packages ADD COLUMN php_memory_mb    INTEGER DEFAULT 256;

-- Sweep: one row per scan, one row per finding.
CREATE TABLE IF NOT EXISTS sweep_runs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id  INTEGER NOT NULL,
  started_at  TEXT DEFAULT (datetime('now')),
  finished_at TEXT,
  status      TEXT NOT NULL DEFAULT 'running',        -- running | done | failed
  files       INTEGER NOT NULL DEFAULT 0,
  findings    INTEGER NOT NULL DEFAULT 0,
  engine      TEXT NOT NULL DEFAULT 'patterns',       -- patterns | patterns+clamav
  note        TEXT,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_sweep_runs_account ON sweep_runs (account_id, id);

CREATE TABLE IF NOT EXISTS sweep_findings (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  run_id      INTEGER NOT NULL,
  account_id  INTEGER NOT NULL,
  path        TEXT NOT NULL,                          -- relative to the account's home directory
  rule        TEXT NOT NULL,
  severity    TEXT NOT NULL DEFAULT 'medium',         -- high | medium | low
  snippet     TEXT,
  status      TEXT NOT NULL DEFAULT 'open',           -- open | quarantined | ignored
  created_at  TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_sweep_findings_account ON sweep_findings (account_id, status);

-- Git Deploy: the repository connected to an account (access tokens are kept by the privileged helper, not here).
CREATE TABLE IF NOT EXISTS git_deploy (
  account_id   INTEGER PRIMARY KEY,
  repo_url     TEXT NOT NULL,
  branch       TEXT NOT NULL DEFAULT 'main',
  subdir       TEXT NOT NULL DEFAULT '',
  secret       TEXT NOT NULL,
  has_token    INTEGER NOT NULL DEFAULT 0,
  last_deploy  TEXT,
  last_status  TEXT,
  last_commit  TEXT,
  last_output  TEXT,
  updated_at   TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
