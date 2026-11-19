<?php
// compare.php - Páginas comparativas públicas (/comparar/<slug>), prerenderizadas en front/dist/compare/.
$slug = strtolower((string)($_GET['c'] ?? ''));
$file = preg_match('/^[a-z0-9-]{2,30}$/', $slug) ? __DIR__ . '/front/dist/compare/' . $slug . '.html' : '';

if ($file === '' || !is_file($file)) {
    header('Location: /#comparar', true, 302);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
readfile($file);
