-- 0018_query_indexes: índices para las consultas más repetidas (listados y ordenaciones).
-- Se añaden solo compuestos que cubren filtro + orden en las vistas principales.

-- Usuarios de una agencia por rol (listado de usuarios, admins de notificaciones)
SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'users'
              AND index_name = 'idx_users_agency_role'),
    'SET @migration_noop = 1',
    'ALTER TABLE `users` ADD INDEX `idx_users_agency_role` (`agency_id`, `role`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Proyectos de una agencia ordenados por actividad (barra lateral y listados)
SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'projects'
              AND index_name = 'idx_projects_agency_updated'),
    'SET @migration_noop = 1',
    'ALTER TABLE `projects` ADD INDEX `idx_projects_agency_updated` (`agency_id`, `updated_at`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Tareas de un proyecto en el orden del tablero
SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'tasks'
              AND index_name = 'idx_tasks_project_order'),
    'SET @migration_noop = 1',
    'ALTER TABLE `tasks` ADD INDEX `idx_tasks_project_order` (`project_id`, `sort_order`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Pestañas del proyecto: adjuntos, enlaces, tickets y checklists por fecha
SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'project_files'
              AND index_name = 'idx_files_project_created'),
    'SET @migration_noop = 1',
    'ALTER TABLE `project_files` ADD INDEX `idx_files_project_created` (`project_id`, `created_at`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'project_links'
              AND index_name = 'idx_links_project_created'),
    'SET @migration_noop = 1',
    'ALTER TABLE `project_links` ADD INDEX `idx_links_project_created` (`project_id`, `created_at`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'tickets'
              AND index_name = 'idx_tickets_project_created'),
    'SET @migration_noop = 1',
    'ALTER TABLE `tickets` ADD INDEX `idx_tickets_project_created` (`project_id`, `created_at`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'checklists'
              AND index_name = 'idx_checklists_project_created'),
    'SET @migration_noop = 1',
    'ALTER TABLE `checklists` ADD INDEX `idx_checklists_project_created` (`project_id`, `created_at`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Recordatorios y facturas pendientes por proyecto
SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'invoices'
              AND index_name = 'idx_invoices_project_due'),
    'SET @migration_noop = 1',
    'ALTER TABLE `invoices` ADD INDEX `idx_invoices_project_due` (`project_id`, `due_date`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
