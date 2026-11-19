-- 0034_quotes: módulo de cotizaciones con ítems y exportación PDF.

CREATE TABLE IF NOT EXISTS `quotes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `project_id` INT NULL,
    `quote_number` VARCHAR(40) NOT NULL,
    `title` VARCHAR(180) NOT NULL,
    `client_name` VARCHAR(150) NOT NULL,
    `client_company` VARCHAR(150) NULL,
    `client_email` VARCHAR(150) NULL,
    `client_phone` VARCHAR(50) NULL,
    `client_address` VARCHAR(255) NULL,
    `issue_date` DATE NOT NULL,
    `valid_until` DATE NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `discount_type` ENUM('none', 'percent', 'fixed') NOT NULL DEFAULT 'none',
    `discount_value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `terms` TEXT NULL,
    `status` ENUM('borrador', 'enviada', 'aceptada', 'rechazada') NOT NULL DEFAULT 'borrador',
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_quote_number_agency` (`agency_id`, `quote_number`),
    INDEX `idx_quotes_agency_date` (`agency_id`, `issue_date`),
    INDEX `idx_quotes_project` (`project_id`),
    CONSTRAINT `fk_quotes_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_quotes_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_quotes_author` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quote_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `quote_id` INT NOT NULL,
    `description` VARCHAR(255) NOT NULL,
    `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `sort_order` INT NOT NULL DEFAULT 0,
    INDEX `idx_quote_items_quote` (`quote_id`, `sort_order`),
    CONSTRAINT `fk_quote_items_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
