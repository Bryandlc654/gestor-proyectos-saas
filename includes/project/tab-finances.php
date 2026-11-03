<?php
// includes/project/tab-finances.php - Pestaña Costo y facturación (solo admin).
// Parcial de project-view.php: usa las variables preparadas en esa página.
?>
        <!-- ==================== TAB 6: COSTO & FACTURACIÓN (Solo Admin) ==================== -->
        <?php if (isAdmin()): ?>
        <section id="tab-finanzas" class="tab-content hidden space-y-5">
            <!-- Encabezado con Botones de Acción -->
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-bold text-base text-slate-900">Finanzas</h2>
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-mono"><?= e($projectCurrency) ?></span>
                </div>

                <div class="flex items-center gap-2">
                    <?php if ($canManageProject): ?>
                        <button onclick="openModal('edit-budget-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs transition">
                            <i data-lucide="calculator" class="w-3.5 h-3.5 text-slate-500"></i>
                            <span>Costo</span>
                        </button>
                        <button onclick="openModal('create-invoice-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs shadow-xs transition">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            <span>Nueva Factura</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tarjetas de Métricas Financieras -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <!-- 1. Presupuesto / Costo Total -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs space-y-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 block">Costo Total</span>
                    <div class="text-lg sm:text-xl font-display font-bold text-slate-900" id="card-fin-budget">
                        <?= e($projectCurrency) ?> $<?= number_format($projectBudget, 2) ?>
                    </div>
                </div>

                <!-- 2. Total Facturado -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs space-y-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 block">Facturado</span>
                    <div class="text-lg sm:text-xl font-display font-bold text-blue-700" id="card-fin-invoiced">
                        <?= e($projectCurrency) ?> $<?= number_format($totalInvoiced, 2) ?>
                    </div>
                </div>

                <!-- 3. Cobrado / Pagado -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs space-y-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 block">Cobrado</span>
                    <div class="text-lg sm:text-xl font-display font-bold text-emerald-700" id="card-fin-paid">
                        <?= e($projectCurrency) ?> $<?= number_format($totalPaid, 2) ?>
                    </div>
                </div>

                <!-- 4. Facturas Por Cobrar (Pendiente) -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs space-y-1">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 block">Por Cobrar</span>
                    <div class="text-lg sm:text-xl font-display font-bold <?= $totalPending > 0 ? 'text-amber-600' : 'text-slate-900' ?>" id="card-fin-pending">
                        <?= e($projectCurrency) ?> $<?= number_format($totalPending, 2) ?>
                    </div>
                </div>
            </div>

            <!-- Tabla de Facturas y Cobros -->
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="font-display font-bold text-xs sm:text-sm text-slate-900">Historial de Cobros</h3>
                    <span class="text-xs text-slate-400"><?= count($invoices) ?> facturas</span>
                </div>

                <div id="invoices-empty-state" class="p-12 text-center <?= empty($invoices) ? '' : 'hidden' ?>">
                    <div class="w-12 h-12 bg-slate-100 text-slate-400 rounded-2xl flex items-center justify-center mx-auto mb-3">
                        <i data-lucide="receipt" class="w-6 h-6"></i>
                    </div>
                    <h4 class="text-sm font-semibold text-slate-800">No hay facturas registradas</h4>
                    <p class="text-xs text-slate-500 mt-1 mb-4">Emite la primera factura o hito de cobro para este proyecto.</p>
                    <?php if ($canManageProject): ?>
                        <button onclick="openModal('create-invoice-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 text-white text-xs font-semibold rounded-lg shadow-xs">
                            + Emitir Factura
                        </button>
                    <?php endif; ?>
                </div>

                <div id="invoices-table-container" class="overflow-x-auto <?= empty($invoices) ? 'hidden' : '' ?>">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="text-slate-400 font-semibold border-b border-slate-100 uppercase text-[10px] bg-slate-50/50">
                                <th class="py-3 px-4">N° Factura</th>
                                <th class="py-3 px-4">Concepto / Hito</th>
                                <th class="py-3 px-4">Importe</th>
                                <th class="py-3 px-4">Vencimiento</th>
                                <th class="py-3 px-4">Estado</th>
                                <th class="py-3 px-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100" id="invoices-table-body">
                            <?php foreach ($invoices as $inv): ?>
                                <?php
                                    $statusBadge = match($inv['status']) {
                                        'pagada'    => 'bg-emerald-50 text-emerald-700 border-emerald-200/60',
                                        'pendiente' => 'bg-amber-50 text-amber-700 border-amber-200/60',
                                        'anulada'   => 'bg-rose-50 text-rose-700 border-rose-200/60',
                                        default     => 'bg-slate-50 text-slate-700 border-slate-200/60'
                                    };
                                ?>
                                <tr id="invoice-row-<?= $inv['id'] ?>" class="hover:bg-slate-50/60 transition">
                                    <td class="py-3 px-4 font-mono font-bold text-slate-900">
                                        <?= e($inv['invoice_number']) ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-slate-800"><?= e($inv['concept']) ?></div>
                                        <?php if (!empty($inv['notes'])): ?>
                                            <div class="text-[11px] text-slate-400 truncate max-w-xs mt-0.5"><?= e($inv['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 font-bold text-slate-900">
                                        <?= e($inv['currency'] ?: 'USD') ?> $<?= number_format(floatval($inv['amount']), 2) ?>
                                    </td>
                                    <td class="py-3 px-4 text-slate-500">
                                        <?= $inv['due_date'] ? formatDate($inv['due_date']) : '<span class="text-slate-300">-</span>' ?>
                                        <?php if ($inv['status'] === 'pagada' && !empty($inv['paid_at'])): ?>
                                            <div class="text-[10px] text-emerald-600 font-medium">Pagado el <?= formatDate($inv['paid_at']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span id="inv-badge-<?= $inv['id'] ?>" class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider border <?= $statusBadge ?>">
                                            <?= e($inv['status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-right">
                                        <?php if ($canManageProject): ?>
                                            <div class="inline-flex items-center gap-1">
                                                <select onchange="updateInvoiceStatusAjax(<?= $inv['id'] ?>, this.value)" class="text-[11px] font-semibold py-1 px-2 rounded-lg border border-slate-200 bg-white text-slate-700 cursor-pointer focus:ring-1 focus:ring-brand-500">
                                                    <option value="pendiente" <?= $inv['status'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                                    <option value="pagada" <?= $inv['status'] === 'pagada' ? 'selected' : '' ?>>Pagada</option>
                                                    <option value="anulada" <?= $inv['status'] === 'anulada' ? 'selected' : '' ?>>Anulada</option>
                                                </select>
                                                <button onclick="deleteInvoiceAjax(<?= $inv['id'] ?>)" class="p-1 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" title="Eliminar factura">
                                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-[11px] text-slate-400 italic">Solo lectura</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>
