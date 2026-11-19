-- 0031_gantt_scheduling: fechas de inicio, hitos y líneas base para el cronograma.

ALTER TABLE `tasks`
    ADD COLUMN `start_date` DATE NULL AFTER `due_date`,
    ADD COLUMN `is_milestone` TINYINT(1) NOT NULL DEFAULT 0 AFTER `priority`,
    ADD INDEX `idx_tasks_project_start` (`project_id`, `start_date`);

-- Las tareas existentes con vencimiento pasan a tener una barra de un día.
UPDATE `tasks` SET `start_date` = `due_date` WHERE `start_date` IS NULL AND `due_date` IS NOT NULL;

CREATE TABLE IF NOT EXISTS `project_baselines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_project_baselines_project` (`project_id`, `created_at`),
    CONSTRAINT `fk_project_baselines_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_project_baselines_author` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `baseline_tasks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `baseline_id` INT NOT NULL,
    `task_id` INT NOT NULL,
    `start_date` DATE NULL,
    `due_date` DATE NULL,
    UNIQUE KEY `uq_baseline_task` (`baseline_id`, `task_id`),
    INDEX `idx_baseline_tasks_task` (`task_id`),
    CONSTRAINT `fk_baseline_tasks_baseline` FOREIGN KEY (`baseline_id`) REFERENCES `project_baselines` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_baseline_tasks_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
