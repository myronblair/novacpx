-- Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
-- Migration 017: per-reseller and per-end-user on/off switch for the malware scanner (on by default).
ALTER TABLE users ADD COLUMN sweep_enabled INTEGER NOT NULL DEFAULT 1;
