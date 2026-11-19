-- 0027_finance_payables: cuentas por pagar y sus pagos parciales.
-- Las cuentas por cobrar siguen viviendo en `invoices` (facturas emitidas a clientes).

CREATE TABLE IF NOT EXISTS `payables` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `project_id` INT NULL,
    `supplier` VARCHAR(150) NOT NULL,
    `concept` VARCHAR(255) NOT NULL,
    `category` VARCHAR(60) NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `due_date` DATE NULL,
    `status` ENUM('pendiente', 'pagada', 'anulada') NOT NULL DEFAULT 'pendiente',
    `paid_at` DATE NULL,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_payables_agency_due` (`agency_id`, `due_date`),
    INDEX `idx_payables_agency_status` (`agency_id`, `status`),
    INDEX `idx_payables_project` (`project_id`),
    CONSTRAINT `fk_payables_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payables_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_payables_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payable_payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `payable_id` INT NOT NULL,
    `agency_id` INT NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `paid_at` DATE NOT NULL,
    `method` VARCHAR(40) NULL,
    `notes` VARCHAR(255) NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_payable_payments_payable` (`payable_id`, `paid_at`),
    INDEX `idx_payable_payments_agency` (`agency_id`),
    CONSTRAINT `fk_pp_payable` FOREIGN KEY (`payable_id`) REFERENCES `payables` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pp_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pp_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
