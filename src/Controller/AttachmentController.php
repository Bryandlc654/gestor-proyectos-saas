<?php

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use App\Repository\ProjectContentRepository;
use App\Repository\ProjectRepository;
use App\Security\Permissions;
use App\Security\UrlValidator;
use App\Support\Format;
use App\Support\Html;
use App\Upload\AttachmentStorage;

/**
 * Adjuntos de un proyecto: carpetas, enlaces y archivos.
 * - Carpetas y enlaces: solo el equipo de la agencia dueña.
 * - Subir archivos: equipo dueño e invitados con acceso de colaboración.
 * - Borrar archivos de otros: solo el equipo interno dueño; cada uno puede borrar los suyos.
 */
final class AttachmentController extends ProjectScopedController
{
    public function __construct(
        array $user,
        ProjectRepository $projects,
        private readonly ProjectContentRepository $content,
        private readonly AttachmentStorage $storage,
        private readonly string $uploadDir,
    ) {
        parent::__construct($user, $projects);
    }

    public function registerFolders(Router $router): void
    {
        $router->on('create', $this->createFolder(...))->on('delete', $this->deleteFolder(...));
    }

    public function registerLinks(Router $router): void
    {
        $router->on('create', $this->createLink(...))->on('delete', $this->deleteLink(...));
    }

    public function registerFiles(Router $router): void
    {
        $router->on('upload', $this->uploadFile(...))->on('delete', $this->deleteFile(...));
    }

    // --- Carpetas ---

    public function createFolder(Request $request): array
    {
        $projectId = $this->authorizeStructure($request);
        $name = mb_substr($request->string('name'), 0, 100);
        if ($name === '') {
            throw HttpException::invalid('El nombre de la carpeta es obligatorio');
        }
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $request->string('color')) ? $request->string('color') : '#6366F1';

