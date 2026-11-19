-- 0024_dynamic_task_statuses: columnas de estado personalizadas (crear, ordenar y tipo).
-- Los estados siguen siendo por agencia; las tareas pasan a guardar la clave de la columna.

ALTER TABLE `agency_task_statuses`
    ADD COLUMN `kind` ENUM('open', 'in_progress', 'done') NOT NULL DEFAULT 'open' AFTER `label`,
    ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `kind`;

UPDATE `agency_task_statuses` SET `kind` = 'in_progress', `sort_order` = 2 WHERE `status_key` = 'en_progreso';
UPDATE `agency_task_statuses` SET `kind` = 'done', `sort_order` = 3 WHERE `status_key` = 'completada';
UPDATE `agency_task_statuses` SET `kind` = 'open', `sort_order` = 1 WHERE `status_key` = 'pendiente';
UPDATE `agency_task_statuses` SET `sort_order` = `id` WHERE `sort_order` = 0;

ALTER TABLE `tasks` MODIFY COLUMN `status` VARCHAR(40) NOT NULL DEFAULT 'pendiente';
