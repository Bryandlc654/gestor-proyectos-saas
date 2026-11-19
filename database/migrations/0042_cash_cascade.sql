-- 0042_cash_cascade: al eliminar una agencia, sus movimientos deben borrarse
-- junto con sus cuentas y categorías. La aplicación sigue impidiendo borrar
-- cuentas o categorías con movimientos desde la interfaz.
--
-- Las claves originales usaban RESTRICT, lo que bloqueaba el borrado en cascada
-- de `agencies` porque MySQL intentaba eliminar `cash_accounts` antes que
-- `cash_transactions`.

ALTER TABLE `cash_transactions`
    DROP FOREIGN KEY `fk_cash_transactions_account`,
    DROP FOREIGN KEY `fk_cash_transactions_category`;

ALTER TABLE `cash_transactions`
    ADD CONSTRAINT `fk_cash_transactions_account`
        FOREIGN KEY (`account_id`) REFERENCES `cash_accounts` (`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_cash_transactions_category`
        FOREIGN KEY (`category_id`) REFERENCES `cash_categories` (`id`) ON DELETE CASCADE;
