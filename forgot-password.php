<?php
// forgot-password.php - Recuperación de contraseña en React.
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard');
    exit;
}

header('Location: /app/forgot-password');
exit;
