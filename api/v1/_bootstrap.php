<?php
// api/v1/_bootstrap.php - Autenticación, errores y utilidades comunes de la API v1.
if (!defined('VERTICE_API_V1')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../config/app.php';

use App\Support\ApiException;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$apiRequestId = bin2hex(random_bytes(8));
header('X-Request-Id: ' . $apiRequestId);

/** @param array<string, mixed> $payload */
function apiJson(array $payload, int $status = 200, array $meta = []): void
{
    global $apiRequestId;
    http_response_code($status);
    $meta = array_merge(['request_id' => $apiRequestId], $meta);
    echo json_encode(array_merge($payload, ['meta' => $meta]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @param array<string, mixed> $details */
function apiError(int $status, string $code, string $message, array $details = []): void
{
    global $apiRequestId;
    http_response_code($status);
    if ($status === 429) {
        header('Retry-After: 60');
    }
    $error = ['code' => $code, 'message' => $message];
    if ($details !== []) {
        $error['details'] = $details;
    }
    echo json_encode([
        'error' => $error,
        'request_id' => $apiRequestId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @param string[] $methods */
function apiRequireMethod(array $methods): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        apiError(405, 'method_not_allowed', 'Método HTTP no permitido para este endpoint.');
    }
}

/** @return array<string, mixed> */
function apiJsonBody(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_contains($contentType, 'application/json')) {
        throw new ApiException(415, 'unsupported_media_type', 'Envía el cuerpo como application/json.');
    }

    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if ($raw === false) {
        throw new ApiException(400, 'invalid_body', 'No se pudo leer el cuerpo de la petición.');
    }
    if (strlen($raw) > 1048576) {
        throw new ApiException(413, 'payload_too_large', 'El cuerpo de la petición supera 1 MB.');
    }
    if (trim($raw) === '') {
        throw new ApiException(400, 'invalid_json', 'El cuerpo JSON está vacío.');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new ApiException(400, 'invalid_json', 'El cuerpo no es un JSON válido.');
    }
    return $data;
}

/** @param array<string, mixed> $row */
function apiTicketResource(array $row): array
{
    return App\Support\TicketResource::fromRow($row);
}

set_exception_handler(function (Throwable $e): void {
    global $apiRequestId;
    if ($e instanceof ApiException) {
        apiError($e->status, $e->errorCode, $e->getMessage(), $e->details);
    }
    error_log('api v1 [' . $apiRequestId . ']: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    apiError(500, 'internal_error', 'Error interno del servidor.');
});

$apiToken = apiTokenService()->authenticate();
