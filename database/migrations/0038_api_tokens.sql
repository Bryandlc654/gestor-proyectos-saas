-- 0038_api_tokens: tokens de integración, límites de uso y trazabilidad de tickets externos.

CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `name` VARCHAR(80) NOT NULL,
    `token_prefix` VARCHAR(20) NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `scopes` TEXT NOT NULL,
    `project_ids` TEXT NULL,
    `rate_limit_per_minute` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    `expires_at` DATETIME NULL,
    `last_used_at` DATETIME NULL,
    `last_used_ip` VARCHAR(45) NULL,
    `revoked_at` DATETIME NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_api_tokens_hash` (`token_hash`),
    INDEX `idx_api_tokens_agency` (`agency_id`, `revoked_at`),
    CONSTRAINT `fk_api_tokens_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_api_tokens_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_rate_limits` (
    `token_id` INT NOT NULL,
    `window_start` INT UNSIGNED NOT NULL,
    `hits` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`token_id`, `window_start`),
    CONSTRAINT `fk_api_rate_token` FOREIGN KEY (`token_id`) REFERENCES `api_tokens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `tickets`
    ADD COLUMN `external_source` VARCHAR(60) NULL AFTER `status`,
    ADD COLUMN `external_id` VARCHAR(100) NULL AFTER `external_source`,
    ADD COLUMN `external_requester_name` VARCHAR(150) NULL AFTER `external_id`,
    ADD COLUMN `external_requester_email` VARCHAR(150) NULL AFTER `external_requester_name`,
    ADD COLUMN `external_url` VARCHAR(500) NULL AFTER `external_requester_email`,
    ADD UNIQUE KEY `uq_tickets_external` (`project_id`, `external_source`, `external_id`);
