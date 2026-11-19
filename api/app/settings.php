<?php
// api/app/settings.php - Perfil, seguridad, actividad, permisos, Kanban y marca para React.
require_once __DIR__ . '/_bootstrap.php';

use App\Repository\ActivityRepository;
use App\Repository\TaskRepository;
use App\Service\RolePermissionService;
use App\Support\AvatarLibrary;

$deleteUploadedAvatar = static function (int $targetUserId): void {
    $stmt = getDB()->prepare('SELECT avatar FROM users WHERE id = ?');
    $stmt->execute([$targetUserId]);
    $oldAvatar = (string)($stmt->fetchColumn() ?: '');
    if (str_starts_with($oldAvatar, 'uploads/avatars/')) {
        $path = dirname(__DIR__, 2) . '/' . $oldAvatar;
        if (is_file($path)) {
            @unlink($path);
        }
    }
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'update_profile') {
            $profileService()->update($userId, (string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? ''));
            appJson(['success' => true, 'message' => 'Perfil actualizado. Recarga para ver los cambios en el menú.']);
        }

        if ($action === 'change_password') {
            $version = profileService()->changePassword(
                $userId,
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['new_password'] ?? ''),
                (string)($_POST['new_password_confirm'] ?? '')
            );
            $_SESSION['session_version'] = $version;
            appJson(['success' => true, 'message' => 'Contraseña actualizada. Las demás sesiones se cerraron.']);
        }

        if ($action === 'upload_avatar') {
            $file = $_FILES['avatar'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                appJson(['error' => 'Selecciona una imagen válida.'], 422);
            }
            if ((int)$file['size'] > 2 * 1024 * 1024) {
                appJson(['error' => 'La imagen no debe superar 2 MB.'], 422);
            }
            $mime = detectMime($file['tmp_name']);
            $extension = ALLOWED_IMAGE_MIMES[$mime] ?? null;
            if ($extension === null) {
                appJson(['error' => 'Formato no soportado. Usa PNG, JPG, WEBP o GIF.'], 422);
            }

            $avatarDir = dirname(__DIR__, 2) . '/uploads/avatars';
            if (!is_dir($avatarDir)) {
                mkdir($avatarDir, 0755, true);
            }
            $filename = 'user_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            if (!move_uploaded_file($file['tmp_name'], $avatarDir . '/' . $filename)) {
                appJson(['error' => 'No se pudo guardar la imagen.'], 422);
            }

            $deleteUploadedAvatar($userId);
            $avatarPath = 'uploads/avatars/' . $filename;
            profileService()->updateAvatar($userId, $avatarPath);
            $_SESSION['user_avatar'] = $avatarPath;
            appJson(['success' => true, 'avatarUrl' => '/' . $avatarPath, 'message' => 'Foto de perfil actualizada.']);
        }

        if ($action === 'select_avatar') {
            $path = AvatarLibrary::path((string)($_POST['avatar_key'] ?? ''));
            if ($path === null) throw new \DomainException('Avatar no válido.');
            $deleteUploadedAvatar($userId);
            profileService()->updateAvatar($userId, $path);
            $_SESSION['user_avatar'] = $path;
            appJson(['success' => true, 'avatarUrl' => '/' . $path, 'message' => 'Avatar actualizado.']);
        }

        if ($action === 'remove_avatar') {
            $deleteUploadedAvatar($userId);
            profileService()->updateAvatar($userId, null);
            $_SESSION['user_avatar'] = null;
            appJson(['success' => true, 'message' => 'Foto de perfil eliminada.']);
        }

        if ($action === 'start_2fa') {
            $setup = twoFactorService()->startSetup($userId);
            $_SESSION['2fa_setup_secret'] = $setup['secret'];
            $_SESSION['2fa_setup_uri'] = $setup['uri'];
            appJson(['success' => true, 'secret' => $setup['secret'], 'uri' => $setup['uri']]);
        }

        if ($action === 'confirm_2fa') {
            $secret = (string)($_SESSION['2fa_setup_secret'] ?? '');
            if ($secret === '') throw new \DomainException('La configuración caducó. Vuelve a empezar.');
            $codes = twoFactorService()->enable($userId, $secret, (string)($_POST['code'] ?? ''));
            unset($_SESSION['2fa_setup_secret'], $_SESSION['2fa_setup_uri']);
            activityRepository()->logByUser($userId, null, '2fa.enabled', 'Activó el doble factor');
            appJson(['success' => true, 'recoveryCodes' => $codes, 'message' => 'Doble factor activado correctamente.']);
        }

        if ($action === 'disable_2fa') {
            $_SESSION['session_version'] = twoFactorService()->disable($userId, (string)($_POST['password'] ?? ''));
            unset($_SESSION['2fa_setup_secret'], $_SESSION['2fa_setup_uri']);
            activityRepository()->logByUser($userId, null, '2fa.disabled', 'Desactivó el doble factor');
            appJson(['success' => true, 'message' => 'Doble factor desactivado.']);
        }

        if ($action === 'save_task_statuses') {
            if (!isAdmin()) appJson(['error' => 'Solo administradores'], 403);
            $rows = [];
            $posted = $_POST['statuses'] ?? null;
            if (is_string($posted)) {
                $decoded = json_decode($posted, true);
                $posted = is_array($decoded) ? $decoded : [];
            }
            if (is_array($posted) && $posted !== []) {
                foreach ($posted as $row) {
                    if (!is_array($row)) continue;
                    $rows[] = [
                        'key' => (string)($row['key'] ?? ''),
                        'label' => (string)($row['label'] ?? ''),
                        'kind' => (string)($row['kind'] ?? 'open'),
                    ];
                }
            } else {
                foreach ((array)($_POST['status_keys'] ?? []) as $key) {
                    $key = (string)$key;
                    $rows[] = [
                        'key' => $key,
                        'label' => (string)($_POST['status_labels'][$key] ?? ''),
                        'kind' => (string)($_POST['status_kinds'][$key] ?? 'open'),
                    ];
                }
            }
            taskRepository()->saveStatuses($agencyId, $rows);
            appJson(['success' => true, 'message' => 'Columnas del Kanban actualizadas.']);
        }

        if ($action === 'add_task_status') {
            if (!isAdmin()) appJson(['error' => 'Solo administradores'], 403);
            taskRepository()->addStatus($agencyId, (string)($_POST['label'] ?? ''), (string)($_POST['kind'] ?? 'open'));
            appJson(['success' => true, 'message' => 'Columna añadida.']);
        }

        if ($action === 'delete_task_status') {
            if (!isAdmin()) appJson(['error' => 'Solo administradores'], 403);
            taskRepository()->deleteStatus($agencyId, (string)($_POST['status_key'] ?? ''));
            appJson(['success' => true, 'message' => 'Columna eliminada.']);
        }

        if ($action === 'move_task_status') {
            if (!isAdmin()) appJson(['error' => 'Solo administradores'], 403);
            taskRepository()->moveStatus($agencyId, (string)($_POST['status_key'] ?? ''), (string)($_POST['direction'] ?? 'down'));
            appJson(['success' => true]);
        }

        if ($action === 'reset_task_statuses') {
            if (!isAdmin()) appJson(['error' => 'Solo administradores'], 403);
            taskRepository()->resetStatuses($agencyId);
            appJson(['success' => true, 'message' => 'Columnas restablecidas a los valores predeterminados.']);
        }

        if ($action === 'save_branding') {
            if (!isAdmin()) appJson(['error' => 'Solo administradores'], 403);
            $logoError = (int)($_FILES['app_logo']['error'] ?? UPLOAD_ERR_NO_FILE);
            // Si se eligió un logo y la subida falló, avisar antes de guardar nada (no a medias ni en silencio)
            if ($logoError !== UPLOAD_ERR_OK && $logoError !== UPLOAD_ERR_NO_FILE) {
                throw new \DomainException(uploadErrorMessage($logoError));
            }
            if ($logoError === UPLOAD_ERR_OK && (int)($_FILES['app_logo']['size'] ?? 0) > 2 * 1024 * 1024) {
                throw new \DomainException('El logo no debe superar 2 MB.');
            }
            if ($logoError === UPLOAD_ERR_OK && !isset(ALLOWED_IMAGE_MIMES[detectMime((string)$_FILES['app_logo']['tmp_name'])])) {
                throw new \DomainException('Formato no soportado. Usa PNG, JPG, WEBP o GIF.');
            }
            $newSlug = trim((string)($_POST['agency_slug'] ?? ''));
            if ($newSlug !== '') {
                agencyRepository()->setSlug($agencyId, $newSlug);
            }
            $newName = trim((string)($_POST['app_name'] ?? ''));
            if ($newName !== '') {
                agencyRepository()->rename($agencyId, mb_substr($newName, 0, 150));
            }

            $currentLogo = (string)(currentAgency()['logo'] ?? '');
            $logoMessage = 'Configuración de marca guardada.';
            if (!empty($_FILES['app_logo']['name']) && $logoError === UPLOAD_ERR_OK) {
                $mime = detectMime($_FILES['app_logo']['tmp_name']);
                $extension = ALLOWED_IMAGE_MIMES[$mime] ?? null;
                if ($extension === null) {
                    throw new \DomainException('Formato no soportado. Usa PNG, JPG, WEBP o GIF.');
                }
                $dir = dirname(__DIR__, 2) . '/uploads/logo';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $filename = 'agency_' . $agencyId . '_' . time() . '.' . $extension;
                if (!move_uploaded_file($_FILES['app_logo']['tmp_name'], $dir . '/' . $filename)) {
                    throw new \DomainException('No se pudo guardar el archivo del logo.');
                }
                $oldPath = dirname(__DIR__, 2) . '/' . $currentLogo;
                if ($currentLogo !== '' && is_file($oldPath)) {
                    @unlink($oldPath);
                }
                agencyRepository()->setLogo($agencyId, 'uploads/logo/' . $filename);
                $logoMessage = 'Logo y nombre de la aplicación actualizados.';
            }
            appJson(['success' => true, 'message' => $logoMessage]);
        }

        if ($action === 'remove_logo') {
            if (!isAdmin()) appJson(['error' => 'Solo administradores'], 403);
            $currentLogo = (string)(currentAgency()['logo'] ?? '');
            if ($currentLogo !== '') {
                $path = dirname(__DIR__, 2) . '/' . $currentLogo;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            agencyRepository()->setLogo($agencyId, null);
            appJson(['success' => true, 'message' => 'Logo restablecido al diseño predeterminado.']);
        }

        if ($action === 'save_permissions') {
            if (!isAdmin()) appJson(['error' => 'Solo los administradores pueden cambiar los permisos'], 403);
            $posted = $_POST['permissions'] ?? [];
            if (is_string($posted)) {
                $decoded = json_decode($posted, true);
                $posted = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($posted)) $posted = [];
            foreach (['project_manager', 'colaborador', 'cliente'] as $roleKey) {
                $roleModules = $posted[$roleKey] ?? [];
                if (!is_array($roleModules)) $roleModules = [];
                rolePermissionService()->saveRole($agencyId, $roleKey, array_keys($roleModules), $userId);
            }
            activityRepository()->logByUser($userId, null, 'settings.role_modules', 'Actualizó los módulos permitidos por rol');
            appJson(['success' => true, 'message' => 'Permisos por rol actualizados. Los cambios se aplican de inmediato.']);
        }

        appJson(['error' => 'Acción no válida'], 400);
    } catch (\DomainException $e) {
        appJson(['error' => $e->getMessage()], 422);
    }
}

