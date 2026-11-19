<?php
// api/app/connections.php - Agencias conectadas para React.
require_once __DIR__ . '/_bootstrap.php';

if (!isAdmin() || !moduleAllowed('connections')) {
    appJson(['error' => 'Solo los administradores gestionan conexiones'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $connectionId = (int)($_POST['connection_id'] ?? 0);
    try {
        if ($action === 'request') {
            $status = connectionRepository()->request($agencyId, (string)($_POST['code'] ?? ''), $userId);
            appJson(['success' => true, 'status' => $status]);
        }
        if ($action === 'accept') {
            appJson(['success' => connectionRepository()->accept($connectionId, $agencyId)]);
        }
        if ($action === 'remove') {
            appJson(['success' => connectionRepository()->remove($connectionId, $agencyId)]);
        }
        if ($action === 'regenerate_code') {
            appJson(['success' => true, 'code' => agencyRepository()->regenerateConnectCode($agencyId)]);
        }
        appJson(['error' => 'Acción no válida'], 400);
    } catch (\DomainException $e) {
        appJson(['error' => $e->getMessage()], 422);
    }
}

appMethod('GET');

$groups = connectionRepository()->forAgency($agencyId);
$map = static function (array $rows): array {
    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'agencyId' => (int)$row['agency_id'],
            'agencyName' => $row['agency_name'],
            'agencyLogo' => $row['agency_logo'] ? '/' . ltrim($row['agency_logo'], '/') : null,
            'createdAt' => $row['created_at'],
            'acceptedAt' => $row['accepted_at'],
        ];
    }, $rows);
};

appJson([
    'code' => agencyRepository()->connectCode($agencyId),
    'connected' => $map($groups['connected']),
    'incoming' => $map($groups['incoming']),
    'outgoing' => $map($groups['outgoing']),
]);
