-- 0036_crm_projects_quotes: conecta oportunidades con proyectos y propuestas.
-- Una oportunidad ganada puede convertirse en un proyecto y puede tener varias cotizaciones.

ALTER TABLE `crm_deals`
    ADD COLUMN `converted_project_id` INT NULL AFTER `lost_reason`,
    ADD COLUMN `converted_at` DATETIME NULL AFTER `converted_project_id`,
    ADD INDEX `idx_crm_deals_project` (`converted_project_id`),
    ADD CONSTRAINT `fk_crm_deal_project` FOREIGN KEY (`converted_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL;

ALTER TABLE `quotes`
    ADD COLUMN `deal_id` INT NULL AFTER `project_id`,
    ADD INDEX `idx_quotes_deal` (`deal_id`),
    ADD CONSTRAINT `fk_quotes_deal` FOREIGN KEY (`deal_id`) REFERENCES `crm_deals` (`id`) ON DELETE SET NULL;
