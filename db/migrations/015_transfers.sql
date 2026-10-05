-- Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
-- Migration 015: account transfer between NovaCPX servers.

-- One-time download links for exported bundles (only a hash of the token is kept).
CREATE TABLE IF NOT EXISTS transfer_tokens (
  token_hash TEXT PRIMARY KEY,
  bundle_id  TEXT NOT NULL,
  username   TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  used       INTEGER NOT NULL DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now'))
);

-- History of exports and imports (no secrets).
CREATE TABLE IF NOT EXISTS transfer_log (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  direction  TEXT NOT NULL,                 -- export | import
  username   TEXT NOT NULL,
  status     TEXT NOT NULL,                 -- ready | downloaded | done | failed
  detail     TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);

-- Exports and imports run in the background (building an account restarts PHP, which must not happen inside a web request).
CREATE TABLE IF NOT EXISTS transfer_jobs (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  kind       TEXT NOT NULL,                 -- export | import
  args       TEXT NOT NULL DEFAULT '{}',    -- cleared when the job ends (an import link is a credential)
  status     TEXT NOT NULL DEFAULT 'running', -- running | done | failed
  result     TEXT,
  error      TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);
