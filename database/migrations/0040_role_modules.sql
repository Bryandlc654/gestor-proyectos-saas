-- 0040_role_modules: módulos habilitados por rol y agencia.
-- Los administradores conservan acceso total y no se almacenan aquí.

CREATE TABLE IF NOT EXISTS `agency_role_modules` (
    `agency_id` INT NOT NULL,
    `role` ENUM('project_manager', 'colaborador', 'cliente') NOT NULL,
    `module` VARCHAR(40) NOT NULL,
    `allowed` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`agency_id`, `role`, `module`),
    CONSTRAINT `fk_role_modules_agency` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
