-- 0028_invoice_client: cliente/deudor de una factura (cuenta por cobrar).
-- Es texto libre para permitir escribir un nombre nuevo o elegir una sugerencia.

ALTER TABLE `invoices`
    ADD COLUMN `client_name` VARCHAR(150) NULL AFTER `invoice_number`;
