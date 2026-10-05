-- Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
-- Migration 018: the Cloudflare integration remembers each domain's Cloudflare zone.
ALTER TABLE dns_zones ADD COLUMN cf_zone_id TEXT;
