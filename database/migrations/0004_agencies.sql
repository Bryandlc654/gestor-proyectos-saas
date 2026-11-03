-- 0004_agencies: base multi-agencia (SaaS).
-- Cada usuario y cada proyecto pertenece a una agencia; los datos existentes pasan a una agencia
-- inicial creada con el nombre y logo configurados hasta ahora en app_settings.

CREATE TABLE IF NOT EXISTS `agencies` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `logo` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users` ADD COLUMN `agency_id` INT NULL AFTER `id`;
ALTER TABLE `projects` ADD COLUMN `agency_id` INT NULL AFTER `id`;

-- Agencia inicial solo si ya hay datos (en una instalación nueva no se crea nada) y no se creó
-- ya en una ejecución anterior de esta migración que se cortó a mitad
INSERT INTO `agencies` (`name`, `logo`)
SELECT
    COALESCE((SELECT NULLIF(`setting_value`, '') FROM `app_settings` WHERE `setting_key` = 'app_name'), 'Mi agencia'),
    (SELECT NULLIF(`setting_value`, '') FROM `app_settings` WHERE `setting_key` = 'app_logo')
FROM DUAL
WHERE (EXISTS (SELECT 1 FROM `users`) OR EXISTS (SELECT 1 FROM `projects`))
  AND NOT EXISTS (SELECT 1 FROM `agencies`);

UPDATE `users` SET `agency_id` = (SELECT MIN(`id`) FROM `agencies`) WHERE `agency_id` IS NULL;
UPDATE `projects` SET `agency_id` = (SELECT MIN(`id`) FROM `agencies`) WHERE `agency_id` IS NULL;

ALTER TABLE `users`
    MODIFY `agency_id` INT NOT NULL,
    ADD INDEX `idx_users_agency` (`agency_id`),
    ADD CONSTRAINT `fk_users_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE;

ALTER TABLE `projects`
    MODIFY `agency_id` INT NOT NULL,
    ADD INDEX `idx_projects_agency` (`agency_id`),
    ADD CONSTRAINT `fk_projects_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE;

-- Invitaciones por enlace para el equipo y los clientes de cada agencia.
-- Solo se guarda el hash del token; el enlace completo se muestra una única vez al crearla.
CREATE TABLE IF NOT EXISTS `agency_invitations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `agency_id` INT NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `role` ENUM('admin', 'project_manager', 'colaborador', 'cliente') NOT NULL DEFAULT 'colaborador',
    `token_hash` CHAR(64) NOT NULL,
    `invited_by` INT NULL,
    `expires_at` DATETIME NOT NULL,
    `accepted_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_invitation_token` (`token_hash`),
    INDEX `idx_invitation_agency` (`agency_id`),
    CONSTRAINT `fk_invitation_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_invitation_inviter` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
