-- 0003: el responsable de una tarea pasa a ser una clave foránea a users (antes era el nombre en texto).
-- La columna `assigned_to` se conserva temporalmente como dato heredado de solo lectura:
-- se muestra mientras la tarea no tenga `assigned_user_id` y se vacía al reasignarla.

ALTER TABLE `tasks`
    ADD COLUMN `assigned_user_id` INT NULL AFTER `assigned_to`,
    ADD CONSTRAINT `fk_tasks_assignee` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- 1) Coincidencia exacta de nombre con un miembro (o el propietario) del mismo proyecto
UPDATE `tasks` t
INNER JOIN `projects` p ON p.`id` = t.`project_id`
INNER JOIN `users` u ON u.`name` = t.`assigned_to`
SET t.`assigned_user_id` = u.`id`
WHERE t.`assigned_user_id` IS NULL
  AND t.`assigned_to` IS NOT NULL AND t.`assigned_to` <> ''
  AND (u.`id` = p.`user_id`
       OR u.`id` IN (SELECT pm.`user_id` FROM `project_members` pm WHERE pm.`project_id` = t.`project_id`));

-- 2) Si no es miembro pero el nombre es único en el sistema, usar ese usuario
UPDATE `tasks` t
INNER JOIN (
    SELECT `name`, MIN(`id`) AS id FROM `users` GROUP BY `name` HAVING COUNT(*) = 1
) u ON u.`name` = t.`assigned_to`
SET t.`assigned_user_id` = u.`id`
WHERE t.`assigned_user_id` IS NULL
  AND t.`assigned_to` IS NOT NULL AND t.`assigned_to` <> '';
