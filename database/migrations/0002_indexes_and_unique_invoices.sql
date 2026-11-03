-- 0002: índices para los filtros y ordenaciones más frecuentes + número de factura único por proyecto.

-- Renombrar números de factura duplicados (si los hubiera) antes de crear el índice único
UPDATE `invoices` i
INNER JOIN (
    SELECT `project_id`, `invoice_number`, MIN(`id`) AS keep_id
    FROM `invoices`
    GROUP BY `project_id`, `invoice_number`
    HAVING COUNT(*) > 1
) d ON i.`project_id` = d.`project_id` AND i.`invoice_number` = d.`invoice_number` AND i.`id` <> d.keep_id
SET i.`invoice_number` = CONCAT(i.`invoice_number`, '-', i.`id`);

ALTER TABLE `invoices`
    ADD UNIQUE KEY `uq_invoice_project_number` (`project_id`, `invoice_number`),
    ADD INDEX `idx_invoices_status_due` (`status`, `due_date`);

ALTER TABLE `tasks`
    ADD INDEX `idx_tasks_project_status` (`project_id`, `status`),
    ADD INDEX `idx_tasks_due_date` (`due_date`);

ALTER TABLE `tickets`
    ADD INDEX `idx_tickets_project_status` (`project_id`, `status`);

ALTER TABLE `comments`
    ADD INDEX `idx_comments_project_created` (`project_id`, `created_at`);

ALTER TABLE `projects`
    ADD INDEX `idx_projects_status` (`status`);

ALTER TABLE `users`
    ADD INDEX `idx_users_role` (`role`);
