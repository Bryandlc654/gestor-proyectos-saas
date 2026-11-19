-- 0043_cash_automation: enlaza movimientos de caja con facturas y pagos.
-- Un movimiento generado guarda su origen y no puede duplicarse:
-- la clave única (agency_id, source_type, source_id) lo garantiza.

ALTER TABLE `cash_transactions`
    ADD COLUMN `source_type` VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER `notes`,
    ADD COLUMN `source_id` INT NULL AFTER `source_type`,
    ADD UNIQUE KEY `uq_cash_transactions_source` (`agency_id`, `source_type`, `source_id`),
    ADD INDEX `idx_cash_transactions_source_type` (`source_type`, `source_id`);

CREATE TABLE IF NOT EXISTS `cash_automation_rules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `kind` ENUM('invoice', 'payable') NOT NULL,
    `currency` VARCHAR(10) NOT NULL,
    `account_id` INT NOT NULL,
    `category_id` INT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_cash_automation_agency_kind_currency` (`agency_id`, `kind`, `currency`),
    INDEX `idx_cash_automation_agency` (`agency_id`, `kind`, `currency`),
    CONSTRAINT `fk_cash_automation_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cash_automation_account` FOREIGN KEY (`account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cash_automation_category` FOREIGN KEY (`category_id`) REFERENCES `cash_categories` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cash_automation_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
