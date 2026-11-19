<?php
// database/reminders.php - Envía recordatorios de vencimiento (solo CLI/cron)
//
// Uso:
//   php database/reminders.php
//
// Programa este comando una vez al día, por ejemplo a las 08:00.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app.php';

$result = reminderService()->run();
fwrite(STDOUT, sprintf(
    "Recordatorios enviados: %d tareas, %d facturas.\n",
    $result['tasks'],
    $result['invoices']
));
