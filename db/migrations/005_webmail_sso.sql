-- Migration 005: Webmail SSO — encrypted IMAP password + SSO tokens table
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_accounts' AND COLUMN_NAME = 'enc_password'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE email_accounts ADD COLUMN enc_password VARCHAR(512) DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS webmail_sso_tokens (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token      VARCHAR(64)  NOT NULL,
  email      VARCHAR(320) NOT NULL,
  enc_pass   VARCHAR(512) NOT NULL,
  expires_at DATETIME     NOT NULL,
  UNIQUE KEY uq_token (token),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
