-- 0010_password_reset_and_2fa: recuperación de contraseña y doble factor (TOTP).

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_password_reset_token` (`token_hash`),
    INDEX `idx_password_reset_user` (`user_id`, `created_at`),
    CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'users'
              AND column_name = 'two_factor_secret'),
    'SET @migration_noop = 1',
    'ALTER TABLE `users` ADD COLUMN `two_factor_secret` VARCHAR(64) NULL AFTER `avatar`'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'users'
              AND column_name = 'two_factor_enabled'),
    'SET @migration_noop = 1',
    'ALTER TABLE `users` ADD COLUMN `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `two_factor_secret`'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
    EXISTS (SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'users'
              AND column_name = 'two_factor_recovery_codes'),
    'SET @migration_noop = 1',
    'ALTER TABLE `users` ADD COLUMN `two_factor_recovery_codes` TEXT NULL AFTER `two_factor_enabled`'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
