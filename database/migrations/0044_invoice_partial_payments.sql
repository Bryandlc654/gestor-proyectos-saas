-- 0044_invoice_partial_payments: cobros parciales de facturas y cuentas por cobrar.
-- `paid_amount` guarda el total cobrado; `invoice_payments` conserva cada cobro.

ALTER TABLE `invoices`
    ADD COLUMN `paid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `amount`,
    ADD INDEX `idx_invoices_agency_paid` (`agency_id`, `paid_at`);

UPDATE `invoices`
SET `paid_amount` = `amount`
WHERE `status` = 'pagada' AND `paid_amount` = 0;

CREATE TABLE IF NOT EXISTS `invoice_payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_id` INT NOT NULL,
    `agency_id` INT NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `paid_at` DATE NOT NULL,
    `method` VARCHAR(40) NULL,
    `notes` VARCHAR(255) NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_invoice_payments_invoice` (`invoice_id`, `paid_at`),
    INDEX `idx_invoice_payments_agency` (`agency_id`),
    CONSTRAINT `fk_ip_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ip_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ip_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial inicial para facturas que ya estaban cobradas antes de esta migración.
INSERT INTO `invoice_payments` (`invoice_id`, `agency_id`, `amount`, `currency`, `paid_at`, `method`, `notes`, `created_by`)
SELECT i.`id`, COALESCE(i.`agency_id`, p.`agency_id`), i.`amount`, COALESCE(i.`currency`, 'USD'),
       COALESCE(i.`paid_at`, DATE(i.`created_at`)), NULL,
       'Cobro registrado antes de habilitar los pagos parciales', NULL
FROM `invoices` i
LEFT JOIN `projects` p ON p.`id` = i.`project_id`
WHERE i.`status` = 'pagada'
  AND COALESCE(i.`agency_id`, p.`agency_id`) IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `invoice_payments` ip WHERE ip.`invoice_id` = i.`id`);
