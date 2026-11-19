<?php
// api/v1/projects.php - Proyectos a los que puede acceder el token.
define('VERTICE_API_V1', true);
require __DIR__ . '/_bootstrap.php';

apiRequireMethod(['GET']);

$service = apiTokenService();
$service->requireScope($apiToken, 'projects:read');

$agencyId = (int)$apiToken['agency_id'];
$allowed = $service->projectIds($apiToken);

$sql = "SELECT id, name, status, currency, color, deadline, updated_at
        FROM projects
        WHERE agency_id = ? AND archived_at IS NULL";
$params = [$agencyId];

if ($allowed !== []) {
    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
    $sql .= " AND id IN ($placeholders)";
    $params = array_merge($params, $allowed);
}

$sql .= " ORDER BY name ASC";
$stmt = getDB()->prepare($sql);
$stmt->execute($params);

$projects = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $projects[] = [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'status' => (string)$row['status'],
        'currency' => (string)($row['currency'] ?: 'USD'),
        'color' => $row['color'] !== null ? (string)$row['color'] : null,
        'deadline' => $row['deadline'] !== null ? (string)$row['deadline'] : null,
        'updated_at' => !empty($row['updated_at']) ? date('c', strtotime((string)$row['updated_at'])) : null,
    ];
}

apiJson(['data' => $projects], 200, ['count' => count($projects)]);
