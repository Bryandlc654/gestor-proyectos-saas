<?php
// includes/sidebar.php
$currentPage = basename($_SERVER['PHP_SELF']);
$db = getDB();
$userRole = currentUserRole();
$roleBadge = getRoleBadge($userRole);
$customLogo = getAppSetting('app_logo', '');
$customAppName = getAppSetting('app_name', 'FlowTasks');

// Obtener proyectos rápidos del usuario según su rol para el sidebar
if ($userRole === 'admin') {
    $stmtSidebar = $db->query("SELECT id, name, color FROM projects ORDER BY updated_at DESC LIMIT 6");
    $sidebarProjects = $stmtSidebar->fetchAll();
} elseif ($userRole === 'cliente') {
    $stmtSidebar = $db->prepare("
        SELECT p.id, p.name, p.color 
        FROM projects p
        INNER JOIN project_members pm ON p.id = pm.project_id
        WHERE pm.user_id = ?
        ORDER BY p.updated_at DESC LIMIT 6
    ");
    $stmtSidebar->execute([$_SESSION['user_id']]);
    $sidebarProjects = $stmtSidebar->fetchAll();
} else {
    $stmtSidebar = $db->prepare("
        SELECT id, name, color FROM projects 
        WHERE user_id = ? OR id IN (SELECT project_id FROM project_members WHERE user_id = ?)
        ORDER BY updated_at DESC LIMIT 6
    ");
    $stmtSidebar->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $sidebarProjects = $stmtSidebar->fetchAll();
}

// Clases de un enlace del menú: mismo estilo activo/inactivo para todas las secciones
$navItemClass = function (bool $active): string {
    return 'sidebar-nav-item flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors '
        . ($active ? 'bg-slate-100 text-slate-900 font-medium' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900');
};
$navIconClass = fn(bool $active): string => 'w-[18px] h-[18px] flex-shrink-0 ' . ($active ? 'text-slate-900' : 'text-slate-400');
$mainNav = [
    ['dashboard.php', 'layout-dashboard', 'Dashboard', true],
    ['tasks.php', 'check-square', 'Tareas', true],
    ['invoices.php', 'receipt', 'Facturación', in_array($userRole, ['admin', 'project_manager'])],
    ['users.php', 'users', 'Usuarios y roles', isAdmin()],
    ['settings.php', 'settings', 'Configuración', isAdmin()],
];
?>

<!-- Backdrop para móviles -->
<div id="mobile-backdrop" class="fixed inset-0 bg-slate-900/30 z-20 hidden md:hidden transition-opacity"></div>

<!-- Sidebar Principal -->
<aside id="main-sidebar" class="fixed inset-y-0 left-0 z-30 w-60 bg-white border-r border-slate-200/70 flex flex-col transition-all duration-300 transform -translate-x-full md:relative md:translate-x-0 flex-shrink-0">
    <!-- Header Sidebar con Logo y Botón de Colapsar -->
    <div class="sidebar-header h-16 flex items-center justify-between px-4 transition-all">
        <a href="dashboard.php" class="flex items-center gap-2.5 min-w-0" title="<?= e($customAppName) ?>">
            <?php if (!empty($customLogo) && file_exists(__DIR__ . '/../' . $customLogo)): ?>
                <img src="<?= e($customLogo) ?>" alt="<?= e($customAppName) ?>" class="h-7 w-7 object-contain rounded-md flex-shrink-0">
            <?php else: ?>
                <div class="w-7 h-7 rounded-lg bg-slate-900 flex items-center justify-center text-white flex-shrink-0">
                    <i data-lucide="layers" class="w-4 h-4"></i>
                </div>
            <?php endif; ?>
            <span class="sidebar-text font-semibold text-[15px] text-slate-900 tracking-tight truncate"><?= e($customAppName) ?></span>
        </a>
        <div class="flex items-center gap-1">
            <button onclick="toggleSidebar()" class="sidebar-toggle-collapse p-1.5 rounded-md text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition hidden md:flex items-center justify-center" title="Colapsar menú lateral">
                <i data-lucide="panel-left-close" class="w-4 h-4"></i>
            </button>
            <button id="close-mobile-menu" class="p-1 rounded-md text-slate-400 hover:text-slate-600 md:hidden">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
    </div>

    <!-- Navegación -->
    <div class="flex-1 overflow-y-auto px-3 pt-2 pb-4 space-y-6">
        <nav class="space-y-0.5 flex flex-col items-stretch">
            <?php foreach ($mainNav as [$href, $icon, $label, $visible]): ?>
                <?php if (!$visible) continue; $active = $currentPage === $href; ?>
                <a href="<?= $href ?>" title="<?= e($label) ?>" class="<?= $navItemClass($active) ?>">
                    <i data-lucide="<?= $icon ?>" class="<?= $navIconClass($active) ?>"></i>
                    <span class="sidebar-text truncate"><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Lista de Proyectos Activos -->
        <div>
            <div class="flex items-center justify-between px-3 mb-1.5">
                <p class="sidebar-section-title text-xs font-medium text-slate-400">
                    <?= $userRole === 'admin' ? 'Proyectos' : 'Tus proyectos' ?>
                </p>
                <?php if (canCreateProjects($userRole)): ?>
                    <button onclick="openModal('create-project-modal')" title="Nuevo proyecto" class="sidebar-section-title text-slate-400 hover:text-slate-900 p-0.5 rounded hover:bg-slate-100 transition-colors">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                    </button>
                <?php endif; ?>
            </div>
            <div class="sidebar-divider-collapsed w-6 h-px bg-slate-200 mx-auto my-2"></div>

            <nav class="space-y-0.5 flex flex-col items-stretch">
                <?php if (!empty($sidebarProjects)): ?>
                    <?php foreach ($sidebarProjects as $sp): ?>
                        <?php
                            $isCurrentProject = ($currentPage === 'project-view.php' && isset($_GET['id']) && (int)$_GET['id'] === (int)$sp['id']);
                            $projColor = $sp['color'] ?: '#6366F1';
                        ?>
                        <a href="project-view.php?id=<?= $sp['id'] ?>" title="<?= e($sp['name']) ?>" class="<?= $navItemClass($isCurrentProject) ?>">
                            <span class="w-[18px] h-[18px] flex items-center justify-center flex-shrink-0">
                                <span class="w-2 h-2 rounded-full" style="background-color: <?= e($projColor) ?>;"></span>
                            </span>
                            <span class="sidebar-text truncate"><?= e($sp['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (canCreateProjects($userRole)): ?>
                        <div class="sidebar-divider-collapsed text-center pt-1">
                            <button onclick="openModal('create-project-modal')" title="Nuevo proyecto" class="w-7 h-7 rounded-md border border-dashed border-slate-300 flex items-center justify-center text-slate-400 hover:text-slate-900 hover:border-slate-400 mx-auto transition">
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="sidebar-text px-3 text-xs text-slate-400">Aún no hay proyectos.</p>
                <?php endif; ?>
            </nav>
        </div>
    </div>

    <!-- Footer Sidebar: Usuario, Rol y Logout -->
    <div class="p-3 border-t border-slate-100">
        <!-- Botón expandir visible solo cuando el sidebar está colapsado -->
        <div class="sidebar-toggle-expand w-full justify-center mb-2">
            <button onclick="toggleSidebar()" class="w-8 h-8 rounded-md text-slate-400 hover:text-slate-900 hover:bg-slate-100 transition flex items-center justify-center" title="Expandir menú lateral">
                <i data-lucide="panel-left-open" class="w-4 h-4"></i>
            </button>
        </div>

        <div class="sidebar-footer-card">
            <div class="sidebar-footer-inner flex items-center justify-between gap-2 w-full px-1">
                <div class="flex items-center gap-2.5 min-w-0" title="<?= e($user['name']) ?> (<?= $roleBadge['label'] ?>)">
                    <div class="w-8 h-8 rounded-full bg-slate-100 text-slate-700 font-medium text-xs flex items-center justify-center flex-shrink-0">
                        <?= e(mb_strtoupper(mb_substr($user['name'], 0, 2))) ?>
                    </div>
                    <div class="sidebar-text min-w-0">
                        <p class="text-[13px] font-medium text-slate-900 truncate leading-tight"><?= e($user['name']) ?></p>
                        <p class="text-xs text-slate-400 truncate"><?= $roleBadge['label'] ?></p>
                    </div>
                </div>
                <a href="logout.php" title="Cerrar sesión" class="p-1.5 text-slate-400 hover:text-slate-900 rounded-md hover:bg-slate-100 transition-colors flex-shrink-0">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    </div>
</aside>
