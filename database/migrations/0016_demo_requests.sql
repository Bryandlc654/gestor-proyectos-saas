-- 0016_demo_requests: solicitudes de demostración desde la landing pública.

CREATE TABLE IF NOT EXISTS `demo_requests` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `company` VARCHAR(150) NOT NULL,
    `team_size` VARCHAR(40) NULL,
    `needs` VARCHAR(255) NULL,
    `message` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_demo_requests_created` (`created_at`),
    INDEX `idx_demo_requests_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
