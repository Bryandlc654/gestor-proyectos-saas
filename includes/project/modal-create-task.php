<?php
// includes/project/modal-create-task.php - Modal de creación de tarea.
// Parcial de project-view.php: usa las variables preparadas en esa página.
?>
<!-- 2. Modal: Crear Tarea (Ampliación con pestañas internas para Comentarios, Checklists y Adjuntos directos) -->
<div id="create-task-modal" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs hidden">
    <div class="bg-white rounded-3xl max-w-4xl w-full shadow-2xl border border-slate-100 overflow-hidden flex flex-col max-h-[92vh]">
        <!-- Cabecera Crear Tarea -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/60">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-brand-500"></span>
                <h3 class="font-display font-bold text-slate-900 text-base sm:text-lg">Nueva Tarea / Actividad</h3>
            </div>
            <button type="button" onclick="closeModal('create-task-modal')" class="text-slate-400 hover:text-slate-600 p-1.5 hover:bg-slate-100 rounded-lg transition">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <!-- Pestañas Superiores de Creación de Tarea -->
        <div class="px-6 pt-3 border-b border-slate-100 flex items-center gap-6 bg-white text-xs font-medium">
            <button type="button" onclick="switchCreateTaskTab('general')" id="create-task-tab-btn-general" class="create-task-tab-btn pb-2.5 font-bold text-brand-600 border-b-2 border-brand-600 flex items-center gap-1.5">
                <i data-lucide="layout" class="w-3.5 h-3.5"></i>
                <span>Información Principal *</span>
            </button>
            <button type="button" onclick="switchCreateTaskTab('comments')" id="create-task-tab-btn-comments" class="create-task-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5">
                <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
                <span>Comentario Inicial</span>
                <span id="create-task-comments-indicator" class="w-1.5 h-1.5 rounded-full bg-brand-500 hidden"></span>
            </button>
            <button type="button" onclick="switchCreateTaskTab('checklists')" id="create-task-tab-btn-checklists" class="create-task-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5">
                <i data-lucide="list-checks" class="w-3.5 h-3.5"></i>
                <span>Checklist Inicial</span>
                <span id="create-task-checklist-badge" class="bg-slate-100 px-1.5 py-0.2 rounded-full text-[10px] hidden font-bold">0</span>
            </button>
            <button type="button" onclick="switchCreateTaskTab('adjuntos')" id="create-task-tab-btn-adjuntos" class="create-task-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5">
                <i data-lucide="paperclip" class="w-3.5 h-3.5"></i>
                <span>Adjuntos</span>
                <span id="create-task-adjuntos-indicator" class="w-1.5 h-1.5 rounded-full bg-emerald-500 hidden"></span>
            </button>
        </div>

        <form id="create-task-form" onsubmit="submitTaskAjax(event)" class="flex flex-col flex-1 overflow-hidden">
            <div class="p-6 overflow-y-auto space-y-5 flex-1">
                <!-- TAB CREACIÓN 1: GENERAL -->
                <div id="create-task-sec-general" class="create-task-section space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Título de la Tarea *</label>
                        <input type="text" id="task-title-input" required placeholder="Ej: Diseñar componentes del carrito y checkout" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-brand-500 bg-white shadow-2xs">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Descripción Detallada</label>
                        <textarea id="task-desc-input" rows="4" placeholder="Especificaciones, requerimientos y detalles para completar la tarea..." class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:ring-1 focus:ring-brand-500 bg-white leading-relaxed shadow-2xs"></textarea>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Prioridad</label>
                            <select id="task-prio-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                                <option value="baja">Baja</option>
                                <option value="media" selected>Media</option>
                                <option value="alta">Alta</option>
                                <option value="urgente">Urgente</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Fecha de Entrega</label>
                            <input type="date" id="task-date-input" class="w-full px-3 py-1.5 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase mb-1.5">Asignar a Colaborador</label>
                            <select id="task-assigned-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                                <option value="">Sin asignar</option>
                                <?php foreach ($collaborators as $col): ?>
                                    <option value="<?= (int)$col['id'] ?>"><?= e($col['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- TAB CREACIÓN 2: COMENTARIO INICIAL -->
                <div id="create-task-sec-comments" class="create-task-section hidden space-y-4">
                    <div class="p-3.5 rounded-2xl bg-slate-50/70 border border-slate-200/70 space-y-3">
                        <label class="block text-xs font-semibold text-slate-800">Primer comentario o notas de arranque (Opcional)</label>
                        <textarea id="create-task-comment-text" rows="3" placeholder="Deja una nota de inicio, instrucciones para el responsable o pega una captura..." class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:ring-1 focus:ring-brand-500 bg-white leading-relaxed"></textarea>
                        
                        <div id="create-task-comment-preview-box" class="hidden relative inline-block border border-slate-200 rounded-lg p-1 bg-white">
                            <img id="create-task-comment-preview-img" src="" alt="Vista previa" class="h-20 max-w-xs object-cover rounded">
                            <button type="button" onclick="clearCreateTaskCommentImage()" class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-rose-600 text-white flex items-center justify-center text-[10px]">✕</button>
                        </div>

                        <input type="file" id="create-task-comment-image-file" accept="image/*" class="hidden" onchange="handleCreateTaskCommentImageSelect(this)">
                        
                        <div class="flex items-center justify-between">
                            <button type="button" onclick="document.getElementById('create-task-comment-image-file').click()" class="inline-flex items-center gap-1.5 text-xs text-slate-500 hover:text-brand-600">
                                <i data-lucide="image" class="w-3.5 h-3.5"></i>
                                <span>Adjuntar imagen (o Ctrl+V en el texto)</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TAB CREACIÓN 3: CHECKLIST INICIAL -->
                <div id="create-task-sec-checklists" class="create-task-section hidden space-y-4">
                    <div class="p-3.5 rounded-2xl bg-slate-50/70 border border-slate-200/70 space-y-3">
                        <label class="block text-xs font-semibold text-slate-800">Nombre de la Lista de Pasos (Opcional)</label>
                        <input type="text" id="create-task-chk-title" placeholder="Ej: Pasos de implementación inicial" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                        
                        <label class="block text-xs font-semibold text-slate-700 pt-1">Pasos o Tareas secundarias (Uno por línea)</label>
                        <textarea id="create-task-chk-items" rows="3" placeholder="Paso 1: Definir variables&#10;Paso 2: Maquetar interfaz&#10;Paso 3: Pruebas responsive" class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white font-mono"></textarea>
                    </div>
                </div>

                <!-- TAB CREACIÓN 4: ADJUNTOS INICIALES -->
                <div id="create-task-sec-adjuntos" class="create-task-section hidden space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Subir Archivo inicial -->
                        <div class="p-3.5 rounded-2xl bg-slate-50/70 border border-slate-200/70 space-y-2.5">
                            <span class="text-xs font-semibold text-slate-800 flex items-center gap-1.5">
                                <i data-lucide="file-up" class="w-3.5 h-3.5 text-brand-600"></i>
                                <span>Adjuntar un Archivo</span>
                            </span>
                            <input type="file" id="create-task-file-input" class="w-full text-xs text-slate-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-white file:text-brand-700 file:border file:border-slate-200 cursor-pointer">
                            <p class="text-[11px] text-slate-400">Se subirá y vinculará automáticamente al crear la tarea.</p>
                        </div>

                        <!-- Enlace Web inicial -->
                        <div class="p-3.5 rounded-2xl bg-slate-50/70 border border-slate-200/70 space-y-2.5">
                            <span class="text-xs font-semibold text-slate-800 flex items-center gap-1.5">
                                <i data-lucide="link" class="w-3.5 h-3.5 text-emerald-600"></i>
                                <span>Vincular un Enlace</span>
                            </span>
                            <input type="text" id="create-task-link-title" placeholder="Título (ej: Especificación)" class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs bg-white">
                            <input type="url" id="create-task-link-url" placeholder="https://..." class="w-full px-2.5 py-1.5 rounded-lg border border-slate-200 text-xs bg-white">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer con Acciones -->
            <div class="px-6 py-3.5 border-t border-slate-100 flex items-center justify-end gap-2 bg-slate-50/60">
                <button type="button" onclick="closeModal('create-task-modal')" class="px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-200/80 transition">Cancelar</button>
                <button type="submit" id="submit-task-btn" class="px-4 py-1.5 rounded-xl text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-2xs transition flex items-center gap-1.5">
                    <i data-lucide="check" class="w-3.5 h-3.5"></i>
                    <span>Crear Tarea</span>
                </button>
            </div>
        </form>
    </div>
</div>
