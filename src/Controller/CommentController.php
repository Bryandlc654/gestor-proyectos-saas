<?php

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ProjectContentRepository;
use App\Repository\ProjectRepository;
use App\Service\NotificationService;
use App\Support\Format;
use App\Support\Html;
use App\Upload\AttachmentStorage;

/** Notas del proyecto y comentarios de tareas, con imagen opcional y menciones (@correo). */
final class CommentController extends ProjectScopedController
{
    private const IMAGE_MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        array $user,
        ProjectRepository $projects,
        private readonly ProjectContentRepository $content,
        private readonly string $uploadDir,
        private readonly ?NotificationService $notifications = null,
    ) {
        parent::__construct($user, $projects);
    }

    public function register(Router $router): void
    {
        $router
            ->on('create', $this->create(...))
            ->on('update', $this->update(...))
            ->on('delete', $this->delete(...));
    }

    public function create(Request $request): array
    {
        $projectId = $this->authorizeCommenting($request);
        $taskId = $this->taskOf($request, $projectId);
        $content = $request->string('content');
        $parentId = $this->optionalId($request, 'parent_id');

        $image = $request->file('image');
        $imageError = $image !== null ? (int)($image['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        // Si se intentó adjuntar una imagen y falló, avisar en vez de publicar la nota sin ella
        if ($imageError !== UPLOAD_ERR_OK && $imageError !== UPLOAD_ERR_NO_FILE) {
            throw HttpException::invalid(self::uploadErrorMessage($imageError));
        }
        $hasImage = $imageError === UPLOAD_ERR_OK;

        if ($content === '' && !$hasImage) {
            throw HttpException::invalid('Escribe una nota o adjunta una imagen');
        }

        if ($parentId !== null) {
            $parent = $this->content->findComment($parentId, $projectId);
            if ($parent === null) {
                throw HttpException::badRequest('El comentario al que intentas responder no existe');
            }
            // Una respuesta sin tarea explícita hereda la del comentario padre
            $taskId ??= $parent['task_id'];
        }

        $imagePath = $hasImage ? $this->storeImage($image) : null;
        $commentId = $this->content->createComment($projectId, $taskId, $parentId, $this->userId(), $content, $imagePath);
        $this->notifyMentions($content, $projectId, $taskId);

        $taskTitle = $taskId !== null ? $this->content->taskTitle($taskId) : null;

        return [
            'success' => true,
            'message' => $parentId ? 'Respuesta publicada con éxito' : 'Nota publicada con éxito',
            'comment' => [
                'id' => $commentId,
                'parent_id' => $parentId,
                'user_id' => $this->userId(),
                'can_manage' => true,
                'task_id' => $taskId,
                'task_title' => $taskTitle !== null ? Html::e($taskTitle) : null,
                'author_name' => Html::e($this->userName()),
                'initials' => strtoupper(substr($this->userName(), 0, 2)),
                'content' => nl2br(Html::e($content)),
                'raw_content' => $content,
                'image_url' => $imagePath ? 'uploads/' . $imagePath : null,
                'created_at' => Format::date(date('Y-m-d H:i:s'), true),
            ],
        ];
    }

    public function update(Request $request): array
    {
        $projectId = $this->authorizeCommenting($request);
        $comment = $this->ownComment($request, $projectId, 'No tienes permiso para editar este comentario');
        $content = $request->string('content');
        if ($content === '') {
            throw HttpException::invalid('El comentario no puede estar vacío');
        }

        $this->content->updateComment($comment['id'], $projectId, $content);
        $this->notifyMentions($content, $projectId, $comment['task_id']);

        return [
            'success' => true,
            'message' => 'Comentario actualizado',
            'comment' => ['id' => $comment['id'], 'content' => nl2br(Html::e($content)), 'raw_content' => $content],
        ];
    }

    public function delete(Request $request): array
    {
        $projectId = $this->authorizeCommenting($request);
        $comment = $this->ownComment($request, $projectId, 'No tienes permiso para eliminar este comentario');

        if ($comment['image_path'] !== null) {
            $path = $this->uploadDir . basename($comment['image_path']);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->content->deleteComment($comment['id'], $projectId);

        return ['success' => true, 'message' => 'Comentario eliminado', 'comment_id' => $comment['id'], 'task_id' => $comment['task_id']];
    }

    /** Ver = solo lectura: comentar exige acceso de escritura al proyecto. */
    private function authorizeCommenting(Request $request): int
    {
        $projectId = $this->authorizeProject($request);
        $this->require($this->projects->canComment($projectId, $this->userId(), $this->role()), 'Tu acceso a este proyecto es de solo lectura');
        return $projectId;
    }

    private function taskOf(Request $request, int $projectId): ?int
    {
        $taskId = $this->optionalId($request, 'task_id');
        if ($taskId !== null && !$this->projects->taskBelongsTo($taskId, $projectId)) {
            throw HttpException::badRequest('La tarea no pertenece a este proyecto');
        }
        return $taskId;
    }

    /** Comentario del proyecto que el usuario puede editar/borrar (propio, o moderador). */
    private function ownComment(Request $request, int $projectId, string $forbidden): array
    {
        $comment = $this->content->findComment($request->int('comment_id'), $projectId);
        if ($comment === null) {
            throw HttpException::notFound('Comentario no encontrado');
        }
        $canModerate = $this->projects->canModerate($projectId, $this->userId(), $this->role());
        if ($comment['user_id'] !== $this->userId() && !$canModerate) {
            throw HttpException::forbidden($forbidden);
        }
        return $comment;
    }

    private function storeImage(array $file): string
    {
        if ((int)$file['size'] > self::IMAGE_MAX_BYTES) {
            throw HttpException::invalid('La imagen supera el límite de 10 MB');
        }
        // La extensión se decide por el contenido real del archivo, no por el nombre enviado
        $mime = is_uploaded_file($file['tmp_name']) ? AttachmentStorage::detectMime($file['tmp_name']) : '';
        $extension = AttachmentStorage::IMAGE_MIMES[$mime] ?? null;
        if ($extension === null) {
            throw HttpException::invalid('Formato de imagen no soportado (usa PNG, JPG, WEBP o GIF)');
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        $storedName = 'comment_' . bin2hex(random_bytes(12)) . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], $this->uploadDir . $storedName)) {
            throw new HttpException(500, 'No se pudo guardar la imagen');
        }
        return $storedName;
    }

    private function notifyMentions(string $content, int $projectId, ?int $taskId): void
    {
        if ($this->notifications === null
            || !preg_match_all('/@([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $content, $matches)) {
            return;
        }

        $emails = array_map('strtolower', array_unique($matches[1]));
        $projectName = $this->projects->name($projectId) ?? 'un proyecto';
        $taskTitle = $taskId !== null ? $this->content->taskTitle($taskId) : null;

        foreach ($this->projects->mentionableUsers($projectId) as $mentioned) {
            if ($mentioned['id'] === $this->userId() || !in_array(strtolower($mentioned['email']), $emails, true)) {
                continue;
            }
            $this->notifications->notifyMention($mentioned['id'], $mentioned['agency_id'], $this->userName(), $projectName, $projectId, $taskTitle);
        }
    }

    public static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño máximo permitido por el servidor.',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió solo en parte. Inténtalo de nuevo.',
            UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo.',
            default => 'No se pudo recibir el archivo en el servidor.',
        };
    }
}
