<?php
// app.php - Sirve el front React y protege las rutas privadas en servidor.
require_once __DIR__ . '/config/app.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/app', PHP_URL_PATH) ?: '/app';
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/app';
}

$publicRoutes = [
    '/app/login',
    '/app/register',
    '/app/forgot-password',
    '/app/reset-password',
    '/app/verify-email',
    '/app/invite',
    '/app/welcome',
    '/app/2fa',
];
$isPublic = in_array($path, $publicRoutes, true) || str_starts_with($path, '/app/legal/');

// La landing vive en la raíz; /app/welcome queda como alias permanente.
if (!isLoggedIn() && $path === '/app/welcome') {
    header('Location: /', true, 301);
    exit;
}

// Las pantallas de la aplicación no deben indexarse: solo la landing en "/".
header('X-Robots-Tag: noindex, nofollow');

if (!isLoggedIn() && !$isPublic) {
    header('Location: /app/login');
    exit;
}

if (isLoggedIn() && in_array($path, ['/app/login', '/app/register', '/app/welcome'], true)) {
    header('Location: /app/dashboard');
    exit;
}

$index = __DIR__ . '/front/dist/index.html';
if (!is_file($index)) {
    http_response_code(503);
    echo 'Front no compilado.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile($index);
