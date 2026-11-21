<?php
// software-para-agencias.php - Página pública de Vértice Agency (/software-para-agencias), prerenderizada en front/dist/agencias.html.
// La home ("/") es solo de Vértice ERP.
$page = __DIR__ . '/front/dist/agencias.html';
if (!is_file($page)) {
    header('Location: /', true, 302);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
readfile($page);
