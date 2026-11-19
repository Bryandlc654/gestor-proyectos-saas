-- 0039_webhooks: webhooks salientes con eventos, cola de entregas y reintentos.

ALTER TABLE `api_tokens`
    ADD COLUMN `webhook_url` VARCHAR(500) NULL AFTER `project_ids`,
    ADD COLUMN `webhook_events` TEXT NULL AFTER `webhook_url`,
    ADD COLUMN `webhook_active` TINYINT(1) NOT NULL DEFAULT 0 AFTER `webhook_events`;

CREATE TABLE IF NOT EXISTS `webhook_events` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_uid` CHAR(32) NOT NULL,
    `agency_id` INT NOT NULL,
    `event_type` VARCHAR(40) NOT NULL,
    `ticket_id` INT NULL,
    `project_id` INT NULL,
    `payload` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_webhook_events_uid` (`event_uid`),
    INDEX `idx_webhook_events_agency` (`agency_id`, `created_at`),
    INDEX `idx_webhook_events_ticket` (`ticket_id`),
    CONSTRAINT `fk_webhook_events_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_id` BIGINT UNSIGNED NOT NULL,
    `token_id` INT NOT NULL,
    `status` ENUM('pending', 'processing', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `next_attempt_at` DATETIME NOT NULL,
    `last_attempt_at` DATETIME NULL,
    `response_code` SMALLINT NULL,
    `response_body` TEXT NULL,
    `delivered_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_webhook_deliveries_due` (`status`, `next_attempt_at`),
    INDEX `idx_webhook_deliveries_token` (`token_id`, `created_at`),
    INDEX `idx_webhook_deliveries_event` (`event_id`),
    CONSTRAINT `fk_webhook_deliveries_event` FOREIGN KEY (`event_id`) REFERENCES `webhook_events` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_webhook_deliveries_token` FOREIGN KEY (`token_id`) REFERENCES `api_tokens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
