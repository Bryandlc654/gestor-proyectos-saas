<?php
// api/app/reports.php - Reportes configurables, vistas guardadas y exportaciones programadas para React.
require_once __DIR__ . '/_bootstrap.php';

use App\Service\ReportService;

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('reports')) {
    appJson(['error' => 'Módulo de reportes no disponible'], 403);
}

$nextRunFor = static function (string $frequency): string {
    return match ($frequency) {
        'daily' => (new DateTimeImmutable('tomorrow 08:00'))->format('Y-m-d H:i:s'),
        'monthly' => (new DateTimeImmutable('first day of next month 08:00'))->format('Y-m-d H:i:s'),
        default => (new DateTimeImmutable('next monday 08:00'))->format('Y-m-d H:i:s'),
    };
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $name = trim((string)($_POST['name'] ?? ''));
    $reportType = (string)($_POST['report_type'] ?? 'projects');
    if (!in_array($reportType, ReportService::TYPES, true)) {
        $reportType = 'projects';
    }
    $filtersInput = [
        'from' => (string)($_POST['from'] ?? ''),
        'to' => (string)($_POST['to'] ?? ''),
        'project_id' => (int)($_POST['project_id'] ?? 0),
    ];

    try {
        if ($action === 'save_view') {
            if ($name === '' || mb_strlen($name) > 120) {
                appJson(['error' => 'Escribe un nombre de hasta 120 caracteres para la vista.'], 422);
            }
            getDB()->prepare('INSERT INTO saved_reports (agency_id, user_id, name, report_type, filters) VALUES (?, ?, ?, ?, ?)')->execute([
                $agencyId,
                $userId,
                mb_substr($name, 0, 120),
                $reportType,
                json_encode($filtersInput, JSON_UNESCAPED_UNICODE),
            ]);
            appJson(['success' => true, 'message' => 'Vista guardada.']);
        }

        if ($action === 'delete_view') {
            $viewId = (int)($_POST['view_id'] ?? 0);
            getDB()->prepare('DELETE FROM saved_reports WHERE id = ? AND user_id = ?')->execute([$viewId, $userId]);
            appJson(['success' => true, 'message' => 'Vista eliminada.']);
        }

        if ($action === 'save_schedule') {
            if ($name === '' || mb_strlen($name) > 120) {
                appJson(['error' => 'Escribe un nombre de hasta 120 caracteres para la programación.'], 422);
            }
            $frequency = (string)($_POST['frequency'] ?? 'weekly');
            if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
                $frequency = 'weekly';
            }

            $rawRecipients = (string)($_POST['recipients'] ?? '');
            $emails = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', $rawRecipients) ?: []), static function (string $email): bool {
                return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            })));
            if ($emails === []) {
                appJson(['error' => 'Indica al menos un correo válido para enviar el reporte.'], 422);
            }
            if (count($emails) > 10) {
                $emails = array_slice($emails, 0, 10);
            }

            getDB()->prepare('
                INSERT INTO report_schedules
                    (agency_id, user_id, name, report_type, filters, frequency, recipients, active, next_run_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
            ')->execute([
                $agencyId,
                $userId,
                mb_substr($name, 0, 120),
                $reportType,
                json_encode($filtersInput, JSON_UNESCAPED_UNICODE),
                $frequency,
                implode(',', $emails),
                $nextRunFor($frequency),
            ]);
            appJson(['success' => true, 'message' => 'Exportación programada. Se enviará por correo según la frecuencia indicada.']);
        }

        if ($action === 'toggle_schedule') {
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $stmt = getDB()->prepare('SELECT id, active, next_run_at, frequency FROM report_schedules WHERE id = ? AND agency_id = ?');
            $stmt->execute([$scheduleId, $agencyId]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($schedule === false) {
                appJson(['error' => 'Programación no encontrada.'], 404);
            }
            $newActive = (int)$schedule['active'] === 1 ? 0 : 1;
            $nextRun = $schedule['next_run_at'];
            if ($newActive === 1 && (empty($nextRun) || $nextRun <= date('Y-m-d H:i:s'))) {
                $nextRun = $nextRunFor((string)$schedule['frequency']);
            }
            getDB()->prepare('UPDATE report_schedules SET active = ?, next_run_at = ? WHERE id = ? AND agency_id = ?')
                ->execute([$newActive, $nextRun, $scheduleId, $agencyId]);
            appJson(['success' => true, 'active' => $newActive === 1, 'message' => $newActive === 1 ? 'Programación activada.' : 'Programación pausada.']);
        }

        if ($action === 'delete_schedule') {
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            getDB()->prepare('DELETE FROM report_schedules WHERE id = ? AND agency_id = ?')->execute([$scheduleId, $agencyId]);
            appJson(['success' => true, 'message' => 'Programación eliminada.']);
        }

        appJson(['error' => 'Acción no reconocida.'], 400);
    } catch (\DomainException $e) {
        appJson(['error' => $e->getMessage()], 422);
    }
}

appMethod('GET');

