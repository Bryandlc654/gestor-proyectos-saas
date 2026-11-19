-- 0033_general_receivables: una cuenta por cobrar puede no pertenecer a un proyecto.
-- agency_id identifica siempre la agencia dueña, incluso en cuentas generales.

ALTER TABLE `invoices`
    MODIFY COLUMN `project_id` INT NULL,
    ADD COLUMN `agency_id` INT NULL AFTER `project_id`,
    ADD INDEX `idx_invoices_agency` (`agency_id`, `status`),
    ADD CONSTRAINT `fk_invoices_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE;

UPDATE `invoices` i
INNER JOIN `projects` p ON p.id = i.project_id
SET i.agency_id = p.agency_id;
