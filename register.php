<?php
// register.php - Registro de agencia en React.
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard');
    exit;
}

header('Location: /app/register');
exit;
