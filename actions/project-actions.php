<?php
// actions/project-actions.php
require_once __DIR__ . '/../config/app.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit;
}

$user = currentUser();
$db = getDB();
$action = $_POST['action'] ?? '';
$csrf = $_POST['csrf_token'] ?? '';

if (!verify_csrf($csrf)) {
    setFlash('error', 'Token de seguridad inválido.');
    header('Location: ../dashboard.php');
    exit;
}

switch ($action) {
    case 'create':
        if (!canCreateProjects($user['role'])) {
            setFlash('error', 'Tu nivel de acceso no permite crear nuevos proyectos.');
            header('Location: ../dashboard.php');
            exit;
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = trim($_POST['category'] ?? 'Desarrollo Web');
        $color = trim($_POST['color'] ?? '#4F46E5');
        $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
        $budget = floatval($_POST['budget'] ?? 0);
        $currency = trim($_POST['currency'] ?? 'USD');

        if (empty($name)) {
            setFlash('error', 'El nombre del proyecto es obligatorio.');
            header('Location: ../dashboard.php');
            exit;
        }

        $stmt = $db->prepare("INSERT INTO projects (user_id, name, description, category, color, deadline, budget, currency, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo')");
        $stmt->execute([$user['id'], $name, $description, $category, $color, $deadline, $budget, $currency]);
        $newId = $db->lastInsertId();

        // Vincular al creador como miembro Project Manager
        $db->prepare("INSERT IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'project_manager')")
           ->execute([$newId, $user['id']]);

        setFlash('success', '¡Proyecto creado con éxito!');
        header("Location: ../project-view.php?id={$newId}");
        exit;

    case 'update':
        $projectId = (int)($_POST['project_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'activo';
        $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
        $budget = isset($_POST['budget']) ? floatval($_POST['budget']) : null;
        $currency = trim($_POST['currency'] ?? 'USD');

        if ($projectId <= 0 || empty($name)) {
            setFlash('error', 'Datos inválidos para actualizar el proyecto.');
            header('Location: ../dashboard.php');
            exit;
        }

        if (!canManageProject($projectId, $user['id'], $user['role'])) {
            setFlash('error', 'No tienes permisos para modificar este proyecto.');
            header('Location: ../dashboard.php');
            exit;
        }

        if ($budget !== null) {
            $stmt = $db->prepare("UPDATE projects SET name = ?, description = ?, status = ?, deadline = ?, budget = ?, currency = ? WHERE id = ?");
            $stmt->execute([$name, $description, $status, $deadline, $budget, $currency, $projectId]);
        } else {
            $stmt = $db->prepare("UPDATE projects SET name = ?, description = ?, status = ?, deadline = ? WHERE id = ?");
            $stmt->execute([$name, $description, $status, $deadline, $projectId]);
        }

        setFlash('success', 'Proyecto actualizado correctamente.');
        header("Location: ../project-view.php?id={$projectId}");
        exit;

    case 'delete':
        $projectId = (int)($_POST['project_id'] ?? 0);
        if ($projectId > 0) {
            if (!canDeleteProjects($user['role']) || !canManageProject($projectId, $user['id'], $user['role'])) {
                setFlash('error', 'Solo Administradores y el Project Manager responsable pueden eliminar el proyecto.');
                header('Location: ../dashboard.php');
                exit;
            }

            // Eliminar archivos físicos asociados antes de borrar de la base de datos
            $stmtFiles = $db->prepare("SELECT stored_name FROM project_files WHERE project_id = ?");
            $stmtFiles->execute([$projectId]);
            $filesToDelete = $stmtFiles->fetchAll();
            foreach ($filesToDelete as $f) {
                $filePath = __DIR__ . '/../uploads/' . $f['stored_name'];
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            $stmt = $db->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->execute([$projectId]);
            setFlash('success', 'Proyecto y todos sus elementos eliminados.');
        }
        header('Location: ../dashboard.php');
        exit;

    default:
        header('Location: ../dashboard.php');
        exit;
}
