<?php
// config/app.php - Utilidades globales, sesión y seguridad

// Activar compresión GZIP de salida si está disponible para reducir transferencia en un 70-80%
if (!ob_get_level() && !headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

require_once __DIR__ . '/database.php';

// Detectar HTTPS (también detrás de proxy / balanceador)
function isHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

// Cabeceras de seguridad HTTP
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
    if (isHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Flags para incrustar JSON de forma segura dentro de <script> (evita romper con </script>)
const JSON_SCRIPT_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

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

// Puede cambiar el estado de tickets: admin, project_manager, colaborador (cliente NO)
function canChangeTicketStatus(?string $userRole = null): bool {
    $role = $userRole ?? currentUserRole();
    return in_array($role, ['admin', 'project_manager', 'colaborador']);
}

// Puede eliminar tickets: admin, project_manager
function canDeleteTickets(?string $userRole = null): bool {
    $role = $userRole ?? currentUserRole();
    return in_array($role, ['admin', 'project_manager']);
}

// Comprobar que una tarea pertenece al proyecto indicado (evita mezclar datos entre proyectos)
function taskBelongsToProject(int $taskId, int $projectId): bool {
    $stmt = getDB()->prepare("SELECT 1 FROM tasks WHERE id = ? AND project_id = ?");
    $stmt->execute([$taskId, $projectId]);
    return (bool)$stmt->fetchColumn();
}

// Comprobar que una carpeta pertenece al proyecto indicado
function folderBelongsToProject(int $folderId, int $projectId): bool {
    $stmt = getDB()->prepare("SELECT 1 FROM project_folders WHERE id = ? AND project_id = ?");
    $stmt->execute([$folderId, $projectId]);
    return (bool)$stmt->fetchColumn();
}

// Validar URL externa: solo http/https (bloquea javascript:, data:, etc.)
function isSafeUrl(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

// Extensiones permitidas para adjuntos (lista blanca)
const ALLOWED_ATTACHMENT_EXTENSIONS = [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
    'txt', 'csv', 'md', 'rtf',
    'jpg', 'jpeg', 'png', 'gif', 'webp',
    'zip', 'rar', '7z',
    'mp3', 'mp4', 'mov', 'webm',
    'fig', 'psd', 'ai', 'sketch',
];

// Tipos MIME reales permitidos para imágenes (comentarios / logo)
const ALLOWED_IMAGE_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

// Detectar el tipo MIME real del archivo a partir de su contenido
function detectMime(string $path): string {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    return $finfo->file($path) ?: 'application/octet-stream';
}

/**
 * Valida y guarda un adjunto subido en uploads/.
 * Devuelve ['ok' => true, 'stored_name', 'original_name', 'size', 'mime'] o ['ok' => false, 'error'].
 */
function storeUploadedAttachment(array $file, int $maxBytes = 20 * 1024 * 1024): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Error al recibir el archivo. Asegúrate de que no exceda el límite permitido.'];
    }
    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'El archivo no debe superar los ' . formatBytes($maxBytes) . '.'];
    }

    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_ATTACHMENT_EXTENSIONS, true)) {
        return ['ok' => false, 'error' => 'Tipo de archivo no permitido.'];
    }

    $mime = detectMime($file['tmp_name']);
    // Bloquear contenido ejecutable o interpretable por el navegador aunque se disfrace con otra extensión
    if (preg_match('#^(text/html|application/xhtml|image/svg|application/x-(php|httpd-php|sh|executable|msdownload))#', $mime)) {
        return ['ok' => false, 'error' => 'El contenido del archivo no está permitido.'];
    }

    $uploadDir = dirname(__DIR__) . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $storedName)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.'];
    }

    return ['ok' => true, 'stored_name' => $storedName, 'original_name' => $origName, 'size' => (int)$file['size'], 'mime' => $mime];
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

// Limitación de intentos de inicio de sesión (fuerza bruta)
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MINUTES = 15;

function isLoginThrottled(string $email, string $ip): bool {
    try {
        $stmt = getDB()->prepare("
            SELECT
                SUM(email = ?) AS by_email,
                SUM(ip_address = ?) AS by_ip
            FROM login_attempts
            WHERE attempted_at > (NOW() - INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)
              AND (email = ? OR ip_address = ?)
        ");
        $stmt->execute([$email, $ip, $email, $ip]);
        $row = $stmt->fetch();
        // Por IP se permite más margen (varios usuarios tras la misma red)
        return (int)$row['by_email'] >= LOGIN_MAX_ATTEMPTS || (int)$row['by_ip'] >= LOGIN_MAX_ATTEMPTS * 4;
    } catch (Exception $e) {
        error_log('login_attempts no disponible: ' . $e->getMessage());
        return false;
    }
}

function recordFailedLogin(string $email, string $ip): void {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)")->execute([$email, $ip]);
        // Limpieza ocasional de registros antiguos
        if (random_int(1, 50) === 1) {
            $db->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
        }
    } catch (Exception $e) {
        error_log('login_attempts no disponible: ' . $e->getMessage());
    }
}

function clearLoginAttempts(string $email): void {
    try {
        getDB()->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$email]);
    } catch (Exception $e) {
        error_log('login_attempts no disponible: ' . $e->getMessage());
    }
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
