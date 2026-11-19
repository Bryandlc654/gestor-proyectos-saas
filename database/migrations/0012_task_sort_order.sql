-- 0012_task_sort_order: orden manual de tareas dentro de cada columna del Kanban.

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'tasks'
              AND column_name = 'sort_order'),
    'SET @migration_noop = 1',
    'ALTER TABLE `tasks` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `status`'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Las tareas existentes conservan un orden estable inicial
UPDATE `tasks` SET `sort_order` = `id` WHERE `sort_order` = 0;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'tasks'
              AND index_name = 'idx_tasks_project_status_order'),
    'SET @migration_noop = 1',
    'ALTER TABLE `tasks` ADD INDEX `idx_tasks_project_status_order` (`project_id`, `status`, `sort_order`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
