<?php
// config/app.php - Utilidades globales, sesión y seguridad

// Activar compresión GZIP de salida si está disponible para reducir transferencia en un 70-80%
if (!ob_get_level() && !headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

// Sanitización rápida de cadenas para prevenir XSS
function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Comprobar si el usuario tiene sesión activa
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Redireccionar si no está autenticado
function requireLogin(): void {
    if (!isLoggedIn()) {
        setFlash('error', 'Debes iniciar sesión para acceder al sistema.');
        header('Location: login.php');
        exit;
    }
}

// Obtener datos del usuario logueado con rol
function currentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }

    // Auto-recuperar rol si no estuviera en sesión
    if (empty($_SESSION['user_role'])) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $r = $stmt->fetchColumn();
            $_SESSION['user_role'] = $r ?: 'colaborador';
        } catch (Exception $e) {
            $_SESSION['user_role'] = 'colaborador';
        }
    }

    return [
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['user_name'] ?? 'Usuario',
        'email' => $_SESSION['user_email'] ?? '',
        'role'  => $_SESSION['user_role'] ?? 'colaborador',
    ];
}

// Helpers de Roles y Permisos (admin, project_manager, colaborador, cliente)
function currentUserRole(): string {
    $u = currentUser();
    return $u ? $u['role'] : 'colaborador';
}

function isAdmin(): bool {
    return currentUserRole() === 'admin';
}

function isProjectManager(): bool {
    $r = currentUserRole();
    return $r === 'project_manager' || $r === 'admin';
}

function isColaborador(): bool {
    $r = currentUserRole();
    return $r === 'colaborador' || isProjectManager();
}

function isCliente(): bool {
    return currentUserRole() === 'cliente';
}

