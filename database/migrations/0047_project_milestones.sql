-- 0047_project_milestones: hitos del proyecto (entregas clave) que se muestran en el cronograma.
CREATE TABLE IF NOT EXISTS `project_milestones` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `due_date` DATE NOT NULL,
    `is_done` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_milestones_project` (`project_id`, `due_date`),
    CONSTRAINT `fk_milestones_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_milestones_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
