-- 0046_quote_followup: seguimiento comercial, versiones y conversión de cotizaciones.

ALTER TABLE `quotes`
    ADD COLUMN `sent_at` DATETIME NULL AFTER `status`,
    ADD COLUMN `accepted_at` DATETIME NULL AFTER `sent_at`,
    ADD COLUMN `rejected_at` DATETIME NULL AFTER `accepted_at`,
    ADD COLUMN `last_emailed_at` DATETIME NULL AFTER `rejected_at`,
    ADD COLUMN `email_count` INT NOT NULL DEFAULT 0 AFTER `last_emailed_at`,
    ADD COLUMN `version` INT NOT NULL DEFAULT 1 AFTER `email_count`,
    ADD COLUMN `parent_quote_id` INT NULL AFTER `version`,
    ADD COLUMN `invoice_id` INT NULL AFTER `parent_quote_id`,
    ADD INDEX `idx_quotes_parent` (`parent_quote_id`),
    ADD INDEX `idx_quotes_invoice` (`invoice_id`),
    ADD CONSTRAINT `fk_quotes_parent` FOREIGN KEY (`parent_quote_id`) REFERENCES `quotes` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_quotes_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL;
