-- 0022_support: solicitudes de soporte enviadas al equipo de Vértice.
-- Se guarda el histórico por agencia y se avisa por correo al buzón de soporte.

CREATE TABLE IF NOT EXISTS `support_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `user_id` INT NULL,
    `contact_email` VARCHAR(150) NULL,
    `subject` VARCHAR(150) NOT NULL,
    `category` ENUM('tecnico', 'facturacion', 'cuenta', 'otro') NOT NULL DEFAULT 'otro',
    `priority` ENUM('baja', 'media', 'alta') NOT NULL DEFAULT 'media',
    `status` ENUM('abierto', 'en_proceso', 'resuelto') NOT NULL DEFAULT 'abierto',
    `message` TEXT NOT NULL,
    `resolved_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_support_agency_time` (`agency_id`, `created_at`),
    INDEX `idx_support_status_time` (`status`, `created_at`),
    INDEX `idx_support_user` (`user_id`),
    CONSTRAINT `fk_support_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_support_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
