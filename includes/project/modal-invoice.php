<?php
// includes/project/modal-invoice.php - Modal de nueva factura / hito de cobro.
// Parcial de project-view.php: usa las variables preparadas en esa página.
?>
<!-- Modal: Emitir Nueva Factura / Hito de Cobro -->
<div id="create-invoice-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
    <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-display font-bold text-slate-800 text-base flex items-center gap-2">
                <i data-lucide="receipt" class="w-4 h-4 text-brand-600"></i>
                <span>Emitir Factura / Cobro</span>
            </h3>
            <button type="button" onclick="closeModal('create-invoice-modal')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form onsubmit="submitCreateInvoiceAjax(event)" class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">N° Factura</label>
                    <input type="text" id="inv-number-input" placeholder="Auto si se omite" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Moneda</label>
                    <select id="inv-currency-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white font-mono">
                        <option value="USD" <?= $projectCurrency === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                        <option value="EUR" <?= $projectCurrency === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                        <option value="MXN" <?= $projectCurrency === 'MXN' ? 'selected' : '' ?>>MXN ($)</option>
                        <option value="PEN" <?= $projectCurrency === 'PEN' ? 'selected' : '' ?>>PEN (S/)</option>
                        <option value="COP" <?= $projectCurrency === 'COP' ? 'selected' : '' ?>>COP ($)</option>
                        <option value="CLP" <?= $projectCurrency === 'CLP' ? 'selected' : '' ?>>CLP ($)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Concepto o Hito de Cobro *</label>
                <input type="text" id="inv-concept-input" required placeholder="Ej: Anticipo inicial 50% o Entrega Fase 1" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-brand-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Importe *</label>
                    <input type="number" step="0.01" min="0.01" id="inv-amount-input" required placeholder="0.00" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white font-semibold">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Fecha Vencimiento</label>
                    <input type="date" id="inv-due-date-input" class="w-full px-3 py-1.5 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Estado</label>
                    <select id="inv-status-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                        <option value="pendiente" selected>Por cobrar (Pendiente)</option>
                        <option value="pagada">Pagada / Cobrada</option>
                        <option value="anulada">Anulada</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Notas / Referencia</label>
                    <input type="text" id="inv-notes-input" placeholder="Transferencia, cheque, etc." class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('create-invoice-modal')" class="px-4 py-2 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100">Cancelar</button>
                <button type="submit" id="btn-submit-invoice" class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-sm flex items-center gap-1.5">
                    <i data-lucide="check" class="w-4 h-4"></i>
                    <span>Guardar Factura</span>
                </button>
            </div>
        </form>
    </div>
</div>
