-- 0045_project_client_and_start: cliente y fecha de inicio del proyecto.
-- Se usan en el alta y el detalle del proyecto en React.

ALTER TABLE `projects`
    ADD COLUMN `client_name` VARCHAR(150) NULL AFTER `name`,
    ADD COLUMN `start_date` DATE NULL AFTER `deadline`;
