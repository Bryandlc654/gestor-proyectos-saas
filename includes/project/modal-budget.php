<?php
// includes/project/modal-budget.php - Modal de presupuesto del proyecto.
// Parcial de project-view.php: usa las variables preparadas en esa página.
?>
<!-- Modal: Editar Presupuesto / Costo Total del Proyecto -->
<div id="edit-budget-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs hidden">
    <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="font-display font-bold text-slate-800 text-base flex items-center gap-2">
                <i data-lucide="coins" class="w-4 h-4 text-brand-600"></i>
                <span>Definir Costo Total del Proyecto</span>
            </h3>
            <button type="button" onclick="closeModal('edit-budget-modal')" class="text-slate-400 hover:text-slate-600">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form onsubmit="submitUpdateBudgetAjax(event)" class="p-6 space-y-4">
            <p class="text-xs text-slate-500">
                Indica el costo o presupuesto total acordado con el cliente para este proyecto.
            </p>
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Costo Total *</label>
                    <input type="number" step="0.01" min="0" id="budget-amount-input" required value="<?= htmlspecialchars((string)$projectBudget) ?>" placeholder="0.00" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-brand-500 font-bold">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Moneda</label>
                    <select id="budget-currency-input" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white font-mono">
                        <option value="USD" <?= $projectCurrency === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                        <option value="EUR" <?= $projectCurrency === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                        <option value="MXN" <?= $projectCurrency === 'MXN' ? 'selected' : '' ?>>MXN ($)</option>
                        <option value="PEN" <?= $projectCurrency === 'PEN' ? 'selected' : '' ?>>PEN (S/)</option>
                        <option value="COP" <?= $projectCurrency === 'COP' ? 'selected' : '' ?>>COP ($)</option>
                        <option value="CLP" <?= $projectCurrency === 'CLP' ? 'selected' : '' ?>>CLP ($)</option>
                    </select>
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-3 border-t border-slate-100">
                <button type="button" onclick="closeModal('edit-budget-modal')" class="px-4 py-2 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-100">Cancelar</button>
                <button type="submit" id="btn-submit-budget" class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-sm">Guardar Costo</button>
            </div>
        </form>
    </div>
</div>
