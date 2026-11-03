<?php
// database/migrate_roles.php - Migración para roles y niveles de acceso
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();

    // 1. Agregar columna 'role' en 'users' si no existe
    $colRole = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetchAll();
    if (empty($colRole)) {
        $db->exec("ALTER TABLE `users` ADD COLUMN `role` ENUM('admin', 'project_manager', 'colaborador', 'cliente') NOT NULL DEFAULT 'colaborador' AFTER `email`");
        echo "Columna 'role' agregada a tabla 'users'.\n";
    } else {
        // Asegurar que el ENUM tenga exactamente los 4 valores
        $db->exec("ALTER TABLE `users` MODIFY COLUMN `role` ENUM('admin', 'project_manager', 'colaborador', 'cliente') NOT NULL DEFAULT 'colaborador'");
        echo "Columna 'role' actualizada.\n";
    }

    // 2. Asegurar que los usuarios existentes tengan un rol válido (admin para id 1)
    $db->exec("UPDATE `users` SET `role` = 'admin' WHERE `id` = 1 OR `email` = 'admin@demo.com'");

    // 3. Crear o actualizar usuarios de demostración para los 4 roles
    $passwordHash = password_hash('admin123', PASSWORD_BCRYPT);
    $demoUsers = [
        [
            'name'  => 'Administrador Sistema',
            'email' => 'admin@demo.com',
            'role'  => 'admin'
        ],
        [
            'name'  => 'Carlos Project Manager',
            'email' => 'pm@demo.com',
            'role'  => 'project_manager'
        ],
        [
            'name'  => 'Ana Colaboradora',
            'email' => 'colaborador@demo.com',
            'role'  => 'colaborador'
        ],
        [
            'name'  => 'Roberto Cliente',
            'email' => 'cliente@demo.com',
            'role'  => 'cliente'
        ]
    ];

    $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $insertStmt = $db->prepare("INSERT INTO users (name, email, role, password) VALUES (?, ?, ?, ?)");
    $updateStmt = $db->prepare("UPDATE users SET name = ?, role = ? WHERE email = ?");

    foreach ($demoUsers as $du) {
        $checkStmt->execute([$du['email']]);
        $existing = $checkStmt->fetch();
        if ($existing) {
            $updateStmt->execute([$du['name'], $du['role'], $du['email']]);
            echo "Usuario {$du['email']} actualizado como {$du['role']}.\n";
        } else {
            $insertStmt->execute([$du['name'], $du['email'], $du['role'], $passwordHash]);
            echo "Usuario {$du['email']} creado con rol {$du['role']}.\n";
        }
    }

    // 4. Crear tabla project_members si no existe
    $db->exec("CREATE TABLE IF NOT EXISTS `project_members` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `project_id` INT NOT NULL,
        `user_id` INT NOT NULL,
        `role` ENUM('project_manager', 'colaborador', 'cliente') DEFAULT 'colaborador',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_proj_user` (`project_id`, `user_id`),
        CONSTRAINT `fk_pm_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_pm_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Tabla 'project_members' verificada.\n";

    // Vincular usuarios demo a proyectos existentes para que puedan verlos de inmediato
    $projects = $db->query("SELECT id FROM projects")->fetchAll(PDO::FETCH_COLUMN);
    $allUsers = $db->query("SELECT id, role FROM users")->fetchAll();

    $stmtAddMember = $db->prepare("INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)");
    foreach ($projects as $pid) {
        foreach ($allUsers as $u) {
            $memberRole = ($u['role'] === 'admin') ? 'project_manager' : $u['role'];
            $stmtAddMember->execute([$pid, $u['id'], $memberRole]);
        }
    }
    echo "Miembros vinculados a proyectos existentes.\n";

    echo "Migración de roles completada con éxito.\n";
} catch (Exception $e) {
    echo "Error en migración: " . $e->getMessage() . "\n";
}
