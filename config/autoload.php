<?php
// config/autoload.php - Carga de clases del namespace App\ (src/)
// Usa el autoloader de Composer si existe (desarrollo, tests) y si no, un cargador PSR-4 mínimo,
// de modo que en producción no hace falta ejecutar composer install.

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(function (string $class): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}
