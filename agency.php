<?php
// agency.php - Acceso público de agencia en React.
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard');
    exit;
}

$slug = trim((string)($_GET['slug'] ?? ''));
header('Location: /app/login' . ($slug !== '' ? '?agency=' . rawurlencode($slug) : ''));
exit;
