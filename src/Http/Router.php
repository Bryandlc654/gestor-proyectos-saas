<?php

namespace App\Http;

/**
 * Router de la API basado en el parámetro "action" (formato que ya usa el frontend).
 * Cada handler recibe la Request y devuelve el array que se envía como JSON.
 */
final class Router
{
    /** @var array<string, callable(Request): array> */
    private array $routes = [];

    public function on(string $action, callable $handler): self
    {
        $this->routes[$action] = $handler;
        return $this;
    }

    public function has(string $action): bool
    {
        return isset($this->routes[$action]);
    }

    /**
     * Ejecuta el handler de la acción y devuelve [status, payload]. No envía nada.
     *
     * @return array{0: int, 1: array}
     */
    public function handle(Request $request): array
    {
        $action = $request->string('action');

        if (!$this->has($action)) {
            return [400, self::error('Acción no válida')];
        }

        try {
            return [200, ($this->routes[$action])($request)];
        } catch (HttpException $e) {
            return [$e->status(), self::error($e->getMessage())];
        } catch (\DomainException $e) {
            // Reglas de negocio y validación (incluye ValidationException): mensaje para el usuario
            return [422, self::error($e->getMessage())];
        }
    }

    /**
     * Formato único de error de toda la API: código HTTP + {success: false, error, message}.
     * `error` es la clave que lee el front; `message` se mantiene por compatibilidad.
     *
     * @return array{success: false, error: string, message: string}
     */
    public static function error(string $message): array
    {
        return ['success' => false, 'error' => $message, 'message' => $message];
    }

    public function dispatch(Request $request): never
    {
        [$status, $payload] = $this->handle($request);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
