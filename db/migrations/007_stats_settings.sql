-- Migration 007: server_stats table + server type settings + WHMCS key
CREATE TABLE IF NOT EXISTS server_stats (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cpu_usage   DECIMAL(5,2)  DEFAULT 0,
    ram_usage   DECIMAL(5,2)  DEFAULT 0,
    disk_usage  DECIMAL(5,2)  DEFAULT 0,
    load_avg    DECIMAL(8,2)  DEFAULT 0,
    net_in_kb   BIGINT        DEFAULT 0,
    net_out_kb  BIGINT        DEFAULT 0,
    recorded_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('web_server',   'apache'),
    ('mail_server',  'postfix-dovecot'),
    ('ftp_server',   'proftpd'),
    ('dns_server',   'bind9'),
    ('whmcs_api_key', ''),
    ('whmcs_enabled', '0'),
    ('ns1_hostname', ''),
    ('ns2_hostname', '');
