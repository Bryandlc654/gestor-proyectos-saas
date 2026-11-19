<?php
// api/v1/tickets.php - Crear y listar tickets desde sistemas externos.
define('VERTICE_API_V1', true);
require __DIR__ . '/_bootstrap.php';

apiRequireMethod(['GET', 'POST']);

$service = apiTokenService();
$db = getDB();
$agencyId = (int)$apiToken['agency_id'];
$statuses = ['abierto', 'en_revision', 'resuelto', 'cerrado'];
$priorities = ['baja', 'media', 'alta', 'critica'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $service->requireScope($apiToken, 'tickets:read');

    $projectId = (int)($_GET['project_id'] ?? 0);
    $status = strtolower(trim((string)($_GET['status'] ?? '')));
    $priority = strtolower(trim((string)($_GET['priority'] ?? '')));
    $updatedSince = trim((string)($_GET['updated_since'] ?? ''));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, min(10000, (int)($_GET['offset'] ?? 0)));

    if ($status !== '' && !in_array($status, $statuses, true)) {
        throw new App\Support\ApiException(422, 'validation_error', 'El estado indicado no es válido.', ['field' => 'status']);
    }
    if ($priority !== '' && !in_array($priority, $priorities, true)) {
        throw new App\Support\ApiException(422, 'validation_error', 'La prioridad indicada no es válida.', ['field' => 'priority']);
    }

    $sql = "
        SELECT t.*, u.name AS author_name
        FROM tickets t
        INNER JOIN projects p ON p.id = t.project_id
        LEFT JOIN users u ON u.id = t.user_id
        WHERE p.agency_id = ? AND p.archived_at IS NULL
    ";
    $params = [$agencyId];

    if ($projectId > 0) {
        $service->ensureProjectAccess($apiToken, $projectId);
        $sql .= " AND t.project_id = ?";
        $params[] = $projectId;
    } else {
        $allowed = $service->projectIds($apiToken);
        if ($allowed !== []) {
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $sql .= " AND t.project_id IN ($placeholders)";
            $params = array_merge($params, $allowed);
        }
    }

    if ($status !== '') {
        $sql .= " AND t.status = ?";
        $params[] = $status;
    }
    if ($priority !== '') {
        $sql .= " AND t.priority = ?";
        $params[] = $priority;
    }
    if ($updatedSince !== '') {
        $timestamp = strtotime($updatedSince);
        if ($timestamp === false) {
            throw new App\Support\ApiException(422, 'validation_error', 'updated_since debe ser una fecha ISO 8601 válida.', ['field' => 'updated_since']);
        }
        $sql .= " AND t.updated_at >= ?";
        $params[] = date('Y-m-d H:i:s', $timestamp);
    }

    $sql .= " ORDER BY t.updated_at DESC, t.id DESC LIMIT " . ($limit + 1) . " OFFSET {$offset}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    $tickets = array_map('apiTicketResource', $rows);
    apiJson(['data' => $tickets], 200, [
        'count' => count($tickets),
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => $hasMore,
    ]);
}

// POST: registrar ticket.
$service->requireScope($apiToken, 'tickets:write');
$body = apiJsonBody();

$projectId = (int)($body['project_id'] ?? 0);
$project = $service->ensureProjectAccess($apiToken, $projectId);

$title = trim((string)($body['title'] ?? ''));
$description = trim((string)($body['description'] ?? ''));
$priority = strtolower(trim((string)($body['priority'] ?? 'media')));
$status = strtolower(trim((string)($body['status'] ?? 'abierto')));
$externalSource = trim((string)($body['source'] ?? $body['external_source'] ?? ($apiToken['name'] ?? 'api')));
$externalId = trim((string)($body['external_id'] ?? ''));
$requesterName = trim((string)($body['requester_name'] ?? ''));
$requesterEmail = strtolower(trim((string)($body['requester_email'] ?? '')));
$externalUrl = trim((string)($body['external_url'] ?? ''));

