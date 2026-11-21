<?php
// solution.php - Páginas de solución públicas (/soluciones/<slug>), prerenderizadas en front/dist/soluciones/.
$slug = strtolower((string)($_GET['s'] ?? ''));
$file = preg_match('/^[a-z0-9-]{2,60}$/', $slug) ? __DIR__ . '/front/dist/soluciones/' . $slug . '.html' : '';

if ($file === '' || !is_file($file)) {
    header('Location: /', true, 302);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
readfile($file);
