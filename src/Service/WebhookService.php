<?php

namespace App\Service;

use App\Support\TicketResource;
use App\Support\WebhookUrl;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Webhooks salientes: encola eventos de tickets y entrega las peticiones firmadas
 * con reintentos exponenciales. El secreto se deriva del hash del token.
 */
final class WebhookService
{
    private const MAX_ATTEMPTS = 8;
    private const BACKOFF = [60, 300, 900, 3600, 21600];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Encola un evento para todos los webhooks activos de la agencia que lo escuchen.
     * No interrumpe la operación principal del ticket si algo falla.
     *
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $previous
     */
    public function dispatch(int $agencyId, string $event, array $ticket, ?int $sourceTokenId = null, array $previous = []): void
    {
        if (!isset(ApiTokenService::WEBHOOK_EVENTS[$event])) {
            return;
        }

        try {
            $projectId = (int)($ticket['project_id'] ?? 0);
            if ($projectId <= 0) {
                return;
            }

            $stmt = $this->db->prepare("
                SELECT *
                FROM api_tokens
                WHERE agency_id = ?
                  AND webhook_active = 1
                  AND webhook_url IS NOT NULL
                  AND webhook_url <> ''
                  AND revoked_at IS NULL
                  AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $stmt->execute([$agencyId]);

            $targets = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $token) {
                if ($sourceTokenId !== null && (int)$token['id'] === $sourceTokenId) {
                    continue;
                }
                $events = $this->decodeArray((string)($token['webhook_events'] ?? ''));
                if (!in_array($event, $events, true)) {
                    continue;
                }
                $projectIds = array_map('intval', $this->decodeArray((string)($token['project_ids'] ?? '')));
                if ($projectIds !== [] && !in_array($projectId, $projectIds, true)) {
                    continue;
                }
                $targets[] = (int)$token['id'];
            }

            if ($targets === []) {
                return;
            }

            $eventUid = bin2hex(random_bytes(16));
            $payload = [
                'id' => 'evt_' . $eventUid,
                'event' => $event,
                'created_at' => date('c'),
                'data' => TicketResource::fromRow($ticket),
            ];
            if ($previous !== []) {
                $payload['previous'] = $previous;
            }
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return;
            }

            $ownsTransaction = !$this->db->inTransaction();
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }
            $stmt = $this->db->prepare("
                INSERT INTO webhook_events (event_uid, agency_id, event_type, ticket_id, project_id, payload)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$eventUid, $agencyId, $event, (int)($ticket['id'] ?? 0), $projectId, $json]);
            $eventId = (int)$this->db->lastInsertId();

            $deliveryStmt = $this->db->prepare("
                INSERT INTO webhook_deliveries (event_id, token_id, status, attempts, next_attempt_at)
                VALUES (?, ?, 'pending', 0, NOW())
            ");
            $deliveryIds = [];
            foreach ($targets as $tokenId) {
                $deliveryStmt->execute([$eventId, $tokenId]);
                $deliveryIds[] = (int)$this->db->lastInsertId();
            }
            if ($ownsTransaction) {
                $this->db->commit();

                // Intento inmediato acotado: si el destino tarda o falla, la cola lo reintenta.
                foreach (array_slice($deliveryIds, 0, 1) as $deliveryId) {
                    $this->claimAndDeliver($deliveryId, true);
                }
            }
        } catch (Throwable $e) {
            if (isset($ownsTransaction) && $ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('webhook dispatch: ' . $e->getMessage());
        }
    }

    /**
     * Procesa entregas pendientes. Pensado para ejecutarse por cron cada minuto.
     *
     * @return array{processed:int,delivered:int,failed:int}
     */
    public function processPending(int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));

        // Recupera entregas que quedaron bloqueadas por un worker interrumpido.
        try {
            $this->db->prepare("
                UPDATE webhook_deliveries
                SET status = 'pending', next_attempt_at = NOW()
                WHERE status = 'processing' AND last_attempt_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            ")->execute();
        } catch (Throwable $e) {
            error_log('webhook recovery: ' . $e->getMessage());
        }

        $stmt = $this->db->prepare("
            SELECT id FROM webhook_deliveries
            WHERE status = 'pending' AND next_attempt_at <= NOW()
            ORDER BY id ASC
            LIMIT {$limit}
        ");
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $stats = ['processed' => 0, 'delivered' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            $result = $this->claimAndDeliver($id, false);
            if ($result === '') {
                continue;
            }
            $stats['processed']++;
            if ($result === 'delivered') {
                $stats['delivered']++;
            } elseif ($result === 'failed') {
                $stats['failed']++;
            }
        }

        // Limpieza oportunista de históricos antiguos.
        if (random_int(1, 50) === 1) {
            try {
                $this->db->prepare("DELETE FROM webhook_deliveries WHERE status = 'delivered' AND delivered_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->execute();
                $this->db->prepare("DELETE FROM webhook_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) AND NOT EXISTS (SELECT 1 FROM webhook_deliveries d WHERE d.event_id = webhook_events.id AND d.status IN ('pending','processing'))")->execute();
            } catch (Throwable $e) {
                error_log('webhook cleanup: ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /** @return array<int, array<string, mixed>> */
    public function recentDeliveries(int $agencyId, ?int $tokenId = null, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $sql = "
            SELECT d.*, e.event_type, e.event_uid, t.name AS token_name
            FROM webhook_deliveries d
            INNER JOIN webhook_events e ON e.id = d.event_id
            INNER JOIN api_tokens t ON t.id = d.token_id
            WHERE e.agency_id = ?
        ";
        $params = [$agencyId];
        if ($tokenId !== null && $tokenId > 0) {
            $sql .= " AND d.token_id = ?";
            $params[] = $tokenId;
        }
        $sql .= " ORDER BY d.id DESC LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function retryDelivery(int $deliveryId, int $agencyId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE webhook_deliveries d
            INNER JOIN webhook_events e ON e.id = d.event_id
            SET d.status = 'pending', d.attempts = 0, d.next_attempt_at = NOW(),
                d.response_code = NULL, d.response_body = NULL, d.delivered_at = NULL
            WHERE d.id = ? AND e.agency_id = ?
        ");
        $stmt->execute([$deliveryId, $agencyId]);
        return $stmt->rowCount() > 0;
    }

    public static function signature(string $secret, string $timestamp, string $payload): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    private function claimAndDeliver(int $deliveryId, bool $immediate): string
    {
        $claim = $this->db->prepare("
            UPDATE webhook_deliveries
            SET status = 'processing', last_attempt_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $claim->execute([$deliveryId]);
        if ($claim->rowCount() === 0) {
            return '';
        }
        return $this->deliver($deliveryId, $immediate);
    }

    private function deliver(int $deliveryId, bool $immediate = false): string
    {
        $stmt = $this->db->prepare("
            SELECT d.*, e.payload, e.event_type, e.event_uid,
                   t.webhook_url, t.token_hash, t.webhook_active, t.revoked_at, t.expires_at
            FROM webhook_deliveries d
            INNER JOIN webhook_events e ON e.id = d.event_id
            INNER JOIN api_tokens t ON t.id = d.token_id
            WHERE d.id = ?
        ");
        $stmt->execute([$deliveryId]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$delivery) {
            return 'failed';
        }

        if ((int)$delivery['webhook_active'] !== 1
            || !empty($delivery['revoked_at'])
            || (!empty($delivery['expires_at']) && strtotime((string)$delivery['expires_at']) <= time())
            || empty($delivery['webhook_url'])) {
            $this->markFailed($deliveryId, (int)$delivery['attempts'], null, 'Webhook desactivado, revocado o caducado.');
            return 'failed';
        }

        try {
            WebhookUrl::normalize((string)$delivery['webhook_url']);
        } catch (InvalidArgumentException $e) {
            $this->markFailed($deliveryId, (int)$delivery['attempts'], null, $e->getMessage());
            return 'failed';
        }

        $payload = (string)$delivery['payload'];
        $timestamp = (string)time();
        $secret = ApiTokenService::webhookSecret((string)$delivery['token_hash']);
        $signature = self::signature($secret, $timestamp, $payload);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Vertice-Webhooks/1.0',
            'X-Vertice-Event: ' . (string)$delivery['event_type'],
            'X-Vertice-Event-Id: ' . (string)$delivery['event_uid'],
            'X-Vertice-Delivery: ' . (string)$deliveryId,
            'X-Vertice-Timestamp: ' . $timestamp,
            'X-Vertice-Signature: ' . $signature,
        ];

        [$status, $body, $error] = $this->post((string)$delivery['webhook_url'], $payload, $headers, $immediate);

        if ($error === '' && $status >= 200 && $status < 300) {
            $stmt = $this->db->prepare("
                UPDATE webhook_deliveries
                SET status = 'delivered', attempts = attempts + 1, response_code = ?, response_body = ?,
                    delivered_at = NOW(), last_attempt_at = NOW(), next_attempt_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$status, $this->truncate($body), $deliveryId]);
            return 'delivered';
        }

        $attempts = (int)$delivery['attempts'] + 1;
        $message = $error !== '' ? $error : ('HTTP ' . $status . ': ' . $this->truncate($body, 500));
        $this->scheduleRetry($deliveryId, $attempts, $status > 0 ? $status : null, $message);
        return $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'retrying';
    }

    private function scheduleRetry(int $deliveryId, int $attempts, ?int $status, string $message): void
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->markFailed($deliveryId, $attempts, $status, $message);
            return;
        }

        $index = min($attempts - 1, count(self::BACKOFF) - 1);
        $delay = self::BACKOFF[$index];
        $next = date('Y-m-d H:i:s', time() + $delay);

        $stmt = $this->db->prepare("
            UPDATE webhook_deliveries
            SET status = 'pending', attempts = ?, response_code = ?, response_body = ?,
                last_attempt_at = NOW(), next_attempt_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$attempts, $status, $this->truncate($message), $next, $deliveryId]);
    }

    private function markFailed(int $deliveryId, int $attempts, ?int $status, string $message): void
    {
        $stmt = $this->db->prepare("
            UPDATE webhook_deliveries
            SET status = 'failed', attempts = ?, response_code = ?, response_body = ?,
                last_attempt_at = NOW(), next_attempt_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([max($attempts, 1), $status, $this->truncate($message), $deliveryId]);
    }

    /**
     * @param string[] $headers
     * @return array{0:int,1:string,2:string}
     */
    private function post(string $url, string $payload, array $headers, bool $immediate = false): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => $immediate ? 1 : 8,
                CURLOPT_TIMEOUT => $immediate ? 2 : 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];

            $ip = WebhookUrl::publicIp($url);
            if ($ip === null) {
                curl_close($ch);
                return [0, '', 'No se pudo validar la IP pública del webhook.'];
            }
            $parts = parse_url($url);
            $host = (string)($parts['host'] ?? '');
            $port = (int)($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));
            $address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $ip . ']' : $ip;
            if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
                $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $address];
            }

            curl_setopt_array($ch, $options);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = $body === false ? curl_error($ch) : '';
            curl_close($ch);
            return [$status, $body === false ? '' : (string)$body, $error];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => 15,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return [0, '', 'No se pudo conectar con el webhook.'];
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                $status = (int)$matches[1];
            }
        }
        return [$status, (string)$body, ''];
    }

    /** @return string[] */
    private function decodeArray(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function truncate(string $value, int $limit = 2000): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }
}
