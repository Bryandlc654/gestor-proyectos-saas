<?php

namespace App\Repository;

use DomainException;
use PDO;
use PDOException;

/**
 * Conexiones entre agencias. Una agencia solicita la conexión con el código de la otra y esta la acepta;
 * cualquiera de las dos puede rechazarla, cancelarla o eliminarla después.
 */
final class ConnectionRepository
{
    public function __construct(private readonly PDO $db, private readonly AgencyRepository $agencies)
    {
    }

    /**
     * Solicita la conexión con la agencia dueña del código. Si esa agencia ya nos había enviado una
     * solicitud, se acepta directamente. Devuelve 'pending' o 'accepted'.
     */
    public function request(int $agencyId, string $code, ?int $userId): string
    {
        $target = $this->agencies->findByConnectCode($code);
        if ($target === null) {
            throw new DomainException('No existe ninguna agencia con ese código');
        }
        if ($target['id'] === $agencyId) {
            throw new DomainException('Ese es el código de tu propia agencia');
        }

        $existing = $this->between($agencyId, $target['id']);
        if ($existing !== null) {
            if ($existing['status'] === 'accepted') {
                throw new DomainException('Ya estás conectado con ' . $target['name']);
            }
            if ($existing['requester_agency_id'] === $agencyId) {
                throw new DomainException('Ya enviaste una solicitud a ' . $target['name']);
            }
            $this->accept($existing['id'], $agencyId);
            return 'accepted';
        }

        try {
            $this->db->prepare("
                INSERT INTO agency_connections (requester_agency_id, target_agency_id, requested_by) VALUES (?, ?, ?)
            ")->execute([$agencyId, $target['id'], $userId]);
        } catch (PDOException $e) {
            // Solicitud simultánea en sentido contrario: la restricción única la frena
            throw new DomainException('Ya hay una solicitud entre las dos agencias. Recarga la página.');
        }
        return 'pending';
    }

    /** Solo la agencia que recibió la solicitud puede aceptarla. */
    public function accept(int $connectionId, int $agencyId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE agency_connections SET status = 'accepted', accepted_at = NOW()
            WHERE id = ? AND target_agency_id = ? AND status = 'pending'
        ");
        $stmt->execute([$connectionId, $agencyId]);
        return $stmt->rowCount() > 0;
    }

    /** Rechazar, cancelar o desconectar: cualquiera de las dos agencias puede hacerlo. */
    public function remove(int $connectionId, int $agencyId): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM agency_connections WHERE id = ? AND (requester_agency_id = ? OR target_agency_id = ?)
        ");
        $stmt->execute([$connectionId, $agencyId, $agencyId]);
        return $stmt->rowCount() > 0;
    }

    public function areConnected(int $agencyA, int $agencyB): bool
    {
        $connection = $this->between($agencyA, $agencyB);
        return $connection !== null && $connection['status'] === 'accepted';
    }

    /**
     * Conexiones de la agencia agrupadas: 'connected', 'incoming' (esperan nuestra respuesta)
     * y 'outgoing' (esperan la de la otra agencia).
     *
     * @return array{connected: array, incoming: array, outgoing: array}
     */
    public function forAgency(int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, c.status, c.requester_agency_id, c.created_at, c.accepted_at,
                   a.id AS agency_id, a.name AS agency_name, a.logo AS agency_logo
            FROM agency_connections c
            INNER JOIN agencies a ON a.id = IF(c.requester_agency_id = ?, c.target_agency_id, c.requester_agency_id)
            WHERE c.requester_agency_id = ? OR c.target_agency_id = ?
            ORDER BY a.name
        ");
        $stmt->execute([$agencyId, $agencyId, $agencyId]);

        $groups = ['connected' => [], 'incoming' => [], 'outgoing' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int)$row['id'];
            $row['agency_id'] = (int)$row['agency_id'];
            $sentByUs = (int)$row['requester_agency_id'] === $agencyId;
            unset($row['requester_agency_id']);
            $group = $row['status'] === 'accepted' ? 'connected' : ($sentByUs ? 'outgoing' : 'incoming');
            $groups[$group][] = $row;
        }
        return $groups;
    }

    /** @return array{id: int, requester_agency_id: int, status: string}|null */
    private function between(int $agencyA, int $agencyB): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, requester_agency_id, status FROM agency_connections
            WHERE pair_low = LEAST(?, ?) AND pair_high = GREATEST(?, ?)
        ");
        $stmt->execute([$agencyA, $agencyB, $agencyA, $agencyB]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['id' => (int)$row['id'], 'requester_agency_id' => (int)$row['requester_agency_id'], 'status' => $row['status']] : null;
    }
}
