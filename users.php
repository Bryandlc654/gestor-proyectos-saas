<?php
// users.php - Página Maestra para la Gestión Integral de Usuarios, Clientes, Roles y Permisos
require_once __DIR__ . '/config/app.php';
requireLogin();

// Restricción estricta: Solo el Administrador puede ingresar
if (!isAdmin()) {
    setFlash('error', 'Acceso denegado. Solo los administradores pueden gestionar usuarios y roles.');
    header('Location: dashboard.php');
    exit;
}

define('PAGE_TITLE', 'Gestión de Usuarios & Roles');
$user = currentUser();
$db = getDB();

// Filtros y Búsqueda
$search = trim($_GET['search'] ?? '');
$filterRole = trim($_GET['role'] ?? 'todos');

$where = [];
$params = [];

if ($filterRole !== 'todos' && in_array($filterRole, ['admin', 'project_manager', 'colaborador', 'cliente'])) {
    $where[] = "u.role = ?";
    $params[] = $filterRole;
}

if (!empty($search)) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Consulta de usuarios con sus proyectos vinculados
$query = "
    SELECT 
        u.id, 
        u.name, 
        u.email, 
        u.role, 
        u.created_at,
        GROUP_CONCAT(p.id ORDER BY p.name SEPARATOR ',') as project_ids,
        GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR '||') as project_names
    FROM users u
    LEFT JOIN project_members pm ON u.id = pm.user_id
    LEFT JOIN projects p ON pm.project_id = p.id
    $whereSql
    GROUP BY u.id
    ORDER BY u.id ASC
";
$stmtUsers = $db->prepare($query);
$stmtUsers->execute($params);
$usersList = $stmtUsers->fetchAll();

