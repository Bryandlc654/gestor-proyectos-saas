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
?>

<!-- Backdrop para móviles -->
<div id="mobile-backdrop" class="fixed inset-0 bg-slate-900/50 z-20 hidden md:hidden transition-opacity"></div>

<!-- Sidebar Principal -->
<aside id="main-sidebar" class="fixed inset-y-0 left-0 z-30 w-64 bg-white border-r border-slate-200/80 flex flex-col transition-all duration-300 transform -translate-x-full md:relative md:translate-x-0 flex-shrink-0">
    <!-- Header Sidebar con Logo y Botón de Colapsar -->
    <div class="sidebar-header min-h-[4.5rem] sm:min-h-[5rem] py-4 sm:py-5 flex items-center justify-between px-4 border-b border-slate-100 transition-all">
        <a href="dashboard.php" class="flex items-center gap-2.5 group min-w-0" title="<?= e($customAppName) ?>">
            <?php if (!empty($customLogo) && file_exists(__DIR__ . '/../' . $customLogo)): ?>
                <img src="<?= e($customLogo) ?>" alt="<?= e($customAppName) ?>" class="h-8 w-8 object-contain rounded-lg flex-shrink-0">
                <span class="sidebar-text font-display font-bold text-base text-slate-900 tracking-tight truncate"><?= e($customAppName) ?></span>
            <?php else: ?>
                <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-400 flex items-center justify-center text-white shadow-md shadow-brand-500/20 group-hover:scale-105 transition-transform flex-shrink-0">
                    <i data-lucide="layers" class="w-4 h-4"></i>
                </div>
                <span class="sidebar-text font-display font-bold text-base text-slate-900 tracking-tight truncate"><?= e($customAppName) ?></span>
            <?php endif; ?>
        </a>
        <div class="flex items-center gap-1">
            <button onclick="toggleSidebar()" class="sidebar-toggle-collapse p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition hidden md:flex items-center justify-center" title="Colapsar menú lateral">
                <i data-lucide="chevrons-left" class="w-4 h-4"></i>
            </button>
            <button id="close-mobile-menu" class="p-1 rounded-lg text-slate-400 hover:text-slate-600 md:hidden">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
    </div>

    <!-- Navegación -->
    <div class="flex-1 overflow-y-auto px-3 py-4 space-y-5">
        <!-- Menú Principal -->
        <div>
            <p class="sidebar-section-title px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-2">Principal</p>
            <div class="sidebar-divider-collapsed w-8 h-px bg-slate-200 mx-auto my-2"></div>
            <nav class="space-y-1 flex flex-col items-stretch">
                <a href="dashboard.php" title="Dashboard" class="sidebar-nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all <?= ($currentPage === 'dashboard.php') ? 'bg-brand-50 text-brand-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                    <i data-lucide="layout-dashboard" class="w-5 h-5 flex-shrink-0 <?= ($currentPage === 'dashboard.php') ? 'text-brand-600' : 'text-slate-400' ?>"></i>
                    <span class="sidebar-text truncate">Dashboard</span>
                </a>

                <a href="tasks.php" title="Tareas Globales" class="sidebar-nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all <?= ($currentPage === 'tasks.php') ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                    <i data-lucide="check-square" class="w-5 h-5 flex-shrink-0 <?= ($currentPage === 'tasks.php') ? 'text-indigo-600' : 'text-slate-400' ?>"></i>
                    <span class="sidebar-text truncate">Tareas Globales</span>
                </a>

                <?php if (in_array($userRole, ['admin', 'project_manager'])): ?>
                    <a href="invoices.php" title="Facturación" class="sidebar-nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all <?= ($currentPage === 'invoices.php') ? 'bg-emerald-50 text-emerald-800 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                        <i data-lucide="receipt" class="w-5 h-5 flex-shrink-0 <?= ($currentPage === 'invoices.php') ? 'text-emerald-600' : 'text-slate-400' ?>"></i>
                        <span class="sidebar-text truncate">Facturación</span>
                    </a>
                <?php endif; ?>

                <?php if (isAdmin()): ?>
                    <a href="users.php" title="Usuarios & Roles" class="sidebar-nav-item flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all <?= ($currentPage === 'users.php') ? 'bg-purple-100 text-purple-900 font-semibold' : 'text-purple-700 hover:bg-purple-50' ?>">
                        <div class="flex items-center gap-3 min-w-0">
                            <i data-lucide="users" class="w-5 h-5 flex-shrink-0 text-purple-600"></i>
                            <span class="sidebar-text truncate">Usuarios & Roles</span>
                        </div>
                        <span class="sidebar-text text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded bg-purple-200/80 text-purple-800 flex-shrink-0">Admin</span>
                    </a>

                    <a href="settings.php" title="Configuraciones" class="sidebar-nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all <?= ($currentPage === 'settings.php') ? 'bg-indigo-50 text-brand-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                        <i data-lucide="settings" class="w-5 h-5 flex-shrink-0 <?= ($currentPage === 'settings.php') ? 'text-brand-600' : 'text-slate-400' ?>"></i>
                        <span class="sidebar-text truncate">Configuraciones</span>
                    </a>
                <?php endif; ?>
            </nav>
        </div>

        <!-- Lista de Proyectos Activos -->
        <div>
            <div class="flex items-center justify-between px-3 mb-2">
                <p class="sidebar-section-title text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                    <?= $userRole === 'admin' ? 'Todos los Proyectos' : 'Tus Proyectos' ?>
                </p>
                <?php if (canCreateProjects($userRole)): ?>
                    <button onclick="openModal('create-project-modal')" title="Nuevo Proyecto" class="sidebar-section-title text-brand-600 hover:text-brand-700 p-0.5 rounded hover:bg-brand-50 transition-colors">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                    </button>
                <?php endif; ?>
            </div>
            <div class="sidebar-divider-collapsed w-8 h-px bg-slate-200 mx-auto my-2"></div>
            
            <nav class="space-y-1 flex flex-col items-stretch">
                <?php if (!empty($sidebarProjects)): ?>
                    <?php foreach ($sidebarProjects as $sp): ?>
                        <?php 
                            $isCurrentProject = ($currentPage === 'project-view.php' && isset($_GET['id']) && (int)$_GET['id'] === (int)$sp['id']);
                            $projInitial = strtoupper(mb_substr($sp['name'], 0, 1));
                            $projColor = $sp['color'] ?: '#6366F1';
                        ?>
                        <a href="project-view.php?id=<?= $sp['id'] ?>" title="<?= e($sp['name']) ?>" class="sidebar-nav-item flex items-center gap-3 px-3 py-2 rounded-xl text-sm font-medium transition-all group <?= $isCurrentProject ? 'bg-indigo-50/80 text-brand-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                            <span class="w-6 h-6 rounded-lg flex items-center justify-center text-[10px] font-bold flex-shrink-0 transition-transform group-hover:scale-105" style="background-color: <?= e($projColor) ?>25; color: <?= e($projColor) ?>; border: 1.5px solid <?= e($projColor) ?>50;"><?= e($projInitial) ?></span>
                            <span class="sidebar-text truncate"><?= e($sp['name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (canCreateProjects($userRole)): ?>
                        <div class="sidebar-divider-collapsed text-center pt-1">
                            <button onclick="openModal('create-project-modal')" title="Nuevo Proyecto" class="w-7 h-7 rounded-lg border border-dashed border-slate-300 flex items-center justify-center text-slate-400 hover:text-brand-600 hover:border-brand-400 hover:bg-brand-50/50 mx-auto transition">
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="sidebar-text px-3 text-xs text-slate-400 italic">No hay proyectos activos aún.</p>
                <?php endif; ?>
            </nav>
        </div>
    </div>

    <!-- Footer Sidebar: Usuario, Rol y Logout -->
    <div class="p-3 border-t border-slate-100 bg-slate-50/50">
        <!-- Botón expandir visible solo cuando el sidebar está colapsado -->
        <div class="sidebar-toggle-expand w-full justify-center mb-2">
            <button onclick="toggleSidebar()" class="w-8 h-8 rounded-xl text-slate-400 hover:text-brand-600 hover:bg-slate-200/60 transition flex items-center justify-center" title="Expandir menú lateral">
                <i data-lucide="chevrons-right" class="w-4 h-4"></i>
            </button>
        </div>

        <div class="sidebar-footer-card p-2.5 rounded-2xl bg-white border border-slate-200/70 shadow-2xs space-y-2">
            <div class="sidebar-footer-inner flex items-center justify-between gap-2 w-full">
                <div class="flex items-center gap-2 min-w-0" title="<?= e($user['name']) ?> (<?= $roleBadge['label'] ?>)">
                    <div class="w-8 h-8 rounded-full bg-brand-100 text-brand-700 font-bold text-xs flex items-center justify-center flex-shrink-0 shadow-2xs">
                        <?= strtoupper(substr($user['name'], 0, 2)) ?>
                    </div>
                    <div class="sidebar-text min-w-0">
                        <p class="text-xs font-semibold text-slate-900 truncate leading-tight"><?= e($user['name']) ?></p>
                        <p class="text-[10px] text-slate-400 truncate"><?= e($user['email']) ?></p>
                    </div>
                </div>
                <a href="logout.php" title="Cerrar Sesión" class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition-colors flex-shrink-0">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                </a>
            </div>
            <div class="sidebar-text pt-1.5 border-t border-slate-100 flex items-center justify-start">
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider border <?= $roleBadge['class'] ?>">
                    <span class="w-1.5 h-1.5 rounded-full <?= $roleBadge['dot'] ?>"></span>
                    <?= $roleBadge['label'] ?>
                </span>
            </div>
        </div>
    </div>
</aside>