appMethod('GET');

$permissions = null;
if (isAdmin()) {
    $permissions = [
        'matrix' => rolePermissionService()->forAgency($agencyId),
        'modules' => RolePermissionService::MODULES,
        'groups' => RolePermissionService::GROUPS,
        'ceilings' => RolePermissionService::CEILINGS,
        'core' => RolePermissionService::CORE,
        'labels' => RolePermissionService::ROLE_LABELS,
    ];
}

$activityLabels = ActivityRepository::labels();
$activityAction = trim((string)($_GET['activity_action'] ?? ''));
if ($activityAction !== '' && !isset($activityLabels[$activityAction])) {
    $activityAction = '';
}
$activityProject = (int)($_GET['activity_project'] ?? 0);
$activityPage = max(1, (int)($_GET['activity_page'] ?? 1));

$activity = [];
$activityProjects = [];
$activityPagination = ['page' => 1, 'perPage' => 50, 'total' => 0, 'pages' => 1];
if (isAdmin()) {
    $perPage = 50;
    [$activityRows, $activityTotal] = activityRepository()->pageForAgency(
        $agencyId,
        $perPage,
        ($activityPage - 1) * $perPage,
        $activityAction !== '' ? $activityAction : null,
        $activityProject > 0 ? $activityProject : null
    );
    $activityPages = max(1, (int)ceil($activityTotal / $perPage));
    $activityPage = min($activityPage, $activityPages);
    $activityPagination = ['page' => $activityPage, 'perPage' => $perPage, 'total' => (int)$activityTotal, 'pages' => $activityPages];

    foreach ($activityRows as $row) {
        $activity[] = [
            'id' => (int)$row['id'],
            'action' => $row['action'],
            'description' => $row['description'],
            'user' => $row['user_name'] ?? null,
            'createdAt' => $row['created_at'],
            'label' => $row['label'] ?? $row['action'],
            'projectName' => $row['project_name'] ?? null,
        ];
    }

    $stmtActivityProjects = getDB()->prepare('SELECT id, name FROM projects WHERE agency_id = ? ORDER BY name ASC');
    $stmtActivityProjects->execute([$agencyId]);
    $activityProjects = array_map(static fn(array $project): array => [
        'id' => (int)$project['id'],
        'name' => $project['name'],
    ], $stmtActivityProjects->fetchAll(PDO::FETCH_ASSOC));
}

