<?php
// config/env.php - Autoload de clases y variables de entorno (.env)
// Las funciones globales se mantienen por compatibilidad; la lógica está en App\Support\Env.

require_once __DIR__ . '/autoload.php';

use App\Support\Env;

function loadEnv(string $path): void {
    Env::load($path);
}

function env(string $key, ?string $default = null): ?string {
    return Env::get($key, $default);
}

function isProduction(): bool {
    return Env::isProduction();
}

Env::load(dirname(__DIR__) . '/.env');
