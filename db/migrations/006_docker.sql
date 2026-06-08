-- Migration 006: Docker tiered container management (#31-35)
CREATE TABLE IF NOT EXISTS docker_quotas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    max_containers INT DEFAULT 2,
    max_memory_mb INT DEFAULT 512,
    max_cpus DECIMAL(4,2) DEFAULT 1.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS docker_containers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    container_id VARCHAR(64) DEFAULT NULL,
    name VARCHAR(128) NOT NULL,
    image VARCHAR(255) NOT NULL,
    app_key VARCHAR(64) DEFAULT NULL,
    status ENUM('running','stopped','error','pending') DEFAULT 'pending',
    ports TEXT DEFAULT NULL,
    memory_mb INT DEFAULT NULL,
    cpus DECIMAL(4,2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (account_id),
    INDEX (container_id(12))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS docker_compose_stacks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT DEFAULT NULL,
    name VARCHAR(128) NOT NULL,
    stack_dir VARCHAR(500) NOT NULL,
    compose_file TEXT NOT NULL,
    status ENUM('running','stopped','error','pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
