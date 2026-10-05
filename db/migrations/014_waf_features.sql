-- Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
-- Migration 014: request firewall (WAF) per account, per-package tool lists.

CREATE TABLE IF NOT EXISTS waf_rules (
  account_id INTEGER PRIMARY KEY,
  mode       TEXT NOT NULL DEFAULT 'off',          -- off | block
  rules      TEXT NOT NULL DEFAULT '[]',           -- JSON list of rule slugs
  exempt     TEXT NOT NULL DEFAULT '[]',           -- JSON list of URL paths the rules skip
  updated_at TEXT DEFAULT (datetime('now')),
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- Which optional tools a package includes (JSON list of tool slugs). NULL = everything, so existing packages keep working.
ALTER TABLE packages ADD COLUMN tools TEXT;
