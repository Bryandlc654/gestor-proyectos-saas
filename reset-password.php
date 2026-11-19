<?php
// reset-password.php - Nueva contraseña en React, conservando el token.
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard');
    exit;
}

$token = (string)($_GET['token'] ?? '');
header('Location: /app/reset-password' . ($token !== '' ? '?token=' . rawurlencode($token) : ''));
exit;
