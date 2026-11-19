-- 0008_activity_log: historial de actividad y auditoría por agencia/proyecto.
-- Guarda quién hizo qué y cuándo. El proyecto y el usuario pueden quedar a NULL si se eliminan.

CREATE TABLE IF NOT EXISTS `activity_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `user_id` INT NULL,
    `project_id` INT NULL,
    `action` VARCHAR(60) NOT NULL,
    `description` VARCHAR(255) NULL,
    `metadata` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_activity_agency_time` (`agency_id`, `created_at`),
    INDEX `idx_activity_project_time` (`project_id`, `created_at`),
    INDEX `idx_activity_action` (`action`),
    CONSTRAINT `fk_activity_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_activity_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
