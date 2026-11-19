<?php
// api/v1/ticket.php - Consultar o actualizar un ticket concreto.
define('VERTICE_API_V1', true);
require __DIR__ . '/_bootstrap.php';

apiRequireMethod(['GET', 'PATCH']);

$service = apiTokenService();
$db = getDB();
$agencyId = (int)$apiToken['agency_id'];
$ticketId = (int)($_GET['id'] ?? 0);

if ($ticketId <= 0) {
    throw new App\Support\ApiException(422, 'validation_error', 'El identificador del ticket no es válido.');
}

$selectTicket = static function (int $id, int $agencyId) use ($db): ?array {
    $stmt = $db->prepare("
        SELECT t.*, u.name AS author_name
        FROM tickets t
        INNER JOIN projects p ON p.id = t.project_id
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.id = ? AND p.agency_id = ?
    ");
    $stmt->execute([$id, $agencyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};

$ticket = $selectTicket($ticketId, $agencyId);
if ($ticket === null) {
    throw new App\Support\ApiException(404, 'ticket_not_found', 'Ticket no encontrado.');
}
$service->ensureProjectAccess($apiToken, (int)$ticket['project_id']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $service->requireScope($apiToken, 'tickets:read');
    apiJson(['data' => apiTicketResource($ticket)]);
}

$service->requireScope($apiToken, 'tickets:write');
$body = apiJsonBody();

$statuses = ['abierto', 'en_revision', 'resuelto', 'cerrado'];
$priorities = ['baja', 'media', 'alta', 'critica'];
$fields = [];
$params = [];

if (array_key_exists('title', $body)) {
    $title = trim((string)$body['title']);
    if ($title === '' || mb_strlen($title) > 200) {
        throw new App\Support\ApiException(422, 'validation_error', 'El título no puede estar vacío ni superar 200 caracteres.', ['field' => 'title']);
    }
    $fields[] = 't.title = ?';
    $params[] = $title;
}

if (array_key_exists('description', $body)) {
    $description = trim((string)$body['description']);
    if ($description === '' || mb_strlen($description) > 20000) {
        throw new App\Support\ApiException(422, 'validation_error', 'La descripción no puede estar vacía ni superar 20000 caracteres.', ['field' => 'description']);
    }
    $fields[] = 't.description = ?';
    $params[] = $description;
}

if (array_key_exists('priority', $body)) {
    $priority = strtolower(trim((string)$body['priority']));
    if (!in_array($priority, $priorities, true)) {
        throw new App\Support\ApiException(422, 'validation_error', 'La prioridad no es válida.', ['field' => 'priority']);
    }
    $fields[] = 't.priority = ?';
    $params[] = $priority;
}

if (array_key_exists('status', $body)) {
    $status = strtolower(trim((string)$body['status']));
    if (!in_array($status, $statuses, true)) {
        throw new App\Support\ApiException(422, 'validation_error', 'El estado no es válido.', ['field' => 'status']);
    }
    $fields[] = 't.status = ?';
    $params[] = $status;
}

if (array_key_exists('external_url', $body)) {
    $externalUrl = trim((string)$body['external_url']);
    if ($externalUrl !== '' && (!isSafeUrl($externalUrl) || mb_strlen($externalUrl) > 500)) {
        throw new App\Support\ApiException(422, 'validation_error', 'external_url debe ser una URL HTTP/HTTPS válida.', ['field' => 'external_url']);
    }
    $fields[] = 't.external_url = ?';
    $params[] = $externalUrl !== '' ? $externalUrl : null;
}

if ($fields === []) {
    throw new App\Support\ApiException(422, 'validation_error', 'Indica al menos un campo para actualizar.');
}

$fields[] = 't.updated_at = NOW()';
$params[] = $ticketId;
$params[] = $agencyId;

$stmt = $db->prepare("
    UPDATE tickets t
    INNER JOIN projects p ON p.id = t.project_id
    SET " . implode(', ', $fields) . "
    WHERE t.id = ? AND p.agency_id = ?
");
$stmt->execute($params);

$updated = $selectTicket($ticketId, $agencyId);
if ($updated === null) {
    throw new App\Support\ApiException(404, 'ticket_not_found', 'Ticket no encontrado.');
}

webhookService()->dispatch($agencyId, 'ticket.updated', $updated, (int)$apiToken['id']);
if ((string)$ticket['status'] !== (string)$updated['status']) {
    webhookService()->dispatch($agencyId, 'ticket.status_changed', $updated, (int)$apiToken['id'], [
        'status' => (string)$ticket['status'],
    ]);
}

activityRepository()->log(
    $agencyId,
    (int)($apiToken['created_by'] ?? 0) ?: null,
    (int)$updated['project_id'],
    'api.ticket_updated',
    'Ticket #' . $ticketId . ' actualizado vía API',
    ['token_id' => (int)$apiToken['id'], 'fields' => array_values(array_map(static fn(string $field): string => trim(str_replace('t.', '', explode('=', $field)[0])), $fields))]
);

apiJson(['data' => apiTicketResource($updated)]);
