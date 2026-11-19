-- 0002: índices para los filtros y ordenaciones más frecuentes + número de factura único por proyecto.

-- Renombrar números de factura duplicados (si los hubiera) antes de crear el índice único
UPDATE `invoices` i
INNER JOIN (
    SELECT `project_id`, `invoice_number`, MIN(`id`) AS keep_id
    FROM `invoices`
    GROUP BY `project_id`, `invoice_number`
    HAVING COUNT(*) > 1
) d ON i.`project_id` = d.`project_id` AND i.`invoice_number` = d.`invoice_number` AND i.`id` <> d.keep_id
SET i.`invoice_number` = CONCAT(i.`invoice_number`, '-', i.`id`);

-- MySQL commits DDL implicitly. If a previous run stopped after creating an
-- index but before recording this migration, reuse that index and continue.
SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'invoices'
              AND index_name = 'uq_invoice_project_number'),
    'SET @migration_noop = 1',
    'ALTER TABLE `invoices` ADD UNIQUE KEY `uq_invoice_project_number` (`project_id`, `invoice_number`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'invoices'
              AND index_name = 'idx_invoices_status_due'),
    'SET @migration_noop = 1',
    'ALTER TABLE `invoices` ADD INDEX `idx_invoices_status_due` (`status`, `due_date`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'tasks'
              AND index_name = 'idx_tasks_project_status'),
    'SET @migration_noop = 1',
    'ALTER TABLE `tasks` ADD INDEX `idx_tasks_project_status` (`project_id`, `status`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'tasks'
              AND index_name = 'idx_tasks_due_date'),
    'SET @migration_noop = 1',
    'ALTER TABLE `tasks` ADD INDEX `idx_tasks_due_date` (`due_date`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'tickets'
              AND index_name = 'idx_tickets_project_status'),
    'SET @migration_noop = 1',
    'ALTER TABLE `tickets` ADD INDEX `idx_tickets_project_status` (`project_id`, `status`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'comments'
              AND index_name = 'idx_comments_project_created'),
    'SET @migration_noop = 1',
    'ALTER TABLE `comments` ADD INDEX `idx_comments_project_created` (`project_id`, `created_at`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'projects'
              AND index_name = 'idx_projects_status'),
    'SET @migration_noop = 1',
    'ALTER TABLE `projects` ADD INDEX `idx_projects_status` (`status`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'users'
              AND index_name = 'idx_users_role'),
    'SET @migration_noop = 1',
    'ALTER TABLE `users` ADD INDEX `idx_users_role` (`role`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
