-- 0019_activity_indexes: índices para el historial de auditoría con filtros.
-- El módulo Actividad filtra por agencia y, opcionalmente, por acción o proyecto, ordenando por fecha.

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'activity_log'
              AND index_name = 'idx_activity_agency_action_time'),
    'SET @migration_noop = 1',
    'ALTER TABLE `activity_log` ADD INDEX `idx_activity_agency_action_time` (`agency_id`, `action`, `created_at`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'activity_log'
              AND index_name = 'idx_activity_agency_project_time'),
    'SET @migration_noop = 1',
    'ALTER TABLE `activity_log` ADD INDEX `idx_activity_agency_project_time` (`agency_id`, `project_id`, `created_at`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
