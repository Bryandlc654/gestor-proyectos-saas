<?php
// database/reports.php - Procesa las exportaciones programadas (cron diario).
// Uso: php database/reports.php

require_once __DIR__ . '/../config/app.php';

$db = getDB();
$now = new DateTimeImmutable();

$stmt = $db->prepare("
    SELECT rs.*, u.role
    FROM report_schedules rs
    LEFT JOIN users u ON u.id = rs.user_id AND u.agency_id = rs.agency_id
    WHERE rs.active = 1
      AND rs.next_run_at IS NOT NULL
      AND rs.next_run_at <= NOW()
    ORDER BY rs.next_run_at ASC
    LIMIT 50
");
$stmt->execute();
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($schedules === []) {
    echo "Sin exportaciones programadas pendientes.\n";
    exit(0);
}

$dir = __DIR__ . '/../storage/reports';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "No se pudo crear storage/reports.\n");
    exit(1);
}

foreach ($schedules as $schedule) {
    if (empty($schedule['role'])) {
        $db->prepare('UPDATE report_schedules SET active = 0 WHERE id = ?')->execute([(int)$schedule['id']]);
        echo 'Programación #' . $schedule['id'] . " desactivada: usuario no encontrado.\n";
        continue;
    }

    $filters = json_decode((string)$schedule['filters'], true);
    if (!is_array($filters)) {
        $filters = [];
    }

    $dataset = reportService()->dataset(
        (string)$schedule['report_type'],
        (int)$schedule['agency_id'],
        (int)$schedule['user_id'],
        (string)$schedule['role'],
        $filters
    );

    $csv = "\xEF\xBB\xBF" . exportService()->csv($dataset['headers'], $dataset['rows']);
    $filename = 'reporte-' . (int)$schedule['id'] . '-' . $now->format('Ymd-His') . '.csv';
    if (file_put_contents($dir . '/' . $filename, $csv) === false) {
        echo 'Programación #' . $schedule['id'] . ": no se pudo guardar el archivo.\n";
        continue;
    }

    $token = bin2hex(random_bytes(24));
    $expires = $now->modify('+7 days')->format('Y-m-d H:i:s');
    $nextRun = match ((string)$schedule['frequency']) {
        'daily' => $now->modify('+1 day')->setTime(8, 0)->format('Y-m-d H:i:s'),
        'monthly' => $now->modify('first day of next month')->setTime(8, 0)->format('Y-m-d H:i:s'),
        default => $now->modify('+1 week')->setTime(8, 0)->format('Y-m-d H:i:s'),
    };

    $db->prepare("
        UPDATE report_schedules
        SET last_run_at = ?, next_run_at = ?, last_token = ?, last_file = ?, token_expires_at = ?
        WHERE id = ?
    ")->execute([
        $now->format('Y-m-d H:i:s'),
        $nextRun,
        $token,
        $filename,
        $expires,
        (int)$schedule['id'],
    ]);

    $link = appUrl() . '/report-download.php?token=' . $token;
    $subject = 'Reporte ' . ($dataset['title'] ?? 'Vértice') . ' · ' . $now->format('d/m/Y');
    $body = "Reporte: " . ($dataset['title'] ?? '') . "\n\n"
        . "Puedes descargar el CSV desde este enlace (válido hasta el " . $expires . "):\n" . $link . "\n";
    $html = '<p>Reporte: <strong>' . e($dataset['title'] ?? '') . '</strong></p>'
        . '<p><a href="' . e($link) . '">Descargar CSV</a></p>'
        . '<p style="font-size:12px;color:#64748b;">El enlace caduca el ' . e($expires) . '.</p>';

    $sent = 0;
    foreach (preg_split('/[,;]+/', (string)$schedule['recipients']) ?: [] as $recipient) {
        $recipient = trim($recipient);
        if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            if (mailer()->send($recipient, $subject, $body, $html)) {
                $sent++;
            }
        }
    }

    echo 'Programación #' . $schedule['id'] . ' procesada; correos enviados: ' . $sent . ".\n";
}

echo "Listo.\n";
