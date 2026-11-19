<?php
// api/app/users.php - Usuarios, roles, proyectos e invitaciones para React.
require_once __DIR__ . '/_bootstrap.php';

if (!isAdmin() || !moduleAllowed('users')) {
    appJson(['error' => 'Solo los administradores pueden gestionar usuarios y roles'], 403);
}

$roles = [
    'admin' => ['label' => 'Administrador', 'className' => 'bg-purple-50 text-purple-700 border-purple-200/80', 'dot' => 'bg-purple-500'],
    'project_manager' => ['label' => 'Project Manager', 'className' => 'bg-indigo-50 text-indigo-700 border-indigo-200/80', 'dot' => 'bg-indigo-500'],
    'colaborador' => ['label' => 'Colaborador', 'className' => 'bg-emerald-50 text-emerald-700 border-emerald-200/80', 'dot' => 'bg-emerald-500'],
    'cliente' => ['label' => 'Cliente', 'className' => 'bg-amber-50 text-amber-700 border-amber-200/80', 'dot' => 'bg-amber-500'],
];

$agencyProjectIds = static function (mixed $projectIds) use ($agencyId): array {
    if (is_string($projectIds)) {
        $decoded = json_decode($projectIds, true);
        $projectIds = is_array($decoded) ? $decoded : [];
    }
    $ids = array_unique(array_filter(array_map('intval', is_array($projectIds) ? $projectIds : [])));
    return array_values(array_filter($ids, static fn(int $id): bool => projectRepository()->ownerAgencyId($id) === $agencyId));
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create_user') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $newRole = (string)($_POST['role'] ?? 'colaborador');
            $projectIds = $agencyProjectIds($_POST['project_ids'] ?? []);

            if ($name === '') appJson(['error' => 'El nombre completo es obligatorio'], 422);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) appJson(['error' => 'Ingresa un correo electrónico válido'], 422);
            if (strlen($password) < 8) appJson(['error' => 'La contraseña debe tener al menos 8 caracteres'], 422);
            if (!isset($roles[$newRole])) appJson(['error' => 'Nivel de acceso no válido'], 422);

            $stmtCheck = getDB()->prepare('SELECT id FROM users WHERE email = ?');
            $stmtCheck->execute([$email]);
            if ($stmtCheck->fetch()) appJson(['error' => 'Ya existe un usuario registrado con este correo'], 409);

            $db = getDB();
            $db->beginTransaction();
            try {
                $db->prepare('INSERT INTO users (agency_id, name, email, password, role, email_verified_at) VALUES (?, ?, ?, ?, ?, NOW())')->execute([
                    $agencyId,
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $newRole,
                ]);
                $newUserId = (int)$db->lastInsertId();

                if ($projectIds !== []) {
                    $memberRole = $newRole === 'cliente' ? 'cliente' : ($newRole === 'project_manager' ? 'project_manager' : 'colaborador');
                    $values = implode(',', array_fill(0, count($projectIds), '(?, ?, ?)'));
                    $params = [];
                    foreach ($projectIds as $projectId) {
                        $params[] = $projectId;
                        $params[] = $newUserId;
                        $params[] = $memberRole;
                    }
                    $db->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES {$values}")->execute($params);
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            appJson(['success' => true, 'id' => $newUserId, 'message' => 'Usuario / Cliente creado exitosamente']);
        }

        if ($action === 'assign_projects') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            if ($targetUserId <= 0 || !agencyRepository()->userBelongs($targetUserId, $agencyId)) {
                appJson(['error' => 'Usuario no encontrado'], 404);
            }
            $projectIds = $agencyProjectIds($_POST['project_ids'] ?? []);

            $stmtUser = getDB()->prepare('SELECT role FROM users WHERE id = ?');
            $stmtUser->execute([$targetUserId]);
            $targetRole = (string)$stmtUser->fetchColumn();
            if ($targetRole === '') appJson(['error' => 'Usuario no encontrado'], 404);
            $memberRole = $targetRole === 'cliente' ? 'cliente' : ($targetRole === 'project_manager' ? 'project_manager' : 'colaborador');

            $db = getDB();
            $db->beginTransaction();
            try {
                $db->prepare('DELETE FROM project_members WHERE user_id = ? AND project_id IN (SELECT id FROM projects WHERE agency_id = ?)')->execute([$targetUserId, $agencyId]);

                if ($projectIds !== []) {
                    $values = implode(',', array_fill(0, count($projectIds), '(?, ?, ?)'));
                    $params = [];
                    foreach ($projectIds as $projectId) {
                        $params[] = $projectId;
                        $params[] = $targetUserId;
                        $params[] = $memberRole;
                    }
                    $db->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES {$values}")->execute($params);
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            appJson(['success' => true, 'message' => 'Proyectos vinculados correctamente']);
        }

        if ($action === 'update_role') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $newRole = (string)($_POST['new_role'] ?? '');
            if (!isset($roles[$newRole])) appJson(['error' => 'Rol seleccionado no válido'], 422);
            if ($targetUserId <= 0 || !agencyRepository()->userBelongs($targetUserId, $agencyId)) {
                appJson(['error' => 'Usuario no encontrado'], 404);
            }

            if ($newRole !== 'admin') {
                $stmtAdmins = getDB()->prepare("SELECT COUNT(*) FROM users WHERE agency_id = ? AND role = 'admin' AND id <> ?");
                $stmtAdmins->execute([$agencyId, $targetUserId]);
                $stmtWasAdmin = getDB()->prepare("SELECT role = 'admin' FROM users WHERE id = ?");
                $stmtWasAdmin->execute([$targetUserId]);
                if ($stmtWasAdmin->fetchColumn() && (int)$stmtAdmins->fetchColumn() === 0) {
                    appJson(['error' => 'La agencia debe tener al menos un administrador'], 422);
                }
            }

            getDB()->prepare('UPDATE users SET role = ? WHERE id = ? AND agency_id = ?')->execute([$newRole, $targetUserId, $agencyId]);
            $memberRole = $newRole === 'cliente' ? 'cliente' : ($newRole === 'project_manager' ? 'project_manager' : 'colaborador');
            getDB()->prepare('UPDATE project_members SET role = ? WHERE user_id = ?')->execute([$memberRole, $targetUserId]);

            if ($targetUserId === $userId) {
                $_SESSION['user_role'] = $newRole;
            }

            appJson(['success' => true, 'role' => $newRole, 'message' => 'Nivel de acceso actualizado correctamente']);
        }

        if ($action === 'delete_user') {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            if ($targetUserId <= 0 || !agencyRepository()->userBelongs($targetUserId, $agencyId)) {
                appJson(['error' => 'Usuario no encontrado'], 404);
            }
            if ($targetUserId === $userId) {
                appJson(['error' => 'No puedes eliminar tu propia cuenta de administrador'], 422);
            }

            getDB()->prepare('DELETE FROM users WHERE id = ? AND agency_id = ?')->execute([$targetUserId, $agencyId]);
            appJson(['success' => true, 'message' => 'Usuario eliminado correctamente']);
        }

        if ($action === 'create_invitation') {
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $inviteRole = (string)($_POST['role'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) appJson(['error' => 'El correo electrónico no tiene un formato válido'], 422);
            if (!isset($roles[$inviteRole])) appJson(['error' => 'Rol no válido'], 422);

            $stmtCheck = getDB()->prepare('SELECT 1 FROM users WHERE email = ?');
            $stmtCheck->execute([$email]);
            if ($stmtCheck->fetchColumn()) appJson(['error' => 'Ese correo ya tiene una cuenta en la plataforma'], 409);

            $token = invitationRepository()->create($agencyId, $email, $inviteRole, $userId);
            activityRepository()->logByUser($userId, null, 'invitation.created', 'Invitó a ' . $email . ' como ' . $inviteRole);
            try {
                notificationService()->sendInvitation($email, currentAgency()['name'], $inviteRole, $token);
            } catch (Throwable $e) {
                error_log('Invitación: ' . $e->getMessage());
            }

            appJson([
                'success' => true,
                'invitePath' => '/app/invite?token=' . $token,
                'expiresDays' => \App\Repository\InvitationRepository::TTL_DAYS,
                'message' => 'Invitación creada. Copia el enlace y envíaselo: solo se muestra esta vez.',
            ]);
        }

        if ($action === 'revoke_invitation') {
            $revoked = invitationRepository()->revoke((int)($_POST['invitation_id'] ?? 0), $agencyId);
            appJson($revoked
                ? ['success' => true, 'message' => 'Invitación revocada']
                : ['error' => 'Invitación no encontrada'], $revoked ? 200 : 404);
        }

        appJson(['error' => 'Acción no reconocida'], 400);
    } catch (\DomainException $e) {
        appJson(['error' => $e->getMessage()], 422);
    }
}

