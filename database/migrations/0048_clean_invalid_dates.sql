-- 0048_clean_invalid_dates: limpia fechas imposibles (0000-00-00, mes o día 00).
-- Antes solo se validaba el formato AAAA-MM-DD; MySQL sin modo estricto guardaba 2026-02-31
-- como 0000-00-00 y el cronograma del front entraba en un bucle infinito.
-- Se compara como texto para que funcione también con NO_ZERO_DATE / modo estricto.

UPDATE `projects` SET `deadline` = NULL
WHERE CAST(`deadline` AS CHAR) LIKE '0000-%' OR CAST(`deadline` AS CHAR) LIKE '%-00-%' OR CAST(`deadline` AS CHAR) LIKE '%-00';

UPDATE `projects` SET `start_date` = NULL
WHERE CAST(`start_date` AS CHAR) LIKE '0000-%' OR CAST(`start_date` AS CHAR) LIKE '%-00-%' OR CAST(`start_date` AS CHAR) LIKE '%-00';

UPDATE `tasks` SET `due_date` = NULL
WHERE CAST(`due_date` AS CHAR) LIKE '0000-%' OR CAST(`due_date` AS CHAR) LIKE '%-00-%' OR CAST(`due_date` AS CHAR) LIKE '%-00';

UPDATE `tasks` SET `start_date` = NULL
WHERE CAST(`start_date` AS CHAR) LIKE '0000-%' OR CAST(`start_date` AS CHAR) LIKE '%-00-%' OR CAST(`start_date` AS CHAR) LIKE '%-00';

UPDATE `invoices` SET `due_date` = NULL
WHERE CAST(`due_date` AS CHAR) LIKE '0000-%' OR CAST(`due_date` AS CHAR) LIKE '%-00-%' OR CAST(`due_date` AS CHAR) LIKE '%-00';

UPDATE `baseline_tasks` SET `start_date` = NULL
WHERE CAST(`start_date` AS CHAR) LIKE '0000-%' OR CAST(`start_date` AS CHAR) LIKE '%-00-%' OR CAST(`start_date` AS CHAR) LIKE '%-00';

UPDATE `baseline_tasks` SET `due_date` = NULL
WHERE CAST(`due_date` AS CHAR) LIKE '0000-%' OR CAST(`due_date` AS CHAR) LIKE '%-00-%' OR CAST(`due_date` AS CHAR) LIKE '%-00';

-- Los hitos exigen fecha: se les asigna la de creación para que sigan visibles y editables.
UPDATE `project_milestones` SET `due_date` = DATE(`created_at`)
WHERE CAST(`due_date` AS CHAR) LIKE '0000-%' OR CAST(`due_date` AS CHAR) LIKE '%-00-%' OR CAST(`due_date` AS CHAR) LIKE '%-00';