if ($title === '' || mb_strlen($title) > 200) {
    throw new App\Support\ApiException(422, 'validation_error', 'El título es obligatorio y no puede superar 200 caracteres.', ['field' => 'title']);
}
if ($description === '' || mb_strlen($description) > 20000) {
    throw new App\Support\ApiException(422, 'validation_error', 'La descripción es obligatoria y no puede superar 20000 caracteres.', ['field' => 'description']);
}
if (!in_array($priority, $priorities, true)) {
    throw new App\Support\ApiException(422, 'validation_error', 'La prioridad debe ser baja, media, alta o critica.', ['field' => 'priority']);
}
if (!in_array($status, $statuses, true)) {
    throw new App\Support\ApiException(422, 'validation_error', 'El estado no es válido.', ['field' => 'status']);
}
if (mb_strlen($externalSource) > 60) {
    throw new App\Support\ApiException(422, 'validation_error', 'source no puede superar 60 caracteres.', ['field' => 'source']);
}
if ($externalSource === '') {
    $externalSource = 'api';
}
if (mb_strlen($externalId) > 100) {
    throw new App\Support\ApiException(422, 'validation_error', 'external_id no puede superar 100 caracteres.', ['field' => 'external_id']);
}
if (mb_strlen($requesterName) > 150) {
    throw new App\Support\ApiException(422, 'validation_error', 'requester_name no puede superar 150 caracteres.', ['field' => 'requester_name']);
}
if ($requesterEmail !== '' && (!filter_var($requesterEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($requesterEmail) > 150)) {
    throw new App\Support\ApiException(422, 'validation_error', 'requester_email no es válido.', ['field' => 'requester_email']);
}
if ($externalUrl !== '' && (!isSafeUrl($externalUrl) || mb_strlen($externalUrl) > 500)) {
    throw new App\Support\ApiException(422, 'validation_error', 'external_url debe ser una URL HTTP/HTTPS válida.', ['field' => 'external_url']);
}

$selectTicket = static function (int $ticketId, int $agencyId) use ($db): ?array {
    $stmt = $db->prepare("
        SELECT t.*, u.name AS author_name
        FROM tickets t
        INNER JOIN projects p ON p.id = t.project_id
        LEFT JOIN users u ON u.id = t.user_id
        WHERE t.id = ? AND p.agency_id = ?
    ");
    $stmt->execute([$ticketId, $agencyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};

// Idempotencia: si el sistema externo reintenta con el mismo external_id, no duplicamos.
if ($externalId !== '') {
    $stmt = $db->prepare("
        SELECT t.id
        FROM tickets t
        INNER JOIN projects p ON p.id = t.project_id
        WHERE t.project_id = ? AND t.external_source = ? AND t.external_id = ? AND p.agency_id = ?
        LIMIT 1
    ");
    $stmt->execute([$projectId, $externalSource, $externalId, $agencyId]);
    $existingId = (int)$stmt->fetchColumn();
    if ($existingId > 0) {
        $existing = $selectTicket($existingId, $agencyId);
        if ($existing !== null) {
            apiJson(['data' => apiTicketResource($existing)], 200, [
                'created' => false,
                'duplicate' => true,
            ]);
        }
    }
}

$actorId = $service->actorUserId($apiToken);
$stmt = $db->prepare("
    INSERT INTO tickets
        (project_id, user_id, title, description, priority, status, external_source, external_id,
         external_requester_name, external_requester_email, external_url)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $projectId,
    $actorId,
    $title,
    $description,
    $priority,
    $status,
    $externalSource,
    $externalId !== '' ? $externalId : null,
    $requesterName !== '' ? $requesterName : null,
    $requesterEmail !== '' ? $requesterEmail : null,
    $externalUrl !== '' ? $externalUrl : null,
]);
$ticketId = (int)$db->lastInsertId();
$ticket = $selectTicket($ticketId, $agencyId);

if ($ticket !== null) {
    webhookService()->dispatch($agencyId, 'ticket.created', $ticket, (int)$apiToken['id']);
}

activityRepository()->log(
    $agencyId,
    (int)($apiToken['created_by'] ?? 0) ?: null,
    $projectId,
    'api.ticket_created',
    'Ticket #' . $ticketId . ' creado vía API' . ($externalSource !== '' ? ' (' . $externalSource . ')' : ''),
    ['token_id' => (int)$apiToken['id'], 'external_source' => $externalSource, 'external_id' => $externalId ?: null]
);

apiJson(['data' => $ticket !== null ? apiTicketResource($ticket) : null], 201, [
    'created' => true,
    'duplicate' => false,
]);
