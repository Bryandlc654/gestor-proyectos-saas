-- 0049_project_meetings: actas de reunión del proyecto. Cada acta guarda un enlace (Drive, Meet, Notion...)
-- y/o un archivo subido (PDF, Word...), más la fecha, asistentes y un resumen de acuerdos.
CREATE TABLE IF NOT EXISTS `project_meetings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `meeting_date` DATE NOT NULL,
    `attendees` VARCHAR(500) NULL,
    `summary` TEXT NULL,
    `link_url` VARCHAR(500) NULL,
    `original_name` VARCHAR(255) NULL,
    `stored_name` VARCHAR(255) NULL,
    `file_size` INT NULL,
    `file_type` VARCHAR(100) NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_meetings_project` (`project_id`, `meeting_date`),
    CONSTRAINT `fk_meetings_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_meetings_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
