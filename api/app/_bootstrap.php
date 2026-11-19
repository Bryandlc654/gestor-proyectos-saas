<?php
// api/app/_bootstrap.php - Arranque común de la API del front React.
// Mantiene la sesión PHP existente y exige CSRF en escrituras.

require_once __DIR__ . '/../../config/app.php';

use App\Service\RolePermissionService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Errores de validación del dominio (DomainException) y errores controlados de la API (ApiException):
// son mensajes pensados para el usuario, así que se devuelven como 422/estado propio en JSON
// en lugar del 500 "Algo salió mal" del manejador general.
$previousExceptionHandler = set_exception_handler(null);
set_exception_handler(static function (Throwable $e) use ($previousExceptionHandler): void {
    if ($e instanceof \DomainException || $e instanceof \App\Support\ApiException) {
        if (!headers_sent()) {
            http_response_code($e instanceof \App\Support\ApiException ? $e->status : 422);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    if ($previousExceptionHandler !== null) {
        $previousExceptionHandler($e);
        return;
    }
    throw $e;
});

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// Permite enviar JSON desde React además de formularios clásicos.
$rawBody = file_get_contents('php://input');
if ($rawBody !== false && $rawBody !== '') {
    $jsonBody = json_decode($rawBody, true);
    if (is_array($jsonBody)) {
        $_POST = array_merge($_POST, $jsonBody);
    }
}

$user = currentUser();
$agency = currentAgency();
$agencyId = currentAgencyId();
$role = currentUserRole();
$userId = (int)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $token = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!verify_csrf($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Token de seguridad inválido']);
        exit;
    }
}

/** @return array<string, bool> */
function appModules(): array
{
    $modules = [];
    foreach (array_keys(RolePermissionService::MODULES) as $module) {
        $modules[$module] = moduleAllowed($module);
    }

    return $modules;
}

function appJson(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function appMethod(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
        appJson(['error' => 'Método no permitido'], 405);
    }
}
