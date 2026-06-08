-- Migration 004: API rate limiting table
CREATE TABLE IF NOT EXISTS api_rate_limits (
  ip           VARCHAR(45)  NOT NULL,
  endpoint     VARCHAR(64)  NOT NULL DEFAULT 'api',
  hits         INT UNSIGNED NOT NULL DEFAULT 1,
  window_start INT UNSIGNED NOT NULL,
  PRIMARY KEY (ip, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
