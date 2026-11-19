-- 0011_project_archive: archivado de proyectos (papelera) en lugar de borrado directo.
-- Los proyectos archivados desaparecen de dashboards, listados y compartidos, pero se pueden restaurar.

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'projects'
              AND column_name = 'archived_at'),
    'SET @migration_noop = 1',
    'ALTER TABLE `projects` ADD COLUMN `archived_at` DATETIME NULL AFTER `updated_at`'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'projects'
              AND column_name = 'archived_by'),
    'SET @migration_noop = 1',
    'ALTER TABLE `projects` ADD COLUMN `archived_by` INT NULL AFTER `archived_at`'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'projects'
              AND index_name = 'idx_projects_archived'),
    'SET @migration_noop = 1',
    'ALTER TABLE `projects` ADD INDEX `idx_projects_archived` (`agency_id`, `archived_at`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_schema = DATABASE() AND table_name = 'projects'
              AND constraint_name = 'fk_projects_archived_by'),
    'SET @migration_noop = 1',
    'ALTER TABLE `projects` ADD CONSTRAINT `fk_projects_archived_by` FOREIGN KEY (`archived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
