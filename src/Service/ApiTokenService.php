<?php

namespace App\Service;

use App\Support\ApiException;
use App\Support\WebhookUrl;
use PDO;
use Throwable;

/**
 * Emisión, autenticación y autorización de tokens de integración.
 * Los tokens son de alta entropía, se guardan hasheados y se limitan por scopes,
 * proyectos permitidos, caducidad y peticiones por minuto.
 */
final class ApiTokenService
{
    public const SCOPES = [
        'projects:read' => 'Leer proyectos',
        'tickets:read' => 'Leer tickets',
        'tickets:write' => 'Crear y actualizar tickets',
    ];

    public const WEBHOOK_EVENTS = [
        'ticket.created' => 'Ticket creado',
        'ticket.updated' => 'Ticket actualizado',
        'ticket.status_changed' => 'Estado del ticket cambiado',
        'ticket.deleted' => 'Ticket eliminado',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param string[] $scopes
     * @param int[] $projectIds
     * @return array{token:string,record:array<string,mixed>}
     */
    public function createToken(
        int $agencyId,
        int $userId,
        string $name,
        array $scopes,
        array $projectIds,
        int $rateLimitPerMinute = 60,
        ?string $expiresAt = null,
        ?string $webhookUrl = null,
        array $webhookEvents = [],
        bool $webhookActive = true
    ): array {
        $name = trim($name);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
            throw new ApiException(422, 'validation_error', 'El nombre del token debe tener entre 3 y 80 caracteres.');
        }

        $scopes = array_values(array_unique(array_intersect($scopes, array_keys(self::SCOPES))));
        if ($scopes === []) {
            throw new ApiException(422, 'validation_error', 'Selecciona al menos un permiso para el token.');
        }

        $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn(int $id): bool => $id > 0)));
        if ($projectIds !== []) {
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            $stmt = $this->db->prepare("SELECT id FROM projects WHERE agency_id = ? AND id IN ($placeholders)");
            $stmt->execute([$agencyId, ...$projectIds]);
            $valid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            sort($valid);
            $requested = $projectIds;
            sort($requested);
            if ($valid !== $requested) {
                throw new ApiException(422, 'validation_error', 'Uno o más proyectos del token no pertenecen a tu agencia.');
            }
        }

        $rateLimitPerMinute = max(10, min(600, $rateLimitPerMinute));

        if ($expiresAt !== null && $expiresAt !== '') {
            $timestamp = strtotime($expiresAt);
            if ($timestamp === false || $timestamp <= time()) {
                throw new ApiException(422, 'validation_error', 'La fecha de caducidad debe ser futura.');
            }
            $expiresAt = date('Y-m-d H:i:s', $timestamp);
        } else {
            $expiresAt = null;
        }

        [$webhookUrl, $webhookEvents, $webhookActive] = $this->normalizeWebhookConfig($webhookUrl, $webhookEvents, $webhookActive);

        $rawToken = 'vrt_' . bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $prefix = substr($rawToken, 0, 12);

        $stmt = $this->db->prepare("
            INSERT INTO api_tokens
                (agency_id, name, token_prefix, token_hash, scopes, project_ids, webhook_url, webhook_events, webhook_active, rate_limit_per_minute, expires_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $agencyId,
            $name,
            $prefix,
            $tokenHash,
            json_encode($scopes, JSON_UNESCAPED_UNICODE),
            json_encode($projectIds, JSON_UNESCAPED_UNICODE),
            $webhookUrl,
            json_encode($webhookEvents, JSON_UNESCAPED_UNICODE),
            $webhookActive ? 1 : 0,
            $rateLimitPerMinute,
            $expiresAt,
            $userId,
        ]);

        $record = $this->findById((int)$this->db->lastInsertId(), $agencyId);
        if ($record === null) {
            throw new ApiException(500, 'internal_error', 'No se pudo crear el token.');
        }

        return ['token' => $rawToken, 'record' => $record];
    }

    /** @return array<int, array<string, mixed>> */
    public function listTokens(int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, u.name AS creator_name
            FROM api_tokens t
            LEFT JOIN users u ON u.id = t.created_by
            WHERE t.agency_id = ?
            ORDER BY t.created_at DESC, t.id DESC
        ");
        $stmt->execute([$agencyId]);

        $tokens = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['scopes_list'] = $this->decodeJsonArray((string)$row['scopes']);
            $row['project_ids_list'] = $this->decodeJsonArray((string)($row['project_ids'] ?? ''));
            $row['webhook_events_list'] = $this->decodeJsonArray((string)($row['webhook_events'] ?? ''));
            $row['webhook_secret'] = self::webhookSecret((string)$row['token_hash']);
            $row['is_revoked'] = !empty($row['revoked_at']);
            $row['is_expired'] = !empty($row['expires_at']) && strtotime((string)$row['expires_at']) <= time();
            $tokens[] = $row;
        }
        return $tokens;
    }

    /**
     * Actualiza la configuración del webhook de un token.
     *
     * @param string[] $events
     */
    public function updateWebhook(int $tokenId, int $agencyId, ?string $url, array $events, bool $active): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM api_tokens WHERE id = ? AND agency_id = ?");
        $stmt->execute([$tokenId, $agencyId]);
        if (!$stmt->fetchColumn()) {
            return false;
        }

        [$webhookUrl, $webhookEvents, $webhookActive] = $this->normalizeWebhookConfig($url, $events, $active);

        $stmt = $this->db->prepare("
            UPDATE api_tokens
            SET webhook_url = ?, webhook_events = ?, webhook_active = ?
            WHERE id = ? AND agency_id = ?
        ");
        $stmt->execute([
            $webhookUrl,
            json_encode($webhookEvents, JSON_UNESCAPED_UNICODE),
            $webhookActive ? 1 : 0,
            $tokenId,
            $agencyId,
        ]);
        return true;
    }

    public static function webhookSecret(string $tokenHash): string
    {
        return hash_hmac('sha256', 'vertice-webhook-v1', $tokenHash);
    }

    /**
     * @param string[] $events
     * @return array{0:?string,1:string[],2:bool}
     */
    private function normalizeWebhookConfig(?string $url, array $events, bool $active): array
    {
        $url = $url !== null ? trim($url) : '';
        $events = array_values(array_unique(array_intersect($events, array_keys(self::WEBHOOK_EVENTS))));

        if ($url === '') {
            return [null, [], false];
        }

        try {
            $url = WebhookUrl::normalize($url);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(422, 'validation_error', $e->getMessage());
        }

        if ($events === []) {
            throw new ApiException(422, 'validation_error', 'Selecciona al menos un evento para el webhook.');
        }

        return [$url, $events, $active];
    }

    public function revokeToken(int $id, int $agencyId): bool
    {
        $stmt = $this->db->prepare("UPDATE api_tokens SET revoked_at = NOW() WHERE id = ? AND agency_id = ? AND revoked_at IS NULL");
        $stmt->execute([$id, $agencyId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Autentica la petición actual mediante Authorization: Bearer.
     *
     * @return array<string, mixed>
     */
    public function authenticate(): array
    {
        $rawToken = $this->bearerToken();
        if ($rawToken === '') {
            throw new ApiException(401, 'missing_token', 'Falta la cabecera Authorization: Bearer <token>.');
        }

        $stmt = $this->db->prepare("
            SELECT t.*, a.name AS agency_name, u.name AS creator_name
            FROM api_tokens t
            INNER JOIN agencies a ON a.id = t.agency_id
            LEFT JOIN users u ON u.id = t.created_by
            WHERE t.token_hash = ?
            LIMIT 1
        ");
        $stmt->execute([hash('sha256', $rawToken)]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$token) {
            throw new ApiException(401, 'invalid_token', 'Token de API inválido.');
        }
        if (!empty($token['revoked_at'])) {
            throw new ApiException(401, 'revoked_token', 'Este token de API está revocado.');
        }
        if (!empty($token['expires_at']) && strtotime((string)$token['expires_at']) <= time()) {
            throw new ApiException(401, 'expired_token', 'Este token de API ha caducado.');
        }

        $this->applyRateLimit($token);

        $token['scopes_list'] = $this->decodeJsonArray((string)$token['scopes']);
        $token['project_ids_list'] = $this->decodeJsonArray((string)($token['project_ids'] ?? ''));

        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        try {
            $this->db->prepare("UPDATE api_tokens SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?")
                ->execute([$ip, (int)$token['id']]);
        } catch (Throwable $e) {
            error_log('api token touch: ' . $e->getMessage());
        }

        return $token;
    }

    /** @param array<string, mixed> $token */
    public function requireScope(array $token, string $scope): void
    {
        $scopes = (array)($token['scopes_list'] ?? []);
        if ($scope === 'tickets:read' && in_array('tickets:write', $scopes, true)) {
            return;
        }
        if (!in_array($scope, $scopes, true)) {
            throw new ApiException(403, 'insufficient_scope', 'El token no tiene el permiso ' . $scope . '.');
        }
    }

    /**
     * @param array<string, mixed> $token
     * @return array<int, int>
     */
    public function projectIds(array $token): array
    {
        return array_map('intval', (array)($token['project_ids_list'] ?? []));
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     */
    public function ensureProjectAccess(array $token, int $projectId): array
    {
        if ($projectId <= 0) {
            throw new ApiException(422, 'validation_error', 'El campo project_id es obligatorio.');
        }

        $stmt = $this->db->prepare("SELECT id, agency_id, name, status, currency FROM projects WHERE id = ? AND agency_id = ? AND archived_at IS NULL");
        $stmt->execute([$projectId, (int)$token['agency_id']]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$project) {
            throw new ApiException(404, 'project_not_found', 'El proyecto no existe o no pertenece a la agencia del token.');
        }

        $allowed = $this->projectIds($token);
        if ($allowed !== [] && !in_array($projectId, $allowed, true)) {
            throw new ApiException(403, 'project_forbidden', 'Este token no tiene acceso al proyecto indicado.');
        }

        return $project;
    }

    /**
     * Usuario que figura como autor de los tickets creados por la API.
     *
     * @param array<string, mixed> $token
     */
    public function actorUserId(array $token): int
    {
        $agencyId = (int)$token['agency_id'];
        $creatorId = (int)($token['created_by'] ?? 0);
        if ($creatorId > 0) {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND agency_id = ?");
            $stmt->execute([$creatorId, $agencyId]);
            if ($stmt->fetchColumn()) {
                return $creatorId;
            }
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE agency_id = ? ORDER BY (role = 'admin') DESC, id ASC LIMIT 1");
        $stmt->execute([$agencyId]);
        $fallback = (int)$stmt->fetchColumn();
        if ($fallback <= 0) {
            throw new ApiException(422, 'no_actor_user', 'La agencia no tiene usuarios disponibles para registrar el ticket.');
        }
        return $fallback;
    }

    /** @param array<string, mixed> $token */
    private function applyRateLimit(array $token): void
    {
        $tokenId = (int)$token['id'];
        $limit = max(10, min(600, (int)($token['rate_limit_per_minute'] ?? 60)));
        $window = intdiv(time(), 60) * 60;

        $stmt = $this->db->prepare("
            INSERT INTO api_rate_limits (token_id, window_start, hits)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE hits = hits + 1
        ");
        $stmt->execute([$tokenId, $window]);

        $stmt = $this->db->prepare("SELECT hits FROM api_rate_limits WHERE token_id = ? AND window_start = ?");
        $stmt->execute([$tokenId, $window]);
        $hits = (int)$stmt->fetchColumn();

        if ($hits > $limit) {
            throw new ApiException(429, 'rate_limited', 'Se ha superado el límite de peticiones por minuto de este token.', [
                'limit' => $limit,
                'window_start' => $window,
            ]);
        }

        if (random_int(1, 100) === 1) {
            try {
                $this->db->prepare("DELETE FROM api_rate_limits WHERE window_start < ?")->execute([time() - 3600]);
            } catch (Throwable $e) {
                error_log('api rate cleanup: ' . $e->getMessage());
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function findById(int $id, int $agencyId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM api_tokens WHERE id = ? AND agency_id = ?");
        $stmt->execute([$id, $agencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['scopes_list'] = $this->decodeJsonArray((string)$row['scopes']);
        $row['project_ids_list'] = $this->decodeJsonArray((string)($row['project_ids'] ?? ''));
        $row['webhook_events_list'] = $this->decodeJsonArray((string)($row['webhook_events'] ?? ''));
        $row['webhook_secret'] = self::webhookSecret((string)$row['token_hash']);
        return $row;
    }

    /** @return string[] */
    private function decodeJsonArray(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function bearerToken(): string
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) {
                if (strcasecmp((string)$key, 'Authorization') === 0) {
                    $header = (string)$value;
                    break;
                }
            }
        }

        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches)) {
            return $matches[1];
        }
        return '';
    }
}