// Estadísticas de usuarios
$statsCounts = $db->query("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as count_admin,
        SUM(CASE WHEN role = 'project_manager' THEN 1 ELSE 0 END) as count_pm,
        SUM(CASE WHEN role = 'colaborador' THEN 1 ELSE 0 END) as count_collab,
        SUM(CASE WHEN role = 'cliente' THEN 1 ELSE 0 END) as count_client
    FROM users
")->fetch();

// Todos los proyectos disponibles para asignar
$allProjects = $db->query("SELECT id, name, color FROM projects ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- Contenedor Principal de Usuarios -->
<main class="flex-1 flex flex-col min-w-0 bg-[#F8FAFC] overflow-y-auto">
    <!-- Barra Superior -->
    <header class="min-h-[4.5rem] sm:min-h-[5rem] py-4 sm:py-5 bg-white border-b border-slate-200/80 px-6 sm:px-10 flex items-center justify-between sticky top-0 z-10">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar()" class="p-2 -ml-2 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition" title="Alternar menú lateral">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div>
                <h1 class="font-display font-bold text-lg sm:text-xl text-slate-900 tracking-tight">Usuarios & Control de Acceso</h1>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="toggleNewUserForm()" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-semibold text-xs sm:text-sm shadow-sm shadow-purple-500/25 transition">
                <i data-lucide="user-plus" class="w-4 h-4"></i>
                <span>Nuevo Usuario</span>
            </button>
        </div>
    </header>

    <div class="p-4 sm:p-8 max-w-7xl w-full mx-auto space-y-6">

        <!-- 4 Tarjetas de Métricas de Usuarios -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Usuarios</span>
                    <div class="w-8 h-8 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center">
                        <i data-lucide="users" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-display font-black text-slate-900"><?= (int)$statsCounts['total_users'] ?></div>
                <span class="text-xs text-slate-400 font-medium">cuentas activas</span>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-purple-700">Administradores</span>
                    <div class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                        <i data-lucide="shield" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-display font-black text-purple-700"><?= (int)$statsCounts['count_admin'] ?></div>
                <span class="text-xs text-slate-400 font-medium">control absoluto</span>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-blue-700">Colaboradores & PM</span>
                    <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                        <i data-lucide="user-check" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-display font-black text-blue-700"><?= (int)$statsCounts['count_collab'] + (int)$statsCounts['count_pm'] ?></div>
                <span class="text-xs text-slate-400 font-medium"><?= (int)$statsCounts['count_pm'] ?> managers / <?= (int)$statsCounts['count_collab'] ?> operativos</span>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Clientes</span>
                    <div class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <i data-lucide="briefcase" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-display font-black text-amber-600"><?= (int)$statsCounts['count_client'] ?></div>
                <span class="text-xs text-slate-400 font-medium">acceso restringido a sus proyectos</span>
            </div>
        </div>

        <!-- Formulario Desplegable para Crear Nuevo Usuario -->
        <div id="new-user-form-card" class="hidden bg-white rounded-3xl border border-purple-200/80 shadow-sm p-6 sm:p-8 space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-purple-50 text-purple-700 flex items-center justify-center font-bold">
                        <i data-lucide="user-plus" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h3 class="font-display font-bold text-slate-900 text-base">Registrar Nuevo Usuario / Cliente</h3>
                        <p class="text-xs text-slate-400">Configura sus credenciales, rol y proyectos permitidos</p>
                    </div>
                </div>
                <button type="button" onclick="toggleNewUserForm()" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <form id="create-user-form" onsubmit="handleCreateUserPage(event)" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Nombre Completo *</label>
                        <input type="text" name="name" required placeholder="Ej: Carlos Méndez" class="w-full text-xs sm:text-sm px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-1 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Correo Electrónico *</label>
                        <input type="email" name="email" required placeholder="usuario@empresa.com" class="w-full text-xs sm:text-sm px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-1 focus:ring-purple-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Contraseña Inicial (mín. 8 caracteres) *</label>
                        <input type="password" name="password" minlength="8" required placeholder="••••••••" class="w-full text-xs sm:text-sm px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-1 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Nivel de Acceso (Rol) *</label>
                        <select name="role" id="new-user-role-select" onchange="handleNewUserRoleChange(this.value)" class="w-full text-xs sm:text-sm px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-1 focus:ring-purple-500 font-medium">
                            <option value="cliente" selected>Cliente (Solo proyectos asignados y tickets)</option>
                            <option value="colaborador">Colaborador (Ejecuta tareas asignadas)</option>
                            <option value="project_manager">Project Manager (Gestiona proyectos)</option>
                            <option value="admin">Administrador (Control total del sistema)</option>
                        </select>
                    </div>
                </div>

                <!-- Selección de Proyectos Asignados para Cliente o Miembro -->
                <div id="new-user-projects-group" class="p-4 rounded-2xl bg-purple-50/50 border border-purple-100 space-y-2">
                    <label class="block text-xs font-bold text-purple-950 flex items-center gap-1.5">
                        <i data-lucide="folder-check" class="w-3.5 h-3.5 text-purple-600"></i>
                        <span>Proyectos Asignados a este usuario:</span>
                    </label>
                    <p class="text-[11px] text-purple-700">Para clientes, solo podrán visualizar las tareas y tickets de los proyectos marcados aquí.</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 pt-2 max-h-48 overflow-y-auto">
                        <?php foreach ($allProjects as $proj): ?>
                            <label class="flex items-center gap-2 p-2 rounded-xl bg-white border border-purple-100 hover:border-purple-300 cursor-pointer text-xs transition">
                                <input type="checkbox" name="project_ids[]" value="<?= $proj['id'] ?>" class="rounded text-purple-600 focus:ring-purple-500">
                                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background-color: <?= e($proj['color'] ?: '#6366F1') ?>;"></span>
                                <span class="truncate font-medium text-slate-800"><?= e($proj['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                    <button type="button" onclick="toggleNewUserForm()" class="px-4 py-2 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100 transition">Cancelar</button>
                    <button type="submit" id="btn-save-new-user" class="px-5 py-2 rounded-xl text-xs font-semibold text-white bg-purple-600 hover:bg-purple-700 shadow-2xs transition">Registrar Usuario</button>
                </div>
            </form>
        </div>

        <!-- Barra de Búsqueda y Filtros de Usuarios -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col md:flex-row items-center justify-between gap-3">
            <form method="GET" action="users.php" class="w-full flex flex-col md:flex-row items-center gap-3">
                <div class="relative flex-1 w-full">
                    <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Buscar por nombre o correo electrónico..." class="w-full pl-10 pr-4 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm focus:ring-1 focus:ring-purple-500 bg-white">
                </div>

                <div class="flex items-center gap-2 w-full md:w-auto">
                    <select name="role" onchange="this.form.submit()" class="px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white text-slate-700 font-medium">
                        <option value="todos" <?= $filterRole === 'todos' ? 'selected' : '' ?>>Todos los roles</option>
                        <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>Administradores</option>
                        <option value="project_manager" <?= $filterRole === 'project_manager' ? 'selected' : '' ?>>Project Managers</option>
                        <option value="colaborador" <?= $filterRole === 'colaborador' ? 'selected' : '' ?>>Colaboradores</option>
                        <option value="cliente" <?= $filterRole === 'cliente' ? 'selected' : '' ?>>Clientes</option>
                    </select>

                    <?php if (!empty($search) || $filterRole !== 'todos'): ?>
                        <a href="users.php" class="px-3 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition whitespace-nowrap">
                            Limpiar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Tabla Maestra de Usuarios -->
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-2xs overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h3 class="font-display font-bold text-sm text-slate-900">Directorio de Cuentas</h3>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"><?= count($usersList) ?></span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-600">
                    <thead class="bg-slate-50/70 border-b border-slate-100 uppercase text-[10px] tracking-wider text-slate-400">
                        <tr>
                            <th class="py-3.5 px-6">Usuario</th>
                            <th class="py-3.5 px-4">Nivel de Acceso (Rol)</th>
                            <th class="py-3.5 px-4">Proyectos Vinculados</th>
                            <th class="py-3.5 px-4">Registro</th>
                            <th class="py-3.5 px-6 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($usersList as $u): ?>
                            <?php 
                                $rBadge = getRoleBadge($u['role']);
                                $pIds = !empty($u['project_ids']) ? explode(',', $u['project_ids']) : [];
                                $pNames = !empty($u['project_names']) ? explode('||', $u['project_names']) : [];
                                $isSelf = ($u['id'] == $user['id']);
                            ?>
                            <tr class="hover:bg-slate-50/60 transition">
                                <!-- Usuario -->
                                <td class="py-3.5 px-6">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full bg-brand-100 text-brand-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                                            <?= strtoupper(substr($u['name'], 0, 2)) ?>
                                        </div>
                                        <div>
                                            <div class="font-bold text-slate-900 flex items-center gap-1.5">
                                                <span><?= e($u['name']) ?></span>
                                                <?php if ($isSelf): ?>
                                                    <span class="text-[9px] font-extrabold uppercase px-1.5 py-0.2 rounded bg-indigo-100 text-brand-700">Tú</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[11px] text-slate-400"><?= e($u['email']) ?></div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Rol / Selector Rápido -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <?php if ($isSelf): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-[10px] font-bold uppercase tracking-wider border <?= $rBadge['class'] ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?= $rBadge['dot'] ?>"></span>
                                            <?= $rBadge['label'] ?>
                                        </span>
                                    <?php else: ?>
                                        <select onchange="changeRoleAjax(<?= $u['id'] ?>, this.value)" class="text-xs font-semibold py-1 px-2.5 rounded-xl border border-slate-200 bg-white text-slate-800 cursor-pointer focus:ring-1 focus:ring-purple-500">
                                            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                                            <option value="project_manager" <?= $u['role'] === 'project_manager' ? 'selected' : '' ?>>Project Manager</option>
                                            <option value="colaborador" <?= $u['role'] === 'colaborador' ? 'selected' : '' ?>>Colaborador</option>
                                            <option value="cliente" <?= $u['role'] === 'cliente' ? 'selected' : '' ?>>Cliente</option>
                                        </select>
                                    <?php endif; ?>
                                </td>

                                <!-- Proyectos Vinculados -->
                                <td class="py-3.5 px-4">
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="text-xs text-purple-700 font-semibold flex items-center gap-1">
                                            <i data-lucide="shield-check" class="w-3.5 h-3.5"></i> Todos los proyectos (Acceso total)
                                        </span>
                                    <?php else: ?>
                                        <div class="flex flex-wrap items-center gap-1 max-w-md">
                                            <?php if (empty($pNames)): ?>
                                                <span class="text-[11px] text-slate-400 italic">Sin proyectos asignados</span>
                                            <?php else: ?>
                                                <?php foreach (array_slice($pNames, 0, 3) as $pn): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium bg-slate-100 text-slate-700">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-brand-500"></span>
                                                        <span class="truncate max-w-[130px]"><?= e($pn) ?></span>
                                                    </span>
                                                <?php endforeach; ?>
                                                <?php if (count($pNames) > 3): ?>
                                                    <span class="text-[10px] text-slate-400 font-bold px-1.5 py-0.5 rounded bg-slate-100">
                                                        +<?= count($pNames) - 3 ?> más
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <button type="button" onclick="openAssignModal(<?= $u['id'] ?>, '<?= e(addslashes($u['name'])) ?>', <?= json_encode($pIds) ?>)" class="ml-1 text-purple-600 hover:text-purple-800 font-semibold text-[11px] hover:underline flex items-center gap-0.5">
                                                <i data-lucide="edit-3" class="w-3 h-3"></i>
                                                <span>Editar</span>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Fecha Registro -->
                                <td class="py-3.5 px-4 whitespace-nowrap text-slate-400 text-xs">
                                    <?= formatDate($u['created_at']) ?>
                                </td>

                                <!-- Acciones -->
                                <td class="py-3.5 px-6 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1.5 justify-end">
                                        <?php if (!$isSelf): ?>
                                            <button onclick="deleteUser(<?= $u['id'] ?>, '<?= e(addslashes($u['name'])) ?>')" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" title="Eliminar usuario">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-[11px] text-slate-400 italic">Sesión actual</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</main>

<!-- Modal: Asignar Proyectos a Usuario -->
<div id="assign-projects-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden transition-opacity">
    <div class="bg-white rounded-3xl max-w-lg w-full shadow-2xl border border-slate-100 overflow-hidden transform transition-all">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-purple-50/50">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-purple-100 text-purple-700 flex items-center justify-center">
                    <i data-lucide="folder-check" class="w-4 h-4"></i>
                </div>
                <div>
                    <h3 class="font-display font-bold text-slate-900 text-sm">Vincular Proyectos</h3>
                    <p class="text-[11px] text-slate-400" id="assign-modal-user-name"></p>
                </div>
            </div>
            <button type="button" onclick="closeModal('assign-projects-modal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <form id="assign-projects-form" onsubmit="handleSaveProjects(event)" class="p-6 space-y-4">
            <input type="hidden" name="target_user_id" id="assign-target-user-id">

            <p class="text-xs text-slate-500">Selecciona los proyectos a los que tendrá acceso este usuario:</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-64 overflow-y-auto p-1">
                <?php foreach ($allProjects as $proj): ?>
                    <label class="flex items-center gap-2 p-2.5 rounded-xl border border-slate-200 hover:border-purple-300 cursor-pointer text-xs transition bg-slate-50/50 hover:bg-white">
                        <input type="checkbox" name="project_ids[]" value="<?= $proj['id'] ?>" id="assign-chk-<?= $proj['id'] ?>" class="rounded text-purple-600 focus:ring-purple-500">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background-color: <?= e($proj['color'] ?: '#6366F1') ?>;"></span>
                        <span class="truncate font-medium text-slate-800"><?= e($proj['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('assign-projects-modal')" class="px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100 transition">Cancelar</button>
                <button type="submit" id="btn-save-projects" class="px-4 py-1.5 rounded-xl text-xs font-semibold text-white bg-purple-600 hover:bg-purple-700 shadow-2xs transition">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleNewUserForm() {
    const card = document.getElementById('new-user-form-card');
    if (card) {
        card.classList.toggle('hidden');
        if (!card.classList.contains('hidden')) {
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
}

function handleNewUserRoleChange(role) {
    const group = document.getElementById('new-user-projects-group');
    if (group) {
        if (role === 'admin') {
            group.classList.add('opacity-50', 'pointer-events-none');
        } else {
            group.classList.remove('opacity-50', 'pointer-events-none');
        }
    }
}

async function handleCreateUserPage(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-save-new-user');
    btn.disabled = true;
    btn.innerText = 'Registrando...';

    const formData = new FormData(e.target);
    formData.append('action', 'create_user');
    formData.append('csrf_token', '<?= csrf_token() ?>');

    try {
        const res = await fetch('api/users.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Error al crear usuario');
        }
    } catch (err) {
        alert('Error de conexión con el servidor');
    } finally {
        btn.disabled = false;
        btn.innerText = 'Registrar Usuario';
    }
}

function openAssignModal(userId, userName, projectIds) {
    document.getElementById('assign-target-user-id').value = userId;
    document.getElementById('assign-modal-user-name').innerText = 'Usuario: ' + userName;

    // Resetear checkboxes
    document.querySelectorAll('#assign-projects-form input[type="checkbox"]').forEach(chk => {
        chk.checked = false;
    });

    // Marcar los asignados
    projectIds.forEach(id => {
        const chk = document.getElementById('assign-chk-' + id);
        if (chk) chk.checked = true;
    });

    openModal('assign-projects-modal');
}

async function handleSaveProjects(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-save-projects');
    btn.disabled = true;
    btn.innerText = 'Guardando...';

    const formData = new FormData(e.target);
    formData.append('action', 'assign_projects');
    formData.append('csrf_token', '<?= csrf_token() ?>');

    try {
        const res = await fetch('api/users.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Error al guardar proyectos');
        }
    } catch (err) {
        alert('Error de conexión');
    } finally {
        btn.disabled = false;
        btn.innerText = 'Guardar Cambios';
    }
}

async function changeRoleAjax(userId, newRole) {
    const formData = new FormData();
    formData.append('action', 'update_role');
    formData.append('csrf_token', '<?= csrf_token() ?>');
    formData.append('target_user_id', userId);
    formData.append('new_role', newRole);

    try {
        const res = await fetch('api/users.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Error al actualizar rol');
        }
    } catch (err) {
        alert('Error de conexión');
    }
}

async function deleteUser(userId, userName) {
    if (!confirm(`¿Estás seguro de que deseas eliminar permanentemente al usuario "${userName}"? Esta acción no se puede deshacer.`)) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'delete_user');
    formData.append('csrf_token', '<?= csrf_token() ?>');
    formData.append('target_user_id', userId);

    try {
        const res = await fetch('api/users.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Error al eliminar usuario');
        }
    } catch (err) {
        alert('Error de conexión');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
