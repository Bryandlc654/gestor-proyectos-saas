<?php

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Security\Permissions;
use App\Security\UrlValidator;
use App\Support\Format;
use App\Support\Html;

/**
 * API de tareas: crear, editar, cambiar estado, eliminar y detalle.
 * Las respuestas mantienen el formato JSON que espera el frontend (textos ya escapados + campos raw_*).
 */
final class TaskController
{
    /**
     * @param array{id: int|string, role: string} $user Usuario autenticado
     */
    public function __construct(
        private readonly array $user,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
    ) {
    }

    public function register(Router $router): void
    {
        $router
            ->on('create', $this->create(...))
            ->on('update', $this->update(...))
            ->on('update_status', $this->updateStatus(...))
            ->on('delete', $this->delete(...))
            ->on('get_detail', $this->detail(...));
    }

    public function create(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!Permissions::canManageTasks($this->role())) {
            throw HttpException::forbidden('Tu nivel de acceso no permite crear tareas');
        }

        $title = $this->requireTitle($request);
        $description = $request->string('description');
        $priority = $request->enum('priority', TaskRepository::PRIORITIES, 'media');
        $dueDate = $request->string('due_date') ?: null;
        [$assignedUserId, $assigneeName] = $this->resolveAssignee($request, $projectId);

        $taskId = $this->tasks->create($projectId, $title, $description, $priority, $assignedUserId, $dueDate);

