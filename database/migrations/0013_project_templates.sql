-- 0013_project_templates: plantillas de proyecto con tareas y checklists en JSON.

CREATE TABLE IF NOT EXISTS `project_templates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(50) DEFAULT 'General',
    `color` VARCHAR(20) DEFAULT '#4F46E5',
    `payload` LONGTEXT NOT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_templates_agency` (`agency_id`, `created_at`),
    CONSTRAINT `fk_templates_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_templates_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
