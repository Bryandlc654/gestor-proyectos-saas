<?php
// api/app/trash.php - Papelera de proyectos para React.
require_once __DIR__ . '/_bootstrap.php';

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('trash')) {
    appJson(['error' => 'Módulo de papelera no disponible'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $projectId = (int)($_POST['project_id'] ?? 0);
    $archived = $projectId > 0 ? projectRepository()->archivedProject($projectId, $agencyId) : null;
    if ($archived === null) appJson(['error' => 'Proyecto archivado no encontrado'], 404);

    if ($action === 'restore') {
        projectRepository()->restore($projectId, $userId);
        activityRepository()->logByUser($userId, $projectId, 'project.restored', 'Restauró el proyecto "' . $archived['name'] . '"');
        appJson(['success' => true]);
    }
    if ($action === 'delete_forever') {
        if (!isAdmin()) appJson(['error' => 'Solo un administrador puede eliminar definitivamente'], 403);
        deleteProjectUploads($projectId);
        getDB()->prepare('DELETE FROM projects WHERE id = ? AND agency_id = ? AND archived_at IS NOT NULL')->execute([$projectId, $agencyId]);
        activityRepository()->logByUser($userId, null, 'project.deleted', 'Eliminó definitivamente el proyecto "' . $archived['name'] . '"');
        appJson(['success' => true]);
    }
    appJson(['error' => 'Acción no válida'], 400);
}

appMethod('GET');

$projects = [];
foreach (projectRepository()->archivedForAgency($agencyId) as $project) {
    $projects[] = [
        'id' => (int)$project['id'],
        'name' => $project['name'],
        'color' => $project['color'] ?: '#0E66FF',
        'archivedAt' => $project['archived_at'],
        'archivedBy' => $project['archived_by_name'] ?? null,
    ];
}

appJson(['projects' => $projects, 'isAdmin' => isAdmin()]);
