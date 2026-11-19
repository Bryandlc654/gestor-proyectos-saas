-- 0014_reminders_and_session_version: recordatorios de vencimiento e invalidación de sesiones.

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'tasks'
              AND column_name = 'reminder_sent_at'),
    'SET @migration_noop = 1',
    'ALTER TABLE `tasks` ADD COLUMN `reminder_sent_at` DATETIME NULL AFTER `due_date`'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'invoices'
              AND column_name = 'reminder_sent_at'),
    'SET @migration_noop = 1',
    'ALTER TABLE `invoices` ADD COLUMN `reminder_sent_at` DATETIME NULL AFTER `due_date`'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'users'
              AND column_name = 'session_version'),
    'SET @migration_noop = 1',
    'ALTER TABLE `users` ADD COLUMN `session_version` INT NOT NULL DEFAULT 0 AFTER `two_factor_recovery_codes`'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
