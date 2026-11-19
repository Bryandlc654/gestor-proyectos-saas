<?php
// security.php - Redirige a la pestaña Seguridad dentro de Configuración.
require_once __DIR__ . '/config/app.php';
requireLogin();
header('Location: /app/settings?tab=seguridad');
exit;