appMethod('GET');

$search = trim((string)($_GET['search'] ?? ''));
$filterRole = trim((string)($_GET['role'] ?? 'todos'));
if (!isset($roles[$filterRole])) {
    $filterRole = 'todos';
}

$where = ['u.agency_id = ?'];
$params = [$agencyId];
if ($filterRole !== 'todos') {
    $where[] = 'u.role = ?';
    $params[] = $filterRole;
}
if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$perPage = 50;
$stmtTotal = getDB()->prepare("SELECT COUNT(*) FROM users u {$whereSql}");
$stmtTotal->execute($params);
$total = (int)$stmtTotal->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$stmtUsers = getDB()->prepare("
    SELECT u.id, u.name, u.email, u.role, u.created_at,
           GROUP_CONCAT(p.id ORDER BY p.name SEPARATOR ',') AS project_ids,
           GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR '||') AS project_names
    FROM users u
    LEFT JOIN project_members pm ON u.id = pm.user_id
    LEFT JOIN projects p ON pm.project_id = p.id AND p.agency_id = u.agency_id
    {$whereSql}
    GROUP BY u.id
    ORDER BY u.id ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmtUsers->execute($params);

$users = [];
foreach ($stmtUsers->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $users[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'roleLabel' => $roles[$row['role']]['label'] ?? $row['role'],
        'projectIds' => !empty($row['project_ids']) ? array_map('intval', explode(',', (string)$row['project_ids'])) : [],
        'projectNames' => !empty($row['project_names']) ? explode('||', (string)$row['project_names']) : [],
        'createdAt' => $row['created_at'],
    ];
}

