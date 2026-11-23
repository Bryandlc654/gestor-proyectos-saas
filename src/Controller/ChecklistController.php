<?php

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ChecklistRepository;
use App\Repository\ProjectContentRepository;
use App\Repository\ProjectRepository;
use App\Support\Html;

/** Checklists del proyecto o de una tarea. Los clientes y las agencias invitadas en modo ver solo leen. */
final class ChecklistController extends ProjectScopedController
{
    public function __construct(
        array $user,
        ProjectRepository $projects,
        private readonly ChecklistRepository $checklists,
        private readonly ProjectContentRepository $content,
    ) {
        parent::__construct($user, $projects);
    }

    public function register(Router $router): void
    {
        $router
            ->on('create_checklist', $this->createChecklist(...))
            ->on('delete_checklist', $this->deleteChecklist(...))
            ->on('add_item', $this->addItem(...))
            ->on('toggle_item', $this->toggleItem(...))
            ->on('delete_item', $this->deleteItem(...));
    }

    public function createChecklist(Request $request): array
    {
        $projectId = $this->authorize($request);
        $title = mb_substr($request->string('title'), 0, 255);
        if ($title === '') {
            throw HttpException::invalid('El título de la lista es obligatorio');
        }
        $taskId = $this->optionalId($request, 'task_id');
        if ($taskId !== null && !$this->projects->taskBelongsTo($taskId, $projectId)) {
            throw HttpException::badRequest('La tarea no pertenece a este proyecto');
        }

        $checklistId = $this->checklists->create($projectId, $taskId, $title);
        $taskTitle = $taskId !== null ? $this->content->taskTitle($taskId) : null;

        return [
            'success' => true,
            'message' => 'Lista de verificación creada',
            'checklist' => [
                'id' => $checklistId,
                'task_id' => $taskId,
                'task_title' => $taskTitle !== null ? Html::e($taskTitle) : null,
                'title' => Html::e($title),
            ],
        ];
    }

    public function deleteChecklist(Request $request): array
    {
        $projectId = $this->authorize($request);
        $checklistId = $request->int('checklist_id');
        if (!$this->checklists->delete($checklistId, $projectId)) {
            throw HttpException::notFound('Lista no encontrada');
        }
        return ['success' => true, 'message' => 'Lista eliminada', 'checklist_id' => $checklistId];
    }

    public function addItem(Request $request): array
    {
        $projectId = $this->authorize($request);
        $checklistId = $request->int('checklist_id');
        $title = mb_substr($request->string('title'), 0, 255);
        if ($checklistId <= 0 || $title === '') {
            throw HttpException::invalid('El nombre del paso es obligatorio');
        }
        if (!$this->checklists->belongsTo($checklistId, $projectId)) {
            throw HttpException::notFound('Lista no encontrada');
        }

        $itemId = $this->checklists->addItem($checklistId, $title);
        return [
            'success' => true,
            'message' => 'Paso agregado',
            'item' => ['id' => $itemId, 'checklist_id' => $checklistId, 'title' => Html::e($title), 'is_completed' => 0],
        ];
    }

    public function toggleItem(Request $request): array
    {
        $projectId = $this->authorize($request);
        $itemId = $request->int('item_id');
        $completed = !in_array($request->string('is_completed'), ['', '0', 'false'], true);
        if (!$this->checklists->setItemCompleted($itemId, $projectId, $completed)) {
            throw HttpException::notFound('Paso no encontrado');
        }
        return ['success' => true, 'item_id' => $itemId, 'is_completed' => $completed ? 1 : 0];
    }

    public function deleteItem(Request $request): array
    {
        $projectId = $this->authorize($request);
        $itemId = $request->int('item_id');
        if (!$this->checklists->deleteItem($itemId, $projectId)) {
            throw HttpException::notFound('Paso no encontrado');
        }
        return ['success' => true, 'message' => 'Paso eliminado', 'item_id' => $itemId];
    }

    private function authorize(Request $request): int
    {
        $projectId = $this->authorizeProject($request);
        $this->require(
            $this->projects->canManageChecklists($projectId, $this->userId(), $this->role()),
            'Los clientes tienen acceso de solo lectura en las listas de verificación'
        );
        return $projectId;
    }
}