// Comprobar si el usuario tiene permiso para acceder/ver un proyecto
function canAccessProject(int $projectId, int $userId, ?string $userRole = null): bool {
    $role = $userRole ?? currentUserRole();
    if ($role === 'admin') return true;
    try {
        $db = getDB();
        if ($role === 'cliente') {
            // Un cliente solo puede acceder a los proyectos a los que ha sido explícitamente vinculado
            $stmt = $db->prepare("
                SELECT id FROM project_members 
                WHERE project_id = ? AND user_id = ?
            ");
            $stmt->execute([$projectId, $userId]);
            return (bool)$stmt->fetch();
        }
        $stmt = $db->prepare("
            SELECT id FROM projects 
            WHERE id = ? AND (user_id = ? OR id IN (SELECT project_id FROM project_members WHERE user_id = ?))
        ");
        $stmt->execute([$projectId, $userId, $userId]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

// Comprobar si el usuario puede gestionar/editar metadatos del proyecto (Admin o PM propietario/asignado)
function canManageProject(int $projectId, int $userId, ?string $userRole = null): bool {
    $role = $userRole ?? currentUserRole();
    if ($role === 'admin') return true;
    if ($role === 'project_manager') {
        try {
            $db = getDB();
            $stmt = $db->prepare("
                SELECT id FROM projects 
                WHERE id = ? AND (user_id = ? OR id IN (SELECT project_id FROM project_members WHERE user_id = ? AND role = 'project_manager'))
            ");
            $stmt->execute([$projectId, $userId, $userId]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            return false;
        }
    }
    return false;
}

// Puede crear o editar tareas: admin, project_manager, colaborador
function canManageTasks(?string $userRole = null): bool {
    $role = $userRole ?? currentUserRole();
    return in_array($role, ['admin', 'project_manager', 'colaborador']);
}

// Alias semántico para edición de tareas
function canEditTasks(?string $userRole = null): bool {
    return canManageTasks($userRole);
}

// Puede eliminar tareas: admin, project_manager
function canDeleteTasks(?string $userRole = null): bool {
    $role = $userRole ?? currentUserRole();
    return in_array($role, ['admin', 'project_manager']);
}

// Puede cambiar estado de tareas: admin, project_manager, colaborador (cliente NO)
function canChangeTaskStatus(?string $userRole = null): bool {
    $role = $userRole ?? currentUserRole();
    return in_array($role, ['admin', 'project_manager', 'colaborador']);
}

// Puede gestionar checklists (crear listas, añadir pasos, completar): admin, project_manager, colaborador
function canManageChecklists(?string $userRole = null): bool {
    $role = $userRole ?? currentUserRole();
    return in_array($role, ['admin', 'project_manager', 'colaborador']);
}

// Puede gestionar archivos y carpetas: admin, project_manager, colaborador
function canManageAttachments(?string $userRole = null): bool {
    $role = $userRole ?? currentUserRole();
    return in_array($role, ['admin', 'project_manager', 'colaborador']);
}

// Puede crear o consultar tickets: todos los roles (admin, project_manager, colaborador, cliente)
function canCreateTickets(?string $userRole = null): bool {
    return true;
}

// Puede crear proyectos: admin, project_manager
function canCreateProjects(?string $userRole = null): bool {
    $role = $userRole ?? currentUserRole();
    return in_array($role, ['admin', 'project_manager']);
}

// Puede eliminar proyectos: solo admin o creador con rol project_manager
function canDeleteProjects(?string $userRole = null): bool {
    $role = $userRole ?? currentUserRole();
    return in_array($role, ['admin', 'project_manager']);
}

// Badge visual para renderizar en UI
function getRoleBadge(string $role): array {
    return match($role) {
        'admin' => [
            'label' => 'Administrador',
            'class' => 'bg-purple-50 text-purple-700 border-purple-200/80',
            'dot'   => 'bg-purple-500'
        ],
        'project_manager' => [
            'label' => 'Project Manager',
            'class' => 'bg-indigo-50 text-indigo-700 border-indigo-200/80',
            'dot'   => 'bg-indigo-500'
        ],
        'colaborador' => [
            'label' => 'Colaborador',
            'class' => 'bg-emerald-50 text-emerald-700 border-emerald-200/80',
            'dot'   => 'bg-emerald-500'
        ],
        'cliente' => [
            'label' => 'Cliente',
            'class' => 'bg-amber-50 text-amber-700 border-amber-200/80',
            'dot'   => 'bg-amber-500'
        ],
        default => [
            'label' => ucfirst($role),
            'class' => 'bg-slate-50 text-slate-700 border-slate-200/80',
            'dot'   => 'bg-slate-400'
        ]
    };
}

// Manejo de mensajes flash (notificaciones tipo toast)
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type'    => $type, // 'success', 'error', 'info', 'warning'
        'message' => $message,
    ];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Generación y verificación de Tokens CSRF
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool {
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Formateo de fechas a formato amigable en español
function formatDate(?string $date, bool $withTime = false): string {
    if (!$date) return 'Sin fecha';
    $timestamp = strtotime($date);
    if ($withTime) {
        return date('d M, Y - h:i A', $timestamp);
    }
    return date('d M, Y', $timestamp);
}

// Formateo de tamaño de archivo (bytes a KB, MB)
function formatBytes(int $bytes, int $precision = 1): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Configuración Global y Logo de la Aplicación
function getAppSetting(string $key, string $default = ''): string {
    static $settingsCache = null;
    if ($settingsCache === null) {
        $settingsCache = [];
        try {
            $db = getDB();
            $stmt = $db->query("SELECT setting_key, setting_value FROM app_settings");
            if ($stmt) {
                while ($row = $stmt->fetch()) {
                    $settingsCache[$row['setting_key']] = $row['setting_value'];
                }
            }
        } catch (Exception $e) {
            // Ignorar error si la tabla aún no existe
        }
    }
    return $settingsCache[$key] ?? $default;
}

function setAppSetting(string $key, ?string $value): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO app_settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        return $stmt->execute([$key, $value]);
    } catch (Exception $e) {
        return false;
    }
}
