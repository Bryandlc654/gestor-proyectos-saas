<?php
// api/users.php - Gestión de usuarios, roles y niveles de acceso
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$user = currentUser();
$db = getDB();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$csrf = $_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? '');

if (!verify_csrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

switch ($action) {
    // 1. Listar usuarios con sus roles y proyectos asociados (Solo Admin)
    case 'list':
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Solo los administradores pueden gestionar usuarios y roles']);
            exit;
        }

        // Obtener usuarios y agrupar los proyectos a los que están asignados
        $stmt = $db->query("
            SELECT 
                u.id, u.name, u.email, u.role, u.created_at,
                GROUP_CONCAT(p.id ORDER BY p.name SEPARATOR ',') as project_ids,
                GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR '||') as project_names
            FROM users u
            LEFT JOIN project_members pm ON u.id = pm.user_id
            LEFT JOIN projects p ON pm.project_id = p.id
            GROUP BY u.id
            ORDER BY u.id ASC
        ");
        $users = $stmt->fetchAll();

        // Obtener lista completa de proyectos para selección
        $stmtProjects = $db->query("SELECT id, name, color FROM projects ORDER BY name ASC");
        $allProjects = $stmtProjects->fetchAll();

        $data = array_map(function($u) {
            $badge = getRoleBadge($u['role']);
            $projIds = !empty($u['project_ids']) ? array_map('intval', explode(',', $u['project_ids'])) : [];
            $projNames = !empty($u['project_names']) ? explode('||', $u['project_names']) : [];

            return [
                'id'            => (int)$u['id'],
                'name'          => $u['name'],
                'email'         => $u['email'],
                'role'          => $u['role'],
                'role_label'    => $badge['label'],
                'role_class'    => $badge['class'],
                'project_ids'   => $projIds,
                'project_names' => $projNames,
                'created_at'    => formatDate($u['created_at'])
            ];
        }, $users);

        echo json_encode([
            'success'      => true, 
            'users'        => $data,
            'all_projects' => $allProjects
        ]);
        exit;

    // 2. Crear Nuevo Usuario o Cliente (Solo Admin)
    case 'create_user':
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acción reservada exclusivamente para administradores']);
            exit;
        }

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'colaborador';
        $projectIds = $_POST['project_ids'] ?? [];

        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'El nombre completo es obligatorio']);
            exit;
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Ingresa un correo electrónico válido']);
            exit;
        }

        if (strlen($password) < 8) {
            echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres']);
            exit;
        }

        if (!in_array($role, ['admin', 'project_manager', 'colaborador', 'cliente'])) {
            echo json_encode(['success' => false, 'message' => 'Nivel de acceso no válido']);
            exit;
        }

        // Verificar si el correo ya existe
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Ya existe un usuario registrado con este correo']);
            exit;
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $db->beginTransaction();
        try {
            $stmtInsert = $db->prepare("
                INSERT INTO users (name, email, password, role)
                VALUES (?, ?, ?, ?)
            ");
            $stmtInsert->execute([$name, $email, $hashedPassword, $role]);
            $newUserId = (int)$db->lastInsertId();

            // Asignar a los proyectos seleccionados si se enviaron
            if (!empty($projectIds) && is_array($projectIds)) {
                $stmtMember = $db->prepare("
                    INSERT INTO project_members (project_id, user_id, role)
                    VALUES (?, ?, ?)
                ");
                $memberRole = ($role === 'cliente') ? 'cliente' : (($role === 'project_manager') ? 'project_manager' : 'colaborador');

                foreach ($projectIds as $pId) {
                    $pId = (int)$pId;
                    if ($pId > 0) {
                        $stmtMember->execute([$pId, $newUserId, $memberRole]);
                    }
                }
            }

            $db->commit();
            echo json_encode([
                'success' => true, 
                'message' => 'Usuario / Cliente creado exitosamente',
                'user_id' => $newUserId
            ]);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Error al crear usuario: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error al crear el usuario. Inténtalo de nuevo.']);
            exit;
        }

    // 3. Asignar proyectos a un usuario / cliente (Solo Admin)
    case 'assign_projects':
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acción reservada exclusivamente para administradores']);
            exit;
        }

        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $projectIds = $_POST['project_ids'] ?? [];

        if ($targetUserId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Usuario inválido']);
            exit;
        }

        // Obtener el rol del usuario para asignarle el rol correspondiente en project_members
        $stmtUser = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmtUser->execute([$targetUserId]);
        $targetUser = $stmtUser->fetch();

        if (!$targetUser) {
            echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
            exit;
        }

        $targetRole = $targetUser['role'];
        $memberRole = ($targetRole === 'cliente') ? 'cliente' : (($targetRole === 'project_manager') ? 'project_manager' : 'colaborador');

        $db->beginTransaction();
        try {
            // Eliminar asignaciones anteriores
            $stmtDel = $db->prepare("DELETE FROM project_members WHERE user_id = ?");
            $stmtDel->execute([$targetUserId]);

            // Insertar nuevas asignaciones
            if (!empty($projectIds) && is_array($projectIds)) {
                $stmtIns = $db->prepare("INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)");
                foreach ($projectIds as $pId) {
                    $pId = (int)$pId;
                    if ($pId > 0) {
                        $stmtIns->execute([$pId, $targetUserId, $memberRole]);
                    }
                }
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Proyectos vinculados correctamente']);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Error al guardar asignaciones: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error al guardar asignaciones. Inténtalo de nuevo.']);
            exit;
        }

    // 4. Actualizar rol de un usuario (Solo Admin)
    case 'update_role':
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acción reservada exclusivamente para administradores']);
            exit;
        }

        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $newRole = $_POST['new_role'] ?? '';

        if (!in_array($newRole, ['admin', 'project_manager', 'colaborador', 'cliente'])) {
            echo json_encode(['success' => false, 'message' => 'Rol seleccionado no válido']);
            exit;
        }

        if ($targetUserId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Usuario no válido']);
            exit;
        }

        $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->execute([$newRole, $targetUserId]);

        // Sincronizar también su rol en project_members si existe
        $memberRole = ($newRole === 'cliente') ? 'cliente' : (($newRole === 'project_manager') ? 'project_manager' : 'colaborador');
        $stmtSync = $db->prepare("UPDATE project_members SET role = ? WHERE user_id = ?");
        $stmtSync->execute([$memberRole, $targetUserId]);

        // Si el admin se modificó a sí mismo, sincronizar su sesión
        if ($targetUserId === $user['id']) {
            $_SESSION['user_role'] = $newRole;
        }

        echo json_encode([
            'success'  => true,
            'message'  => 'Nivel de acceso actualizado correctamente',
            'new_role' => $newRole,
            'badge'    => getRoleBadge($newRole)
        ]);
        exit;

    // 5. Eliminar usuario (Solo Admin)
    case 'delete_user':
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acción reservada exclusivamente para administradores']);
            exit;
        }

        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if ($targetUserId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Usuario no válido']);
            exit;
        }

        if ($targetUserId === $user['id']) {
            echo json_encode(['success' => false, 'message' => 'No puedes eliminar tu propia cuenta de administrador']);
            exit;
        }

        $stmtDel = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmtDel->execute([$targetUserId]);

        echo json_encode(['success' => true, 'message' => 'Usuario eliminado correctamente']);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);
        exit;
}
