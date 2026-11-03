<?php
// actions/ticket-actions.php
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
    header("Location: ../project-view.php?id={$projectId}#tickets");
    exit;
}

// Verificar acceso al proyecto según rol (propietario, miembro o admin)
if (!canAccessProject($projectId, $user['id'], $user['role'])) {
    setFlash('error', 'Acceso no autorizado.');
    header('Location: ../dashboard.php');
    exit;
}

// Permisos por acción según rol (todos pueden crear tickets)
$allowed = match ($action) {
    'create'        => canCreateTickets($user['role']),
    'update_status' => canChangeTicketStatus($user['role']),
    'delete'        => canDeleteTickets($user['role']),
    default         => false,
};
if (!$allowed) {
    setFlash('error', 'Tu nivel de acceso no permite realizar esta acción.');
    header("Location: ../project-view.php?id={$projectId}#tickets");
    exit;
}

switch ($action) {
    case 'create':
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['baja', 'media', 'alta', 'critica']) ? $_POST['priority'] : 'media';

        if (empty($title) || empty($description)) {
            setFlash('error', 'El asunto y la descripción son obligatorios para registrar un ticket.');
        } else {
            $stmt = $db->prepare("INSERT INTO tickets (project_id, user_id, title, description, priority, status) VALUES (?, ?, ?, ?, ?, 'abierto')");
            $stmt->execute([$projectId, $user['id'], $title, $description, $priority]);
            setFlash('success', 'Ticket registrado con éxito.');
        }
        break;

    case 'update_status':
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['abierto', 'en_revision', 'resuelto', 'cerrado']) ? $_POST['status'] : 'abierto';

        if ($ticketId > 0) {
            $stmt = $db->prepare("UPDATE tickets SET status = ? WHERE id = ? AND project_id = ?");
            $stmt->execute([$status, $ticketId, $projectId]);
            setFlash('success', 'Estado del ticket actualizado.');
        }
        break;

    case 'delete':
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        if ($ticketId > 0) {
            $stmt = $db->prepare("DELETE FROM tickets WHERE id = ? AND project_id = ?");
            $stmt->execute([$ticketId, $projectId]);
            setFlash('success', 'Ticket eliminado.');
        }
        break;
}

header("Location: ../project-view.php?id={$projectId}#tickets");
exit;
