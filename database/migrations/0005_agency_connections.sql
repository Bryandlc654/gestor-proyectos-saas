-- 0005_agency_connections: conexión entre agencias mediante un código.
-- Cada agencia tiene un código público (se genera al consultarlo por primera vez) que puede compartir
-- con otra agencia. La otra agencia lo introduce y la primera acepta o rechaza la solicitud.
-- Conectarse no comparte nada todavía: solo habilita que más adelante se compartan proyectos.

ALTER TABLE `agencies`
    ADD COLUMN `connect_code` CHAR(9) NULL AFTER `logo`,
    ADD UNIQUE KEY `uq_agency_connect_code` (`connect_code`);

CREATE TABLE IF NOT EXISTS `agency_connections` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `requester_agency_id` INT NOT NULL,
    `target_agency_id` INT NOT NULL,
    `status` ENUM('pending', 'accepted') NOT NULL DEFAULT 'pending',
    `requested_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `accepted_at` DATETIME NULL,
    -- Una sola conexión por pareja de agencias, la pida quien la pida
    `pair_low` INT AS (LEAST(`requester_agency_id`, `target_agency_id`)) STORED,
    `pair_high` INT AS (GREATEST(`requester_agency_id`, `target_agency_id`)) STORED,
    UNIQUE KEY `uq_connection_pair` (`pair_low`, `pair_high`),
    INDEX `idx_connection_target` (`target_agency_id`),
    CONSTRAINT `fk_connection_requester` FOREIGN KEY (`requester_agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_connection_target` FOREIGN KEY (`target_agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_connection_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
