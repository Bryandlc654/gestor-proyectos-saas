<?php

namespace App\Repository;

use App\Security\Permissions;
use PDO;

/**
 * Consultas de pertenencia y acceso a proyectos.
 */
final class ProjectRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Admin: todo. Cliente: solo proyectos a los que fue vinculado.
     * Resto: proyectos propios o donde es miembro.
     */
    public function canAccess(int $projectId, int $userId, string $role): bool
    {
        if ($role === Permissions::ADMIN) {
            return true;
        }

        if ($role === Permissions::CLIENTE) {
            $stmt = $this->db->prepare("SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?");
            $stmt->execute([$projectId, $userId]);
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $this->db->prepare("
            SELECT 1 FROM projects
            WHERE id = ? AND (user_id = ? OR id IN (SELECT project_id FROM project_members WHERE user_id = ?))
        ");
        $stmt->execute([$projectId, $userId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Editar metadatos, finanzas o eliminar: admin, o PM propietario / PM asignado al proyecto.
     */
    public function canManage(int $projectId, int $userId, string $role): bool
    {
        if ($role === Permissions::ADMIN) {
            return true;
        }
        if ($role !== Permissions::PROJECT_MANAGER) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT 1 FROM projects
            WHERE id = ? AND (user_id = ? OR id IN (SELECT project_id FROM project_members WHERE user_id = ? AND role = 'project_manager'))
        ");
        $stmt->execute([$projectId, $userId, $userId]);
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
     * Nombre del usuario si puede ser responsable de tareas del proyecto (miembro o propietario); null si no.
     */
    public function assigneeName(int $userId, int $projectId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT u.name FROM users u
            WHERE u.id = ?
              AND (u.id IN (SELECT user_id FROM project_members WHERE project_id = ?)
                   OR u.id = (SELECT user_id FROM projects WHERE id = ?))
        ");
        $stmt->execute([$userId, $projectId, $projectId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : $name;
    }
}
