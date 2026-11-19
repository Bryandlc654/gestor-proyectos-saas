-- 0015_agency_slugs: enlace público por agencia (ej. /agencia1).

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'agencies'
              AND column_name = 'slug'),
    'SET @migration_noop = 1',
    'ALTER TABLE `agencies` ADD COLUMN `slug` VARCHAR(60) NULL AFTER `connect_code`'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Valor estable para agencias existentes; luego el admin puede cambiarlo desde Configuración → Marca.
UPDATE `agencies` SET `slug` = CONCAT('agencia-', `id`) WHERE `slug` IS NULL OR `slug` = '';

ALTER TABLE `agencies` MODIFY `slug` VARCHAR(60) NOT NULL DEFAULT '';

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'agencies'
              AND index_name = 'uq_agency_slug'),
    'SET @migration_noop = 1',
    'ALTER TABLE `agencies` ADD UNIQUE KEY `uq_agency_slug` (`slug`)'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
