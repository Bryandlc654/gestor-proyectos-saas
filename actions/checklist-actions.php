<?php
// actions/checklist-actions.php
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
$projectId = (int)($_POST['project_id'] ?? 0);

if (!verify_csrf($csrf)) {
    setFlash('error', 'Token de seguridad inválido.');
    header("Location: ../project-view.php?id={$projectId}#checklists");
    exit;
}

// Verificar acceso al proyecto según rol (propietario, miembro o admin)
if (!canAccessProject($projectId, $user['id'], $user['role'])) {
    setFlash('error', 'Acceso no autorizado.');
    header('Location: ../dashboard.php');
    exit;
}

if (!canManageChecklists($user['role'])) {
    setFlash('error', 'Tu nivel de acceso no permite modificar listas de verificación.');
    header("Location: ../project-view.php?id={$projectId}#checklists");
    exit;
}

switch ($action) {
    case 'create_checklist':
        $title = trim($_POST['title'] ?? '');
        if (!empty($title)) {
            $stmt = $db->prepare("INSERT INTO checklists (project_id, title) VALUES (?, ?)");
            $stmt->execute([$projectId, $title]);
            setFlash('success', 'Lista de verificación creada.');
        } else {
            setFlash('error', 'El nombre de la lista es obligatorio.');
        }
        break;

    case 'delete_checklist':
        $checklistId = (int)($_POST['checklist_id'] ?? 0);
        if ($checklistId > 0) {
            $stmt = $db->prepare("DELETE FROM checklists WHERE id = ? AND project_id = ?");
            $stmt->execute([$checklistId, $projectId]);
            setFlash('success', 'Lista eliminada correctamente.');
        }
        break;

    case 'add_item':
        $checklistId = (int)($_POST['checklist_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');

        if ($checklistId > 0 && !empty($title)) {
            // Verificar que el checklist pertenezca al proyecto
            $checkOwner = $db->prepare("SELECT id FROM checklists WHERE id = ? AND project_id = ?");
            $checkOwner->execute([$checklistId, $projectId]);
            if ($checkOwner->fetch()) {
                $stmt = $db->prepare("INSERT INTO checklist_items (checklist_id, title, is_completed) VALUES (?, ?, 0)");
                $stmt->execute([$checklistId, $title]);
                setFlash('success', 'Paso añadido a la lista.');
            }
        }
        break;

    case 'delete_item':
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $stmt = $db->prepare("
                DELETE ci FROM checklist_items ci
                INNER JOIN checklists c ON ci.checklist_id = c.id
                WHERE ci.id = ? AND c.project_id = ?
            ");
            $stmt->execute([$itemId, $projectId]);
            setFlash('success', 'Paso eliminado.');
        }
        break;
}

header("Location: ../project-view.php?id={$projectId}#checklists");
exit;
