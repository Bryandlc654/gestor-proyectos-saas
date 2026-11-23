<?php
// api/app/shares.php - Compartir proyectos con agencias conectadas (front React).
// GET  ?project_id=  -> datos del panel (agencia dueña: agencias conectadas y shares;
//                       agencia invitada: su share y su equipo).
// POST action=share | unshare | set_members
require_once __DIR__ . '/_bootstrap.php';

$shares = projectShareRepository();
$projectId = (int)($_POST['project_id'] ?? ($_GET['project_id'] ?? 0));

if ($projectId <= 0) {
    appJson(['error' => 'Proyecto no indicado'], 422);
}

$canManageShare = canManageProjectShare($projectId, $userId, $role);
$canManageMembers = canManageProjectShareMembers($projectId, $userId, $role);

if (!$canManageShare && !$canManageMembers) {
    appJson(['error' => 'Solo los administradores gestionan los proyectos compartidos'], 403);
}

$accessLabel = static fn(string $access): string => $access === 'collaborate' ? 'colaborar' : 'solo lectura';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $shareId = (int)($_POST['share_id'] ?? 0);

    try {
        // Compartir con una agencia conectada o cambiar el nivel de acceso
        if ($action === 'share') {
            if (!$canManageShare) {
                appJson(['error' => 'Solo el administrador de la agencia dueña puede compartir el proyecto'], 403);
            }
            $guestAgencyId = (int)($_POST['guest_agency_id'] ?? 0);
            if ($guestAgencyId <= 0) {
                appJson(['error' => 'Selecciona una agencia'], 422);
            }
            $access = (string)($_POST['access'] ?? 'view') === 'collaborate' ? 'collaborate' : 'view';

            $existing = null;
            foreach ($shares->forProject($projectId) as $current) {
                if ($current['guest_agency_id'] === $guestAgencyId) {
                    $existing = $current;
                    break;
                }
            }

            $newShareId = $shares->share($projectId, $agencyId, $guestAgencyId, $access, $userId);
            projectRepository()->clearCache();
            $projectName = projectRepository()->name($projectId) ?? ('#' . $projectId);
            activityRepository()->logByUser(
                $userId,
                $projectId,
                $existing === null ? 'share.created' : 'share.updated',
                ($existing === null ? 'Compartió' : 'Cambió el acceso de') . ' "' . $projectName . '" (' . $accessLabel($access) . ')'
            );
            notificationService()->notifyAgencyAdmins(
                $guestAgencyId,
                $existing === null ? 'project.shared' : 'project.share_updated',
                $existing === null ? 'Nuevo proyecto compartido' : 'Acceso a proyecto actualizado',
                $agency['name'] . ($existing === null ? ' compartió el proyecto "' : ' cambió el acceso del proyecto "')
                    . $projectName . '" (' . $accessLabel($access) . ').',
                '/project/' . $projectId
            );
            appJson(['success' => true, 'shareId' => $newShareId, 'message' => $existing === null ? 'Proyecto compartido.' : 'Acceso actualizado.']);
        }

        // Dejar de compartir
        if ($action === 'unshare') {
            $share = $shares->find($shareId);
            if (!$canManageShare || $share === null || $share['project_id'] !== $projectId || $share['owner_agency_id'] !== $agencyId) {
                appJson(['error' => 'Solo el administrador de la agencia dueña puede dejar de compartir'], 403);
            }
            projectRepository()->clearCache();
            if (!$shares->remove($shareId, $agencyId)) {
                appJson(['error' => 'Proyecto compartido no encontrado'], 404);
            }
            $projectName = projectRepository()->name($projectId) ?? ('#' . $projectId);
            activityRepository()->logByUser($userId, $projectId, 'share.removed', 'Dejó de compartir "' . $projectName . '"');
            notificationService()->notifyAgencyAdmins(
                (int)$share['guest_agency_id'],
                'project.unshared',
                'Proyecto dejó de estar compartido',
                $agency['name'] . ' dejó de compartir el proyecto "' . $projectName . '".',
                null
            );
            appJson(['success' => true, 'message' => 'El proyecto dejó de compartirse.']);
        }

        // La agencia invitada elige a los miembros de su equipo que participan
        if ($action === 'set_members') {
            $share = $shares->find($shareId);
            if (!$canManageMembers || $share === null || $share['project_id'] !== $projectId || $share['guest_agency_id'] !== $agencyId) {
                appJson(['error' => 'Proyecto compartido no encontrado'], 403);
            }
            $ids = $_POST['user_ids'] ?? [];
            if (!is_array($ids)) {
                $ids = array_filter(explode(',', (string)$ids));
            }
            $shares->setMembers($shareId, $agencyId, $ids);
            projectRepository()->clearCache();
            activityRepository()->logByUser(
                $userId,
                $projectId,
                'share.members_updated',
                'Actualizó el equipo de su agencia en el proyecto compartido'
            );
            appJson(['success' => true, 'message' => 'Equipo actualizado.']);
        }

        appJson(['error' => 'Acción no válida'], 400);
    } catch (\DomainException $e) {
        appJson(['error' => $e->getMessage()], 422);
    }
}

appMethod('GET');

$response = ['mode' => $canManageShare ? 'owner' : 'guest'];

if ($canManageShare) {
    $currentShares = [];
    foreach ($shares->forProject($projectId) as $row) {
        $currentShares[$row['guest_agency_id']] = $row;
    }
    $response['agencies'] = array_map(static function (array $row) use ($currentShares): array {
        $guestId = (int)$row['agency_id'];
        $current = $currentShares[$guestId] ?? null;
        return [
            'agencyId' => $guestId,
            'agencyName' => $row['agency_name'],
            'agencyLogo' => $row['agency_logo'] ? '/' . ltrim($row['agency_logo'], '/') : null,
            'shareId' => $current !== null ? (int)$current['share_id'] : null,
            'access' => $current['access'] ?? null,
        ];
    }, connectionRepository()->forAgency($agencyId)['connected']);
} else {
    $share = projectRepository()->guestShare($projectId, $userId, $role);
    if ($share === null) {
        appJson(['error' => 'Proyecto compartido no encontrado'], 404);
    }
    $stmtMembers = getDB()->prepare('SELECT id, name, email, role, avatar FROM users WHERE agency_id = ? ORDER BY name ASC');
    $stmtMembers->execute([$agencyId]);
    $response['share'] = [
        'id' => (int)$share['id'],
        'access' => $share['access'],
        'ownerAgencyName' => $share['owner_agency_name'],
    ];
    $response['agencyName'] = $agency['name'];
    $response['members'] = array_map(static fn(array $member): array => [
        'id' => (int)$member['id'],
        'name' => $member['name'],
        'email' => $member['email'],
        'role' => $member['role'],
        'avatarUrl' => !empty($member['avatar']) ? '/' . ltrim((string)$member['avatar'], '/') : null,
    ], $stmtMembers->fetchAll(PDO::FETCH_ASSOC));
    $response['memberIds'] = $shares->memberIds((int)$share['id']);
}

appJson($response);
