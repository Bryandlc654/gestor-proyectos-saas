-- 0029_subtasks_dependencies: subtareas reales y dependencias entre tareas.
-- Una subtarea es una tarea con parent_task_id; puede tener responsable, fecha y estado propios.

ALTER TABLE `tasks`
    ADD COLUMN `parent_task_id` INT NULL AFTER `project_id`,
    ADD INDEX `idx_tasks_parent` (`parent_task_id`),
    ADD CONSTRAINT `fk_tasks_parent` FOREIGN KEY (`parent_task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS `task_dependencies` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT NOT NULL,
    `depends_on_task_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_task_dependency` (`task_id`, `depends_on_task_id`),
    INDEX `idx_task_dependencies_task` (`task_id`),
    INDEX `idx_task_dependencies_depends` (`depends_on_task_id`),
    CONSTRAINT `fk_dependency_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_dependency_depends` FOREIGN KEY (`depends_on_task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
