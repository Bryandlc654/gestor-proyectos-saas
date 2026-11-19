<?php
// sitemap.php - Sitemap XML público (servido en /sitemap.xml vía .htaccess).
$base = 'https://verticeplatform.com';
$landing = __DIR__ . '/front/dist/welcome.html';
$lastmod = date('Y-m-d', is_file($landing) ? (int)filemtime($landing) : time());

$urls = [
    ['loc' => $base . '/', 'priority' => '1.0', 'changefreq' => 'weekly', 'lastmod' => $lastmod],
];

// Páginas de solución prerenderizadas (front/dist/soluciones/<slug>.html)
foreach (glob(__DIR__ . '/front/dist/soluciones/*.html') ?: [] as $file) {
    $urls[] = [
        'loc' => $base . '/soluciones/' . basename($file, '.html'),
        'priority' => '0.9',
        'changefreq' => 'monthly',
        'lastmod' => date('Y-m-d', (int)filemtime($file)),
    ];
}

// Comparativas prerenderizadas (front/dist/compare/<slug>.html)
foreach (glob(__DIR__ . '/front/dist/compare/*.html') ?: [] as $file) {
    $urls[] = [
        'loc' => $base . '/comparar/' . basename($file, '.html'),
        'priority' => '0.8',
        'changefreq' => 'monthly',
        'lastmod' => date('Y-m-d', (int)filemtime($file)),
    ];
}

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n    <loc>" . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
    if (!empty($u['lastmod'])) {
        echo "    <lastmod>{$u['lastmod']}</lastmod>\n";
    }
    echo "    <changefreq>{$u['changefreq']}</changefreq>\n    <priority>{$u['priority']}</priority>\n  </url>\n";
}
echo "</urlset>\n";
