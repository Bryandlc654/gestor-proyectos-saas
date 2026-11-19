-- 0006_project_sharing: proyectos compartidos entre agencias conectadas.
-- La agencia dueña comparte un proyecto con una agencia conectada y decide el acceso:
--   view        -> ver avance, tareas, comentarios y adjuntos (sin poder editar)
--   collaborate -> además crear/mover tareas, comentar y subir archivos
-- La agencia invitada elige qué miembros de su equipo participan en el proyecto.
-- Las finanzas (presupuesto y facturas) siguen siendo solo de la agencia dueña.

CREATE TABLE IF NOT EXISTS `project_shares` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT NOT NULL,
    `owner_agency_id` INT NOT NULL,
    `guest_agency_id` INT NOT NULL,
    `access` ENUM('view', 'collaborate') NOT NULL DEFAULT 'view',
    `shared_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_project_guest` (`project_id`, `guest_agency_id`),
    INDEX `idx_share_guest` (`guest_agency_id`),
    INDEX `idx_share_owner` (`owner_agency_id`),
    CONSTRAINT `fk_share_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_share_owner` FOREIGN KEY (`owner_agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_share_guest` FOREIGN KEY (`guest_agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_share_user` FOREIGN KEY (`shared_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Miembros de la agencia invitada que pueden entrar al proyecto compartido.
-- Se guardan aparte de project_members para no mezclar el equipo de la agencia dueña
-- con el de la invitada (cada uno conserva su propio aislamiento).
CREATE TABLE IF NOT EXISTS `project_share_members` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `share_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_share_user` (`share_id`, `user_id`),
    CONSTRAINT `fk_share_member_share` FOREIGN KEY (`share_id`) REFERENCES `project_shares` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_share_member_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