$avatar = (string)($user['avatar'] ?? '');
$currentAgencyRow = currentAgency();
$logo = (string)($currentAgencyRow['logo'] ?? '');
$avatars = [];
foreach (AvatarLibrary::all() as $key => $item) {
    $avatars[] = ['key' => $key, 'label' => $item['label'], 'file' => '/' . $item['file'], 'gender' => $item['gender']];
}

appJson([
    'isAdmin' => isAdmin(),
    'profile' => [
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $role,
        'roleLabel' => getRoleBadge($role)['label'],
        'agency' => $currentAgencyRow['name'],
        'avatarUrl' => $avatar !== '' ? '/' . ltrim($avatar, '/') : null,
    ],
    'permissions' => $permissions,
    'activity' => $activity,
    'activityLabels' => $activityLabels,
    'activityProjects' => $activityProjects,
    'activityPagination' => $activityPagination,
    'activityFilters' => ['action' => $activityAction, 'projectId' => $activityProject],
    'avatars' => $avatars,
    'twoFactorEnabled' => twoFactorService()->isEnabled($userId),
    'taskStatuses' => isAdmin() ? array_map(static fn(array $status): array => [
        'key' => $status['key'],
        'label' => $status['label'],
        'kind' => $status['kind'],
    ], taskRepository()->statuses($agencyId)) : [],
    'taskStatusDefaults' => TaskRepository::STATUSES,
    'branding' => isAdmin() ? [
        'name' => $currentAgencyRow['name'],
        'slug' => (string)($currentAgencyRow['slug'] ?? ''),
        'logoUrl' => $logo !== '' ? '/' . ltrim($logo, '/') : null,
    ] : null,
    'appUrl' => appUrl(),
]);
