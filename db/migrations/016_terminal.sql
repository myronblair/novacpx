-- Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
-- Migration 016: admin web terminal (a fresh authenticator code opens it for a few minutes).

CREATE TABLE IF NOT EXISTS terminal_unlocks (
  session_hash TEXT PRIMARY KEY,
  user_id      INTEGER NOT NULL,
  expires_at   TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS terminal_attempts (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  at      TEXT DEFAULT (datetime('now'))
);
