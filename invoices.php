<?php
// invoices.php - Módulo General de Facturación y Cobros
require_once __DIR__ . '/config/app.php';
requireLogin();

// Restringir módulo de facturación global al Admin y Project Manager (o ver facturas propias del cliente si aplica)
$user = currentUser();
$userRole = currentUserRole();
$userId = (int)$user['id'];

// Solo admin y project manager pueden gestionar facturación general
if (!in_array($userRole, ['admin', 'project_manager'])) {
    setFlash('error', 'No tienes permisos para acceder al módulo de facturación.');
    header('Location: dashboard.php');
    exit;
}

define('PAGE_TITLE', 'Módulo de Facturación');
$db = getDB();

// Filtros
$filterStatus = $_GET['status'] ?? 'todos';
$filterProject = (int)($_GET['project_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

// Lista de proyectos para el selector
if ($userRole === 'admin') {
    $projectsList = $db->query("SELECT id, name, currency FROM projects ORDER BY name ASC")->fetchAll();
} else {
    $stmtP = $db->prepare("SELECT id, name, currency FROM projects WHERE user_id = ? OR id IN (SELECT project_id FROM project_members WHERE user_id = ?) ORDER BY name ASC");
    $stmtP->execute([$userId, $userId]);
    $projectsList = $stmtP->fetchAll();
}

// Construir query de facturas
$whereClauses = [];
$params = [];

if ($userRole !== 'admin') {
    $whereClauses[] = "(p.user_id = ? OR p.id IN (SELECT project_id FROM project_members WHERE user_id = ?))";
    $params[] = $userId;
    $params[] = $userId;
}

if ($filterStatus !== 'todos' && in_array($filterStatus, ['pendiente', 'pagada', 'anulada'])) {
    $whereClauses[] = "i.status = ?";
    $params[] = $filterStatus;
}

if ($filterProject > 0) {
    $whereClauses[] = "i.project_id = ?";
    $params[] = $filterProject;
}

if (!empty($search)) {
    $whereClauses[] = "(i.invoice_number LIKE ? OR i.concept LIKE ? OR p.name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Obtener totales para los KPI cards
$kpiSql = "
    SELECT 
        COUNT(i.id) as total_invoices,
        COALESCE(SUM(i.amount), 0) as total_amount,
        COALESCE(SUM(CASE WHEN i.status = 'pagada' THEN i.amount ELSE 0 END), 0) as paid_amount,
        COALESCE(SUM(CASE WHEN i.status = 'pendiente' THEN i.amount ELSE 0 END), 0) as pending_amount,
        COALESCE(SUM(CASE WHEN i.status = 'anulada' THEN i.amount ELSE 0 END), 0) as cancelled_amount,
        COALESCE(SUM(CASE WHEN i.status = 'pendiente' THEN 1 ELSE 0 END), 0) as count_pending,
        COALESCE(SUM(CASE WHEN i.status = 'pagada' THEN 1 ELSE 0 END), 0) as count_paid
    FROM invoices i
    INNER JOIN projects p ON i.project_id = p.id
    $whereSql
";
$stmtKpi = $db->prepare($kpiSql);
$stmtKpi->execute($params);
$kpis = $stmtKpi->fetch() ?: [
    'total_invoices' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'pending_amount' => 0,
    'cancelled_amount' => 0, 'count_pending' => 0, 'count_paid' => 0
];

// Obtener listado de facturas
$invoicesSql = "
    SELECT 
        i.*,
        p.name as project_name,
        p.color as project_color,
        p.currency as project_currency
    FROM invoices i
    INNER JOIN projects p ON i.project_id = p.id
    $whereSql
    ORDER BY (i.status = 'pendiente') DESC, i.due_date ASC, i.created_at DESC
";
$stmtInv = $db->prepare($invoicesSql);
$stmtInv->execute($params);
$invoices = $stmtInv->fetchAll();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- Contenedor Principal de Facturación -->
<main class="flex-1 flex flex-col min-w-0 bg-[#F8FAFC] overflow-y-auto">
    <!-- Barra Superior -->
    <header class="min-h-[4.5rem] sm:min-h-[5rem] py-4 sm:py-5 bg-white border-b border-slate-200/80 px-6 sm:px-10 flex items-center justify-between sticky top-0 z-10">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar()" class="p-2 -ml-2 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition" title="Alternar menú lateral">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div>
                <h1 class="font-display font-bold text-lg sm:text-xl text-slate-900 tracking-tight">Facturación & Cobros</h1>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="openModal('create-invoice-modal')" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-sm shadow-brand-500/25 transition">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Nueva Factura</span>
            </button>
        </div>
    </header>

    <div class="p-4 sm:p-8 max-w-7xl w-full mx-auto space-y-6">

        <!-- 4 Tarjetas de Métricas Financieras (KPIs) -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <!-- 1. Total Facturado -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Facturado</span>
                    <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                        <i data-lucide="receipt" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-display font-black text-slate-900">
                    $<?= number_format(floatval($kpis['total_amount']), 2) ?>
                </div>
                <span class="text-xs text-slate-400 font-medium"><?= (int)$kpis['total_invoices'] ?> facturas emitidas</span>
            </div>

            <!-- 2. Monto Cobrado -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Monto Cobrado</span>
                    <div class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <i data-lucide="check-circle" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-display font-black text-emerald-600">
                    $<?= number_format(floatval($kpis['paid_amount']), 2) ?>
                </div>
                <span class="text-xs text-slate-400 font-medium"><?= (int)$kpis['count_paid'] ?> facturas liquidadas</span>
            </div>

            <!-- 3. Por Cobrar / Pendiente -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Por Cobrar</span>
                    <div class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                        <i data-lucide="clock" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-display font-black text-amber-600">
                    $<?= number_format(floatval($kpis['pending_amount']), 2) ?>
                </div>
                <span class="text-xs text-slate-400 font-medium"><?= (int)$kpis['count_pending'] ?> pendientes de pago</span>
            </div>

            <!-- 4. Tasa de Recaudación -->
            <?php 
                $rate = floatval($kpis['total_amount']) > 0 ? round((floatval($kpis['paid_amount']) / floatval($kpis['total_amount'])) * 100) : 0;
            ?>
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-2xs space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Efectividad</span>
                    <div class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">
                        <i data-lucide="pie-chart" class="w-4 h-4"></i>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-display font-black text-purple-600">
                    <?= $rate ?>%
                </div>
                <span class="text-xs text-slate-400 font-medium">del monto recuperado</span>
            </div>
        </div>

        <!-- Barra de Búsqueda y Filtros -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs flex flex-col md:flex-row items-center justify-between gap-3">
            <form method="GET" action="invoices.php" class="w-full flex flex-col md:flex-row items-center gap-3">
                <div class="relative flex-1 w-full">
                    <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                    <input type="text" name="search" value="<?= e($search) ?>" placeholder="Buscar por N° factura, concepto o proyecto..." class="w-full pl-10 pr-4 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm focus:ring-1 focus:ring-brand-500 bg-white">
                </div>

                <div class="flex items-center gap-2 w-full md:w-auto">
                    <select name="status" onchange="this.form.submit()" class="px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white text-slate-700 font-medium">
                        <option value="todos" <?= $filterStatus === 'todos' ? 'selected' : '' ?>>Todos los estados</option>
                        <option value="pendiente" <?= $filterStatus === 'pendiente' ? 'selected' : '' ?>>Pendientes</option>
                        <option value="pagada" <?= $filterStatus === 'pagada' ? 'selected' : '' ?>>Pagadas</option>
                        <option value="anulada" <?= $filterStatus === 'anulada' ? 'selected' : '' ?>>Anuladas</option>
                    </select>

                    <select name="project_id" onchange="this.form.submit()" class="px-3 py-2 rounded-xl border border-slate-200 text-xs bg-white text-slate-700 font-medium max-w-[200px] truncate">
                        <option value="0">Todos los proyectos</option>
                        <?php foreach ($projectsList as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $filterProject === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= e($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if (!empty($search) || $filterStatus !== 'todos' || $filterProject > 0): ?>
                        <a href="invoices.php" class="px-3 py-2 rounded-xl text-xs font-semibold text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition whitespace-nowrap">
                            Limpiar
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Tabla Maestra de Facturación -->
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h3 class="font-display font-bold text-sm text-slate-900">Listado General de Facturas</h3>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"><?= count($invoices) ?></span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-600">
                    <thead class="bg-slate-50/70 border-b border-slate-100 uppercase text-[10px] tracking-wider text-slate-400">
                        <tr>
                            <th class="py-3 px-4">N° Factura</th>
                            <th class="py-3 px-4">Proyecto</th>
                            <th class="py-3 px-4">Concepto</th>
                            <th class="py-3 px-4">Monto</th>
                            <th class="py-3 px-4">Vencimiento</th>
                            <th class="py-3 px-4">Estado</th>
                            <th class="py-3 px-4 text-right">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="7" class="py-12 text-center text-slate-400">
                                    <i data-lucide="receipt" class="w-8 h-8 text-slate-300 mx-auto mb-2"></i>
                                    <p class="font-medium text-xs">No se encontraron facturas con los filtros aplicados.</p>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($invoices as $inv): ?>
                            <?php
                                $badgeClass = match($inv['status']) {
                                    'pagada'    => 'bg-emerald-50 text-emerald-700 border-emerald-200/60',
                                    'pendiente' => 'bg-amber-50 text-amber-700 border-amber-200/60',
                                    'anulada'   => 'bg-rose-50 text-rose-700 border-rose-200/60',
                                    default     => 'bg-slate-50 text-slate-700 border-slate-200/60'
                                };
                            ?>
                            <tr id="inv-row-<?= $inv['id'] ?>" class="hover:bg-slate-50/60 transition">
                                <td class="py-3 px-4 font-mono font-bold text-slate-900 whitespace-nowrap">
                                    <?= e($inv['invoice_number']) ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <a href="project-view.php?id=<?= $inv['project_id'] ?>#finanzas" class="inline-flex items-center gap-1.5 font-semibold text-slate-800 hover:text-brand-600 transition">
                                        <span class="w-2 h-2 rounded-full" style="background-color: <?= e($inv['project_color'] ?: '#6366F1') ?>;"></span>
                                        <span><?= e($inv['project_name']) ?></span>
                                    </a>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="font-medium text-slate-800"><?= e($inv['concept']) ?></div>
                                    <?php if (!empty($inv['notes'])): ?>
                                        <div class="text-[11px] text-slate-400 truncate max-w-xs mt-0.5"><?= e($inv['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 font-bold text-slate-900 whitespace-nowrap">
                                    <?= e($inv['currency'] ?: 'USD') ?> $<?= number_format(floatval($inv['amount']), 2) ?>
                                </td>
                                <td class="py-3 px-4 text-slate-500 whitespace-nowrap">
                                    <?= $inv['due_date'] ? formatDate($inv['due_date']) : '—' ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    <span id="badge-status-<?= $inv['id'] ?>" class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md border <?= $badgeClass ?>">
                                        <?= e($inv['status']) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1.5 justify-end">
                                        <select onchange="changeInvoiceStatus(<?= $inv['id'] ?>, <?= $inv['project_id'] ?>, this.value)" class="text-[11px] font-semibold py-1 px-2 rounded-lg border border-slate-200 bg-white text-slate-700 cursor-pointer focus:ring-1 focus:ring-brand-500">
                                            <option value="pendiente" <?= $inv['status'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                            <option value="pagada" <?= $inv['status'] === 'pagada' ? 'selected' : '' ?>>Pagada</option>
                                            <option value="anulada" <?= $inv['status'] === 'anulada' ? 'selected' : '' ?>>Anulada</option>
                                        </select>
                                        <button onclick="deleteInvoice(<?= $inv['id'] ?>, <?= $inv['project_id'] ?>)" class="p-1 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" title="Eliminar factura">
                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                        </button>
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

<!-- Modal: Crear Nueva Factura -->
<div id="create-invoice-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden transition-opacity">
    <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden transform transition-all">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center">
                    <i data-lucide="receipt" class="w-4 h-4"></i>
                </div>
                <h3 class="font-display font-bold text-slate-800 text-base">Emitir Factura / Cobro</h3>
            </div>
            <button type="button" onclick="closeModal('create-invoice-modal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <form id="create-invoice-form" onsubmit="handleCreateInvoice(event)" class="p-6 space-y-4">
            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Proyecto Destino *</label>
                <select id="inv-project-id" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:ring-1 focus:ring-brand-500 bg-white">
                    <option value="">Selecciona un proyecto...</option>
                    <?php foreach ($projectsList as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">N° Factura *</label>
                    <input type="text" id="inv-number" required placeholder="INV-2026-001" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm font-mono focus:ring-1 focus:ring-brand-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Monto *</label>
                    <input type="number" step="0.01" min="0" id="inv-amount" required placeholder="0.00" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm font-bold focus:ring-1 focus:ring-brand-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Concepto o Hito *</label>
                <input type="text" id="inv-concept" required placeholder="Ej: Anticipo 50% inicio de proyecto" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm focus:ring-1 focus:ring-brand-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Fecha Vencimiento</label>
                    <input type="date" id="inv-due-date" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm focus:ring-1 focus:ring-brand-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Estado Inicial</label>
                    <select id="inv-status" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm focus:ring-1 focus:ring-brand-500 bg-white">
                        <option value="pendiente" selected>Pendiente de Pago</option>
                        <option value="pagada">Pagada</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Notas adicionales (Opcional)</label>
                <textarea id="inv-notes" rows="2" placeholder="Método de pago, cuenta de transferencia o detalles del hito..." class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm focus:ring-1 focus:ring-brand-500"></textarea>
            </div>

            <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('create-invoice-modal')" class="px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100 transition">Cancelar</button>
                <button type="submit" id="btn-submit-inv" class="px-4 py-1.5 rounded-xl text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-2xs transition">Emitir Factura</button>
            </div>
        </form>
    </div>
</div>

<script>
async function handleCreateInvoice(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-submit-inv');
    btn.disabled = true;
    btn.innerText = 'Guardando...';

    const projectId = document.getElementById('inv-project-id').value;
    const invNumber = document.getElementById('inv-number').value.trim();
    const amount = document.getElementById('inv-amount').value;
    const concept = document.getElementById('inv-concept').value.trim();
    const dueDate = document.getElementById('inv-due-date').value;
    const status = document.getElementById('inv-status').value;
    const notes = document.getElementById('inv-notes').value.trim();

    const fd = new FormData();
    fd.append('action', 'create');
    fd.append('csrf_token', '<?= csrf_token() ?>');
    fd.append('project_id', projectId);
    fd.append('invoice_number', invNumber);
    fd.append('amount', amount);
    fd.append('concept', concept);
    fd.append('due_date', dueDate);
    fd.append('status', status);
    fd.append('notes', notes);

    try {
        const res = await fetch('api/invoices.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Error al emitir factura');
        }
    } catch (err) {
        alert('Error al conectar con el servidor');
    } finally {
        btn.disabled = false;
        btn.innerText = 'Emitir Factura';
    }
}

async function changeInvoiceStatus(invoiceId, projectId, newStatus) {
    const fd = new FormData();
    fd.append('action', 'update_status');
    fd.append('csrf_token', '<?= csrf_token() ?>');
    fd.append('project_id', projectId);
    fd.append('invoice_id', invoiceId);
    fd.append('status', newStatus);

    try {
        const res = await fetch('api/invoices.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'Error al actualizar estado');
        }
    } catch (err) {
        alert('Error de conexión');
    }
}

async function deleteInvoice(invoiceId, projectId) {
    if (!confirm('¿Deseas eliminar permanentemente esta factura?')) return;

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('csrf_token', '<?= csrf_token() ?>');
    fd.append('project_id', projectId);
    fd.append('invoice_id', invoiceId);

    try {
        const res = await fetch('api/invoices.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const row = document.getElementById('inv-row-' + invoiceId);
            if (row) row.remove();
        } else {
            alert(data.message || 'Error al eliminar factura');
        }
    } catch (err) {
        alert('Error de conexión');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
