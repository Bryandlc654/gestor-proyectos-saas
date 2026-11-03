<?php

namespace App\Repository;

use App\Security\Permissions;
use PDO;

/**
 * Consultas de pertenencia y acceso a proyectos.
 * Toda la visibilidad se limita a la agencia del usuario (se lee de la BD, no de la sesión).
 */
final class ProjectRepository
{
    private const USER_AGENCY = '(SELECT agency_id FROM users WHERE id = ?)';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Subconsulta con los ids de proyecto que el usuario puede ver, para usar como `project_id IN (...)`.
     * Admin: todos los de su agencia. Cliente: los de su agencia a los que fue vinculado.
     * Resto: los de su agencia que creó o donde es miembro.
     *
     * @return array{0: string, 1: array<int>} [sql, parámetros]
     */
    public function visibleProjectsSql(int $userId, string $role): array
    {
        $agency = 'vp.agency_id = ' . self::USER_AGENCY;

        if ($role === Permissions::ADMIN) {
            return ["SELECT vp.id FROM projects vp WHERE {$agency}", [$userId]];
        }
        if ($role === Permissions::CLIENTE) {
            return [
                "SELECT vp.id FROM projects vp WHERE {$agency}
                   AND vp.id IN (SELECT project_id FROM project_members WHERE user_id = ?)",
                [$userId, $userId],
            ];
        }
        return [
            "SELECT vp.id FROM projects vp WHERE {$agency}
               AND (vp.user_id = ? OR vp.id IN (SELECT project_id FROM project_members WHERE user_id = ?))",
            [$userId, $userId, $userId],
        ];
    }

    public function canAccess(int $projectId, int $userId, string $role): bool
    {
        [$visible, $params] = $this->visibleProjectsSql($userId, $role);
        $stmt = $this->db->prepare("SELECT 1 FROM projects WHERE id = ? AND id IN ({$visible})");
        $stmt->execute([$projectId, ...$params]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Editar metadatos, finanzas o eliminar, siempre dentro de la propia agencia:
     * admin, o PM propietario / PM asignado al proyecto.
     */
    public function canManage(int $projectId, int $userId, string $role): bool
    {
        if ($role === Permissions::ADMIN) {
            $stmt = $this->db->prepare("SELECT 1 FROM projects WHERE id = ? AND agency_id = " . self::USER_AGENCY);
            $stmt->execute([$projectId, $userId]);
            return (bool)$stmt->fetchColumn();
        }
        if ($role !== Permissions::PROJECT_MANAGER) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT 1 FROM projects
            WHERE id = ? AND agency_id = " . self::USER_AGENCY . "
              AND (user_id = ? OR id IN (SELECT project_id FROM project_members WHERE user_id = ? AND role = 'project_manager'))
        ");
        $stmt->execute([$projectId, $userId, $userId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    public function taskBelongsTo(int $taskId, int $projectId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM tasks WHERE id = ? AND project_id = ?");
        $stmt->execute([$taskId, $projectId]);
        return (bool)$stmt->fetchColumn();
    }

    public function folderBelongsTo(int $folderId, int $projectId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM project_folders WHERE id = ? AND project_id = ?");
        $stmt->execute([$folderId, $projectId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Nombre del usuario si puede ser responsable de tareas del proyecto (miembro o propietario
     * de la misma agencia); null si no.
     */
    public function assigneeName(int $userId, int $projectId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT u.name FROM users u
            WHERE u.id = ?
              AND u.agency_id = (SELECT agency_id FROM projects WHERE id = ?)
              AND (u.id IN (SELECT user_id FROM project_members WHERE project_id = ?)
                   OR u.id = (SELECT user_id FROM projects WHERE id = ?))
        ");
        $stmt->execute([$userId, $projectId, $projectId, $projectId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : $name;
    }
}
