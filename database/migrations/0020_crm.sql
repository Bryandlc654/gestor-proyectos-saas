-- 0020_crm: módulo CRM (empresas, contactos, oportunidades y actividades).
-- Todo queda aislado por agencia; los registros relacionados se conservan sin datos huérfanos.

CREATE TABLE IF NOT EXISTS `crm_companies` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `industry` VARCHAR(80) NULL,
    `website` VARCHAR(255) NULL,
    `phone` VARCHAR(40) NULL,
    `email` VARCHAR(150) NULL,
    `address` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_crm_companies_agency` (`agency_id`, `name`),
    CONSTRAINT `fk_crm_company_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_company_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_contacts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `company_id` INT NULL,
    `name` VARCHAR(100) NOT NULL,
    `role` VARCHAR(80) NULL,
    `email` VARCHAR(150) NULL,
    `phone` VARCHAR(40) NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_crm_contacts_agency` (`agency_id`, `name`),
    INDEX `idx_crm_contacts_company` (`company_id`, `name`),
    CONSTRAINT `fk_crm_contact_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_contact_company` FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_deals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `company_id` INT NULL,
    `contact_id` INT NULL,
    `title` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `stage` ENUM('nuevo', 'contactado', 'propuesta', 'negociacion', 'ganado', 'perdido') NOT NULL DEFAULT 'nuevo',
    `value` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(10) DEFAULT 'USD',
    `expected_close` DATE NULL,
    `owner_id` INT NULL,
    `source` VARCHAR(60) NULL,
    `won_at` DATETIME NULL,
    `lost_at` DATETIME NULL,
    `lost_reason` VARCHAR(255) NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_crm_deals_agency_stage` (`agency_id`, `stage`, `updated_at`),
    INDEX `idx_crm_deals_agency_close` (`agency_id`, `expected_close`),
    INDEX `idx_crm_deals_company` (`company_id`),
    INDEX `idx_crm_deals_contact` (`contact_id`),
    INDEX `idx_crm_deals_owner` (`owner_id`),
    CONSTRAINT `fk_crm_deal_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_deal_company` FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_crm_deal_contact` FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_crm_deal_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_crm_deal_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_activities` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `deal_id` INT NULL,
    `company_id` INT NULL,
    `contact_id` INT NULL,
    `user_id` INT NULL,
    `type` ENUM('llamada', 'correo', 'reunion', 'nota', 'tarea') NOT NULL DEFAULT 'nota',
    `title` VARCHAR(150) NOT NULL,
    `notes` TEXT NULL,
    `occurred_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_crm_activities_agency_time` (`agency_id`, `occurred_at`),
    INDEX `idx_crm_activities_deal_time` (`deal_id`, `occurred_at`),
    INDEX `idx_crm_activities_company` (`company_id`, `occurred_at`),
    INDEX `idx_crm_activities_contact` (`contact_id`, `occurred_at`),
    CONSTRAINT `fk_crm_activity_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_activity_deal` FOREIGN KEY (`deal_id`) REFERENCES `crm_deals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_activity_company` FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_crm_activity_contact` FOREIGN KEY (`contact_id`) REFERENCES `crm_contacts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_crm_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