        $folderId = $this->content->createFolder($projectId, $name, $color);
        return [
            'success' => true,
            'message' => 'Carpeta creada exitosamente',
            'folder' => ['id' => $folderId, 'name' => Html::e($name), 'color' => Html::e($color)],
        ];
    }

    public function deleteFolder(Request $request): array
    {
        $projectId = $this->authorizeStructure($request);
        $folderId = $request->int('folder_id');
        if (!$this->content->deleteFolder($folderId, $projectId)) {
            throw HttpException::notFound('Carpeta no encontrada');
        }
        return ['success' => true, 'message' => 'Carpeta eliminada', 'folder_id' => $folderId];
    }

    // --- Enlaces ---

    public function createLink(Request $request): array
    {
        $projectId = $this->authorizeStructure($request);
        $title = mb_substr($request->string('title'), 0, 255);
        $url = $request->string('url');
        if ($title === '' || $url === '') {
            throw HttpException::invalid('Título y URL requeridos');
        }
        if (!UrlValidator::isSafe($url)) {
            throw HttpException::invalid('URL no válida (incluye http:// o https://)');
        }
        [$folderId, $taskId] = $this->folderAndTask($request, $projectId);

        $linkId = $this->content->createLink($projectId, $folderId, $taskId, $title, $url);
        $taskTitle = $taskId !== null ? $this->content->taskTitle($taskId) : null;

        return [
            'success' => true,
            'message' => 'Enlace guardado',
            'link' => [
                'id' => $linkId,
                'folder_id' => $folderId,
                'task_id' => $taskId,
                'task_title' => $taskTitle !== null ? Html::e($taskTitle) : null,
                'title' => Html::e($title),
                'url' => Html::e($url),
                'created_at' => Format::date(date('Y-m-d')),
            ],
        ];
    }

    public function deleteLink(Request $request): array
    {
        $projectId = $this->authorizeStructure($request);
        $linkId = $request->int('link_id');
        if (!$this->content->deleteLink($linkId, $projectId)) {
            throw HttpException::notFound('Enlace no encontrado');
        }
        return ['success' => true, 'message' => 'Enlace eliminado', 'link_id' => $linkId];
    }

    // --- Archivos ---

    public function uploadFile(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        $this->require($this->projects->canUploadAttachments($projectId, $this->userId(), $this->role()), 'Tu acceso a este proyecto es de solo lectura');

        [$folderId, $taskId] = $this->folderAndTask($request, $projectId);
        if ($this->role() === Permissions::CLIENTE) {
            $taskId = null; // los clientes solo suben archivos al proyecto, no a tareas internas
        }

        $upload = $this->storage->store($request->file('attachment') ?? []);
        if (!$upload['ok']) {
            throw HttpException::invalid($upload['error']);
        }

        $fileId = $this->content->createFile($projectId, $this->userId(), $folderId, $taskId, null, $upload);
        $taskTitle = $taskId !== null ? $this->content->taskTitle($taskId) : null;

        return [
            'success' => true,
            'message' => 'Archivo subido con éxito',
            'file' => [
                'id' => $fileId,
                'folder_id' => $folderId,
                'task_id' => $taskId,
                'task_title' => $taskTitle !== null ? Html::e($taskTitle) : null,
                'original_name' => Html::e($upload['original_name']),
                'file_size' => Format::bytes($upload['size']),
                'created_at' => Format::date(date('Y-m-d')),
                'download_url' => self::downloadUrl($fileId),
            ],
        ];
    }

    public function deleteFile(Request $request): array
    {
        $projectId = $this->authorizeProject($request);
        $file = $this->content->findProjectFile($request->int('file_id'), $projectId);
        if ($file === null || !empty($file['invoice_id'])) {
            throw HttpException::notFound('Archivo no encontrado');
        }

        $canManageAll = $this->projects->canManageAttachments($projectId, $this->userId(), $this->role());
        $canUpload = $this->projects->canUploadAttachments($projectId, $this->userId(), $this->role());
        if ((int)$file['user_id'] !== $this->userId() && !$canManageAll) {
            throw HttpException::forbidden('Solo puedes eliminar tus propios adjuntos');
        }
        if (!$canUpload && !$canManageAll) {
            throw HttpException::forbidden('Tu acceso a este proyecto es de solo lectura');
        }

        $this->removeFromDisk((string)$file['stored_name']);
        $this->content->deleteFile((int)$file['id'], $projectId);

        return ['success' => true, 'message' => 'Archivo eliminado', 'file_id' => (int)$file['id']];
    }

    /**
     * Archivo que el usuario puede descargar, o null (se responde 404 sin revelar si existe).
     * Un cliente solo descarga sus propios adjuntos; los documentos de factura solo quien gestiona el proyecto.
     *
     * @return array{path: string, name: string}|null
     */
    public function downloadable(int $fileId): ?array
    {
        $file = $this->content->file($fileId);
        if ($file === null) {
            return null;
        }
        $projectId = (int)$file['project_id'];
        $allowed = !empty($file['invoice_id'])
            ? $this->projects->canManage($projectId, $this->userId(), $this->role())
            : $this->projects->canAccess($projectId, $this->userId(), $this->role());
        if (!$allowed || ($this->role() === Permissions::CLIENTE && (int)$file['user_id'] !== $this->userId())) {
            return null;
        }
        $path = $this->uploadDir . basename((string)$file['stored_name']);
        return is_file($path) ? ['path' => $path, 'name' => basename((string)$file['original_name'])] : null;
    }

    public static function downloadUrl(int $fileId): string
    {
        return '/api/app/files.php?action=download&id=' . $fileId;
    }

    private function authorizeStructure(Request $request): int
    {
        $projectId = $this->authorizeProject($request);
        $this->require($this->projects->canManageAttachments($projectId, $this->userId(), $this->role()), 'Los clientes tienen acceso de solo lectura');
        return $projectId;
    }

    /** @return array{0: ?int, 1: ?int} Carpeta y tarea opcionales, ambas del proyecto */
    private function folderAndTask(Request $request, int $projectId): array
    {
        $folderId = $this->optionalId($request, 'folder_id');
        $taskId = $this->optionalId($request, 'task_id');
        if (($folderId !== null && !$this->projects->folderBelongsTo($folderId, $projectId))
            || ($taskId !== null && !$this->projects->taskBelongsTo($taskId, $projectId))) {
            throw HttpException::badRequest('La carpeta o tarea no pertenece a este proyecto');
        }
        return [$folderId, $taskId];
    }

    private function removeFromDisk(string $storedName): void
    {
        $path = $this->uploadDir . basename($storedName);
        if ($storedName !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
