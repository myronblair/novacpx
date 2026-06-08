-- Migration 003: Nginx Proxy Hosts table
CREATE TABLE IF NOT EXISTS proxy_hosts (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  account_id   INT UNSIGNED DEFAULT NULL,
  domain       VARCHAR(255) NOT NULL,
  upstream     VARCHAR(500) NOT NULL DEFAULT 'http://127.0.0.1:80',
  ssl_enabled  TINYINT(1)   NOT NULL DEFAULT 0,
  enabled      TINYINT(1)   NOT NULL DEFAULT 1,
  custom_config TEXT         DEFAULT NULL,
  notes        VARCHAR(500) DEFAULT NULL,
  created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX (account_id),
  UNIQUE KEY uq_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Panel settings for proxy mode
INSERT INTO settings (`key`, `value`) VALUES ('proxy_mode', 'disabled') ON DUPLICATE KEY UPDATE `key`=`key`;
INSERT INTO settings (`key`, `value`) VALUES ('proxy_auto_sync', '0') ON DUPLICATE KEY UPDATE `key`=`key`;
