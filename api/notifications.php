<?php
// api/notifications.php - Campana de notificaciones internas
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

$user = currentUser();
$userId = (int)$user['id'];
$repo = notificationRepository();

switch ($_POST['action'] ?? 'list') {
    case 'count':
        // Consulta mínima para el badge: no trae la lista completa
        echo json_encode([
            'success' => true,
            'unread' => $repo->unreadCount($userId),
        ]);
        exit;

    case 'list':
        [$notifications, $unread] = $repo->forUserWithUnread($userId);
        $notifications = array_map(static function (array $notification): array {
            $notification['created_at'] = formatDate($notification['created_at'], true);
            return $notification;
        }, $notifications);
        echo json_encode([
            'success' => true,
            'unread' => $unread,
            'notifications' => $notifications,
        ]);
        exit;

    case 'mark_read':
        $id = (int)($_POST['notification_id'] ?? 0);
        echo json_encode([
            'success' => $repo->markRead($id, $userId),
            'unread' => $repo->unreadCount($userId),
        ]);
        exit;

    case 'mark_all_read':
        $repo->markAllRead($userId);
        echo json_encode(['success' => true, 'unread' => 0]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);
        exit;
}
