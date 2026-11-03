<?php
// includes/project/modal-task-detail.php - Modal de detalle y edición de tarea.
// Parcial de project-view.php: usa las variables preparadas en esa página.
?>
<!-- 1. MODAL DETALLE Y GESTIÓN DE TAREA (AMPLIADO, ESTRUCTURA INTEGRADA CON DETALLE PERMANENTE + PESTAÑAS DE ACTIVIDAD) -->
<div id="task-detail-modal" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs hidden">
    <div class="bg-white rounded-3xl max-w-5xl w-full shadow-2xl border border-slate-100 overflow-hidden flex flex-col max-h-[92vh]">
        <!-- Cabecera del Detalle -->
        <div class="px-6 py-3.5 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
            <div class="flex items-center gap-2.5">
                <span id="modal-task-priority" class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-0.5 rounded-md border"></span>
                <span id="modal-task-status-badge" class="text-xs font-semibold px-2.5 py-0.5 rounded-full capitalize"></span>
            </div>
            <div class="flex items-center gap-2">
                <?php if (!$isClient && canEditTasks()): ?>
                <button type="button" id="btn-toggle-edit-task" onclick="toggleTaskEditMode()" class="px-3.5 py-1.5 rounded-xl text-xs font-semibold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 transition flex items-center gap-1.5 shadow-2xs">
                    <i data-lucide="edit-3" class="w-3.5 h-3.5 text-brand-600"></i>
                    <span id="btn-toggle-edit-task-text">Editar Tarea</span>
                </button>
                <?php endif; ?>
                <button type="button" onclick="closeModal('task-detail-modal')" class="text-slate-400 hover:text-slate-600 p-1.5 hover:bg-slate-100 rounded-lg transition">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
        </div>

        <!-- Skeleton Loader para Detalle de Tarea -->
        <div id="task-overview-skeleton" class="p-6 space-y-6 animate-pulse">
            <div>
                <div class="h-7 bg-slate-200 rounded-xl w-3/4 mb-3"></div>
                <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 space-y-2">
                    <div class="h-3 bg-slate-200 rounded w-20"></div>
                    <div class="h-4 bg-slate-200 rounded w-full"></div>
                    <div class="h-4 bg-slate-200 rounded w-4/5"></div>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-100 space-y-2">
                    <div class="h-2.5 bg-slate-200 rounded w-16"></div>
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full bg-slate-200"></div>
                        <div class="h-4 bg-slate-200 rounded w-24"></div>
                    </div>
                </div>
                <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-100 space-y-2">
                    <div class="h-2.5 bg-slate-200 rounded w-16"></div>
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full bg-slate-200"></div>
                        <div class="h-4 bg-slate-200 rounded w-20"></div>
                    </div>
                </div>
                <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-100 space-y-2">
                    <div class="h-2.5 bg-slate-200 rounded w-16"></div>
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full bg-slate-200"></div>
                        <div class="h-4 bg-slate-200 rounded w-20"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cuerpo del Modal (Modo Vista Real) -->
        <div id="task-overview-view" class="p-6 overflow-y-auto space-y-6 flex-1 bg-white hidden">

            <!-- Modo Edición Formulario (Desplegable) -->
            <form id="task-edit-form" onsubmit="submitTaskEditAjax(event)" class="hidden p-5 rounded-2xl bg-slate-50/90 border border-slate-200/90 space-y-4 shadow-2xs">
                <div class="flex items-center justify-between pb-2 border-b border-slate-200">
                    <span class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <i data-lucide="edit-3" class="w-3.5 h-3.5 text-brand-600"></i>
                        <span>Editar Información Principal de la Tarea</span>
                    </span>
                    <button type="button" onclick="toggleTaskEditMode(false)" class="text-xs text-slate-400 hover:text-slate-600">✕ Cancelar</button>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Título de la Tarea *</label>
                    <input type="text" id="edit-task-title-input" required class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-1 focus:ring-brand-500 bg-white shadow-2xs">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Descripción</label>
                    <textarea id="edit-task-desc-input" rows="3" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:ring-1 focus:ring-brand-500 bg-white leading-relaxed shadow-2xs"></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Prioridad</label>
                        <select id="edit-task-prio-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                            <option value="baja">Baja</option>
                            <option value="media">Media</option>
                            <option value="alta">Alta</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Estado</label>
                        <select id="edit-task-status-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                            <option value="pendiente">Por Hacer</option>
                            <option value="en_progreso">En Progreso</option>
                            <option value="completada">Completada</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Fecha Límite</label>
                        <input type="date" id="edit-task-date-input" class="w-full px-3 py-1.5 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 uppercase mb-1">Asignar a</label>
                        <select id="edit-task-assigned-input" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500 bg-white">
                            <option value="">Sin asignar</option>
                            <?php foreach ($collaborators as $col): ?>
                                <option value="<?= (int)$col['id'] ?>"><?= e($col['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end gap-2.5 pt-2 border-t border-slate-200">
                    <button type="button" onclick="toggleTaskEditMode(false)" class="px-3.5 py-1.5 rounded-xl text-xs font-medium text-slate-600 hover:bg-slate-200 transition">Cancelar</button>
                    <button type="submit" id="btn-save-task-edit" class="px-4 py-1.5 rounded-xl text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-2xs transition">Guardar Cambios</button>
                </div>
            </form>

            <!-- SECCIÓN PRINCIPAL: Título, Descripción y Atributos (Siempre Visible) -->
            <div id="task-info-display" class="space-y-4">
                <div>
                    <h2 id="modal-task-title" class="font-display font-bold text-xl sm:text-2xl text-slate-900 leading-tight"></h2>
                    <div class="mt-3 p-4 rounded-2xl bg-slate-50/70 border border-slate-100">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Descripción de la Tarea</span>
                        <p id="modal-task-description" class="text-xs sm:text-sm text-slate-600 leading-relaxed whitespace-pre-line"></p>
                    </div>
                </div>

                <!-- Cuadrícula de Atributos Principales -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                    <div class="p-3.5 rounded-2xl bg-slate-50/60 border border-slate-100 flex items-center gap-3">
                        <div class="w-8 h-8 rounded-xl bg-brand-50 text-brand-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                            <i data-lucide="user" class="w-4 h-4"></i>
                        </div>
                        <div class="min-w-0">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block leading-tight">Responsable</span>
                            <span id="modal-task-assigned" class="text-xs font-semibold text-slate-800 truncate block mt-0.5"></span>
                        </div>
                    </div>

                    <div class="p-3.5 rounded-2xl bg-slate-50/60 border border-slate-100 flex items-center gap-3">
                        <div class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                            <i data-lucide="calendar" class="w-4 h-4"></i>
                        </div>
                        <div class="min-w-0">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block leading-tight">Fecha Límite</span>
                            <span id="modal-task-duedate" class="text-xs font-semibold text-slate-800 truncate block mt-0.5"></span>
                        </div>
                    </div>

                    <div class="p-3.5 rounded-2xl bg-slate-50/60 border border-slate-100 flex items-center gap-3">
                        <div class="w-8 h-8 rounded-xl bg-amber-50 text-amber-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                            <i data-lucide="activity" class="w-4 h-4"></i>
                        </div>
                        <div class="min-w-0">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block leading-tight">Estado Actual</span>
                            <span id="modal-task-status-text" class="text-xs font-semibold text-slate-800 capitalize truncate block mt-0.5"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN INFERIOR: TABS DE ACTIVIDADES (Comentarios, Checklists, Adjuntos) -->
            <?php if (!$isClient): ?>
            <div class="pt-3 border-t border-slate-100 space-y-4">
                <!-- Pestañas de la Tarea -->
                <div class="flex items-center gap-6 border-b border-slate-100 text-xs font-medium">
                    <button type="button" onclick="switchTaskDetailTab('comments')" id="task-tab-btn-comments" class="task-detail-tab-btn pb-2.5 font-bold text-brand-600 border-b-2 border-brand-600 flex items-center gap-1.5 transition">
                        <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
                        <span>Comentarios</span>
                        <span id="modal-task-comments-badge-count" class="bg-brand-50 text-brand-700 px-1.5 py-0.2 rounded-full text-[10px] font-bold">0</span>
                    </button>
                    <button type="button" onclick="switchTaskDetailTab('checklists')" id="task-tab-btn-checklists" class="task-detail-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5 transition">
                        <i data-lucide="list-checks" class="w-3.5 h-3.5"></i>
                        <span>Checklists</span>
                        <span id="modal-task-chk-count" class="bg-slate-100 px-1.5 py-0.2 rounded-full text-[10px] font-bold">0</span>
                    </button>
                    <button type="button" onclick="switchTaskDetailTab('adjuntos')" id="task-tab-btn-adjuntos" class="task-detail-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5 transition">
                        <i data-lucide="paperclip" class="w-3.5 h-3.5"></i>
                        <span>Adjuntos</span>
                        <span id="modal-task-adj-count" class="bg-slate-100 px-1.5 py-0.2 rounded-full text-[10px] font-bold">0</span>
                    </button>
                </div>

                <!-- SUBSECCIÓN: COMENTARIOS DE LA TAREA -->
                <div id="task-detail-sec-comments" class="task-detail-sub-section space-y-4">
                    <form onsubmit="submitTaskCommentAjax(event)" class="space-y-3 bg-slate-50/70 p-3.5 rounded-2xl border border-slate-200/70">
                        <input type="hidden" id="modal-task-id-input">
                        <textarea id="modal-task-comment-input" rows="2" placeholder="Escribe un comentario en esta tarea o pega una captura con Ctrl+V..." class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:ring-1 focus:ring-brand-500 bg-white leading-relaxed"></textarea>
                        
                        <div id="modal-comment-image-preview" class="hidden relative inline-block border border-slate-200 rounded-lg p-1 bg-white">
                            <img id="modal-comment-preview-img" src="" alt="Vista previa" class="h-20 max-w-xs object-cover rounded">
                            <button type="button" onclick="clearModalCommentImage()" class="absolute -top-1.5 -right-1.5 w-5 h-5 rounded-full bg-rose-600 text-white flex items-center justify-center text-[10px]">✕</button>
                        </div>

                        <input type="file" id="modal-comment-image-file" accept="image/*" class="hidden" onchange="handleModalCommentImageSelect(this)">

                        <div class="flex items-center justify-between pt-1">
                            <button type="button" onclick="document.getElementById('modal-comment-image-file').click()" class="inline-flex items-center gap-1.5 text-xs text-slate-500 hover:text-brand-600">
                                <i data-lucide="image" class="w-3.5 h-3.5"></i>
                                <span>Adjuntar imagen (o Ctrl+V)</span>
                            </button>
                            <button type="submit" id="modal-submit-comment-btn" class="px-3.5 py-1.5 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs rounded-xl shadow-xs transition">
                                Enviar Comentario
                            </button>
                        </div>
                    </form>

                    <div id="modal-task-comments-feed" class="space-y-3"></div>
                </div>

                <!-- SUBSECCIÓN: CHECKLISTS DE LA TAREA -->
                <div id="task-detail-sec-checklists" class="task-detail-sub-section hidden space-y-4">
                    <form onsubmit="submitTaskChecklistAjax(event)" class="flex items-center gap-2">
                        <input type="text" id="task-new-chk-title" required placeholder="+ Crear nueva lista de pasos para esta tarea..." class="flex-1 px-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-brand-500">
                        <button type="submit" class="px-3 py-2 bg-brand-600 text-white text-xs font-semibold rounded-xl hover:bg-brand-700 transition">Crear Lista</button>
                    </form>

                    <div id="modal-task-checklists-container" class="space-y-4"></div>
                </div>

                <!-- SUBSECCIÓN: ADJUNTOS DE LA TAREA -->
                <div id="task-detail-sec-adjuntos" class="task-detail-sub-section hidden space-y-4">
                    <!-- Barra superior de acción rápida minimalista -->
                    <div class="flex items-center justify-between pb-1 border-b border-slate-100">
                        <span class="text-xs font-semibold text-slate-700">Archivos y Enlaces de esta tarea</span>
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="toggleTaskAttachmentForm('file')" id="btn-toggle-task-file" class="px-2.5 py-1 text-xs font-medium text-slate-600 hover:text-brand-600 bg-slate-100 hover:bg-brand-50 rounded-lg flex items-center gap-1 transition">
                                <i data-lucide="upload-cloud" class="w-3.5 h-3.5"></i>
                                <span>Subir Archivo</span>
                            </button>
                            <button type="button" onclick="toggleTaskAttachmentForm('link')" id="btn-toggle-task-link" class="px-2.5 py-1 text-xs font-medium text-slate-600 hover:text-brand-600 bg-slate-100 hover:bg-brand-50 rounded-lg flex items-center gap-1 transition">
                                <i data-lucide="link" class="w-3.5 h-3.5"></i>
                                <span>Añadir Enlace</span>
                            </button>
                        </div>
                    </div>

                    <!-- Formulario colapsable para Subir Archivo -->
                    <form id="form-task-add-file" onsubmit="submitTaskFileAjax(event)" class="hidden p-3 bg-slate-50/80 rounded-xl border border-slate-200/80 space-y-2.5 transition-all">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-800 flex items-center gap-1.5">
                                <i data-lucide="file-up" class="w-3.5 h-3.5 text-brand-600"></i>
                                <span>Subir nuevo archivo</span>
                            </span>
                            <button type="button" onclick="toggleTaskAttachmentForm('file')" class="text-slate-400 hover:text-slate-600 text-xs">✕</button>
                        </div>
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                            <input type="file" id="task-file-input" required class="flex-1 text-xs text-slate-500 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-white file:text-brand-700 file:border file:border-slate-200 cursor-pointer">
                            <button type="submit" id="task-file-btn" class="px-3.5 py-1.5 bg-brand-600 text-white text-xs font-semibold rounded-lg hover:bg-brand-700 transition flex items-center justify-center gap-1.5 shadow-2xs">
                                <i data-lucide="upload" class="w-3 h-3"></i>
                                <span>Subir</span>
                            </button>
                        </div>
                    </form>

                    <!-- Formulario colapsable para Añadir Enlace -->
                    <form id="form-task-add-link" onsubmit="submitTaskLinkAjax(event)" class="hidden p-3 bg-slate-50/80 rounded-xl border border-slate-200/80 space-y-2.5 transition-all">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-800 flex items-center gap-1.5">
                                <i data-lucide="link-2" class="w-3.5 h-3.5 text-emerald-600"></i>
                                <span>Vincular enlace web</span>
                            </span>
                            <button type="button" onclick="toggleTaskAttachmentForm('link')" class="text-slate-400 hover:text-slate-600 text-xs">✕</button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <input type="text" id="task-link-title" required placeholder="Título (ej: Boceto en Figma)" class="px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white text-xs focus:ring-1 focus:ring-brand-500">
                            <input type="url" id="task-link-url" required placeholder="https://..." class="px-2.5 py-1.5 rounded-lg border border-slate-200 bg-white text-xs focus:ring-1 focus:ring-brand-500">
                        </div>
                        <div class="flex justify-end pt-1">
                            <button type="submit" class="px-3.5 py-1.5 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition flex items-center gap-1.5 shadow-2xs">
                                <i data-lucide="plus" class="w-3 h-3"></i>
                                <span>Guardar Enlace</span>
                            </button>
                        </div>
                    </form>

                    <!-- Contenedores limpios y organizados para Archivos y Enlaces -->
                    <div class="space-y-3 pt-1">
                        <div>
                            <div class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Archivos</div>
                            <div id="modal-task-files-list" class="space-y-1.5"></div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Enlaces</div>
                            <div id="modal-task-links-list" class="space-y-1.5"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
