<?php
// index.php - Landing pública (React prerenderizado en front/dist/welcome.html).
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: /app/dashboard');
    exit;
}

$landing = __DIR__ . '/front/dist/welcome.html';
if (!is_file($landing)) {
    // Build antiguo sin prerender: servir el shell de React, que también renderiza la landing en "/".
    $landing = __DIR__ . '/front/dist/index.html';
}
if (!is_file($landing)) {
    http_response_code(503);
    echo 'Front no compilado.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
readfile($landing);
