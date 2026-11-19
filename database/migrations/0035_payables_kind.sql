-- 0035_payables_kind: separa gastos directos de pagos a colaboradores.
-- Las cuentas existentes se consideran gastos directos para no reinterpretar datos históricos.

ALTER TABLE `payables`
    ADD COLUMN `kind` ENUM('direct', 'collaborator') NOT NULL DEFAULT 'direct' AFTER `category`,
    ADD INDEX `idx_payables_project_kind` (`project_id`, `kind`, `status`);
