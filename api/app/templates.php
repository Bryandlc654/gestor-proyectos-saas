<?php
// api/app/templates.php - Plantillas de proyecto para React.
require_once __DIR__ . '/_bootstrap.php';

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('templates')) {
    appJson(['error' => 'Módulo de plantillas no disponible'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_project') {
            if (!canCreateProjects($role)) appJson(['error' => 'No puedes crear proyectos'], 403);
            $templateId = (int)($_POST['template_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') appJson(['error' => 'Escribe el nombre del proyecto'], 422);
            $deadline = trim((string)($_POST['deadline'] ?? ''));
            if ($deadline !== '' && !isIsoDate($deadline)) appJson(['error' => 'La fecha de entrega no es válida.'], 422);
            $newId = projectTemplateRepository()->createFromTemplate($templateId, $agencyId, $userId, $name, $deadline !== '' ? $deadline : null);
            activityRepository()->logByUser($userId, $newId, 'project.created', 'Creó el proyecto "' . $name . '" desde una plantilla');
            appJson(['success' => true, 'projectId' => $newId]);
        }
        if ($action === 'delete') {
            $templateId = (int)($_POST['template_id'] ?? 0);
            if (!projectTemplateRepository()->delete($templateId, $agencyId)) appJson(['error' => 'Plantilla no encontrada'], 404);
            activityRepository()->logByUser($userId, null, 'template.deleted', 'Eliminó la plantilla #' . $templateId);
            appJson(['success' => true]);
        }
        appJson(['error' => 'Acción no válida'], 400);
    } catch (\DomainException $e) {
        appJson(['error' => $e->getMessage()], 422);
    }
}

appMethod('GET');

$templates = [];
foreach (projectTemplateRepository()->forAgency($agencyId) as $template) {
    $templates[] = [
        'id' => (int)$template['id'],
        'name' => $template['name'],
        'description' => $template['description'],
        'category' => $template['category'],
        'color' => $template['color'] ?: '#0E66FF',
        'createdAt' => $template['created_at'],
        'createdBy' => $template['created_by_name'] ?? null,
    ];
}

appJson(['templates' => $templates]);