$section = ($_GET['section'] ?? '') === 'schedules' ? 'schedules' : 'report';

$stmtViews = getDB()->prepare('SELECT * FROM saved_reports WHERE user_id = ? ORDER BY created_at DESC');
$stmtViews->execute([$userId]);
$savedViews = [];
$activeView = null;
$viewId = (int)($_GET['view'] ?? 0);

foreach ($stmtViews->fetchAll(PDO::FETCH_ASSOC) as $view) {
    $viewFilters = json_decode((string)$view['filters'], true);
    $entry = [
        'id' => (int)$view['id'],
        'name' => $view['name'],
        'reportType' => $view['report_type'],
        'filters' => is_array($viewFilters) ? [
            'from' => (string)($viewFilters['from'] ?? ''),
            'to' => (string)($viewFilters['to'] ?? ''),
            'project_id' => (int)($viewFilters['project_id'] ?? 0),
        ] : ['from' => '', 'to' => '', 'project_id' => 0],
        'createdAt' => $view['created_at'],
    ];
    $savedViews[] = $entry;
    if ($viewId > 0 && $entry['id'] === $viewId) {
        $activeView = $entry;
    }
}

$report = (string)($_GET['report'] ?? ($activeView['reportType'] ?? 'projects'));
if (!in_array($report, ReportService::TYPES, true)) {
    $report = 'projects';
}

$viewFilters = $activeView['filters'] ?? [];
$filters = [
    'from' => (string)($_GET['from'] ?? ($viewFilters['from'] ?? date('Y-m-01'))),
    'to' => (string)($_GET['to'] ?? ($viewFilters['to'] ?? date('Y-m-d'))),
    'project_id' => (int)($_GET['project_id'] ?? ($viewFilters['project_id'] ?? 0)),
];
$normalized = reportService()->normalizeFilters($filters);
$filters = [
    'from' => $normalized['from'],
    'to' => $normalized['to'],
    'project_id' => $normalized['project_id'],
];
if ($report === 'deadlines' && !isset($_GET['from']) && !isset($_GET['to']) && $activeView === null) {
    $filters['from'] = date('Y-m-d');
    $filters['to'] = date('Y-m-d', strtotime('+30 days'));
}

$visibleIds = array_values(array_map('intval', projectRepository()->visibleProjectIds($userId, $role)));
$in = $visibleIds === [] ? 'IN (0)' : 'IN (' . implode(',', $visibleIds) . ')';
$stmtProjects = getDB()->prepare("SELECT id, name FROM projects WHERE id {$in} AND agency_id = ? ORDER BY name ASC");
$stmtProjects->execute([$agencyId]);
$projects = $stmtProjects->fetchAll(PDO::FETCH_ASSOC);

$dataset = reportService()->dataset($report, $agencyId, $userId, $role, $filters);

$stmtSchedules = getDB()->prepare('
    SELECT rs.*, u.name AS author_name
    FROM report_schedules rs
    LEFT JOIN users u ON u.id = rs.user_id
    WHERE rs.agency_id = ?
    ORDER BY rs.created_at DESC, rs.id DESC
');
$stmtSchedules->execute([$agencyId]);
$schedules = [];
foreach ($stmtSchedules->fetchAll(PDO::FETCH_ASSOC) as $schedule) {
    $schedules[] = [
        'id' => (int)$schedule['id'],
        'name' => $schedule['name'],
        'reportType' => $schedule['report_type'],
        'frequency' => $schedule['frequency'],
        'recipients' => $schedule['recipients'],
        'active' => (int)$schedule['active'] === 1,
        'nextRunAt' => $schedule['next_run_at'],
        'lastRunAt' => $schedule['last_run_at'],
        'author' => $schedule['author_name'],
        'createdAt' => $schedule['created_at'],
    ];
}

$exportBase = '/export.php?type=report&report=' . urlencode($report)
    . '&from=' . urlencode($filters['from'])
    . '&to=' . urlencode($filters['to'])
    . ($filters['project_id'] > 0 ? '&project_id=' . (int)$filters['project_id'] : '');

appJson([
    'section' => $section,
    'report' => $report,
    'types' => [
        ['key' => 'projects', 'label' => 'Proyectos'],
        ['key' => 'people', 'label' => 'Personas'],
        ['key' => 'clients', 'label' => 'Clientes'],
        ['key' => 'deadlines', 'label' => 'Vencimientos'],
    ],
    'typeLabels' => ReportService::TYPE_LABELS,
    'frequencyLabels' => ['daily' => 'Diaria', 'weekly' => 'Semanal', 'monthly' => 'Mensual'],
    'filters' => $filters,
    'dataset' => $dataset,
    'projects' => array_map(static fn(array $project): array => ['id' => (int)$project['id'], 'name' => $project['name']], $projects),
    'savedViews' => $savedViews,
    'activeViewId' => $activeView['id'] ?? null,
    'schedules' => $schedules,
    'exportUrl' => $exportBase . '&format=csv',
    'exportPdfUrl' => $exportBase . '&format=pdf',
]);
