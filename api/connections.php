<?php
// api/connections.php - Conexiones entre agencias mediante código (solo administradores)
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

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Solo los administradores gestionan las conexiones con otras agencias']);
    exit;
}

$user = currentUser();
$agencyId = currentAgencyId();
$connections = connectionRepository();
$connectionId = (int)($_POST['connection_id'] ?? 0);

switch ($_POST['action'] ?? '') {
    // 1. Solicitar conexión con el código de otra agencia
    case 'request':
        try {
            $status = $connections->request($agencyId, (string)($_POST['code'] ?? ''), (int)$user['id']);
        } catch (DomainException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        echo json_encode([
            'success' => true,
            'status'  => $status,
            'message' => $status === 'accepted'
                ? 'Esa agencia ya te había enviado una solicitud: ahora estáis conectadas'
                : 'Solicitud enviada. La otra agencia tiene que aceptarla.',
        ]);
        exit;

    // 2. Aceptar una solicitud recibida
    case 'accept':
        echo json_encode($connections->accept($connectionId, $agencyId)
            ? ['success' => true, 'message' => 'Conexión aceptada']
            : ['success' => false, 'message' => 'Solicitud no encontrada']);
        exit;

    // 3. Rechazar, cancelar o desconectar
    case 'remove':
        echo json_encode($connections->remove($connectionId, $agencyId)
            ? ['success' => true, 'message' => 'Conexión eliminada']
            : ['success' => false, 'message' => 'Conexión no encontrada']);
        exit;

    // 4. Generar un código nuevo (el anterior deja de funcionar)
    case 'regenerate_code':
        echo json_encode(['success' => true, 'code' => agencyRepository()->regenerateConnectCode($agencyId)]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);
        exit;
}
