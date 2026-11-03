<?php
// api/bootstrap.php - Arranque común de los endpoints que usan el Router:
// exige sesión y token CSRF, y deja disponibles $request y $user.

require_once __DIR__ . '/../config/app.php';

use App\Http\Request;

header('Content-Type: application/json; charset=utf-8');

$request = Request::fromGlobals();

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!verify_csrf($request->string('csrf_token'))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

$user = currentUser();
