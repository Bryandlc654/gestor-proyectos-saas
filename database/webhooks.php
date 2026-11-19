<?php
// database/webhooks.php - Procesa la cola de webhooks salientes.
// Uso: php database/webhooks.php [--limit=25]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$limit = 25;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, 8);
    }
}
$limit = max(1, min(200, $limit));

try {
    $service = new App\Service\WebhookService(getDB());
    $stats = $service->processPending($limit);
    echo json_encode($stats, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'webhooks: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
