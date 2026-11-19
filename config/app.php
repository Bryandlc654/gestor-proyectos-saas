<?php
// config/app.php - Arranque de la aplicación (sesión, cabeceras) y funciones de compatibilidad.
// La lógica vive en las clases de src/ (namespace App\); estas funciones globales las envuelven
// para que las páginas existentes sigan funcionando sin cambios.

use App\Repository\ActivityRepository;
use App\Repository\AgencyRepository;
use App\Repository\CashRepository;
use App\Repository\ConnectionRepository;
use App\Repository\CrmRepository;
use App\Repository\FinanceRepository;
use App\Repository\InvitationRepository;
use App\Repository\NotificationRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectShareRepository;
use App\Repository\ProjectTemplateRepository;
use App\Security\Csrf;
use App\Security\LoginThrottle;
use App\Security\Permissions;
use App\Security\UrlValidator;
use App\Service\AccountService;
use App\Service\ApiTokenService;
use App\Service\CashAutomationService;
use App\Service\EmailVerificationService;
use App\Service\ExportService;
use App\Service\GoogleOAuthService;
use App\Service\NotificationService;
use App\Service\PasswordResetService;
use App\Service\ProfileService;
use App\Service\ProfitabilityService;
use App\Service\ReportService;
use App\Service\ReminderService;
use App\Service\RolePermissionService;
use App\Service\TwoFactorService;
use App\Service\WebhookService;
use App\Repository\TaskRepository;
use App\Support\Format;
use App\Support\Mailer;
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

// Comprobar si el usuario tiene sesión activa y que su versión de credenciales siga vigente
function isLoggedIn(): bool {
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $stmt = getDB()->prepare("SELECT session_version, role, agency_id FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $checked = false;
        } else {
            $checked = isset($_SESSION['session_version'])
                && (int)$_SESSION['session_version'] === (int)$row['session_version'];
            if ($checked) {
                // El rol y la agencia salen siempre de la BD: si un admin degrada a alguien,
                // su sesión abierta pierde los permisos en la siguiente petición (no al cerrar sesión).
                $_SESSION['user_role'] = (string)$row['role'];
                $_SESSION['agency_id'] = (int)$row['agency_id'];
            }
        }
    } catch (Throwable $e) {
        error_log('No se pudo validar la sesión: ' . $e->getMessage());
        return true;
    }

    if (!$checked) {
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_name'],
            $_SESSION['user_email'],
            $_SESSION['user_role'],
            $_SESSION['agency_id'],
            $_SESSION['session_version']
        );
    }

    return $checked;
}

