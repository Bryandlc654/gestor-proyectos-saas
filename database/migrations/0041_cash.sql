-- 0041_cash: libro de caja con cuentas, categorías y movimientos.
-- Es independiente de `invoices` y `payables`: aquí se registra el dinero que
-- realmente entra o sale de una cuenta (efectivo, banco, tarjeta u otra).

CREATE TABLE IF NOT EXISTS `cash_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `name` VARCHAR(80) NOT NULL,
    `type` ENUM('efectivo', 'banco', 'tarjeta', 'otro') NOT NULL DEFAULT 'efectivo',
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `opening_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `color` VARCHAR(20) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_cash_accounts_agency_name` (`agency_id`, `name`),
    INDEX `idx_cash_accounts_agency_active` (`agency_id`, `is_active`),
    CONSTRAINT `fk_cash_accounts_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cash_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `type` ENUM('ingreso', 'egreso') NOT NULL,
    `name` VARCHAR(80) NOT NULL,
    `color` VARCHAR(20) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_cash_categories_agency_type_name` (`agency_id`, `type`, `name`),
    INDEX `idx_cash_categories_agency_type` (`agency_id`, `type`, `is_active`),
    CONSTRAINT `fk_cash_categories_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cash_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `account_id` INT NOT NULL,
    `category_id` INT NOT NULL,
    `project_id` INT NULL,
    `type` ENUM('ingreso', 'egreso') NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `occurred_on` DATE NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `reference` VARCHAR(80) NULL,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cash_transactions_agency_date` (`agency_id`, `occurred_on`),
    INDEX `idx_cash_transactions_agency_type` (`agency_id`, `type`, `occurred_on`),
    INDEX `idx_cash_transactions_account` (`account_id`, `occurred_on`),
    INDEX `idx_cash_transactions_category` (`category_id`),
    INDEX `idx_cash_transactions_project` (`project_id`),
    CONSTRAINT `fk_cash_transactions_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cash_transactions_account` FOREIGN KEY (`account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cash_transactions_category` FOREIGN KEY (`category_id`) REFERENCES `cash_categories` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_cash_transactions_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cash_transactions_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
