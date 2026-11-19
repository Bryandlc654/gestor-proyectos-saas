<?php

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Security\Permissions;
use App\Service\NotificationService;
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
        private readonly ?ActivityRepository $activity = null,
        private readonly ?NotificationService $notifications = null,
    ) {
    }

    public function register(Router $router): void
    {
        $router
            ->on('create', $this->create(...))
            ->on('create_subtask', $this->createSubtask(...))
            ->on('update', $this->update(...))
            ->on('update_status', $this->updateStatus(...))
            ->on('reorder', $this->reorder(...))
            ->on('delete', $this->delete(...))
            ->on('reschedule', $this->reschedule(...))
            ->on('add_dependency', $this->addDependency(...))
            ->on('remove_dependency', $this->removeDependency(...))
            ->on('get_detail', $this->detail(...));
    }

    public function create(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!$this->projects->canEditTasks($projectId, (int)$this->user['id'], $this->role())) {
            throw HttpException::forbidden('Tu nivel de acceso no permite crear tareas');
        }

        $title = $this->requireTitle($request);
        $description = $request->string('description');
        $priority = $request->enum('priority', TaskRepository::PRIORITIES, 'media');
        $dueDate = $this->normalizeDate($request->string('due_date'));
        $startDate = $this->normalizeDate($request->string('start_date'));
        $isMilestone = in_array($request->string('is_milestone'), ['1', 'on', 'true'], true);
        [$startDate, $dueDate, $isMilestone] = $this->normalizeSchedule($startDate, $dueDate, $isMilestone);
        [$assignedUserId, $assigneeName] = $this->resolveAssignee($request, $projectId);

        $taskId = $this->tasks->create($projectId, $title, $description, $priority, $assignedUserId, $dueDate, null, $startDate, $isMilestone);
        $this->activity?->logByUser((int)$this->user['id'], $projectId, 'task.created', 'Creó la tarea "' . $title . '"');

        if ($assignedUserId !== null && $assignedUserId !== (int)$this->user['id']) {
            $this->notifications?->notifyTaskAssignment(
                $assignedUserId,
                $title,
                $projectId,
                $this->projects->name($projectId) ?? 'un proyecto'
            );
        }

        return [
            'success' => true,
            'message' => 'Tarea creada exitosamente',
            'task' => [
                'id' => $taskId,
                'title' => Html::e($title),
                'description' => Html::e($description),
                'priority' => $priority,
                'status' => $this->defaultStatus(),
                'assigned_to' => Html::e($assigneeName),
                'due_date' => $dueDate ? Format::date($dueDate) : 'Sin fecha',
                'comments_count' => 0,
            ],
        ];
    }

    public function createSubtask(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!$this->projects->canEditTasks($projectId, (int)$this->user['id'], $this->role())) {
            throw HttpException::forbidden('Tu nivel de acceso no permite crear subtareas');
        }

        $parentId = $request->int('parent_task_id');
        $parent = $this->tasks->find($parentId, $projectId);
        if ($parent === null) {
            throw HttpException::invalid('Tarea padre no encontrada');
        }
        if (!empty($parent['parent_task_id'])) {
            throw HttpException::invalid('No se permiten subtareas dentro de otra subtarea.');
        }

        $title = $this->requireTitle($request);
        $description = $request->string('description');
        $priority = $request->enum('priority', TaskRepository::PRIORITIES, 'media');
        $dueDate = $this->normalizeDate($request->string('due_date'));
        [$assignedUserId, $assigneeName] = $this->resolveAssignee($request, $projectId);

        $subtaskId = $this->tasks->create($projectId, $title, $description, $priority, $assignedUserId, $dueDate, $parentId, $dueDate, false);
        $this->activity?->logByUser(
            (int)$this->user['id'],
            $projectId,
            'task.subtask_created',
            'Creó la subtarea "' . $title . '" en "' . $parent['title'] . '"'
        );

        if ($assignedUserId !== null && $assignedUserId !== (int)$this->user['id']) {
            $this->notifications?->notifyTaskAssignment(
                $assignedUserId,
                $title,
                $projectId,
                $this->projects->name($projectId) ?? 'un proyecto'
            );
        }

        return [
            'success' => true,
            'message' => 'Subtarea creada',
            'subtask' => [
                'id' => $subtaskId,
                'title' => Html::e($title),
                'status' => $this->defaultStatus(),
                'assigned_to' => Html::e($assigneeName),
                'due_date' => $dueDate ? Format::date($dueDate) : '',
                'priority' => $priority,
            ],
        ];
    }

    public function update(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!$this->projects->canEditTasks($projectId, (int)$this->user['id'], $this->role())) {
            throw HttpException::forbidden('No tienes permisos para editar esta tarea');
        }

        $taskId = $request->int('task_id');
        $title = $this->requireTitle($request);
        $description = $request->string('description');
        $priority = $request->enum('priority', TaskRepository::PRIORITIES, 'media');
        $before = $this->tasks->find($taskId, $projectId);
        $status = $this->normalizeStatus(
            $request->string('status'),
            (string)($before['status'] ?? $this->defaultStatus())
        );
        $this->assertCanComplete($taskId, $projectId, $status, (string)($before['status'] ?? ''));
        $dueDate = $this->normalizeDate($request->string('due_date'));
        $startDate = $this->normalizeDate($request->string('start_date'));
        $isMilestone = in_array($request->string('is_milestone'), ['1', 'on', 'true'], true);
        [$startDate, $dueDate, $isMilestone] = $this->normalizeSchedule($startDate, $dueDate, $isMilestone);
        [$assignedUserId, $assigneeName] = $this->resolveAssignee($request, $projectId);

        $this->tasks->update($taskId, $projectId, $title, $description, $priority, $status, $assignedUserId, $dueDate, $startDate, $isMilestone);
        if (($before['status'] ?? null) !== $status) {
            $this->tasks->updateStatus($taskId, $projectId, $status);
        }
        $this->activity?->logByUser((int)$this->user['id'], $projectId, 'task.updated', 'Editó la tarea "' . $title . '"');

        if ($assignedUserId !== null
            && $assignedUserId !== (int)$this->user['id']
            && (int)($before['assigned_user_id'] ?? 0) !== $assignedUserId) {
            $this->notifications?->notifyTaskAssignment(
                $assignedUserId,
                $title,
                $projectId,
                $this->projects->name($projectId) ?? 'un proyecto'
            );
        }

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
        if (!$this->projects->canChangeTaskStatus($projectId, (int)$this->user['id'], $this->role())) {
            throw HttpException::forbidden('Tu acceso a este proyecto es de solo lectura');
        }

        $taskId = $request->int('task_id');
        $before = $this->tasks->find($taskId, $projectId);
        $status = $this->normalizeStatus($request->string('status'));
        $this->assertCanComplete($taskId, $projectId, $status, (string)($before['status'] ?? ''));
        $this->tasks->updateStatus($taskId, $projectId, $status);
        $this->activity?->logByUser(
            (int)$this->user['id'],
            $projectId,
            'task.status_changed',
            'Movió la tarea "' . ($before['title'] ?? ('#' . $taskId)) . '" a ' . $this->statusLabel($status),
            ['from' => $before['status'] ?? null, 'to' => $status]
        );

        return ['success' => true, 'message' => 'Estado actualizado', 'task_id' => $taskId, 'status' => $status];
    }

    public function reorder(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!$this->projects->canChangeTaskStatus($projectId, (int)$this->user['id'], $this->role())) {
            throw HttpException::forbidden('Tu acceso a este proyecto es de solo lectura');
        }

        $taskId = $request->int('task_id');
        $status = $this->normalizeStatus($request->string('status'));
        $ordered = $request->input('ordered_ids', []);
        if (is_string($ordered)) {
            $decoded = json_decode($ordered, true);
            $ordered = is_array($decoded) ? $decoded : array_filter(explode(',', $ordered));
        }
        if (!is_array($ordered)) {
            $ordered = [];
        }

        $before = $this->tasks->find($taskId, $projectId);
        if ($before === null) {
            throw HttpException::invalid('Tarea no encontrada');
        }
        $this->assertCanComplete($taskId, $projectId, $status, (string)($before['status'] ?? ''));

        $this->tasks->reorder($projectId, $taskId, $status, $ordered);
        $this->activity?->logByUser(
            (int)$this->user['id'],
            $projectId,
            'task.reordered',
            'Reordenó la tarea "' . $before['title'] . '" en ' . $this->statusLabel($status)
        );

        return ['success' => true, 'message' => 'Orden actualizado', 'task_id' => $taskId, 'status' => $status];
    }

    public function reschedule(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!$this->projects->canEditTasks($projectId, (int)$this->user['id'], $this->role())) {
            throw HttpException::forbidden('No tienes permisos para reprogramar tareas');
        }

        $taskId = $request->int('task_id');
        $task = $this->tasks->find($taskId, $projectId);
        if ($task === null) {
            throw HttpException::invalid('Tarea no encontrada');
        }

        $startDate = $this->normalizeDate($request->string('start_date'));
        $dueDate = $this->normalizeDate($request->string('due_date'));
        if ($startDate === null || $dueDate === null) {
            throw HttpException::invalid('Indica fecha de inicio y de vencimiento.');
        }
        [$startDate, $dueDate] = $this->normalizeSchedule($startDate, $dueDate, (int)($task['is_milestone'] ?? 0) === 1);
        if ($startDate > $dueDate) {
            throw HttpException::invalid('La fecha de inicio no puede ser posterior a la de vencimiento.');
        }

        $this->tasks->reschedule($taskId, $projectId, $startDate, $dueDate);
        $this->activity?->logByUser(
            (int)$this->user['id'],
            $projectId,
            'task.rescheduled',
            'Reprogramó la tarea "' . $task['title'] . '" del ' . Format::date($startDate) . ' al ' . Format::date($dueDate)
        );

        return [
            'success' => true,
            'message' => 'Fechas actualizadas',
            'task_id' => $taskId,
            'start_date' => $startDate,
            'due_date' => $dueDate,
        ];
    }

    public function addDependency(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!$this->projects->canEditTasks($projectId, (int)$this->user['id'], $this->role())) {
            throw HttpException::forbidden('Tu nivel de acceso no permite editar dependencias');
        }

        $taskId = $request->int('task_id');
        $dependsOnId = $request->int('depends_on_task_id');
        $task = $this->tasks->find($taskId, $projectId);
        $dependency = $this->tasks->find($dependsOnId, $projectId);
        if ($task === null || $dependency === null) {
            throw HttpException::invalid('Tarea no encontrada en este proyecto');
        }

        try {
            $this->tasks->addDependency($taskId, $dependsOnId, $projectId);
        } catch (\DomainException $e) {
            throw HttpException::invalid($e->getMessage());
        }

        $this->activity?->logByUser(
            (int)$this->user['id'],
            $projectId,
            'task.dependency_added',
            'Añadió la dependencia "' . $task['title'] . '" → "' . $dependency['title'] . '"'
        );

        return ['success' => true, 'message' => 'Dependencia añadida'];
    }

    public function removeDependency(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!$this->projects->canEditTasks($projectId, (int)$this->user['id'], $this->role())) {
            throw HttpException::forbidden('Tu nivel de acceso no permite editar dependencias');
        }

        $taskId = $request->int('task_id');
        $dependsOnId = $request->int('depends_on_task_id');
        $this->tasks->removeDependency($taskId, $dependsOnId, $projectId);

        $this->activity?->logByUser(
            (int)$this->user['id'],
            $projectId,
            'task.dependency_removed',
            'Eliminó una dependencia de la tarea #' . $taskId
        );

        return ['success' => true, 'message' => 'Dependencia eliminada'];
    }

    public function delete(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        if (!$this->projects->canDeleteTasks($projectId, (int)$this->user['id'], $this->role())) {
            throw HttpException::forbidden('Solo los Administradores y Project Managers de la agencia dueña pueden eliminar tareas');
        }

        $taskId = $request->int('task_id');
        $before = $this->tasks->find($taskId, $projectId);
        $this->tasks->delete($taskId, $projectId);
        $this->activity?->logByUser(
            (int)$this->user['id'],
            $projectId,
            'task.deleted',
            'Eliminó la tarea "' . ($before['title'] ?? ('#' . $taskId)) . '"'
        );

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
        $userId = (int)$this->user['id'];
        $context = $this->projects->accessContext($projectId, $userId, $role);
        $isGuest = (bool)($context['is_guest'] ?? false);
        $isClient = $role === Permissions::CLIENTE && !$isGuest;

        $parent = !empty($task['parent_task_id']) ? $this->tasks->parentOf($taskId, $projectId) : null;
        $subtasks = $this->tasks->subtasks($taskId, $projectId);
        $blockedBy = $this->tasks->dependenciesOf($taskId, $projectId);
        $blocks = $this->tasks->dependentsOf($taskId, $projectId);
        $dependencyOptions = $this->tasks->dependencyOptions($taskId, $projectId);
        $isBlocked = false;
        foreach ($blockedBy as $dependency) {
            if (!$this->isDoneStatus((string)$dependency['status'])) {
                $isBlocked = true;
                break;
            }
        }

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
                'start_date' => $task['start_date'] ? Format::date($task['start_date']) : 'Sin fecha',
                'raw_start_date' => $task['start_date'] ?: '',
                'is_milestone' => (int)($task['is_milestone'] ?? 0) === 1,
            ],
            'parent' => $parent !== null ? [
                'id' => (int)$parent['id'],
                'title' => Html::e($parent['title']),
            ] : null,
            'subtasks' => array_map($this->presentTaskMini(...), $subtasks),
            'blocked_by' => array_map($this->presentTaskMini(...), $blockedBy),
            'blocks' => array_map($this->presentTaskMini(...), $blocks),
            'dependency_options' => array_map(static fn(array $row): array => [
                'id' => (int)$row['id'],
                'title' => Html::e($row['title']),
                'status' => $row['status'],
            ], $dependencyOptions),
            'is_blocked' => $isBlocked,
            'permissions' => [
                'can_edit' => $this->projects->canEditTasks($projectId, $userId, $role),
                'can_delete' => $this->projects->canDeleteTasks($projectId, $userId, $role),
                'can_change_status' => $this->projects->canChangeTaskStatus($projectId, $userId, $role),
                'can_manage_checklists' => $this->projects->canManageChecklists($projectId, $userId, $role),
                'can_manage_files' => $this->projects->canManageTaskAttachments($projectId, $userId, $role),
                'can_manage_links' => $this->projects->canManageAttachments($projectId, $userId, $role),
                'can_manage_subtasks' => $this->projects->canEditTasks($projectId, $userId, $role),
                'can_manage_dependencies' => $this->projects->canEditTasks($projectId, $userId, $role),
                'is_cliente' => $isClient,
                'user_role' => $role,
            ],
            // Los clientes de la agencia dueña solo ven la actividad y su descripción.
            // Los invitados ven tareas, comentarios y checklists; los enlaces internos siguen siendo de la dueña.
            'comments' => $isClient ? [] : $this->presentComments($this->tasks->comments($taskId, $projectId), $projectId),
            'checklists' => $isClient ? [] : $this->presentChecklists($this->tasks->checklistsWithItems($taskId, $projectId)),
            'files' => $isClient ? [] : $this->presentFiles($this->tasks->files($taskId, $projectId)),
            'links' => ($isClient || $isGuest) ? [] : $this->presentLinks($this->tasks->links($taskId, $projectId)),
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

    private function normalizeDate(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || !Format::isIsoDate($value)) {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * Normaliza start/due/hito: un hito tiene la misma fecha de inicio y fin;
     * si solo llega una fecha, se usa también para la otra.
     *
     * @return array{0: ?string, 1: ?string, 2: bool}
     */
    private function normalizeSchedule(?string $startDate, ?string $dueDate, bool $isMilestone): array
    {
        if ($isMilestone) {
            if ($dueDate === null && $startDate !== null) {
                $dueDate = $startDate;
            }
            if ($dueDate === null) {
                throw HttpException::invalid('Un hito necesita una fecha.');
            }

            return [$dueDate, $dueDate, true];
        }

        if ($startDate !== null && $dueDate === null) {
            $dueDate = $startDate;
        }
        if ($dueDate !== null && $startDate === null) {
            $startDate = $dueDate;
        }
        if ($startDate !== null && $dueDate !== null && $startDate > $dueDate) {
            throw HttpException::invalid('La fecha de inicio no puede ser posterior a la de vencimiento.');
        }

        return [$startDate, $dueDate, false];
    }

    private function agencyId(): int
    {
        return (int)($this->user['agency_id'] ?? 0);
    }

    /** @return array<int, array{key:string,label:string,kind:string,order:int}> */
    private function taskStatuses(): array
    {
        return $this->tasks->statuses($this->agencyId());
    }

    private function defaultStatus(): string
    {
        foreach ($this->taskStatuses() as $status) {
            if ($status['kind'] === 'open') {
                return $status['key'];
            }
        }
        return $this->taskStatuses()[0]['key'] ?? 'pendiente';
    }

    private function normalizeStatus(string $status, ?string $fallback = null): string
    {
        foreach ($this->taskStatuses() as $option) {
            if ($option['key'] === $status) {
                return $status;
            }
        }
        return $fallback ?? $this->defaultStatus();
    }

    private function statusLabel(string $status): string
    {
        foreach ($this->taskStatuses() as $option) {
            if ($option['key'] === $status) {
                return $option['label'];
            }
        }
        return ucfirst(str_replace('_', ' ', $status));
    }

    private function isDoneStatus(string $status): bool
    {
        foreach ($this->taskStatuses() as $option) {
            if ($option['key'] === $status) {
                return $option['kind'] === 'done';
            }
        }

        return $status === 'completada';
    }

    /** Impide completar una tarea si alguna dependencia sigue pendiente. */
    private function assertCanComplete(int $taskId, int $projectId, string $status, ?string $previousStatus = null): void
    {
        if (!$this->isDoneStatus($status)) {
            return;
        }
        if ($previousStatus !== null && $this->isDoneStatus($previousStatus)) {
            return;
        }

        $blocking = $this->tasks->unfinishedDependencies($taskId, $projectId);
        if ($blocking === []) {
            return;
        }

        $titles = array_map(static fn(array $row): string => Html::e((string)$row['title']), array_slice($blocking, 0, 3));
        throw HttpException::invalid(
            'No puedes completar esta tarea: depende de "' . implode('", "', $titles) . '".'
        );
    }

    /** @param array<string, mixed> $row */
    private function presentTaskMini(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'title' => Html::e($row['title']),
            'status' => $row['status'],
            'status_label' => Html::e($this->statusLabel((string)$row['status'])),
            'done' => $this->isDoneStatus((string)$row['status']),
            'assigned_to' => Html::e((string)($row['assigned_to'] ?? '')),
            'due_date' => !empty($row['due_date']) ? Format::date($row['due_date']) : '',
            'priority' => $row['priority'] ?? 'media',
        ];
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

    private function presentComments(array $comments, int $projectId): array
    {
        $canModerate = $this->projects->canModerate($projectId, (int)$this->user['id'], $this->role());
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
            'can_manage' => ($c['user_id'] == $this->user['id'] || $canModerate),
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
