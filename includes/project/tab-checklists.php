<?php
// includes/project/tab-checklists.php - Pestaña Checklists.
// Parcial de project-view.php: usa las variables preparadas en esa página.
?>
        <!-- ==================== TAB 2: CHECKLISTS ==================== -->
        <?php if (!$isClient): ?>
        <section id="tab-checklists" class="tab-content hidden space-y-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-bold text-base text-slate-900">Checklists</h2>
                    <span class="text-xs text-slate-400"><?= count($checklistsData) ?> listas</span>
                </div>
                <?php if ($canManageChecklists): ?>
                    <button onclick="openModal('create-checklist-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs shadow-xs transition">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                        <span>Nueva Lista</span>
                    </button>
                <?php endif; ?>
            </div>

            <div id="checklists-empty-state" class="bg-white rounded-2xl border border-dashed border-slate-200 p-12 text-center <?= empty($checklistsData) ? '' : 'hidden' ?>">
                <div class="w-12 h-12 bg-indigo-50 text-brand-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="list-checks" class="w-6 h-6"></i>
                </div>
                <h3 class="text-sm font-semibold text-slate-800">No hay listas de verificación</h3>
                <p class="text-xs text-slate-500 mt-1 mb-4">Crea un checklist para asegurar el cumplimiento de requisitos de calidad.</p>
                <?php if ($canManageChecklists): ?>
                    <button onclick="openModal('create-checklist-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 text-white text-xs font-semibold rounded-lg shadow-xs">
                        + Crear Checklist
                    </button>
                <?php endif; ?>
            </div>

            <div id="checklists-container" class="grid grid-cols-1 md:grid-cols-2 gap-5 <?= empty($checklistsData) ? 'hidden' : '' ?>">
                <?php foreach ($checklistsData as $cData): ?>
                    <?php $chk = $cData['checklist']; ?>
                    <div id="checklist-card-<?= $chk['id'] ?>" class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-display font-bold text-slate-900 text-sm sm:text-base"><?= e($chk['title']) ?></h3>
                                
                                <!-- Vinculación con Tarea Principal -->
                                <?php if (!empty($chk['task_id']) && !empty($chk['task_title'])): ?>
                                    <button onclick="openTaskDetailModal(<?= $chk['task_id'] ?>)" class="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 bg-brand-50 px-2 py-0.5 rounded-md hover:bg-brand-100 transition mt-1">
                                        <i data-lucide="check-square" class="w-3 h-3"></i>
                                        <span>Tarea: <?= e($chk['task_title']) ?></span>
                                    </button>
                                <?php endif; ?>

                                <p id="chk-progress-text-<?= $chk['id'] ?>" class="text-[11px] text-slate-400 mt-1">
                                    <span class="chk-completed"><?= $cData['completed'] ?></span> de <span class="chk-total"><?= $cData['total'] ?></span> pasos completados (<span class="chk-pct"><?= $cData['percentage'] ?></span>%)
                                </p>
                            </div>
                            <?php if ($canManageChecklists): ?>
                                <button onclick="deleteChecklist(<?= $chk['id'] ?>)" class="text-slate-400 hover:text-rose-600 p-1 rounded transition" title="Eliminar Checklist">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            <?php endif; ?>
                        </div>

                        <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                            <div id="chk-bar-<?= $chk['id'] ?>" class="h-full bg-emerald-500 rounded-full transition-all duration-300" style="width: <?= $cData['percentage'] ?>%;"></div>
                        </div>

                        <div id="chk-items-list-<?= $chk['id'] ?>" class="space-y-1.5">
                            <?php foreach ($cData['items'] as $item): ?>
                                <div id="chk-item-<?= $item['id'] ?>" class="flex items-center justify-between p-2 rounded-xl bg-slate-50/60 hover:bg-slate-100/70 border border-slate-100 transition group">
                                    <label class="flex items-center gap-2.5 <?= $canManageChecklists ? 'cursor-pointer' : 'cursor-not-allowed' ?> flex-1 min-w-0">
                                        <?php if ($canManageChecklists): ?>
                                            <input type="checkbox" onchange="toggleChecklistItemAjax(<?= $item['id'] ?>, <?= $chk['id'] ?>, this)" <?= $item['is_completed'] ? 'checked' : '' ?> class="w-4 h-4 rounded text-brand-600 focus:ring-brand-500 border-slate-300 transition">
                                        <?php else: ?>
                                            <input type="checkbox" disabled <?= $item['is_completed'] ? 'checked' : '' ?> class="w-4 h-4 rounded text-slate-300 border-slate-200">
                                        <?php endif; ?>
                                        <span class="text-xs text-slate-700 truncate <?= $item['is_completed'] ? 'line-through text-slate-400' : '' ?>" id="item-title-<?= $item['id'] ?>">
                                            <?= e($item['title']) ?>
                                        </span>
                                    </label>
                                    <?php if ($canManageChecklists): ?>
                                        <button onclick="deleteChecklistItem(<?= $item['id'] ?>, <?= $chk['id'] ?>)" class="opacity-0 group-hover:opacity-100 text-slate-400 hover:text-rose-600 p-1 rounded transition">
                                            <i data-lucide="x" class="w-3.5 h-3.5"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($canManageChecklists): ?>
                            <form onsubmit="addChecklistItemAjax(event, <?= $chk['id'] ?>)" class="flex items-center gap-2 pt-2 border-t border-slate-100">
                                <input type="text" id="new-item-input-<?= $chk['id'] ?>" required placeholder="+ Agregar un paso..." class="flex-1 px-3 py-1.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-brand-500">
                                <button type="submit" class="px-3 py-1.5 bg-slate-900 text-white rounded-lg text-xs font-semibold hover:bg-slate-800 transition">Añadir</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
