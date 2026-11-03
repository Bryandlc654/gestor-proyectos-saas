<?php
// api/toggle-checklist.php - Endpoint JSON para marcar/desmarcar items de checklist
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$user = currentUser();
$db = getDB();

$csrf = $_POST['csrf_token'] ?? '';
if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido']);
    exit;
}

$itemId = (int)($_POST['item_id'] ?? 0);
$isCompleted = !empty($_POST['is_completed']) ? 1 : 0;

if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de item inválido']);
    exit;
}

if ($user['role'] === 'cliente') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Los clientes tienen acceso de solo lectura en las listas de verificación']);
    exit;
}

// Verificar que el item pertenezca a un proyecto al que el usuario tenga acceso
$stmt = $db->prepare("
    SELECT ci.id, c.project_id 
    FROM checklist_items ci
    INNER JOIN checklists c ON ci.checklist_id = c.id
    WHERE ci.id = ?
");
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item || !canAccessProject($item['project_id'], $user['id'], $user['role'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No tienes permisos para modificar este item']);
    exit;
}

$update = $db->prepare("UPDATE checklist_items SET is_completed = ? WHERE id = ?");
$update->execute([$isCompleted, $itemId]);

echo json_encode([
    'success' => true,
    'item_id' => $itemId,
    'is_completed' => (bool)$isCompleted
]);
exit;