        return [
            'success' => true,
            'message' => 'Tarea creada exitosamente',
            'task' => [
                'id' => $taskId,
                'title' => Html::e($title),
                'description' => Html::e($description),
                'priority' => $priority,
                'status' => 'pendiente',
                'assigned_to' => Html::e($assigneeName),
                'due_date' => $dueDate ? Format::date($dueDate) : 'Sin fecha',
                'comments_count' => 0,
            ],
        ];
    }

    public function update(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!Permissions::canManageTasks($this->role())) {
            throw HttpException::forbidden('No tienes permisos para editar esta tarea');
        }

        $taskId = $request->int('task_id');
        $title = $this->requireTitle($request);
        $description = $request->string('description');
        $priority = $request->enum('priority', TaskRepository::PRIORITIES, 'media');
        $status = $request->enum('status', TaskRepository::STATUSES, 'pendiente');
        $dueDate = $request->string('due_date') ?: null;
        [$assignedUserId, $assigneeName] = $this->resolveAssignee($request, $projectId);

        $this->tasks->update($taskId, $projectId, $title, $description, $priority, $status, $assignedUserId, $dueDate);

        return [
            'success' => true,
            'message' => 'Tarea actualizada exitosamente',
            'task' => [
                'id' => $taskId,
                'title' => Html::e($title),
                'description' => Html::e($description),
                'priority' => $priority,
                'status' => $status,
                'assigned_to' => Html::e($assigneeName ?: 'Sin asignar'),
                'raw_assigned_to' => $assignedUserId ? (string)$assignedUserId : '',
                'due_date' => $dueDate ? Format::date($dueDate) : 'Sin fecha',
                'raw_due_date' => $dueDate ?: '',
            ],
        ];
    }

    public function updateStatus(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!Permissions::canChangeTaskStatus($this->role())) {
            throw HttpException::forbidden('Los clientes tienen acceso de solo lectura en las tareas');
        }

        $taskId = $request->int('task_id');
        $status = $request->enum('status', TaskRepository::STATUSES, 'pendiente');
        $this->tasks->updateStatus($taskId, $projectId, $status);

        return ['success' => true, 'message' => 'Estado actualizado', 'task_id' => $taskId, 'status' => $status];
    }

    public function delete(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!Permissions::canDeleteTasks($this->role())) {
            throw HttpException::forbidden('Solo los Administradores y Project Managers pueden eliminar tareas');
        }

        $taskId = $request->int('task_id');
        $this->tasks->delete($taskId, $projectId);

        return ['success' => true, 'message' => 'Tarea eliminada', 'task_id' => $taskId];
    }

    public function detail(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        $taskId = $request->int('task_id');

        $task = $this->tasks->find($taskId, $projectId);
        if (!$task) {
            throw HttpException::invalid('Tarea no encontrada');
        }

        $role = $this->role();
        $isClient = $role === Permissions::CLIENTE;

        return [
            'success' => true,
            'task' => [
                'id' => $task['id'],
                'title' => Html::e($task['title']),
                'raw_title' => $task['title'],
                'description' => Html::e($task['description']),
                'raw_description' => $task['description'],
                'priority' => $task['priority'],
                'status' => $task['status'],
                'assigned_to' => Html::e($task['assigned_to'] ?: 'Sin asignar'),
                'raw_assigned_to' => $task['assigned_user_id'] ? (string)$task['assigned_user_id'] : '',
                'due_date' => $task['due_date'] ? Format::date($task['due_date']) : 'Sin fecha',
                'raw_due_date' => $task['due_date'] ?: '',
            ],
            'permissions' => [
                'can_edit' => Permissions::canManageTasks($role),
                'can_delete' => Permissions::canDeleteTasks($role),
                'can_change_status' => Permissions::canChangeTaskStatus($role),
                'is_cliente' => $isClient,
                'user_role' => $role,
            ],
            // Los clientes solo ven la actividad y su descripción (no comentarios, checklists ni adjuntos)
            'comments' => $isClient ? [] : $this->presentComments($this->tasks->comments($taskId, $projectId)),
            'checklists' => $isClient ? [] : $this->presentChecklists($this->tasks->checklistsWithItems($taskId, $projectId)),
            'files' => $isClient ? [] : $this->presentFiles($this->tasks->files($taskId, $projectId)),
            'links' => $isClient ? [] : $this->presentLinks($this->tasks->links($taskId, $projectId)),
        ];
    }

    /**
     * Determina el proyecto (por project_id o, si falta, por el task_id) y comprueba el acceso.
     */
    private function authorizeProject(Request $request): int
    {
        $projectId = $request->int('project_id');
        if ($projectId <= 0 && $request->int('task_id') > 0) {
            $projectId = $this->tasks->projectIdOf($request->int('task_id')) ?? 0;
        }

        if ($projectId <= 0 || !$this->projects->canAccess($projectId, (int)$this->user['id'], $this->role())) {
            throw HttpException::forbidden('Acceso denegado al proyecto');
        }
        return $projectId;
    }

    private function requireTitle(Request $request): string
    {
        $title = $request->string('title');
        if ($title === '') {
            throw HttpException::invalid('El título de la tarea es obligatorio');
        }
        return $title;
    }

    /**
     * @return array{0: ?int, 1: string} [id del responsable, nombre] — el responsable debe ser miembro del proyecto
     */
    private function resolveAssignee(Request $request, int $projectId): array
    {
        $assignedUserId = $request->nullableInt('assigned_user_id');
        if (!$assignedUserId) {
            return [null, ''];
        }

        $name = $this->projects->assigneeName($assignedUserId, $projectId);
        if ($name === null) {
            throw HttpException::invalid('El responsable debe ser miembro del proyecto');
        }
        return [$assignedUserId, $name];
    }

    private function role(): string
    {
        return $this->user['role'];
    }

    private function presentComments(array $comments): array
    {
        $isAdmin = $this->role() === Permissions::ADMIN;
        return array_map(fn(array $c) => [
            'id' => $c['id'],
            'parent_id' => $c['parent_id'] ? (int)$c['parent_id'] : null,
            'user_id' => (int)$c['user_id'],
            'author_name' => Html::e($c['author_name']),
            'initials' => strtoupper(substr($c['author_name'], 0, 2)),
            'content' => nl2br(Html::e($c['content'])),
            'raw_content' => $c['content'],
            'image_url' => $c['image_path'] ? 'uploads/' . $c['image_path'] : null,
            'created_at' => Format::date($c['created_at'], true),
            'can_manage' => ($c['user_id'] == $this->user['id'] || $isAdmin),
        ], $comments);
    }

    private function presentChecklists(array $checklists): array
    {
        return array_map(fn(array $chk) => [
            'id' => $chk['id'],
            'title' => Html::e($chk['title']),
            'items' => array_map(fn(array $item) => [
                'id' => $item['id'],
                'title' => Html::e($item['title']),
                'is_completed' => (int)$item['is_completed'],
            ], $chk['items']),
        ], $checklists);
    }

    private function presentFiles(array $files): array
    {
        return array_map(fn(array $f) => [
            'id' => $f['id'],
            'original_name' => Html::e($f['original_name']),
            'file_size' => Format::bytes((int)$f['file_size']),
            'created_at' => Format::date($f['created_at']),
            'download_url' => "api/files.php?action=download&id={$f['id']}",
        ], $files);
    }

    private function presentLinks(array $links): array
    {
        return array_map(fn(array $l) => [
            'id' => $l['id'],
            'title' => Html::e($l['title']),
            'url' => UrlValidator::isSafe($l['url']) ? Html::e($l['url']) : '#',
            'created_at' => Format::date($l['created_at']),
        ], $links);
    }
}
