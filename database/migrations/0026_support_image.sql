-- 0026_support_image: imagen adjunta opcional en las solicitudes de soporte.
-- Se guarda la ruta relativa dentro de uploads/ (por ejemplo: support/abc123.jpg).

ALTER TABLE `support_requests`
    ADD COLUMN `image_path` VARCHAR(255) NULL AFTER `message`;
