-- 0021_invoice_documents: documentos adjuntos a una factura.
-- Se guardan en project_files con invoice_id; así reutilizan el almacenamiento y la validación existentes.

ALTER TABLE `project_files` ADD COLUMN `invoice_id` INT NULL AFTER `folder_id`;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'project_files'
              AND index_name = 'idx_files_invoice'),
    'SET @migration_noop = 1',
    'ALTER TABLE `project_files` ADD INDEX `idx_files_invoice` (`invoice_id`, `created_at`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.table_constraints
            WHERE constraint_schema = DATABASE() AND table_name = 'project_files'
              AND constraint_name = 'fk_files_invoice'),
    'SET @migration_noop = 1',
    'ALTER TABLE `project_files` ADD CONSTRAINT `fk_files_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
