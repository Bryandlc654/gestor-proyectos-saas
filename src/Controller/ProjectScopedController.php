<?php

namespace App\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Repository\ProjectRepository;

/**
 * Base de los controladores cuyas acciones siempre actúan dentro de un proyecto.
 * Resuelve el project_id de la petición y exige que el usuario pueda verlo; cada acción
 * comprueba después su permiso concreto (comentar, gestionar estructura, finanzas...).
 */
abstract class ProjectScopedController
{
    /**
     * @param array{id: int|string, role: string, name?: string, agency_id?: int} $user Usuario autenticado
     */
    public function __construct(
        protected readonly array $user,
        protected readonly ProjectRepository $projects,
    ) {
    }

    protected function userId(): int
    {
        return (int)$this->user['id'];
    }

    protected function role(): string
    {
        return (string)$this->user['role'];
    }

    protected function userName(): string
    {
        return (string)($this->user['name'] ?? 'Usuario');
    }

    /** Proyecto de la petición al que el usuario tiene acceso, o 403. */
    protected function authorizeProject(Request $request): int
    {
        $projectId = $request->int('project_id');
        if ($projectId <= 0 || !$this->projects->canAccess($projectId, $this->userId(), $this->role())) {
            throw HttpException::forbidden('Acceso denegado al proyecto');
        }
        return $projectId;
    }

    /** Lanza 403 con el mensaje indicado si no se cumple el permiso. */
    protected function require(bool $allowed, string $message): void
    {
        if (!$allowed) {
            throw HttpException::forbidden($message);
        }
    }

    /** Id opcional (carpeta, tarea...) que debe pertenecer al proyecto; null si no se envió. */
    protected function optionalId(Request $request, string $key): ?int
    {
        $value = $request->nullableInt($key);
        return $value !== null && $value > 0 ? $value : null;
    }
}
