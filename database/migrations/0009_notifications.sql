-- 0009_notifications: notificaciones internas por usuario.
-- El email es opcional: si MAIL_ENABLED está activo se manda también por correo.

CREATE TABLE IF NOT EXISTS `notifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `type` VARCHAR(40) NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `message` VARCHAR(500) NULL,
    `url` VARCHAR(255) NULL,
    `read_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_notifications_user_read` (`user_id`, `read_at`, `created_at`),
    INDEX `idx_notifications_agency_time` (`agency_id`, `created_at`),
    CONSTRAINT `fk_notifications_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
