<?php
// google-login.php - Inicia el flujo OAuth 2.0 con Google.
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
session_regenerate_id(true);

$service = googleOAuthService();
if (!$service->isConfigured()) {
    setFlash('error', 'El inicio de sesión con Google todavía no está configurado.');
    header('Location: /login');
    exit;
}

$agencySlug = trim((string)($_GET['agency'] ?? ''));
$agency = null;
if ($agencySlug !== '') {
    $agency = agencyRepository()->findBySlug($agencySlug);
    if ($agency === null) {
        setFlash('error', 'La agencia indicada no existe.');
        header('Location: /login');
        exit;
    }
}

$state = bin2hex(random_bytes(32));
$_SESSION['google_oauth'] = [
    'state' => $state,
    'agency_id' => $agency !== null ? (int)$agency['id'] : 0,
    'agency_slug' => $agency['slug'] ?? '',
    'created_at' => time(),
];

$loginHint = trim((string)($_GET['email'] ?? ''));
header('Location: ' . $service->authorizationUrl($state, $loginHint !== '' ? $loginHint : null));
exit;
