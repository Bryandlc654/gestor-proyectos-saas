<?php
// two-factor.php - Verificación 2FA en React.
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard');
    exit;
}

if ((int)($_SESSION['2fa_pending_user_id'] ?? 0) <= 0) {
    header('Location: /app/login');
    exit;
}

header('Location: /app/2fa');
exit;
