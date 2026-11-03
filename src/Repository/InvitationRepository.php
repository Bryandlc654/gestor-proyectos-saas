<?php

namespace App\Repository;

use PDO;

/**
 * Invitaciones por enlace a una agencia. Solo se guarda el hash SHA-256 del token:
 * el enlace completo se muestra una única vez al crearla.
 */
final class InvitationRepository
{
    public const TTL_DAYS = 7;

    public function __construct(private readonly PDO $db)
    {
    }

    /** Crea la invitación y devuelve el token en claro (para construir el enlace). */
    public function create(int $agencyId, string $email, string $role, ?int $invitedBy): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->prepare("
            INSERT INTO agency_invitations (agency_id, email, role, token_hash, invited_by, expires_at)
            VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . self::TTL_DAYS . " DAY))
        ")->execute([$agencyId, $email, $role, hash('sha256', $token), $invitedBy]);
        return $token;
    }

    /** Invitación vigente (no aceptada ni caducada) para el token, con el nombre de la agencia. */
    public function findValid(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT i.id, i.agency_id, i.email, i.role, a.name AS agency_name
            FROM agency_invitations i
            INNER JOIN agencies a ON a.id = i.agency_id
            WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.expires_at > NOW()
        ");
        $stmt->execute([hash('sha256', $token)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function markAccepted(int $id): void
    {
        $this->db->prepare("UPDATE agency_invitations SET accepted_at = NOW() WHERE id = ?")->execute([$id]);
    }

    /** @return array<int, array{id: int, email: string, role: string, expires_at: string}> */
    public function pending(int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, email, role, expires_at FROM agency_invitations
            WHERE agency_id = ? AND accepted_at IS NULL AND expires_at > NOW()
            ORDER BY created_at DESC
        ");
        $stmt->execute([$agencyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function revoke(int $id, int $agencyId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM agency_invitations WHERE id = ? AND agency_id = ? AND accepted_at IS NULL");
        $stmt->execute([$id, $agencyId]);
        return $stmt->rowCount() > 0;
    }
}
