-- 0017_email_verification: verificación del correo al registrarse.
-- El enlace es de un solo uso, caduca en 48 horas y solo se guarda el hash del token.

ALTER TABLE `users` ADD COLUMN `email_verified_at` DATETIME NULL AFTER `avatar`;

-- Las cuentas que ya existían se dan por verificadas: la verificación se pide a partir de esta versión.
UPDATE `users` SET `email_verified_at` = COALESCE(`created_at`, NOW()) WHERE `email_verified_at` IS NULL;

CREATE TABLE IF NOT EXISTS `email_verifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_email_verification_token` (`token_hash`),
    INDEX `idx_email_verification_user` (`user_id`),
    CONSTRAINT `fk_email_verification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
