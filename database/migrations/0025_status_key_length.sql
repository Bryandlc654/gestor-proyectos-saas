-- 0025_status_key_length: las claves de columnas personalizadas pueden ser más largas.
ALTER TABLE `agency_task_statuses` MODIFY COLUMN `status_key` VARCHAR(40) NOT NULL;
