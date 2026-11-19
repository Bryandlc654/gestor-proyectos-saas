-- 0032_reports: vistas guardadas y exportaciones programadas.

CREATE TABLE IF NOT EXISTS `saved_reports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `report_type` ENUM('projects', 'people', 'clients', 'deadlines') NOT NULL,
    `filters` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_saved_reports_user` (`user_id`, `created_at`),
    CONSTRAINT `fk_saved_reports_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_saved_reports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `report_type` ENUM('projects', 'people', 'clients', 'deadlines') NOT NULL,
    `filters` TEXT NULL,
    `frequency` ENUM('daily', 'weekly', 'monthly') NOT NULL DEFAULT 'weekly',
    `recipients` VARCHAR(500) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_run_at` DATETIME NULL,
    `next_run_at` DATETIME NULL,
    `last_token` VARCHAR(64) NULL,
    `last_file` VARCHAR(255) NULL,
    `token_expires_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_report_schedules_due` (`active`, `next_run_at`),
    INDEX `idx_report_schedules_agency` (`agency_id`, `created_at`),
    INDEX `idx_report_schedules_token` (`last_token`),
    CONSTRAINT `fk_report_schedules_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_report_schedules_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
