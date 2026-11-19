<?php

namespace App\Repository;

use App\Security\Permissions;
use PDO;

/**
 * Consultas de pertenencia y acceso a proyectos.
 * Toda la visibilidad se limita a la agencia del usuario (se lee de la BD, no de la sesión),
 * salvo los proyectos que otra agencia conectada haya compartido explícitamente.
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
     * Además: los proyectos que otra agencia conectada compartió con la suya. La agencia invitada
     * los ve completos para su admin, y para el resto solo si fueron elegidos como miembros del share.
     *
     * @return array{0: string, 1: array<int|string>} [sql, parámetros]
     */
    public function visibleProjectsSql(int $userId, string $role): array
    {
        $params = [$userId];
        $ownerAgency = 'vp.agency_id = ' . self::USER_AGENCY;

        if ($role === Permissions::ADMIN) {
            $ownerClause = "({$ownerAgency} AND vp.archived_at IS NULL)";
        } elseif ($role === Permissions::CLIENTE) {
            $ownerClause = "({$ownerAgency}
               AND vp.archived_at IS NULL
               AND vp.id IN (SELECT project_id FROM project_members WHERE user_id = ?))";
            $params[] = $userId;
        } else {
            $ownerClause = "({$ownerAgency}
               AND vp.archived_at IS NULL
               AND (vp.user_id = ? OR vp.id IN (SELECT project_id FROM project_members WHERE user_id = ?)))";
            $params[] = $userId;
            $params[] = $userId;
        }

        $guestClause = "vp.id IN (
            SELECT ps.project_id FROM project_shares ps
            WHERE ps.guest_agency_id = (SELECT agency_id FROM users WHERE id = ?)
              AND ps.owner_agency_id = vp.agency_id
              AND vp.archived_at IS NULL
              AND EXISTS (
                  SELECT 1 FROM agency_connections c
                  WHERE c.status = 'accepted'
                    AND c.pair_low = LEAST(ps.owner_agency_id, ps.guest_agency_id)
                    AND c.pair_high = GREATEST(ps.owner_agency_id, ps.guest_agency_id)
              )
              AND (
                  ? = 'admin'
                  OR EXISTS (
                      SELECT 1 FROM project_share_members psm
                      INNER JOIN users gu ON gu.id = psm.user_id
                      WHERE psm.share_id = ps.id
                        AND psm.user_id = ?
                        AND gu.agency_id = ps.guest_agency_id
                  )
              )
        )";
        $params[] = $userId;   // agencia del usuario invitado
        $params[] = $role;     // el admin de la agencia invitada ve todos los shares
        $params[] = $userId;   // miembro elegido por la agencia invitada

        return ["SELECT vp.id FROM projects vp WHERE ({$ownerClause}) OR {$guestClause}", $params];
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
     * admin, o PM propietario / PM asignado al proyecto. Un proyecto compartido nunca
     * se gestiona desde la agencia invitada.
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
     * Nombre del usuario si puede ser responsable de tareas del proyecto. Puede ser miembro
     * de la agencia dueña o un miembro elegido por la agencia invitada con acceso de colaboración.
     */
    public function assigneeName(int $userId, int $projectId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT u.name FROM users u
            WHERE u.id = ?
              AND (
                  (
                      u.agency_id = (SELECT agency_id FROM projects WHERE id = ?)
                      AND (u.id IN (SELECT user_id FROM project_members WHERE project_id = ?)
                           OR u.id = (SELECT user_id FROM projects WHERE id = ?))
                  )
                  OR EXISTS (
                      SELECT 1
                      FROM project_share_members psm
                      INNER JOIN project_shares ps ON ps.id = psm.share_id
                      INNER JOIN projects p ON p.id = ps.project_id
                      WHERE psm.user_id = u.id
                        AND ps.project_id = ?
                        AND ps.access = 'collaborate'
                        AND ps.guest_agency_id = u.agency_id
                        AND ps.owner_agency_id = p.agency_id
                        AND EXISTS (
                            SELECT 1 FROM agency_connections c
                            WHERE c.status = 'accepted'
                              AND c.pair_low = LEAST(ps.owner_agency_id, ps.guest_agency_id)
                              AND c.pair_high = GREATEST(ps.owner_agency_id, ps.guest_agency_id)
                        )
                  )
              )
        ");
        $stmt->execute([$userId, $projectId, $projectId, $projectId, $projectId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : $name;
    }

    /** @return array<int, array{id: int, name: string, email: string}> Usuarios que pueden ser mencionados. */
    public function mentionableUsers(int $projectId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT u.id, u.name, u.email, u.agency_id
            FROM users u
            WHERE (
                    u.agency_id = (SELECT agency_id FROM projects WHERE id = ?)
                    AND (
                        u.id IN (SELECT user_id FROM project_members WHERE project_id = ?)
                        OR u.id = (SELECT user_id FROM projects WHERE id = ?)
                    )
                  )
               OR EXISTS (
                    SELECT 1
                    FROM project_share_members psm
                    INNER JOIN project_shares ps ON ps.id = psm.share_id
                    WHERE ps.project_id = ?
                      AND ps.access = 'collaborate'
                      AND psm.user_id = u.id
                      AND ps.guest_agency_id = u.agency_id
                  )
            ORDER BY u.name
        ");
        $stmt->execute([$projectId, $projectId, $projectId, $projectId]);
        return array_map(static fn(array $row) => [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'agency_id' => (int)$row['agency_id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function name(int $projectId): ?string
    {
        $stmt = $this->db->prepare("SELECT name FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string)$name;
    }

    public function ownerAgencyId(int $projectId): ?int
    {
        $stmt = $this->db->prepare("SELECT agency_id FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $agencyId = $stmt->fetchColumn();
        return $agencyId === false ? null : (int)$agencyId;
    }

    public function isOwnerAgency(int $projectId, int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM projects WHERE id = ? AND agency_id = " . self::USER_AGENCY);
        $stmt->execute([$projectId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Contexto de acceso al proyecto para el usuario actual.
     *
     * @return array{level: string, is_guest: bool, share: ?array}|null
     *   level: 'owner' | 'view' | 'collaborate'
     */
    public function accessContext(int $projectId, int $userId, string $role): ?array
    {
        $stmt = $this->db->prepare("
            SELECT p.agency_id, (SELECT agency_id FROM users WHERE id = ?) AS user_agency
            FROM projects p WHERE p.id = ?
        ");
        $stmt->execute([$userId, $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        if ((int)$row['user_agency'] === (int)$row['agency_id']) {
            return ['level' => 'owner', 'is_guest' => false, 'share' => null];
        }

        $share = $this->guestShare($projectId, $userId, $role);
        if ($share === null) {
            return null;
        }

        return ['level' => $share['access'], 'is_guest' => true, 'share' => $share];
    }

    /**
     * Proyecto compartido con la agencia del usuario, siempre que la conexión siga aceptada
     * y el usuario sea el admin de la invitada o un miembro elegido por ella.
     *
     * @return array{id: int, project_id: int, owner_agency_id: int, guest_agency_id: int, access: string, owner_agency_name: string, is_admin: bool}|null
     */
    public function guestShare(int $projectId, int $userId, string $role): ?array
    {
        $stmt = $this->db->prepare("
            SELECT ps.id, ps.project_id, ps.owner_agency_id, ps.guest_agency_id, ps.access,
                   a.name AS owner_agency_name
            FROM project_shares ps
            INNER JOIN projects p ON p.id = ps.project_id
            INNER JOIN agencies a ON a.id = ps.owner_agency_id
            WHERE ps.project_id = ?
              AND ps.guest_agency_id = (SELECT agency_id FROM users WHERE id = ?)
              AND ps.owner_agency_id = p.agency_id
        ");
        $stmt->execute([$projectId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT 1 FROM agency_connections
            WHERE status = 'accepted'
              AND pair_low = LEAST(?, ?) AND pair_high = GREATEST(?, ?)
        ");
        $stmt->execute([$row['owner_agency_id'], $row['guest_agency_id'], $row['owner_agency_id'], $row['guest_agency_id']]);
        if (!$stmt->fetchColumn()) {
            return null;
        }

        $isAdmin = $role === Permissions::ADMIN;
        if (!$isAdmin) {
            $stmt = $this->db->prepare("
                SELECT 1 FROM project_share_members psm
                INNER JOIN users u ON u.id = psm.user_id
                WHERE psm.share_id = ? AND psm.user_id = ? AND u.agency_id = ?
            ");
            $stmt->execute([$row['id'], $userId, $row['guest_agency_id']]);
            if (!$stmt->fetchColumn()) {
                return null;
            }
        }

        return [
            'id' => (int)$row['id'],
            'project_id' => (int)$row['project_id'],
            'owner_agency_id' => (int)$row['owner_agency_id'],
            'guest_agency_id' => (int)$row['guest_agency_id'],
            'access' => $row['access'],
            'owner_agency_name' => $row['owner_agency_name'],
            'is_admin' => $isAdmin,
        ];
    }

    /**
     * Ids de los proyectos visibles para el usuario (una sola consulta reutilizable).
     *
     * @return int[]
     */
    public function visibleProjectIds(int $userId, string $role): array
    {
        [$sql, $params] = $this->visibleProjectsSql($userId, $role);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Contexto de acceso para varios proyectos con 2 consultas en total (evita una por proyecto).
     *
     * @param int[] $projectIds
     * @return array<int, array{level: string, is_guest: bool, share: ?array}>
     */
    public function accessContexts(array $projectIds, int $userId, string $role): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $projectIds))));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            SELECT p.id, p.agency_id, (SELECT agency_id FROM users WHERE id = ?) AS user_agency
            FROM projects p
            WHERE p.id IN ($placeholders)
        ");
        $stmt->execute([$userId, ...$ids]);

        $contexts = [];
        $guestIds = [];
        $userAgency = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userAgency = (int)$row['user_agency'];
            $projectId = (int)$row['id'];
            if ((int)$row['agency_id'] === $userAgency) {
                $contexts[$projectId] = ['level' => 'owner', 'is_guest' => false, 'share' => null];
            } else {
                $guestIds[] = $projectId;
            }
        }

        if (empty($guestIds)) {
            return $contexts;
        }

        // Proyectos de otra agencia: solo si están compartidos con la mía, la conexión sigue
        // aceptada y el usuario es el admin invitado o un miembro elegido por su agencia.
        $guestPlaceholders = implode(',', array_fill(0, count($guestIds), '?'));
        $stmt = $this->db->prepare("
            SELECT ps.id, ps.project_id, ps.owner_agency_id, ps.guest_agency_id, ps.access,
                   a.name AS owner_agency_name,
                   EXISTS (
                       SELECT 1 FROM agency_connections c
                       WHERE c.status = 'accepted'
                         AND c.pair_low = LEAST(ps.owner_agency_id, ps.guest_agency_id)
                         AND c.pair_high = GREATEST(ps.owner_agency_id, ps.guest_agency_id)
                   ) AS connected,
                   EXISTS (
                       SELECT 1 FROM project_share_members psm
                       WHERE psm.share_id = ps.id AND psm.user_id = ?
                   ) AS is_member
            FROM project_shares ps
            INNER JOIN projects p ON p.id = ps.project_id
            INNER JOIN agencies a ON a.id = ps.owner_agency_id
            WHERE ps.project_id IN ($guestPlaceholders)
              AND ps.guest_agency_id = ?
              AND ps.owner_agency_id = p.agency_id
        ");
        $stmt->execute([$userId, ...$guestIds, $userAgency]);

        $isAdmin = $role === Permissions::ADMIN;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $share) {
            if (!$share['connected'] || (!$isAdmin && !$share['is_member'])) {
                continue;
            }
            $contexts[(int)$share['project_id']] = [
                'level' => $share['access'],
                'is_guest' => true,
                'share' => [
                    'id' => (int)$share['id'],
                    'project_id' => (int)$share['project_id'],
                    'owner_agency_id' => (int)$share['owner_agency_id'],
                    'guest_agency_id' => (int)$share['guest_agency_id'],
                    'access' => $share['access'],
                    'owner_agency_name' => $share['owner_agency_name'],
                    'is_admin' => $isAdmin,
                ],
            ];
        }

        return $contexts;
    }

    /**
     * Todos los permisos del proyecto en una sola pasada (2-3 consultas).
     * Evita repetir accessContext()/guestShare() una vez por cada permiso en la vista del proyecto.
     *
     * @return array<string, mixed>|null null si el proyecto no existe
     */
    public function permissionsFor(int $projectId, int $userId, string $role): ?array
    {
        // La consulta de metadatos comprueba a la vez que el proyecto existe y es visible para el usuario.
        [$visibleSql, $visibleParams] = $this->visibleProjectsSql($userId, $role);

        $stmt = $this->db->prepare("
            SELECT p.user_id AS owner_user_id,
                   EXISTS(
                       SELECT 1 FROM project_members pm
                       WHERE pm.project_id = p.id AND pm.user_id = ? AND pm.role = 'project_manager'
                   ) AS is_pm_member
            FROM projects p
            WHERE p.id = ? AND p.id IN ({$visibleSql})
        ");
        $stmt->execute([$userId, $projectId, ...$visibleParams]);
        $meta = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($meta === false) {
            return null;
        }

        $context = $this->accessContexts([$projectId], $userId, $role)[$projectId] ?? null;

        $none = [
            'can_access' => false,
            'level' => null,
            'is_guest' => false,
            'is_owner' => false,
            'share' => null,
            'can_manage' => false,
            'can_edit_tasks' => false,
            'can_change_task_status' => false,
            'can_delete_tasks' => false,
            'can_manage_checklists' => false,
            'can_manage_attachments' => false,
            'can_upload_files' => false,
            'can_manage_task_attachments' => false,
            'can_comment' => false,
            'can_moderate' => false,
            'can_view_finances' => false,
            'can_create_tickets' => false,
            'can_change_ticket_status' => false,
            'can_delete_tickets' => false,
            'can_manage_share' => false,
            'can_manage_share_members' => false,
            'show_activity' => false,
        ];
        if ($context === null) {
            return $none;
        }

        $isOwner = $context['level'] === 'owner';
        $canWrite = $this->canWriteContent($context, $role);
        $isAdmin = $role === Permissions::ADMIN;
        $isPm = $role === Permissions::PROJECT_MANAGER;

        return [
            'can_access' => true,
            'level' => $context['level'],
            'is_guest' => $context['is_guest'],
            'is_owner' => $isOwner,
            'share' => $context['share'],
            'can_manage' => $isOwner && ($isAdmin || ($isPm && ((int)$meta['owner_user_id'] === $userId || (bool)$meta['is_pm_member']))),
            'can_edit_tasks' => $canWrite && Permissions::canManageTasks($role),
            'can_change_task_status' => $canWrite && Permissions::canChangeTaskStatus($role),
            'can_delete_tasks' => $isOwner && Permissions::canDeleteTasks($role),
            'can_manage_checklists' => $canWrite && Permissions::canManageChecklists($role),
            'can_manage_attachments' => $isOwner && Permissions::canManageAttachments($role),
            'can_upload_files' => $canWrite,
            'can_manage_task_attachments' => $canWrite,
            'can_comment' => $canWrite,
            'can_moderate' => $isOwner && $isAdmin,
            'can_view_finances' => $isOwner && $isAdmin,
            'can_create_tickets' => $canWrite && Permissions::canCreateTickets($role),
            'can_change_ticket_status' => $canWrite && Permissions::canChangeTicketStatus($role),
            'can_delete_tickets' => $isOwner && Permissions::canDeleteTickets($role),
            'can_manage_share' => $isOwner && $isAdmin,
            'can_manage_share_members' => !$isOwner && $isAdmin,
            'show_activity' => $isOwner && in_array($role, [Permissions::ADMIN, Permissions::PROJECT_MANAGER], true),
        ];
    }

    // --- Permisos de contenido, ya resueltos para el proyecto concreto ---
    public function canEditTasks(int $projectId, int $userId, string $role): bool
    {
        $context = $this->accessContext($projectId, $userId, $role);
        if ($context === null) {
            return false;
        }
        if ($context['level'] === 'owner') {
            return Permissions::canManageTasks($role);
        }
        return $context['level'] === 'collaborate' && Permissions::canManageTasks($role);
    }

    public function canChangeTaskStatus(int $projectId, int $userId, string $role): bool
    {
        $context = $this->accessContext($projectId, $userId, $role);
        if ($context === null) {
            return false;
        }
        if ($context['level'] === 'owner') {
            return Permissions::canChangeTaskStatus($role);
        }
        return $context['level'] === 'collaborate' && Permissions::canChangeTaskStatus($role);
    }

    public function canDeleteTasks(int $projectId, int $userId, string $role): bool
    {
        return $this->isOwnerAgency($projectId, $userId) && Permissions::canDeleteTasks($role);
    }

    public function canManageChecklists(int $projectId, int $userId, string $role): bool
    {
        $context = $this->accessContext($projectId, $userId, $role);
        if ($context === null) {
            return false;
        }
        return $this->canWriteContent($context, $role) && Permissions::canManageChecklists($role);
    }

    /** Carpetas y enlaces: solo el equipo de la agencia dueña. */
    public function canManageAttachments(int $projectId, int $userId, string $role): bool
    {
        return $this->isOwnerAgency($projectId, $userId) && Permissions::canManageAttachments($role);
    }

    /** Subir archivos: el equipo dueño y los invitados con acceso de colaboración. */
    public function canUploadAttachments(int $projectId, int $userId, string $role): bool
    {
        $context = $this->accessContext($projectId, $userId, $role);
        if ($context === null) {
            return false;
        }
        return $this->canWriteContent($context, $role);
    }

    /** Adjuntos dentro del detalle de una tarea. */
    public function canManageTaskAttachments(int $projectId, int $userId, string $role): bool
    {
        $context = $this->accessContext($projectId, $userId, $role);
        if ($context === null) {
            return false;
        }
        if ($context['level'] === 'owner') {
            return Permissions::canManageAttachments($role);
        }
        return $this->canWriteContent($context, $role);
    }

    /** Ver = solo lectura; colaborar = puede comentar. */
    public function canComment(int $projectId, int $userId, string $role): bool
    {
        $context = $this->accessContext($projectId, $userId, $role);
        if ($context === null) {
            return false;
        }
        return $this->canWriteContent($context, $role);
    }

    /** Moderar comentarios de otros: solo el admin de la agencia dueña. */
    public function canModerate(int $projectId, int $userId, string $role): bool
    {
        return $this->isOwnerAgency($projectId, $userId) && $role === Permissions::ADMIN;
    }

    /** Presupuesto y facturas: nunca para la agencia invitada. */
    public function canViewFinances(int $projectId, int $userId, string $role): bool
    {
        return $this->isOwnerAgency($projectId, $userId) && $role === Permissions::ADMIN;
    }

    public function canManageShare(int $projectId, int $userId, string $role): bool
    {
        return $this->isOwnerAgency($projectId, $userId) && $role === Permissions::ADMIN;
    }

    /** La agencia invitada decide su equipo: solo su admin. */
    public function canManageShareMembers(int $projectId, int $userId, string $role): bool
    {
        return $role === Permissions::ADMIN && $this->guestShare($projectId, $userId, $role) !== null;
    }

    public function canCreateTickets(int $projectId, int $userId, string $role): bool
    {
        $context = $this->accessContext($projectId, $userId, $role);
        if ($context === null) {
            return false;
        }
        return $this->canWriteContent($context, $role) && Permissions::canCreateTickets($role);
    }

    public function canChangeTicketStatus(int $projectId, int $userId, string $role): bool
    {
        $context = $this->accessContext($projectId, $userId, $role);
        if ($context === null) {
            return false;
        }
        return $this->canWriteContent($context, $role) && Permissions::canChangeTicketStatus($role);
    }

    /** Eliminar un ticket es una acción destructiva: queda reservada a la agencia dueña. */
    public function canDeleteTickets(int $projectId, int $userId, string $role): bool
    {
        return $this->isOwnerAgency($projectId, $userId) && Permissions::canDeleteTickets($role);
    }

    /**
     * Los clientes de una agencia invitada tienen siempre acceso limitado de solo lectura,
     * aunque el proyecto se haya compartido en modo colaborar.
     */
    private function canWriteContent(array $context, string $role): bool
    {
        if ($context['level'] === 'owner') {
            return true;
        }
        return $context['level'] === 'collaborate' && $role !== Permissions::CLIENTE;
    }

    // --- Papelera de proyectos ---

    public function archive(int $projectId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE projects
            SET archived_at = NOW(), archived_by = ?
            WHERE id = ? AND agency_id = " . self::USER_AGENCY . " AND archived_at IS NULL
        ");
        $stmt->execute([$userId, $projectId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function restore(int $projectId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE projects
            SET archived_at = NULL, archived_by = NULL
            WHERE id = ? AND agency_id = " . self::USER_AGENCY . " AND archived_at IS NOT NULL
        ");
        $stmt->execute([$projectId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<int, array> Proyectos archivados de la agencia (papelera). */
    public function archivedForAgency(int $agencyId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.id, p.name, p.color, p.category, p.status, p.archived_at,
                   u.name AS archived_by_name
            FROM projects p
            LEFT JOIN users u ON u.id = p.archived_by
            WHERE p.agency_id = ? AND p.archived_at IS NOT NULL
            ORDER BY p.archived_at DESC
        ");
        $stmt->execute([$agencyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function archivedProject(int $projectId, int $agencyId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, agency_id, archived_at
            FROM projects
            WHERE id = ? AND agency_id = ? AND archived_at IS NOT NULL
        ");
        $stmt->execute([$projectId, $agencyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
