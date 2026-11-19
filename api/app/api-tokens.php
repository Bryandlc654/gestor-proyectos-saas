<?php
// api/app/api-tokens.php - Tokens e integraciones API para React.
require_once __DIR__ . '/_bootstrap.php';

use App\Service\ApiTokenService;

if (!isAdmin() || !moduleAllowed('api-tokens')) {
    appJson(['error' => 'Solo los administradores gestionan tokens de API'], 403);
}

$decodeList = static function (mixed $value): array {
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
    return is_array($value) ? array_values($value) : [];
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $scopes = $decodeList($_POST['scopes'] ?? []);
            $projectIds = $decodeList($_POST['project_ids'] ?? []);
            $webhookEvents = $decodeList($_POST['webhook_events'] ?? []);
            $rateLimit = max(1, (int)($_POST['rate_limit'] ?? 60));
            $expiresDays = (int)($_POST['expires_in_days'] ?? 365);
            $expiresAt = in_array($expiresDays, [30, 90, 365], true) ? date('Y-m-d H:i:s', strtotime('+' . $expiresDays . ' days')) : null;
            $webhookUrl = trim((string)($_POST['webhook_url'] ?? ''));
            $webhookActive = !empty($_POST['webhook_active']);

            $result = apiTokenService()->createToken(
                $agencyId,
                $userId,
                $name,
                $scopes,
                $projectIds,
                $rateLimit,
                $expiresAt,
                $webhookUrl !== '' ? $webhookUrl : null,
                $webhookEvents,
                $webhookActive
            );
            activityRepository()->logByUser($userId, null, 'api.token_created', 'Creó el token de API "' . $result['record']['name'] . '"');
            appJson([
                'success' => true,
                'token' => $result['token'],
                'record' => [
                    'id' => (int)$result['record']['id'],
                    'name' => $result['record']['name'],
                    'prefix' => $result['record']['token_prefix'],
                ],
            ]);
        }

        if ($action === 'revoke') {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            if (!apiTokenService()->revokeToken($tokenId, $agencyId)) appJson(['error' => 'Token no encontrado o ya revocado'], 404);
            activityRepository()->logByUser($userId, null, 'api.token_revoked', 'Revocó un token de API');
            appJson(['success' => true]);
        }

        if ($action === 'update_webhook') {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            $url = trim((string)($_POST['webhook_url'] ?? ''));
            $events = $decodeList($_POST['webhook_events'] ?? []);
            if (!apiTokenService()->updateWebhook($tokenId, $agencyId, $url !== '' ? $url : null, $events, !empty($_POST['webhook_active']))) {
                appJson(['error' => 'No se pudo actualizar el webhook'], 422);
            }
            activityRepository()->logByUser($userId, null, 'api.webhook_updated', 'Actualizó un webhook de API');
            appJson(['success' => true]);
        }

        if ($action === 'retry_delivery') {
            $deliveryId = (int)($_POST['delivery_id'] ?? 0);
            if ($deliveryId <= 0 || !webhookService()->retryDelivery($deliveryId, $agencyId)) {
                appJson(['error' => 'La entrega no existe o no pertenece a tu agencia.'], 404);
            }
            appJson(['success' => true, 'message' => 'Entrega reenviada a la cola. Se procesará en el próximo ciclo.']);
        }

        if ($action === 'process_queue') {
            $stats = webhookService()->processPending(50);
            appJson([
                'success' => true,
                'message' => 'Cola procesada: ' . (int)$stats['delivered'] . ' entregadas, ' . (int)$stats['failed'] . ' fallidas.',
                'delivered' => (int)$stats['delivered'],
                'failed' => (int)$stats['failed'],
            ]);
        }

        appJson(['error' => 'Acción no válida'], 400);
    } catch (\DomainException $e) {
        appJson(['error' => $e->getMessage()], 422);
    }
}

appMethod('GET');

$deliveriesByToken = [];
foreach (webhookService()->recentDeliveries($agencyId, null, 200) as $delivery) {
    $deliveriesByToken[(int)$delivery['token_id']][] = $delivery;
}

$tokens = [];
foreach (apiTokenService()->listTokens($agencyId) as $token) {
    $tokenDeliveries = [];
    foreach (array_slice($deliveriesByToken[(int)$token['id']] ?? [], 0, 5) as $delivery) {
        $tokenDeliveries[] = [
            'id' => (int)$delivery['id'],
            'eventType' => $delivery['event_type'],
            'status' => $delivery['status'],
            'attempts' => (int)$delivery['attempts'],
            'responseCode' => $delivery['response_code'] !== null ? (int)$delivery['response_code'] : null,
            'responseBody' => $delivery['response_body'],
            'lastAttemptAt' => $delivery['last_attempt_at'],
            'createdAt' => $delivery['created_at'],
        ];
    }

    $tokens[] = [
        'id' => (int)$token['id'],
        'name' => $token['name'],
        'prefix' => $token['token_prefix'],
        'scopes' => $token['scopes_list'],
        'projects' => array_map('intval', $token['project_ids_list']),
        'rateLimit' => (int)$token['rate_limit_per_minute'],
        'expiresAt' => $token['expires_at'],
        'revokedAt' => $token['revoked_at'],
        'lastUsedAt' => $token['last_used_at'],
        'lastUsedIp' => $token['last_used_ip'],
        'createdAt' => $token['created_at'],
        'creator' => $token['creator_name'],
        'isRevoked' => (bool)$token['is_revoked'],
        'isExpired' => (bool)$token['is_expired'],
        'webhookUrl' => $token['webhook_url'],
        'webhookActive' => (int)$token['webhook_active'] === 1,
        'webhookEvents' => $token['webhook_events_list'],
        'webhookSecret' => $token['webhook_secret'],
        'deliveries' => $tokenDeliveries,
    ];
}

$projects = getDB()->query('SELECT id, name FROM projects WHERE agency_id = ' . (int)$agencyId . ' AND archived_at IS NULL ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

appJson([
    'tokens' => $tokens,
    'scopes' => ApiTokenService::SCOPES,
    'webhookEvents' => ApiTokenService::WEBHOOK_EVENTS,
    'projects' => array_map(static fn(array $project): array => ['id' => (int)$project['id'], 'name' => $project['name']], $projects),
    'appUrl' => appUrl(),
]);
