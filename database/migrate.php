<?php
// database/migrate.php - Migración para soporte de adjuntos y checklists vinculados a tareas
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();

    // 1. Agregar task_id a project_files si no existe
    $colsTaskFiles = $db->query("SHOW COLUMNS FROM project_files LIKE 'task_id'")->fetchAll();
    if (empty($colsTaskFiles)) {
        $db->exec("ALTER TABLE `project_files` ADD COLUMN `task_id` INT NULL AFTER `folder_id`");
        $db->exec("ALTER TABLE `project_files` ADD CONSTRAINT `fk_files_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE");
    }

    // 2. Agregar task_id a project_links si no existe
    $colsTaskLinks = $db->query("SHOW COLUMNS FROM project_links LIKE 'task_id'")->fetchAll();
    if (empty($colsTaskLinks)) {
        $db->exec("ALTER TABLE `project_links` ADD COLUMN `task_id` INT NULL AFTER `folder_id`");
        $db->exec("ALTER TABLE `project_links` ADD CONSTRAINT `fk_links_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE");
    }

    // 3. Agregar task_id a checklists si no existe
    $colsTaskChecklists = $db->query("SHOW COLUMNS FROM checklists LIKE 'task_id'")->fetchAll();
    if (empty($colsTaskChecklists)) {
        $db->exec("ALTER TABLE `checklists` ADD COLUMN `task_id` INT NULL AFTER `project_id`");
        $db->exec("ALTER TABLE `checklists` ADD CONSTRAINT `fk_checklists_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE");
    }

    echo "Migración de adjuntos y checklists para tareas completada con éxito.\n";
} catch (Exception $e) {
    echo "Aviso de migración: " . $e->getMessage() . "\n";
}
