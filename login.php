<?php
// login.php - La autenticación vive en el front React (/app/login).
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard');
    exit;
}

header('Location: /app/login');
exit;
