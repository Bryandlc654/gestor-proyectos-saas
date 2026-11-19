<?php
// activity.php - Redirige a la pestaña Actividad dentro de Configuración.
require_once __DIR__ . '/config/app.php';
requireLogin();
$params = $_GET;
unset($params['tab']);
$query = http_build_query($params);
header('Location: /app/settings?tab=actividad' . ($query !== '' ? '&' . $query : ''));
exit;

