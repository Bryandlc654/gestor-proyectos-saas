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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['"Plus Jakarta Sans"', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#EEF2FF',
                            100: '#E0E7FF',
                            500: '#6366F1',
                            600: '#4F46E5',
                            700: '#4338CA',
                        }
                    }
                }
            }
        }
    </script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- Chart.js para Estadísticas y Gráficos Visuales -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* Scrollbar estilizada */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        .tab-btn.active {
            color: #4F46E5;
            border-bottom: 2px solid #4F46E5;
            font-weight: 600;
        }

        /* Animación Skeleton Loader Ultraligera */
        @keyframes skeleton-shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        .skeleton {
            background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
            background-size: 200% 100%;
            animation: skeleton-shimmer 1.5s infinite;
        }

        /* Estado Colapsado del Sidebar (Icon-Only Rail) */
        #main-sidebar.sidebar-collapsed {
            width: 4.5rem !important; /* 72px */
            min-width: 4.5rem !important;
        }
        #main-sidebar.sidebar-collapsed .sidebar-text {
            display: none !important;
        }
        #main-sidebar.sidebar-collapsed .sidebar-header {
            justify-content: center !important;
            padding-left: 0.5rem !important;
            padding-right: 0.5rem !important;
        }
        #main-sidebar.sidebar-collapsed .sidebar-header .flex-shrink-0 {
            margin: 0 auto;
        }
        #main-sidebar.sidebar-collapsed .overflow-y-auto {
            scrollbar-width: none !important;
        }
        #main-sidebar.sidebar-collapsed .overflow-y-auto::-webkit-scrollbar {
            display: none !important;
        }
        #main-sidebar.sidebar-collapsed .sidebar-nav-item {
            justify-content: center !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            width: 2.75rem !important;
            height: 2.75rem !important;
            margin-left: auto !important;
            margin-right: auto !important;
        }
        #main-sidebar.sidebar-collapsed .sidebar-section-title {
            display: none !important;
        }
        #main-sidebar.sidebar-collapsed .sidebar-divider-collapsed {
            display: block !important;
        }
        #main-sidebar.sidebar-collapsed .sidebar-footer-card {
            padding: 0.5rem 0.25rem !important;
            background: transparent !important;
            border-color: transparent !important;
            box-shadow: none !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            gap: 0.5rem !important;
        }
        #main-sidebar.sidebar-collapsed .sidebar-footer-inner {
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 0.5rem !important;
        }
        #main-sidebar.sidebar-collapsed .sidebar-toggle-expand {
            display: flex !important;
        }
        #main-sidebar.sidebar-collapsed .sidebar-toggle-collapse {
            display: none !important;
        }
        #main-sidebar:not(.sidebar-collapsed) .sidebar-toggle-expand {
            display: none !important;
        }
        #main-sidebar:not(.sidebar-collapsed) .sidebar-divider-collapsed {
            display: none !important;
        }
    </style>
</head>
<body class="h-full flex flex-col font-sans text-slate-800 antialiased selection:bg-indigo-500 selection:text-white">
    <?php require_once __DIR__ . '/alert.php'; ?>
    <div class="flex h-screen overflow-hidden">
