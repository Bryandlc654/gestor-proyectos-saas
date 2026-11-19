<?php
// verify-email.php - Verificación de correo en React.
require_once __DIR__ . '/config/app.php';

$token = trim((string)($_GET['token'] ?? ''));
header('Location: /app/verify-email' . ($token !== '' ? '?token=' . rawurlencode($token) : ''));
exit;
