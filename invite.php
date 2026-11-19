<?php
// invite.php - Aceptación de invitaciones en React.
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard');
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
header('Location: /app/invite' . ($token !== '' ? '?token=' . rawurlencode($token) : ''));
exit;
