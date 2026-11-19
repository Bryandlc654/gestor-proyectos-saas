<?php
// api/agency-logos.php - Endpoint público para la landing: nombre y logo de las agencias que tienen logo.
// No expone ningún otro dato de la agencia (ni id, ni slug, ni código de conexión).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');
require_once __DIR__ . '/../config/app.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['agencies' => []]);
    exit;
}

$root = dirname(__DIR__);
$agencies = [];
try {
    $rows = getDB()->query("SELECT name, logo FROM agencies WHERE logo IS NOT NULL AND logo <> '' ORDER BY id")->fetchAll();
    foreach ($rows as $row) {
        $logo = ltrim((string)$row['logo'], '/');
        // Solo logos subidos que existen de verdad en disco
        if (!str_starts_with($logo, 'uploads/logo/') || str_contains($logo, '..') || !is_file($root . '/' . $logo)) {
            continue;
        }
        $agencies[] = ['name' => (string)$row['name'], 'logo' => '/' . $logo];
    }
} catch (Throwable $e) {
    error_log('agency-logos: ' . $e->getMessage());
}

echo json_encode(['agencies' => $agencies], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
