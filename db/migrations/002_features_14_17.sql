-- Migration 002: Features #14-17 (WordPress, Backup, Cloudflare, TOTP)
ALTER TABLE users ADD COLUMN totp_secret       VARCHAR(64)  DEFAULT NULL;
ALTER TABLE users ADD COLUMN totp_enabled      TINYINT(1)   DEFAULT 0;
ALTER TABLE users ADD COLUMN totp_backup_codes TEXT         DEFAULT NULL;
ALTER TABLE accounts ADD COLUMN cf_api_key   VARCHAR(255) DEFAULT NULL;
ALTER TABLE accounts ADD COLUMN cf_api_email VARCHAR(255) DEFAULT NULL;
ALTER TABLE accounts ADD COLUMN cf_zone_id   VARCHAR(64)  DEFAULT NULL;
ALTER TABLE dns_zones ADD COLUMN cf_zone_id VARCHAR(64) DEFAULT NULL;
CREATE TABLE IF NOT EXISTS wordpress_installs (
    id INT AUTO_INCREMENT PRIMARY KEY,account_id INT NOT NULL,domain VARCHAR(255) NOT NULL,
    path VARCHAR(255) DEFAULT '/',db_name VARCHAR(64) DEFAULT NULL,db_user VARCHAR(64) DEFAULT NULL,
    db_pass VARCHAR(128) DEFAULT NULL,wp_version VARCHAR(20) DEFAULT NULL,admin_user VARCHAR(64) DEFAULT NULL,
    admin_email VARCHAR(255) DEFAULT NULL,status ENUM('active','updating','suspended') DEFAULT 'active',
    staging_of INT DEFAULT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS backups (
    id INT AUTO_INCREMENT PRIMARY KEY,account_id INT NOT NULL,filename VARCHAR(255) NOT NULL,
    size BIGINT DEFAULT 0,type ENUM('full','files','database') DEFAULT 'full',
    status ENUM('pending','running','complete','failed') DEFAULT 'pending',
    storage VARCHAR(50) DEFAULT 'local',remote_path VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX (account_id),INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS backup_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,account_id INT NOT NULL UNIQUE,
    frequency ENUM('hourly','daily','weekly','monthly') DEFAULT 'daily',
    type ENUM('full','files','database') DEFAULT 'full',retain_count INT DEFAULT 7,
    last_run TIMESTAMP NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
