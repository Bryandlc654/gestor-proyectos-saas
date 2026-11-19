-- 0007_vertice_branding: renombra la marca por defecto en instalaciones existentes.
-- El nuevo valor por defecto ya está en el baseline; esto actualiza las bases que venían de FlowTasks.

UPDATE `app_settings`
SET `setting_value` = 'Vertice'
WHERE `setting_key` = 'app_name' AND `setting_value` = 'FlowTasks';