$stmtStats = getDB()->prepare("
    SELECT COUNT(*) AS total_users,
           SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS count_admin,
           SUM(CASE WHEN role = 'project_manager' THEN 1 ELSE 0 END) AS count_pm,
           SUM(CASE WHEN role = 'colaborador' THEN 1 ELSE 0 END) AS count_collab,
           SUM(CASE WHEN role = 'cliente' THEN 1 ELSE 0 END) AS count_client
    FROM users WHERE agency_id = ?
");
$stmtStats->execute([$agencyId]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [];

$stmtProjects = getDB()->prepare('SELECT id, name, color FROM projects WHERE agency_id = ? AND archived_at IS NULL ORDER BY name ASC');
$stmtProjects->execute([$agencyId]);

$invitations = [];
foreach (invitationRepository()->pending($agencyId) as $invitation) {
    $invitations[] = [
        'id' => (int)$invitation['id'],
        'email' => $invitation['email'],
        'role' => $invitation['role'],
        'roleLabel' => $roles[$invitation['role']]['label'] ?? $invitation['role'],
        'expiresAt' => $invitation['expires_at'],
    ];
}

appJson([
    'users' => $users,
    'roles' => array_map(static fn(string $key, array $meta): array => [
        'key' => $key,
        'label' => $meta['label'],
        'className' => $meta['className'],
        'dot' => $meta['dot'],
    ], array_keys($roles), array_values($roles)),
    'stats' => [
        'total' => (int)($stats['total_users'] ?? 0),
        'admins' => (int)($stats['count_admin'] ?? 0),
        'managers' => (int)($stats['count_pm'] ?? 0),
        'collaborators' => (int)($stats['count_collab'] ?? 0),
        'clients' => (int)($stats['count_client'] ?? 0),
    ],
    'projects' => array_map(static fn(array $project): array => [
        'id' => (int)$project['id'],
        'name' => $project['name'],
        'color' => $project['color'] ?: '#0E66FF',
    ], $stmtProjects->fetchAll(PDO::FETCH_ASSOC)),
    'invitations' => $invitations,
    'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'pages' => $pages],
    'currentUserId' => $userId,
]);
