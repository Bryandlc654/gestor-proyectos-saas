<?php
// config/app.php - Arranque de la aplicación (sesión, cabeceras) y funciones de compatibilidad.
// La lógica vive en las clases de src/ (namespace App\); estas funciones globales las envuelven
// para que las páginas existentes sigan funcionando sin cambios.

use App\Repository\AgencyRepository;
use App\Repository\ConnectionRepository;
use App\Repository\InvitationRepository;
use App\Repository\ProjectRepository;
use App\Security\Csrf;
use App\Security\LoginThrottle;
use App\Security\Permissions;
use App\Security\UrlValidator;
use App\Service\AccountService;
use App\Support\Format;
use App\Support\Paginator;
use App\Upload\AttachmentStorage;

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

    // Auto-recuperar rol y agencia si no estuvieran en sesión (p. ej. sesiones anteriores a la multi-agencia)
    if (empty($_SESSION['user_role']) || empty($_SESSION['agency_id'])) {
        try {
            $stmt = getDB()->prepare("SELECT role, agency_id FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $row = $stmt->fetch() ?: [];
            $_SESSION['user_role'] = $row['role'] ?? 'colaborador';
            $_SESSION['agency_id'] = (int)($row['agency_id'] ?? 0);
        } catch (Exception $e) {
            $_SESSION['user_role'] = 'colaborador';
            $_SESSION['agency_id'] = 0;
        }
    }

    return [
        'id'        => $_SESSION['user_id'],
        'name'      => $_SESSION['user_name'] ?? 'Usuario',
        'email'     => $_SESSION['user_email'] ?? '',
        'role'      => $_SESSION['user_role'] ?? 'colaborador',
        'agency_id' => (int)($_SESSION['agency_id'] ?? 0),
    ];
}

// Guardar en sesión los datos del usuario que acaba de autenticarse (login, registro, invitación)
function startUserSession(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'] ?? 'colaborador';
    $_SESSION['agency_id'] = (int)$user['agency_id'];
}

function currentAgencyId(): int {
    return (int)(currentUser()['agency_id'] ?? 0);
}

function agencyRepository(): AgencyRepository {
    static $repo = null;
    return $repo ??= new AgencyRepository(getDB());
}

function invitationRepository(): InvitationRepository {
    static $repo = null;
    return $repo ??= new InvitationRepository(getDB());
}

function connectionRepository(): ConnectionRepository {
    static $repo = null;
    return $repo ??= new ConnectionRepository(getDB(), agencyRepository());
}

function accountService(): AccountService {
    static $service = null;
    return $service ??= new AccountService(getDB(), agencyRepository(), invitationRepository());
}

