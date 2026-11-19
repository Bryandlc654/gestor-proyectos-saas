<?php
// profile.php - Redirige a la pestaña Perfil dentro de Configuración.
require_once __DIR__ . '/config/app.php';
requireLogin();
header('Location: /app/settings?tab=perfil');
exit;
