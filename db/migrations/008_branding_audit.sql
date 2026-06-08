-- Migration 008: Reseller branding table

CREATE TABLE IF NOT EXISTS reseller_branding (
  user_id        INT UNSIGNED PRIMARY KEY,
  panel_name     VARCHAR(100)  NOT NULL DEFAULT 'NovaCPX',
  logo_url       VARCHAR(500)  DEFAULT NULL,
  favicon_url    VARCHAR(500)  DEFAULT NULL,
  primary_color  VARCHAR(20)   NOT NULL DEFAULT '#6366f1',
  accent_color   VARCHAR(20)   NOT NULL DEFAULT '#0ea5e9',
  support_email  VARCHAR(255)  DEFAULT NULL,
  support_url    VARCHAR(500)  DEFAULT NULL,
  hide_powered_by TINYINT(1)   NOT NULL DEFAULT 0,
  custom_css     TEXT          DEFAULT NULL,
  updated_at     DATETIME      ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_branding_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