// Agencia del usuario actual (nombre y logo para la interfaz)
function currentAgency(): array {
    static $agency = null;
    return $agency ??= (agencyRepository()->find(currentAgencyId()) ?? ['id' => 0, 'name' => 'FlowTasks', 'logo' => null]);
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

// Repositorio de proyectos compartido por las funciones de compatibilidad
function projectRepository(): ProjectRepository {
    static $repo = null;
    return $repo ??= new ProjectRepository(getDB());
}

// Subconsulta de proyectos visibles para el usuario actual: [sql, parámetros] para `IN (...)`
function visibleProjectsSql(): array {
    $user = currentUser();
    return projectRepository()->visibleProjectsSql((int)$user['id'], $user['role']);
}

// Comprobar si el usuario tiene permiso para acceder/ver un proyecto
function canAccessProject(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canAccess($projectId, $userId, $userRole ?? currentUserRole());
}

// Comprobar si el usuario puede gestionar/editar metadatos del proyecto (Admin o PM propietario/asignado)
function canManageProject(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canManage($projectId, $userId, $userRole ?? currentUserRole());
}

// Permisos por rol (la lógica está en App\Security\Permissions)
function canManageTasks(?string $userRole = null): bool {
    return Permissions::canManageTasks($userRole ?? currentUserRole());
}

function canEditTasks(?string $userRole = null): bool {
    return canManageTasks($userRole);
}

function canDeleteTasks(?string $userRole = null): bool {
    return Permissions::canDeleteTasks($userRole ?? currentUserRole());
}

function canChangeTaskStatus(?string $userRole = null): bool {
    return Permissions::canChangeTaskStatus($userRole ?? currentUserRole());
}

function canManageChecklists(?string $userRole = null): bool {
    return Permissions::canManageChecklists($userRole ?? currentUserRole());
}

function canManageAttachments(?string $userRole = null): bool {
    return Permissions::canManageAttachments($userRole ?? currentUserRole());
}

function canCreateTickets(?string $userRole = null): bool {
    return Permissions::canCreateTickets($userRole ?? currentUserRole());
}

function canChangeTicketStatus(?string $userRole = null): bool {
    return Permissions::canChangeTicketStatus($userRole ?? currentUserRole());
}

function canDeleteTickets(?string $userRole = null): bool {
    return Permissions::canDeleteTickets($userRole ?? currentUserRole());
}

function canCreateProjects(?string $userRole = null): bool {
    return Permissions::canCreateProjects($userRole ?? currentUserRole());
}

function canDeleteProjects(?string $userRole = null): bool {
    return Permissions::canDeleteProjects($userRole ?? currentUserRole());
}

// Pertenencia a proyecto (evita mezclar datos entre proyectos)
function taskBelongsToProject(int $taskId, int $projectId): bool {
    return projectRepository()->taskBelongsTo($taskId, $projectId);
}

function folderBelongsToProject(int $folderId, int $projectId): bool {
    return projectRepository()->folderBelongsTo($folderId, $projectId);
}

// Nombre del usuario si puede ser responsable de tareas del proyecto (miembro o propietario); null si no
function projectAssigneeName(int $userId, int $projectId): ?string {
    return projectRepository()->assigneeName($userId, $projectId);
}

// Validar URL externa: solo http/https (bloquea javascript:, data:, etc.)
function isSafeUrl(string $url): bool {
    return UrlValidator::isSafe($url);
}

const ALLOWED_ATTACHMENT_EXTENSIONS = AttachmentStorage::ALLOWED_EXTENSIONS;
const ALLOWED_IMAGE_MIMES = AttachmentStorage::IMAGE_MIMES;

function detectMime(string $path): string {
    return AttachmentStorage::detectMime($path);
}

// Valida y guarda un adjunto subido en uploads/ (ver App\Upload\AttachmentStorage::store)
function storeUploadedAttachment(array $file, int $maxBytes = AttachmentStorage::DEFAULT_MAX_BYTES): array {
    return (new AttachmentStorage(dirname(__DIR__) . '/uploads/'))->store($file, $maxBytes);
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

// Tokens CSRF (ver App\Security\Csrf)
function csrf_token(): string {
    return Csrf::token();
}

function verify_csrf(?string $token): bool {
    return Csrf::verify($token);
}

// Limitación de intentos de inicio de sesión (ver App\Security\LoginThrottle)
function loginThrottle(): LoginThrottle {
    static $throttle = null;
    return $throttle ??= new LoginThrottle(getDB());
}

function isLoginThrottled(string $email, string $ip): bool {
    return loginThrottle()->isThrottled($email, $ip);
}

function recordFailedLogin(string $email, string $ip): void {
    loginThrottle()->recordFailure($email, $ip);
}

function clearLoginAttempts(string $email): void {
    loginThrottle()->clear($email);
}

// Paginación: calcula la página actual (?page=N), el offset y el total de páginas
function paginate(int $totalItems, int $perPage = 25): array {
    return Paginator::paginate($totalItems, $perPage, (int)($_GET['page'] ?? 1));
}

// URL de la página actual cambiando solo el número de página (conserva filtros)
function pageUrl(int $page): string {
    return '?' . http_build_query(array_merge($_GET, ['page' => $page]));
}

// Formateo de fechas y tamaños (ver App\Support\Format)
function formatDate(?string $date, bool $withTime = false): string {
    return Format::date($date, $withTime);
}

function formatBytes(int $bytes, int $precision = 1): string {
    return Format::bytes($bytes, $precision);
}