// Redireccionar si no está autenticado
function requireLogin(): void {
    if (!isLoggedIn()) {
        setFlash('error', 'Debes iniciar sesión para acceder al sistema.');
        header('Location: /login');
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
            $stmt = getDB()->prepare("SELECT role, agency_id, avatar FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $row = $stmt->fetch() ?: [];
            $_SESSION['user_role'] = $row['role'] ?? 'colaborador';
            $_SESSION['agency_id'] = (int)($row['agency_id'] ?? 0);
            $_SESSION['user_avatar'] = $row['avatar'] ?? null;
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
        'avatar'    => $_SESSION['user_avatar'] ?? null,
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
    $_SESSION['session_version'] = (int)($user['session_version'] ?? 0);
    $_SESSION['user_avatar'] = $user['avatar'] ?? null;
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

function projectShareRepository(): ProjectShareRepository {
    static $repo = null;
    return $repo ??= new ProjectShareRepository(getDB(), connectionRepository());
}

function projectTemplateRepository(): ProjectTemplateRepository {
    static $repo = null;
    return $repo ??= new ProjectTemplateRepository(getDB());
}

function crmRepository(): CrmRepository {
    static $repo = null;
    return $repo ??= new CrmRepository(getDB());
}

function financeRepository(): FinanceRepository {
    static $repo = null;
    return $repo ??= new FinanceRepository(getDB());
}

function cashRepository(): CashRepository {
    static $repo = null;
    return $repo ??= new CashRepository(getDB());
}

function cashAutomationService(): CashAutomationService {
    static $service = null;
    return $service ??= new CashAutomationService(getDB(), cashRepository());
}

function activityRepository(): ActivityRepository {
    static $repo = null;
    return $repo ??= new ActivityRepository(getDB());
}

function notificationRepository(): NotificationRepository {
    static $repo = null;
    return $repo ??= new NotificationRepository(getDB());
}

// Nombre de marca que se muestra en las plantillas de correo
function mailBrandName(): string {
    $name = trim((string)env('MAIL_FROM_NAME', ''));
    return $name !== '' ? $name : 'Vértice';
}

function mailer(): Mailer {
    static $mailer = null;
    if ($mailer !== null) {
        return $mailer;
    }

    // SMTP (Hostinger y similares). Se aceptan claves en mayúsculas o minúsculas.
    $smtpHost = trim((string)env('SMTP_HOST', env('smtp_host', '')));
    $smtpUser = trim((string)env('SMTP_USER', env('smtp_user', '')));
    $smtpPass = (string)env('SMTP_PASS', env('smtp_pass', ''));
    $smtpPort = (int)env('SMTP_PORT', env('smtp_port', '465'));
    $smtpSecure = strtolower(trim((string)env('SMTP_SECURE', env('smtp_secure', ''))));
    if ($smtpSecure === '') {
        $smtpSecure = $smtpPort === 465 ? 'ssl' : ($smtpPort === 587 ? 'tls' : 'none');
    }

    $from = trim((string)env('MAIL_FROM', env('mail_from', '')));
    if ($from === '') {
        $from = $smtpUser !== '' ? $smtpUser : 'no-reply@vertice.local';
    }

    // Si no se indica MAIL_ENABLED, se activa en cuanto hay SMTP configurado.
    $mailEnabled = env('MAIL_ENABLED', env('mail_enabled', null));
    $enabled = $mailEnabled !== null
        ? in_array(strtolower((string)$mailEnabled), ['1', 'true', 'yes', 'on'], true)
        : $smtpHost !== '';

    return $mailer = new Mailer(
        $enabled,
        $from,
        dirname(__DIR__) . '/storage/mail.log',
        [
            'host' => $smtpHost,
            'port' => $smtpPort,
            'user' => $smtpUser,
            'pass' => $smtpPass,
            'secure' => $smtpSecure,
            'name' => (string)env('MAIL_FROM_NAME', 'Vértice'),
        ]
    );
}

function emailVerificationService(): EmailVerificationService {
    static $service = null;
    return $service ??= new EmailVerificationService(getDB(), mailer(), appUrl(), activityRepository(), mailBrandName());
}

// ¿El usuario ya confirmó su correo? (los usuarios antiguos sin el dato cuentan como verificados)
function isEmailVerified(?int $userId = null): bool {
    static $cache = [];

    $userId = $userId ?? (int)(currentUser()['id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    if (!array_key_exists($userId, $cache)) {
        try {
            $stmt = getDB()->prepare("SELECT email_verified_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $value = $stmt->fetchColumn();
            $cache[$userId] = $value !== false && $value !== null && $value !== '';
        } catch (Throwable $e) {
            error_log('verificación de correo: ' . $e->getMessage());
            $cache[$userId] = true;
        }
    }

    return $cache[$userId];
}

function notificationService(): NotificationService {
    static $service = null;
    return $service ??= new NotificationService(notificationRepository(), mailer(), appUrl(), mailBrandName());
}

function passwordResetService(): PasswordResetService {
    static $service = null;
    return $service ??= new PasswordResetService(getDB(), mailer(), activityRepository(), appUrl(), mailBrandName());
}

function googleOAuthService(): GoogleOAuthService {
    static $service = null;
    if ($service === null) {
        $redirect = trim((string)env('GOOGLE_REDIRECT_URI', ''));
        if ($redirect === '') {
            $redirect = appUrl() . '/google-callback';
        }
        $service = new GoogleOAuthService(
            trim((string)env('GOOGLE_CLIENT_ID', '')),
            trim((string)env('GOOGLE_CLIENT_SECRET', '')),
            $redirect
        );
    }
    return $service;
}

function googleOAuthConfigured(): bool {
    return googleOAuthService()->isConfigured();
}

function apiTokenService(): ApiTokenService {
    static $service = null;
    return $service ??= new ApiTokenService(getDB());
}

function rolePermissionService(): RolePermissionService {
    static $service = null;
    return $service ??= new RolePermissionService(getDB());
}

function moduleAllowed(string $module, ?string $role = null, ?int $agencyId = null): bool {
    $role = $role ?? currentUserRole();
    $agencyId = $agencyId ?? currentAgencyId();
    if ($agencyId <= 0) {
        return false;
    }
    return rolePermissionService()->allows($agencyId, $role, $module);
}

/** Bloquea una página completa cuando el rol no tiene el módulo habilitado. */
function requireModule(string $module): void {
    if (!moduleAllowed($module)) {
        setFlash('error', 'No tienes acceso al módulo ' . RolePermissionService::moduleLabel($module) . '.');
        header('Location: /app/dashboard');
        exit;
    }
}

function webhookService(): WebhookService {
    static $service = null;
    return $service ??= new WebhookService(getDB());
}

function exportService(): ExportService {
    static $service = null;
    return $service ??= new ExportService(getDB(), projectRepository(), activityRepository());
}

function reportService(): ReportService {
    static $service = null;
    return $service ??= new ReportService(getDB(), projectRepository());
}

function profitabilityService(): ProfitabilityService {
    static $service = null;
    return $service ??= new ProfitabilityService(getDB());
}

function reminderService(): ReminderService {
    static $service = null;
    return $service ??= new ReminderService(getDB(), notificationService(), activityRepository());
}

function profileService(): ProfileService {
    static $service = null;
    return $service ??= new ProfileService(getDB(), activityRepository());
}

function twoFactorService(): TwoFactorService {
    static $service = null;
    return $service ??= new TwoFactorService(getDB());
}

// URL absoluta de la instalación: APP_URL o el host actual (para enlaces de correo)
function appUrl(): string {
    $configured = trim((string)env('APP_URL', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $scheme = isHttps() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function accountService(): AccountService {
    static $service = null;
    return $service ??= new AccountService(getDB(), agencyRepository(), invitationRepository());
}

// Agencia del usuario actual (nombre y logo para la interfaz)
function currentAgency(): array {
    static $agency = null;
    return $agency ??= (agencyRepository()->find(currentAgencyId()) ?? ['id' => 0, 'name' => 'Vertice', 'logo' => null]);
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

function taskRepository(): TaskRepository {
    static $repo = null;
    return $repo ??= new TaskRepository(getDB());
}

/**
 * Columnas de estado de tareas de una agencia (clave, etiqueta, tipo y orden).
 *
 * @return array<int, array{key:string,label:string,kind:string,order:int}>
 */
function taskStatusesForAgency(int $agencyId): array {
    static $cache = [];
    if (!array_key_exists($agencyId, $cache)) {
        $cache[$agencyId] = taskRepository()->statuses($agencyId);
    }
    return $cache[$agencyId];
}

/** @return array<int, array{key:string,label:string,kind:string,order:int}> */
function taskStatuses(): array {
    return taskStatusesForAgency(currentAgencyId());
}

/** @return array<string, string> */
function taskStatusLabelsForAgency(int $agencyId): array {
    $labels = [];
    foreach (taskStatusesForAgency($agencyId) as $status) {
        $labels[$status['key']] = $status['label'];
    }
    return $labels;
}

/** @return array<string, string> */
function taskStatusLabels(): array {
    return taskStatusLabelsForAgency(currentAgencyId());
}

function taskStatusLabel(string $status, ?int $agencyId = null): string {
    $labels = $agencyId === null ? taskStatusLabels() : taskStatusLabelsForAgency($agencyId);
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/** @return array<string, string> clave => open|in_progress|done */
function taskStatusKinds(?int $agencyId = null): array {
    $kinds = [];
    foreach (taskStatusesForAgency($agencyId ?? currentAgencyId()) as $status) {
        $kinds[$status['key']] = $status['kind'];
    }
    return $kinds;
}

/** open | in_progress | done */
function taskStatusKind(string $status, ?int $agencyId = null): string {
    foreach (taskStatusesForAgency($agencyId ?? currentAgencyId()) as $item) {
        if ($item['key'] === $status) {
            return $item['kind'];
        }
    }
    return match ($status) {
        'completada' => 'done',
        'en_progreso' => 'in_progress',
        default => 'open',
    };
}

function taskIsDone(string $status, ?int $agencyId = null): bool {
    return taskStatusKind($status, $agencyId) === 'done';
}

/** @return string[] Claves de las columnas de un tipo (open, in_progress o done). */
function taskStatusKeys(string $kind, ?int $agencyId = null): array {
    $keys = [];
    foreach (taskStatusesForAgency($agencyId ?? currentAgencyId()) as $status) {
        if ($status['kind'] === $kind) {
            $keys[] = $status['key'];
        }
    }
    return $keys;
}

/** Primera columna de tareas pendientes (donde se crean las tareas nuevas). */
function taskDefaultStatusKey(?int $agencyId = null): string {
    $statuses = taskStatusesForAgency($agencyId ?? currentAgencyId());
    foreach ($statuses as $status) {
        if ($status['kind'] === 'open') {
            return $status['key'];
        }
    }
    return $statuses[0]['key'] ?? 'pendiente';
}

function taskDoneStatusKey(?int $agencyId = null): ?string {
    return taskStatusKeys('done', $agencyId)[0] ?? null;
}

function taskOpenStatusKey(?int $agencyId = null): ?string {
    return taskStatusKeys('open', $agencyId)[0] ?? null;
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

// Contexto de acceso al proyecto: 'owner', 'view' o 'collaborate' (null sin acceso)
function projectAccessContext(int $projectId, int $userId, ?string $userRole = null): ?array {
    return projectRepository()->accessContext($projectId, $userId, $userRole ?? currentUserRole());
}

function isProjectOwnerAgency(int $projectId, int $userId): bool {
    return projectRepository()->isOwnerAgency($projectId, $userId);
}

// Permisos de contenido resueltos para el proyecto concreto (agencia dueña o invitada)
function canEditProjectTasks(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canEditTasks($projectId, $userId, $userRole ?? currentUserRole());
}

function canChangeProjectTaskStatus(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canChangeTaskStatus($projectId, $userId, $userRole ?? currentUserRole());
}

function canDeleteProjectTasks(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canDeleteTasks($projectId, $userId, $userRole ?? currentUserRole());
}

function canManageProjectChecklists(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canManageChecklists($projectId, $userId, $userRole ?? currentUserRole());
}

function canManageProjectStructure(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canManageAttachments($projectId, $userId, $userRole ?? currentUserRole());
}

function canUploadProjectFiles(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canUploadAttachments($projectId, $userId, $userRole ?? currentUserRole());
}

function canManageProjectTaskAttachments(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canManageTaskAttachments($projectId, $userId, $userRole ?? currentUserRole());
}

function canCommentOnProject(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canComment($projectId, $userId, $userRole ?? currentUserRole());
}

function canModerateProjectContent(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canModerate($projectId, $userId, $userRole ?? currentUserRole());
}

function canViewProjectFinances(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canViewFinances($projectId, $userId, $userRole ?? currentUserRole());
}

function canManageProjectShare(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canManageShare($projectId, $userId, $userRole ?? currentUserRole());
}

function canManageProjectShareMembers(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canManageShareMembers($projectId, $userId, $userRole ?? currentUserRole());
}

function canCreateProjectTickets(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canCreateTickets($projectId, $userId, $userRole ?? currentUserRole());
}

function canChangeProjectTicketStatus(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canChangeTicketStatus($projectId, $userId, $userRole ?? currentUserRole());
}

function canDeleteProjectTickets(int $projectId, int $userId, ?string $userRole = null): bool {
    return projectRepository()->canDeleteTickets($projectId, $userId, $userRole ?? currentUserRole());
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

/**
 * true si el cuerpo de la petición superó post_max_size: PHP descarta entonces $_POST y $_FILES
 * (incluido el token CSRF), y sin esta comprobación el usuario vería "token inválido".
 */
function requestExceededPostLimit(): bool {
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && empty($_POST)
        && empty($_FILES)
        && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

// Mensaje legible para el código de error de una subida ($_FILES[...]['error'])
function uploadErrorMessage(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño máximo permitido por el servidor.',
        UPLOAD_ERR_PARTIAL => 'El archivo se subió solo en parte. Inténtalo de nuevo.',
        UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo.',
        default => 'No se pudo recibir el archivo en el servidor.',
    };
}

/**
 * Borra del disco los adjuntos, documentos de factura e imágenes de comentarios de un proyecto.
 * Llamar ANTES de borrar el proyecto: el borrado en cascada elimina las filas pero no los archivos,
 * y las imágenes de comentarios seguirían accesibles por su URL directa.
 */
function deleteProjectUploads(int $projectId): void {
    $db = getDB();
    $uploadDir = dirname(__DIR__) . '/uploads/';
    $names = [];
    $stmt = $db->prepare('SELECT stored_name FROM project_files WHERE project_id = ?');
    $stmt->execute([$projectId]);
    $names = array_merge($names, $stmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt = $db->prepare('SELECT image_path FROM comments WHERE project_id = ? AND image_path IS NOT NULL');
    $stmt->execute([$projectId]);
    $names = array_merge($names, $stmt->fetchAll(PDO::FETCH_COLUMN));

    foreach ($names as $name) {
        $name = basename((string)$name); // nunca salir de uploads/
        if ($name !== '' && is_file($uploadDir . $name)) {
            @unlink($uploadDir . $name);
        }
    }
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
function setFlash(string $type, string $message, bool $celebrate = false): void {
    $_SESSION['flash'] = [
        'type'    => $type, // 'success', 'error', 'info', 'warning'
        'message' => $message,
        'celebrate' => $celebrate,
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

// Importe con moneda y símbolo ("EUR €1,250.00")
function formatMoney(float $amount, ?string $currency = 'USD'): string {
    return Format::money($amount, $currency);
}

function currencySymbol(?string $currency): string {
    return Format::currencySymbol($currency);
}

function isValidCurrency(?string $currency): bool {
    return Format::isValidCurrency($currency);
}

// Fecha AAAA-MM-DD válida en el calendario (no solo con el formato correcto)
function isIsoDate(mixed $value): bool {
    return Format::isIsoDate($value);
}

/**
 * Suma varios importes por moneda: ["USD" => 100, "EUR" => 250] => "EUR €250.00 · USD $100.00".
 * Se usa en tarjetas que agregan datos de proyectos/facturas con monedas distintas.
 */
function formatMoneyTotals(array $totals, int $maxCurrencies = 2): string {
    $clean = [];
    foreach ($totals as $currency => $amount) {
        $amount = (float)$amount;
        if (abs($amount) < 0.005) {
            continue;
        }
        $currency = Format::normalizeCurrency((string)$currency);
        $clean[$currency] = ($clean[$currency] ?? 0) + $amount;
    }

    if ($clean === []) {
        return '0.00';
    }

    arsort($clean);
    $parts = [];
    $shown = 0;
    $maxCurrencies = max(1, $maxCurrencies);
    foreach ($clean as $currency => $amount) {
        if ($shown === $maxCurrencies) {
            $parts[] = '+' . (count($clean) - $maxCurrencies) . ' más';
            break;
        }
        $parts[] = formatMoney((float)$amount, $currency);
        $shown++;
    }
    return implode(' · ', $parts);
}

/**
 * Sugerencias para los campos de proveedor/cliente de finanzas:
 * colaboradores de la agencia, empresas y contactos del CRM, proveedores ya registrados
 * y clientes de facturas. Se puede escribir un nombre nuevo igualmente.
 *
 * @return string[]
 */
function financeContactSuggestions(int $agencyId, ?int $projectId = null, bool $includeSuppliers = true, int $limit = 300): array {
    if ($agencyId <= 0) {
        return [];
    }

    $db = getDB();
    $queries = [
        ["SELECT name FROM users WHERE agency_id = ? AND name <> ''", [$agencyId]],
        ["SELECT name FROM crm_companies WHERE agency_id = ? AND name <> ''", [$agencyId]],
        ["SELECT name FROM crm_contacts WHERE agency_id = ? AND name <> ''", [$agencyId]],
        ["SELECT i.client_name AS name FROM invoices i INNER JOIN projects p ON p.id = i.project_id WHERE p.agency_id = ? AND i.client_name IS NOT NULL AND i.client_name <> ''", [$agencyId]],
    ];
    if ($includeSuppliers) {
        $queries[] = ["SELECT supplier AS name FROM payables WHERE agency_id = ? AND supplier <> ''", [$agencyId]];
    }
    if ($projectId !== null && $projectId > 0) {
        $queries[] = ["SELECT client_name AS name FROM invoices WHERE project_id = ? AND client_name IS NOT NULL AND client_name <> ''", [$projectId]];
    }

    $names = [];
    foreach ($queries as [$sql, $params]) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $name = trim((string)$name);
                $key = mb_strtolower($name);
                if ($name !== '' && !isset($names[$key])) {
                    $names[$key] = $name;
                }
            }
        } catch (Throwable $e) {
            // Tablas opcionales ausentes en instalaciones antiguas: se omiten sus sugerencias.
        }
    }

    $values = array_values($names);
    sort($values, SORT_NATURAL | SORT_FLAG_CASE);
    return array_slice($values, 0, $limit);
}

