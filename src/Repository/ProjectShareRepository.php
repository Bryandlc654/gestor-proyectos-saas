<?php

namespace App\Repository;

use App\Repository\ProjectRepository;

use DomainException;
use PDO;

/**
 * Proyectos compartidos entre agencias conectadas.
 *
 * La agencia dueña del proyecto decide con quién lo comparte y el nivel de acceso
 * ('view' o 'collaborate'). La agencia invitada decide después qué miembros de su
 * equipo entran al proyecto. La conexión entre ambas agencias es un requisito
 * permanente: si se desconectan, el proyecto deja de estar compartido.
 */
final class ProjectShareRepository
{
    public const ACCESS_LEVELS = ['view', 'collaborate'];

    public function __construct(
        private readonly PDO $db,
        private readonly ConnectionRepository $connections,
    ) {
    }

    /** @return array<int, array{share_id: int, guest_agency_id: int, guest_agency_name: string, access: string}> */
    public function forProject(int $projectId): array
    {
        $stmt = $this->db->prepare("
            SELECT ps.id AS share_id, ps.guest_agency_id, ps.access, a.name AS guest_agency_name
            FROM project_shares ps
            INNER JOIN agencies a ON a.id = ps.guest_agency_id
            WHERE ps.project_id = ?
            ORDER BY a.name
        ");
        $stmt->execute([$projectId]);

        return array_map(static fn(array $row) => [
            'share_id' => (int)$row['share_id'],
            'guest_agency_id' => (int)$row['guest_agency_id'],
            'guest_agency_name' => $row['guest_agency_name'],
            'access' => $row['access'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{id: int, project_id: int, owner_agency_id: int, guest_agency_id: int, access: string}|null */
    public function find(int $shareId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, project_id, owner_agency_id, guest_agency_id, access
            FROM project_shares WHERE id = ?
        ");
        $stmt->execute([$shareId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? [
            'id' => (int)$row['id'],
            'project_id' => (int)$row['project_id'],
            'owner_agency_id' => (int)$row['owner_agency_id'],
            'guest_agency_id' => (int)$row['guest_agency_id'],
            'access' => $row['access'],
        ] : null;
    }

    /**
     * Comparte el proyecto o actualiza el acceso si ya estaba compartido con esa agencia.
     * Solo la agencia dueña puede hacerlo y ambas agencias deben seguir conectadas.
     */
    public function share(int $projectId, int $ownerAgencyId, int $guestAgencyId, string $access, ?int $userId): int
    {
        ProjectRepository::invalidateAccessCache();
        if (!in_array($access, self::ACCESS_LEVELS, true)) {
            throw new DomainException('Nivel de acceso no válido');
        }
        if ($ownerAgencyId === $guestAgencyId) {
            throw new DomainException('No puedes compartir un proyecto con tu propia agencia');
        }
        if (!$this->connections->areConnected($ownerAgencyId, $guestAgencyId)) {
            throw new DomainException('Solo puedes compartir proyectos con agencias conectadas');
        }

        $stmt = $this->db->prepare("SELECT agency_id FROM projects WHERE id = ? AND archived_at IS NULL");
        $stmt->execute([$projectId]);
        if ((int)($stmt->fetchColumn() ?: 0) !== $ownerAgencyId) {
            throw new DomainException('El proyecto no pertenece a tu agencia o está archivado');
        }

        $this->db->prepare("
            INSERT INTO project_shares (project_id, owner_agency_id, guest_agency_id, access, shared_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE access = VALUES(access), shared_by = VALUES(shared_by)
        ")->execute([$projectId, $ownerAgencyId, $guestAgencyId, $access, $userId]);

        $stmt = $this->db->prepare("
            SELECT id FROM project_shares WHERE project_id = ? AND guest_agency_id = ?
        ");
        $stmt->execute([$projectId, $guestAgencyId]);
        return (int)$stmt->fetchColumn();
    }

    public function setAccess(int $shareId, int $ownerAgencyId, string $access): bool
    {
        ProjectRepository::invalidateAccessCache();
        if (!in_array($access, self::ACCESS_LEVELS, true)) {
            throw new DomainException('Nivel de acceso no válido');
        }
        $stmt = $this->db->prepare("
            UPDATE project_shares SET access = ?
            WHERE id = ? AND owner_agency_id = ?
        ");
        $stmt->execute([$access, $shareId, $ownerAgencyId]);
        return $stmt->rowCount() > 0;
    }

    /** Deja de compartir. Solo la agencia dueña puede hacerlo. */
    public function remove(int $shareId, int $ownerAgencyId): bool
    {
        ProjectRepository::invalidateAccessCache();
        $stmt = $this->db->prepare("
            DELETE FROM project_shares WHERE id = ? AND owner_agency_id = ?
        ");
        $stmt->execute([$shareId, $ownerAgencyId]);
        return $stmt->rowCount() > 0;
    }

    public function removeBetweenAgencies(int $agencyA, int $agencyB): void
    {
        ProjectRepository::invalidateAccessCache();
        $this->db->prepare("
            DELETE FROM project_shares
            WHERE (owner_agency_id = ? AND guest_agency_id = ?)
               OR (owner_agency_id = ? AND guest_agency_id = ?)
        ")->execute([$agencyA, $agencyB, $agencyB, $agencyA]);
    }

    /** Ids de los miembros de la agencia invitada que participan en el proyecto. */
    public function memberIds(int $shareId): array
    {
        $stmt = $this->db->prepare("SELECT psm.user_id FROM project_share_members psm WHERE psm.share_id = ? ORDER BY psm.user_id");
        $stmt->execute([$shareId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int, array{id: int, name: string, email: string, role: string}> */
    public function members(int $shareId): array
    {
        $stmt = $this->db->prepare("
            SELECT u.id, u.name, u.email, u.role
            FROM project_share_members psm
            INNER JOIN users u ON u.id = psm.user_id
            WHERE psm.share_id = ?
            ORDER BY u.name
        ");
        $stmt->execute([$shareId]);
        return array_map(static fn(array $row) => [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'role' => $row['role'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * La agencia invitada elige a su equipo. Solo se aceptan usuarios de esa misma agencia.
     *
     * @param array<int|string> $userIds
     */
    public function setMembers(int $shareId, int $guestAgencyId, array $userIds): void
    {
        ProjectRepository::invalidateAccessCache();
        $share = $this->find($shareId);
        if ($share === null || $share['guest_agency_id'] !== $guestAgencyId) {
            throw new DomainException('Proyecto compartido no encontrado');
        }
        if (!$this->connections->areConnected($share['owner_agency_id'], $guestAgencyId)) {
            throw new DomainException('La conexión con la agencia dueña ya no está activa');
        }

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!empty($userIds)) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $this->db->prepare("SELECT id FROM users WHERE agency_id = ? AND id IN ($placeholders)");
            $stmt->execute([$guestAgencyId, ...$userIds]);
            $allowed = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $userIds = array_values(array_intersect($userIds, $allowed));
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM project_share_members WHERE share_id = ?")->execute([$shareId]);
            if (!empty($userIds)) {
                $values = implode(',', array_fill(0, count($userIds), '(?, ?)'));
                $params = [];
                foreach ($userIds as $userId) {
                    $params[] = $shareId;
                    $params[] = $userId;
                }
                $this->db->prepare("INSERT INTO project_share_members (share_id, user_id) VALUES {$values}")
                    ->execute($params);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
