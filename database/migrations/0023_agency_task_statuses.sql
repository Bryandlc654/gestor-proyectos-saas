-- 0023_agency_task_statuses: nombres personalizados de las columnas del Kanban por agencia.
-- Las claves internas (pendiente, en_progreso, completada) no cambian; solo su etiqueta visible.

CREATE TABLE IF NOT EXISTS `agency_task_statuses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `status_key` VARCHAR(20) NOT NULL,
    `label` VARCHAR(40) NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_agency_task_status` (`agency_id`, `status_key`),
    CONSTRAINT `fk_agency_task_status_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
