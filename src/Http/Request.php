<?php

namespace App\Http;

/**
 * Datos de la petición HTTP (inmutables una vez creados).
 */
final class Request
{
    public function __construct(
        private readonly string $method,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $_GET, $_POST, $_FILES);
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    // Valor de POST o, si no existe, de GET
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_string($value) ? trim($value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        return is_numeric($value) ? (int)$value : $default;
    }

    // Entero opcional: null si no se envió o viene vacío
    public function nullableInt(string $key): ?int
    {
        $value = $this->input($key);
        return ($value === null || $value === '') ? null : (int)$value;
    }

    // Valor permitido de una lista cerrada, o el valor por defecto
    public function enum(string $key, array $allowed, string $default): string
    {
        $value = $this->input($key);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }
}
