<?php
// includes/header.php
if (!defined('PAGE_TITLE')) {
    define('PAGE_TITLE', 'Gestor de Proyectos');
}
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(PAGE_TITLE) ?> - FlowTasks</title>
    
    <!-- Fuentes Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Estilos compilados con Tailwind (npm run build:css) -->
    <link rel="stylesheet" href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?: 1 ?>">

    <!-- Lucide Icons y Chart.js (versiones fijas con verificación de integridad) -->
    <script src="https://unpkg.com/lucide@1.59.0/dist/umd/lucide.min.js" integrity="sha384-4VQAGBhahgTiMVvXUqbh6qcs45zGzR/8znv7XORRfWkxZfzRbhLL5Uh+fSdvdqdM" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-jb8JQMbMoBUzgWatfe6COACi2ljcDdZQ2OxczGA3bGNeWe+6DChMTBJemed7ZnvJ" crossorigin="anonymous"></script>
</head>
<body class="h-full flex flex-col font-sans text-slate-800 antialiased selection:bg-indigo-500 selection:text-white">
    <?php require_once __DIR__ . '/alert.php'; ?>
    <div class="flex h-screen overflow-hidden">
