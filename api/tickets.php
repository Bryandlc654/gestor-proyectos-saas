<?php
// api/tickets.php - Gestión asíncrona de tickets de soporte
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$user = currentUser();
$db = getDB();
$csrf = $_POST['csrf_token'] ?? '';
$action = $_POST['action'] ?? '';
$projectId = (int)($_POST['project_id'] ?? 0);

if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

// Verificar pertenencia o acceso al proyecto según rol
if (!canAccessProject($projectId, $user['id'], $user['role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso denegado al proyecto']);
    exit;
}

switch ($action) {
    case 'create':
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['baja', 'media', 'alta', 'critica']) ? $_POST['priority'] : 'media';

        if (empty($title) || empty($description)) {
            echo json_encode(['success' => false, 'message' => 'Asunto y descripción son obligatorios']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO tickets (project_id, user_id, title, description, priority, status) VALUES (?, ?, ?, ?, ?, 'abierto')");
        $stmt->execute([$projectId, $user['id'], $title, $description, $priority]);
        $ticketId = (int)$db->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Ticket registrado',
            'ticket' => [
                'id' => $ticketId,
                'title' => e($title),
                'description' => e($description),
                'priority' => $priority,
                'status' => 'abierto',
                'author_name' => e($user['name']),
                'created_at' => formatDate(date('Y-m-d H:i:s'), true)
            ]
        ]);
        break;

    case 'update_status':
        if (!canChangeTicketStatus($user['role'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Tu nivel de acceso no permite cambiar el estado de tickets']);
            exit;
        }

        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['abierto', 'en_revision', 'resuelto', 'cerrado']) ? $_POST['status'] : 'abierto';

        $stmt = $db->prepare("UPDATE tickets SET status = ? WHERE id = ? AND project_id = ?");
        $stmt->execute([$status, $ticketId, $projectId]);

        echo json_encode([
            'success' => true,
            'message' => 'Estado del ticket actualizado',
            'ticket_id' => $ticketId,
            'status' => $status
        ]);
        break;

    case 'delete':
        if (!canDeleteTickets($user['role'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Solo los Administradores y Project Managers pueden eliminar tickets']);
            exit;
        }

        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM tickets WHERE id = ? AND project_id = ?");
        $stmt->execute([$ticketId, $projectId]);

        echo json_encode([
            'success' => true,
            'message' => 'Ticket eliminado',
            'ticket_id' => $ticketId
        ]);
        break;

    case 'list_replies':
        $ticketId = (int)($_GET['ticket_id'] ?? ($_POST['ticket_id'] ?? 0));

        // Verificar que el ticket pertenezca al proyecto
        $stmtT = $db->prepare("SELECT id FROM tickets WHERE id = ? AND project_id = ?");
        $stmtT->execute([$ticketId, $projectId]);
        if (!$stmtT->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Ticket no encontrado']);
            exit;
        }

        $stmtR = $db->prepare("
            SELECT r.*, u.name as author_name, u.role as author_role 
            FROM ticket_replies r 
            INNER JOIN users u ON r.user_id = u.id 
            WHERE r.ticket_id = ? 
            ORDER BY r.created_at ASC
        ");
        $stmtR->execute([$ticketId]);
        $replies = $stmtR->fetchAll();

        $data = array_map(function($r) use ($user) {
            $badge = getRoleBadge($r['author_role']);
            return [
                'id'          => (int)$r['id'],
                'ticket_id'   => (int)$r['ticket_id'],
                'user_id'     => (int)$r['user_id'],
                'author_name' => e($r['author_name']),
                'role_label'  => $badge['label'],
                'role_class'  => $badge['class'],
                'initials'    => strtoupper(substr($r['author_name'], 0, 2)),
                'message'     => nl2br(e($r['message'])),
                'raw_message' => $r['message'],
                'created_at'  => formatDate($r['created_at'], true),
                'can_delete'  => ($r['user_id'] == $user['id'] || isAdmin())
            ];
        }, $replies);

        echo json_encode(['success' => true, 'replies' => $data]);
        break;

    case 'add_reply':
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        if ($ticketId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Ticket no válido']);
            exit;
        }

        if (empty($message)) {
            echo json_encode(['success' => false, 'message' => 'El mensaje de respuesta no puede estar vacío']);
            exit;
        }

        // Verificar que el ticket exista en el proyecto
        $stmtT = $db->prepare("SELECT id, status FROM tickets WHERE id = ? AND project_id = ?");
        $stmtT->execute([$ticketId, $projectId]);
        $ticket = $stmtT->fetch();
        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Ticket no encontrado']);
            exit;
        }

        $stmtIns = $db->prepare("INSERT INTO ticket_replies (ticket_id, user_id, message) VALUES (?, ?, ?)");
        $stmtIns->execute([$ticketId, $user['id'], $message]);
        $replyId = (int)$db->lastInsertId();

        // Actualizar updated_at en tickets
        $db->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute([$ticketId]);

        $badge = getRoleBadge($user['role']);
        echo json_encode([
            'success' => true,
            'message' => 'Respuesta añadida al ticket',
            'reply' => [
                'id'          => $replyId,
                'ticket_id'   => $ticketId,
                'user_id'     => (int)$user['id'],
                'author_name' => e($user['name']),
                'role_label'  => $badge['label'],
                'role_class'  => $badge['class'],
                'initials'    => strtoupper(substr($user['name'], 0, 2)),
                'message'     => nl2br(e($message)),
                'raw_message' => $message,
                'created_at'  => formatDate(date('Y-m-d H:i:s'), true),
                'can_delete'  => true
            ]
        ]);
        break;

    case 'delete_reply':
        $replyId = (int)($_POST['reply_id'] ?? 0);
        $stmtChk = $db->prepare("SELECT r.id, r.user_id, r.ticket_id FROM ticket_replies r INNER JOIN tickets t ON r.ticket_id = t.id WHERE r.id = ? AND t.project_id = ?");
        $stmtChk->execute([$replyId, $projectId]);
        $rep = $stmtChk->fetch();

        if (!$rep) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Respuesta no encontrada']);
            exit;
        }

        if ($rep['user_id'] != $user['id'] && !isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permiso para eliminar esta respuesta']);
            exit;
        }

        $stmtDel = $db->prepare("DELETE FROM ticket_replies WHERE id = ?");
        $stmtDel->execute([$replyId]);

        echo json_encode(['success' => true, 'message' => 'Respuesta eliminada']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
        break;
}
exit;
