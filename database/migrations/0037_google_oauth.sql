-- 0037_google_oauth: vincula cuentas de usuario con Google Sign-In.
-- google_sub es el identificador estable de Google (OpenID Connect subject).

ALTER TABLE `users`
    ADD COLUMN `google_sub` VARCHAR(255) NULL AFTER `password`,
    ADD COLUMN `google_linked_at` DATETIME NULL AFTER `google_sub`,
    ADD UNIQUE KEY `uq_users_google_sub` (`google_sub`);
