// assets/js/project-view.js - Interacción AJAX de la vista de proyecto (project-view.php)
// Los datos del servidor llegan en window.FLOWTASKS (definido en la página).

const PROJECT_ID = window.FLOWTASKS.projectId;
const CSRF_TOKEN = window.FLOWTASKS.csrfToken;
let pastedImageBlob = null;
let modalPastedImageBlob = null;
let currentTaskDetailId = null;

// Toast flotante
function showToast(message, type = 'success') {
    const existing = document.getElementById('ajax-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.id = 'ajax-toast';
    const bg = type === 'success' ? 'bg-emerald-600 shadow-emerald-500/20' : 'bg-rose-600 shadow-rose-500/20';
    const icon = type === 'success' ? 'check-circle-2' : 'alert-circle';
    
    toast.className = `fixed top-5 right-5 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg text-white ${bg} transition-all duration-300 transform translate-y-0`;
    toast.innerHTML = `
        <i data-lucide="${icon}" class="w-4 h-4 flex-shrink-0"></i>
        <span class="text-xs sm:text-sm font-medium">${message}</span>
    `;
    document.body.appendChild(toast);
    lucide.createIcons();

    setTimeout(() => {
        toast.classList.add('opacity-0', '-translate-y-2');
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

// Toggle Kanban vs Lista
function setTaskView(view) {
    const kanban = document.getElementById('tasks-kanban-view');
    const list = document.getElementById('tasks-list-view');
    const btnKanban = document.getElementById('view-btn-kanban');
    const btnList = document.getElementById('view-btn-list');

    if (view === 'list') {
        kanban.classList.add('hidden');
        list.classList.remove('hidden');
        btnList.className = "px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 bg-brand-50 text-brand-700 transition";
        btnKanban.className = "px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 text-slate-600 hover:text-slate-900 transition";
    } else {
        list.classList.add('hidden');
        kanban.classList.remove('hidden');
        btnKanban.className = "px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 bg-brand-50 text-brand-700 transition";
        btnList.className = "px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 text-slate-600 hover:text-slate-900 transition";
    }
    lucide.createIcons();
}

// Tabs Principales
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));

    const target = document.getElementById('tab-' + tabId);
    const btn = document.getElementById('tab-btn-' + tabId);
    if (target && btn) {
        target.classList.remove('hidden');
        btn.classList.add('active');
        window.location.hash = tabId;
    }
}

// Tabs Internos del Modal de Detalle de Tarea (Comentarios, Checklists, Adjuntos)
function switchTaskDetailTab(subTabId) {
    document.querySelectorAll('.task-detail-sub-section').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.task-detail-tab-btn').forEach(btn => {
        btn.className = "task-detail-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5 transition";
    });

    const target = document.getElementById('task-detail-sec-' + subTabId);
    const btn = document.getElementById('task-tab-btn-' + subTabId);
    if (target && btn) {
        target.classList.remove('hidden');
        btn.className = "task-detail-tab-btn pb-2.5 font-medium text-slate-900 border-b-2 border-slate-900 flex items-center gap-1.5 transition";
    }
    lucide.createIcons();
}

window.addEventListener('DOMContentLoaded', () => {
    let hash = window.location.hash.replace('#', '');
    if (hash === 'links' || hash === 'files') hash = 'adjuntos';
    if (hash && document.getElementById('tab-' + hash)) {
        switchTab(hash);
    }
});

let currentTaskData = null;
let createTaskCommentBlob = null;

// Toggle modo edición en el modal de ver tarea
function toggleTaskEditMode(forceState = null) {
    const editForm = document.getElementById('task-edit-form');
    const infoDisplay = document.getElementById('task-info-display');
    const btnText = document.getElementById('btn-toggle-edit-task-text');

    const shouldEdit = forceState !== null ? forceState : (editForm ? editForm.classList.contains('hidden') : false);
    if (shouldEdit && editForm) {
        if (infoDisplay) infoDisplay.classList.add('hidden');
        editForm.classList.remove('hidden');
        if (btnText) btnText.innerText = 'Ver Detalle';
        if (currentTaskData) {
            document.getElementById('edit-task-title-input').value = currentTaskData.raw_title || currentTaskData.title || '';
            document.getElementById('edit-task-desc-input').value = currentTaskData.raw_description || currentTaskData.description || '';
            document.getElementById('edit-task-prio-input').value = currentTaskData.priority || 'media';
            document.getElementById('edit-task-status-input').value = currentTaskData.status || 'pendiente';
            document.getElementById('edit-task-date-input').value = currentTaskData.raw_due_date || '';
            document.getElementById('edit-task-assigned-input').value = currentTaskData.raw_assigned_to || '';
        }
    } else {
        if (editForm) editForm.classList.add('hidden');
        if (infoDisplay) infoDisplay.classList.remove('hidden');
        if (btnText) btnText.innerText = 'Editar Tarea';
    }
    lucide.createIcons();
}

// Pestañas del Modal Crear Tarea
function switchCreateTaskTab(tabKey) {
    document.querySelectorAll('.create-task-section').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.create-task-tab-btn').forEach(btn => {
        btn.className = "create-task-tab-btn pb-2.5 text-slate-500 hover:text-slate-800 border-b-2 border-transparent flex items-center gap-1.5";
    });

    const target = document.getElementById('create-task-sec-' + tabKey);
    const btn = document.getElementById('create-task-tab-btn-' + tabKey);
    if (target && btn) {
        target.classList.remove('hidden');
        btn.className = "create-task-tab-btn pb-2.5 font-bold text-brand-600 border-b-2 border-brand-600 flex items-center gap-1.5";
    }
    lucide.createIcons();
}

// Imagen en Comentario inicial al crear tarea (Ctrl+V)
const createTaskCommentText = document.getElementById('create-task-comment-text');
if (createTaskCommentText) {
    createTaskCommentText.addEventListener('paste', (e) => {
        const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
        if (!items) return;
        for (let item of items) {
            if (item.type.indexOf('image') === 0) {
                const blob = item.getAsFile();
                if (blob) {
                    e.preventDefault();
                    setCreateTaskCommentImagePreview(blob);
                    showToast('¡Captura pegada en el comentario inicial!', 'success');
                    break;
                }
            }
        }
    });
}

function handleCreateTaskCommentImageSelect(input) {
    if (input.files && input.files[0]) {
        setCreateTaskCommentImagePreview(input.files[0]);
    }
}

function setCreateTaskCommentImagePreview(fileOrBlob) {
    createTaskCommentBlob = fileOrBlob;
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('create-task-comment-preview-img').src = e.target.result;
        document.getElementById('create-task-comment-preview-box').classList.remove('hidden');
        document.getElementById('create-task-comments-indicator').classList.remove('hidden');
    };
    reader.readAsDataURL(fileOrBlob);
}

function clearCreateTaskCommentImage() {
    createTaskCommentBlob = null;
    document.getElementById('create-task-comment-preview-img').src = '';
    document.getElementById('create-task-comment-preview-box').classList.add('hidden');
    document.getElementById('create-task-comment-image-file').value = '';
    if (!document.getElementById('create-task-comment-text').value.trim()) {
        document.getElementById('create-task-comments-indicator').classList.add('hidden');
    }
}

// Indicadores visuales en pestañas de crear tarea al escribir
document.getElementById('create-task-comment-text')?.addEventListener('input', (e) => {
    const ind = document.getElementById('create-task-comments-indicator');
    if (e.target.value.trim() || createTaskCommentBlob) {
        ind.classList.remove('hidden');
    } else {
        ind.classList.add('hidden');
    }
});

document.getElementById('create-task-chk-items')?.addEventListener('input', (e) => {
    const badge = document.getElementById('create-task-checklist-badge');
    const lines = e.target.value.split('\n').map(l => l.trim()).filter(l => l.length > 0);
    if (lines.length > 0) {
        badge.innerText = lines.length;
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
});

document.getElementById('create-task-file-input')?.addEventListener('change', (e) => {
    const ind = document.getElementById('create-task-adjuntos-indicator');
    if (e.target.files.length > 0 || document.getElementById('create-task-link-url').value.trim()) {
        ind.classList.remove('hidden');
    } else {
        ind.classList.add('hidden');
    }
});

document.getElementById('create-task-link-url')?.addEventListener('input', (e) => {
    const ind = document.getElementById('create-task-adjuntos-indicator');
    if (e.target.value.trim() || document.getElementById('create-task-file-input').files.length > 0) {
        ind.classList.remove('hidden');
    } else {
        ind.classList.add('hidden');
    }
});
async function openTaskDetailModal(taskId) {
    currentTaskDetailId = taskId;
    toggleTaskEditMode(false); // asegurar modo vista inicialmente
    const modalTaskId = document.getElementById('modal-task-id-input');
    if (modalTaskId) modalTaskId.value = taskId;
    
    // Mostrar skeleton de overview y ocultar contenido real mientras carga
    const ovSkeleton = document.getElementById('task-overview-skeleton');
    const ovView = document.getElementById('task-overview-view');
    if (ovSkeleton) ovSkeleton.classList.remove('hidden');
    if (ovView) ovView.classList.add('hidden');

    // Skeletons de comentarios, checklists y adjuntos con animación pulse
    const commentsFeed = document.getElementById('modal-task-comments-feed');
    if (commentsFeed) {
        commentsFeed.innerHTML = `
            <div class="space-y-3 animate-pulse">
                <div class="flex items-start gap-3 p-3 bg-slate-50/70 rounded-2xl border border-slate-100">
                    <div class="w-7 h-7 rounded-full bg-slate-200 flex-shrink-0"></div>
                    <div class="flex-1 space-y-2">
                        <div class="h-3 bg-slate-200 rounded w-24"></div>
                        <div class="h-3.5 bg-slate-200 rounded w-full"></div>
                        <div class="h-3 bg-slate-200 rounded w-4/5"></div>
                    </div>
                </div>
            </div>
        `;
    }

    const chkContainer = document.getElementById('modal-task-checklists-container');
    if (chkContainer) {
        chkContainer.innerHTML = `
            <div class="p-3.5 bg-slate-50/70 rounded-2xl border border-slate-100 space-y-3 animate-pulse">
                <div class="flex justify-between items-center">
                    <div class="h-3.5 bg-slate-200 rounded w-32"></div>
                    <div class="h-3 bg-slate-200 rounded w-12"></div>
                </div>
            </div>
        `;
    }

    const filesList = document.getElementById('modal-task-files-list');
    if (filesList) {
        filesList.innerHTML = `
            <div class="space-y-2 animate-pulse">
                <div class="h-10 bg-slate-100 rounded-xl w-full"></div>
            </div>
        `;
    }
    const linksList = document.getElementById('modal-task-links-list');
    if (linksList) {
        linksList.innerHTML = `
            <div class="space-y-2 animate-pulse">
                <div class="h-9 bg-slate-100 rounded-xl w-full"></div>
            </div>
        `;
    }

    // Pestaña de actividad por defecto: comentarios
    if (document.getElementById('task-tab-btn-comments')) {
        switchTaskDetailTab('comments');
    }
    openModal('task-detail-modal');

    const formData = new FormData();
    formData.append('action', 'get_detail');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('task_id', taskId);

    try {
        const res = await fetch('api/tasks.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const t = data.task;
            currentTaskData = t;

            document.getElementById('modal-task-title').innerText = t.title;
            document.getElementById('modal-task-description').innerText = t.description || 'Sin descripción detallada.';
            document.getElementById('modal-task-assigned').innerText = t.assigned_to || 'Sin asignar';
            document.getElementById('modal-task-duedate').innerText = t.due_date || 'Sin fecha';
            document.getElementById('modal-task-status-text').innerText = (t.status || 'pendiente').replace('_', ' ');

            const prioBadge = document.getElementById('modal-task-priority');
            if (prioBadge) {
                prioBadge.innerText = t.priority;
                prioBadge.className = "text-[11px] font-medium capitalize px-2 py-0.5 rounded-full " + (
                    t.priority === 'urgente' ? 'bg-rose-50 text-rose-700 border-rose-200/60' :
                    (t.priority === 'alta' ? 'bg-amber-50 text-amber-700 border-amber-200/60' : 'bg-indigo-50 text-indigo-700 border-indigo-200/60')
                );
            }

            const statusBadge = document.getElementById('modal-task-status-badge');
            if (statusBadge) {
                statusBadge.innerText = (t.status || 'pendiente').replace('_', ' ');
                statusBadge.className = "text-xs font-semibold px-2 py-0.5 rounded-full capitalize " + (
                    t.status === 'completada' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700'
                );
            }

            // Ocultar skeleton y revelar vista con contenido
            if (ovSkeleton) ovSkeleton.classList.add('hidden');
            if (ovView) ovView.classList.remove('hidden');

            // Control de visibilidad del botón de Editar Tarea según permisos
            const btnEdit = document.getElementById('btn-toggle-edit-task');
            if (btnEdit) {
                if (data.permissions && !data.permissions.can_edit) {
                    btnEdit.classList.add('hidden');
                } else {
                    btnEdit.classList.remove('hidden');
                }
            }

            // Renderizar Comentarios
            if (document.getElementById('modal-task-comments-feed')) {
                renderTaskModalComments(data.comments);
                const comBadge = document.getElementById('modal-task-comments-badge-count');
                if (comBadge) comBadge.innerText = data.comments ? data.comments.length : 0;
            }

            // Renderizar Checklists de la Tarea
            if (document.getElementById('modal-task-checklists-container')) {
                renderTaskModalChecklists(data.checklists, data.permissions);
                const chkBadge = document.getElementById('modal-task-chk-count');
                if (chkBadge) chkBadge.innerText = data.checklists ? data.checklists.length : 0;
            }

            // Renderizar Adjuntos de la Tarea
            if (document.getElementById('modal-task-files-list')) {
                renderTaskModalAdjuntos(data.files, data.links, data.permissions);
                const totalAdj = (data.files ? data.files.length : 0) + (data.links ? data.links.length : 0);
                const adjBadge = document.getElementById('modal-task-adj-count');
                if (adjBadge) adjBadge.innerText = totalAdj;
            }

            lucide.createIcons();
        } else {
            showToast(data.message || 'Error al cargar tarea', 'error');
        }
    } catch (err) {
        showToast('Error al cargar detalle de tarea', 'error');
    }
}

async function submitTaskEditAjax(e) {
    e.preventDefault();
    if (!currentTaskDetailId) return;

    const btn = document.getElementById('btn-save-task-edit');
    btn.disabled = true;
    btn.innerText = 'Guardando...';

    const formData = new FormData();
    formData.append('action', 'update');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('task_id', currentTaskDetailId);
    formData.append('title', document.getElementById('edit-task-title-input').value.trim());
    formData.append('description', document.getElementById('edit-task-desc-input').value.trim());
    formData.append('priority', document.getElementById('edit-task-prio-input').value);
    formData.append('status', document.getElementById('edit-task-status-input').value);
    formData.append('due_date', document.getElementById('edit-task-date-input').value);
    formData.append('assigned_user_id', document.getElementById('edit-task-assigned-input').value);

    try {
        const res = await fetch('api/tasks.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast('Tarea actualizada correctamente');
            // Refrescar modal y vista
            setTimeout(() => location.reload(), 400);
        } else {
            showToast(data.message || 'Error al actualizar', 'error');
        }
    } catch (err) {
        showToast('Error de conexión', 'error');
    } finally {
        btn.disabled = false;
        btn.innerText = 'Guardar Cambios';
    }
}

function renderTaskModalComments(comments) {
    const feed = document.getElementById('modal-task-comments-feed');
    if (!comments || comments.length === 0) {
        feed.innerHTML = '<p class="text-xs text-slate-400 italic">No hay comentarios en esta tarea aún.</p>';
        return;
    }

    // Separar padres y respuestas
    const parents = comments.filter(c => !c.parent_id);
    const replies = {};
    comments.forEach(c => {
        if (c.parent_id) {
            if (!replies[c.parent_id]) replies[c.parent_id] = [];
            replies[c.parent_id].push(c);
        }
    });

    feed.innerHTML = parents.map(c => {
        const commReplies = replies[c.id] || [];
        return `
            <div id="modal-comment-card-${c.id}" class="bg-white p-3.5 rounded-2xl border border-slate-200/70 shadow-2xs space-y-2.5 group">
                <div class="flex items-start gap-3">
                    <div class="w-7 h-7 rounded-full bg-brand-50 text-brand-700 font-bold text-[11px] flex items-center justify-center flex-shrink-0">
                        ${c.initials}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between mb-0.5">
                            <h4 class="text-xs font-bold text-slate-900">${c.author_name}</h4>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] text-slate-400">${c.created_at}</span>
                                ${c.can_manage ? `
                                    <div class="opacity-0 group-hover:opacity-100 transition flex items-center gap-1">
                                        <button onclick="openEditCommentModal(${c.id}, ${jsAttrArg(c.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                            <i data-lucide="edit-2" class="w-3 h-3"></i>
                                        </button>
                                        <button onclick="deleteCommentAjax(${c.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                            <i data-lucide="trash-2" class="w-3 h-3"></i>
                                        </button>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                        ${c.content ? `<p id="modal-comment-text-${c.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${c.content}</p>` : ''}
                        ${c.image_url ? `<div class="mt-2"><img src="${c.image_url}" class="max-h-56 rounded-xl border border-slate-200 cursor-pointer" onclick="openImageModal(this.src)"></div>` : ''}
                        
                        <!-- Botón Responder dentro de Tarea -->
                        <div class="mt-2 flex items-center gap-3">
                            <button onclick="toggleModalReplyBox(${c.id})" class="text-[10px] font-semibold text-brand-600 hover:text-brand-700 inline-flex items-center gap-1 transition">
                                <i data-lucide="reply" class="w-3 h-3"></i>
                                <span>Responder</span>
                            </button>
                            ${commReplies.length > 0 ? `<span class="text-[10px] text-slate-400 font-medium">${commReplies.length} ${commReplies.length === 1 ? 'respuesta' : 'respuestas'}</span>` : ''}
                        </div>
                    </div>
                </div>

                <!-- Respuestas anidadas en Modal -->
                <div id="modal-replies-container-${c.id}" class="space-y-2 pl-6 sm:pl-8 border-l-2 border-slate-100 ${commReplies.length === 0 ? 'hidden' : ''}">
                    ${commReplies.map(rep => `
                        <div id="modal-comment-card-${rep.id}" class="bg-slate-50/70 p-2.5 rounded-xl border border-slate-200/50 flex items-start gap-2.5 group/mreply">
                            <div class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 font-bold text-[9px] flex items-center justify-center flex-shrink-0">
                                ${rep.initials}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between mb-0.5">
                                    <h5 class="text-[11px] font-bold text-slate-800">${rep.author_name}</h5>
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-[9px] text-slate-400">${rep.created_at}</span>
                                        ${rep.can_manage ? `
                                            <div class="opacity-0 group-hover/mreply:opacity-100 transition flex items-center gap-0.5">
                                                <button onclick="openEditCommentModal(${rep.id}, ${jsAttrArg(rep.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                                    <i data-lucide="edit-2" class="w-2.5 h-2.5"></i>
                                                </button>
                                                <button onclick="deleteCommentAjax(${rep.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                                    <i data-lucide="trash-2" class="w-2.5 h-2.5"></i>
                                                </button>
                                            </div>
                                        ` : ''}
                                    </div>
                                </div>
                                ${rep.content ? `<p id="modal-comment-text-${rep.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${rep.content}</p>` : ''}
                                ${rep.image_url ? `<div class="mt-2"><img src="${rep.image_url}" class="max-h-44 rounded-lg border border-slate-200 cursor-pointer" onclick="openImageModal(this.src)"></div>` : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>

                <!-- Caja para responder en modal -->
                <div id="modal-reply-box-${c.id}" class="hidden pl-6 sm:pl-8 pt-1 border-l-2 border-slate-100">
                    <form onsubmit="submitModalReplyAjax(event, ${c.id})" class="space-y-1.5">
                        <textarea id="modal-reply-content-${c.id}" rows="2" placeholder="Escribe tu respuesta a ${c.author_name}..." required class="w-full p-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-brand-500 bg-slate-50/50 leading-relaxed"></textarea>
                        <div class="flex items-center justify-end gap-1.5">
                            <button type="button" onclick="toggleModalReplyBox(${c.id})" class="px-2.5 py-1 rounded-lg text-xs text-slate-500 hover:bg-slate-100">Cancelar</button>
                            <button type="submit" id="btn-modal-reply-${c.id}" class="inline-flex items-center gap-1 px-3 py-1 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs rounded-xl shadow-xs transition">
                                <i data-lucide="send" class="w-3 h-3"></i>
                                <span>Responder</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `;
    }).join('');
    lucide.createIcons();
}

function renderTaskModalChecklists(checklists, permissions) {
    const canEdit = !permissions || permissions.can_edit !== false;
    const container = document.getElementById('modal-task-checklists-container');
    if (!checklists || checklists.length === 0) {
        container.innerHTML = '<p class="text-xs text-slate-400 italic">Esta tarea no tiene checklists aún.' + (canEdit ? ' Crea uno arriba.' : '') + '</p>';
        return;
    }

    container.innerHTML = checklists.map(chk => {
        const itemsHtml = chk.items.map(it => `
            <div class="flex items-center justify-between p-2 rounded-xl bg-slate-50/70 hover:bg-slate-100/70 border border-slate-100 transition">
                <label class="flex items-center gap-2 ${canEdit ? 'cursor-pointer' : 'cursor-not-allowed'} flex-1">
                    <input type="checkbox" ${canEdit ? `onchange="toggleChecklistItemAjax(${it.id}, ${chk.id}, this)"` : 'disabled'} ${it.is_completed ? 'checked' : ''} class="w-4 h-4 rounded text-brand-600 border-slate-300">
                    <span class="text-xs text-slate-700 ${it.is_completed ? 'line-through text-slate-400' : ''}">${it.title}</span>
                </label>
                ${canEdit ? `<button onclick="deleteChecklistItem(${it.id}, ${chk.id})" class="text-slate-400 hover:text-rose-600 p-1">✕</button>` : ''}
            </div>
        `).join('');

        return `
            <div class="p-3 bg-white rounded-2xl border border-slate-200/80 space-y-2">
                <div class="flex items-center justify-between">
                    <h4 class="font-bold text-xs text-slate-800">${chk.title}</h4>
                    ${canEdit ? `<button onclick="deleteChecklist(${chk.id})" class="text-slate-400 hover:text-rose-600 p-1 text-xs">Eliminar</button>` : ''}
                </div>
                <div class="space-y-1.5">${itemsHtml}</div>
                ${canEdit ? `
                    <form onsubmit="addChecklistItemAjax(event, ${chk.id})" class="flex items-center gap-2 pt-1 border-t border-slate-100">
                        <input type="text" id="new-item-input-${chk.id}" required placeholder="+ Añadir paso..." class="flex-1 px-2.5 py-1 rounded-lg border border-slate-200 text-xs">
                        <button type="submit" class="px-2.5 py-1 bg-slate-900 text-white rounded-lg text-xs font-semibold">Añadir</button>
                    </form>
                ` : ''}
            </div>
        `;
    }).join('');
}

function toggleTaskAttachmentForm(type) {
    const fileForm = document.getElementById('form-task-add-file');
    const linkForm = document.getElementById('form-task-add-link');
    const btnFile = document.getElementById('btn-toggle-task-file');
    const btnLink = document.getElementById('btn-toggle-task-link');

    if (type === 'file') {
        const willShow = fileForm.classList.contains('hidden');
        fileForm.classList.toggle('hidden');
        linkForm.classList.add('hidden');
        btnFile.classList.toggle('bg-brand-50', willShow);
        btnFile.classList.toggle('text-brand-600', willShow);
        btnLink.classList.remove('bg-brand-50', 'text-brand-600');
        if (willShow) document.getElementById('task-file-input').focus();
    } else if (type === 'link') {
        const willShow = linkForm.classList.contains('hidden');
        linkForm.classList.toggle('hidden');
        fileForm.classList.add('hidden');
        btnLink.classList.toggle('bg-brand-50', willShow);
        btnLink.classList.toggle('text-brand-600', willShow);
        btnFile.classList.remove('bg-brand-50', 'text-brand-600');
        if (willShow) document.getElementById('task-link-title').focus();
    }
    lucide.createIcons();
}

function renderTaskModalAdjuntos(files, links, permissions) {
    const canEdit = !permissions || permissions.can_edit !== false;
    const addBtnFile = document.getElementById('btn-toggle-task-file');
    const addBtnLink = document.getElementById('btn-toggle-task-link');
    if (addBtnFile) addBtnFile.style.display = canEdit ? '' : 'none';
    if (addBtnLink) addBtnLink.style.display = canEdit ? '' : 'none';

    const filesList = document.getElementById('modal-task-files-list');
    const linksList = document.getElementById('modal-task-links-list');

    // Cerrar formularios abiertos por defecto al recargar o cambiar de tarea
    document.getElementById('form-task-add-file').classList.add('hidden');
    document.getElementById('form-task-add-link').classList.add('hidden');
    document.getElementById('btn-toggle-task-file').classList.remove('bg-brand-50', 'text-brand-600');
    document.getElementById('btn-toggle-task-link').classList.remove('bg-brand-50', 'text-brand-600');

    if (!files || files.length === 0) {
        filesList.innerHTML = '<div class="py-2 px-3 rounded-lg border border-dashed border-slate-200 text-center text-[11px] text-slate-400">Sin archivos en esta tarea</div>';
    } else {
        filesList.innerHTML = files.map(f => `
            <div class="group flex items-center justify-between p-2 rounded-xl bg-slate-50/70 hover:bg-slate-100/70 border border-slate-200/60 transition">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="w-7 h-7 rounded-lg bg-white flex items-center justify-center border border-slate-200/80 text-brand-600 flex-shrink-0">
                        <i data-lucide="file" class="w-3.5 h-3.5"></i>
                    </div>
                    <div class="min-w-0">
                        <span class="text-xs font-medium text-slate-800 truncate block">${f.original_name}</span>
                        <span class="text-[10px] text-slate-400 block">${f.file_size}</span>
                    </div>
                </div>
                <a href="${f.download_url}" class="p-1.5 text-slate-400 hover:text-brand-600 hover:bg-white rounded-lg transition" title="Descargar">
                    <i data-lucide="download" class="w-3.5 h-3.5"></i>
                </a>
            </div>
        `).join('');
    }

    if (!links || links.length === 0) {
        linksList.innerHTML = '<div class="py-2 px-3 rounded-lg border border-dashed border-slate-200 text-center text-[11px] text-slate-400">Sin enlaces en esta tarea</div>';
    } else {
        linksList.innerHTML = links.map(l => `
            <div class="group flex items-center justify-between p-2 rounded-xl bg-slate-50/70 hover:bg-slate-100/70 border border-slate-200/60 transition">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="w-7 h-7 rounded-lg bg-white flex items-center justify-center border border-slate-200/80 text-emerald-600 flex-shrink-0">
                        <i data-lucide="link" class="w-3.5 h-3.5"></i>
                    </div>
                    <div class="min-w-0">
                        <span class="text-xs font-medium text-slate-800 truncate block">${l.title}</span>
                        <span class="text-[10px] text-slate-400 truncate block">${l.url}</span>
                    </div>
                </div>
                <a href="${l.url}" target="_blank" rel="noopener noreferrer" class="p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-white rounded-lg transition" title="Abrir enlace">
                    <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                </a>
            </div>
        `).join('');
    }
}

// Acciones de Checklists y Adjuntos directos para la Tarea
async function submitTaskChecklistAjax(e) {
    e.preventDefault();
    const input = document.getElementById('task-new-chk-title');
    const title = input.value.trim();
    if (!title || !currentTaskDetailId) return;

    const formData = new FormData();
    formData.append('action', 'create_checklist');
    formData.append('project_id', PROJECT_ID);
    formData.append('task_id', currentTaskDetailId);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('title', title);

    try {
        const res = await fetch('api/checklists.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast('Lista creada para esta tarea');
            openTaskDetailModal(currentTaskDetailId);
            setTimeout(() => switchTaskDetailTab('checklists'), 200);
        }
    } catch (err) {
        showToast('Error al crear checklist', 'error');
    }
}

async function submitTaskFileAjax(e) {
    e.preventDefault();
    const fileInput = document.getElementById('task-file-input');
    if (!fileInput.files.length || !currentTaskDetailId) return;

    const btn = document.getElementById('task-file-btn');
    btn.disabled = true;
    btn.innerText = 'Subiendo...';

    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('project_id', PROJECT_ID);
    formData.append('task_id', currentTaskDetailId);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('attachment', fileInput.files[0]);

    try {
        const res = await fetch('api/files.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            fileInput.value = '';
            showToast('Archivo vinculado a la tarea');
            openTaskDetailModal(currentTaskDetailId);
            setTimeout(() => switchTaskDetailTab('adjuntos'), 200);
        }
    } catch (err) {
        showToast('Error al subir archivo', 'error');
    } finally {
        btn.disabled = false;
        btn.innerText = 'Subir a Tarea';
    }
}

async function submitTaskLinkAjax(e) {
    e.preventDefault();
    const title = document.getElementById('task-link-title').value.trim();
    const url = document.getElementById('task-link-url').value.trim();
    if (!title || !url || !currentTaskDetailId) return;

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('project_id', PROJECT_ID);
    formData.append('task_id', currentTaskDetailId);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('title', title);
    formData.append('url', url);

    try {
        const res = await fetch('api/links.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            document.getElementById('task-link-title').value = '';
            document.getElementById('task-link-url').value = '';
            showToast('Enlace vinculado a la tarea');
            openTaskDetailModal(currentTaskDetailId);
            setTimeout(() => switchTaskDetailTab('adjuntos'), 200);
        }
    } catch (err) {
        showToast('Error al guardar enlace', 'error');
    }
}

// Pegar imagen dentro del modal de tarea (Ctrl + V)
const modalTaskCommentInput = document.getElementById('modal-task-comment-input');
if (modalTaskCommentInput) {
    modalTaskCommentInput.addEventListener('paste', (e) => {
        const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
        if (!items) return;
        for (let item of items) {
            if (item.type.indexOf('image') === 0) {
                const blob = item.getAsFile();
                if (blob) {
                    e.preventDefault();
                    setModalCommentImagePreview(blob);
                    showToast('¡Captura pegada en la tarea!', 'success');
                    break;
                }
            }
        }
    });
}

function handleModalCommentImageSelect(input) {
    if (input.files && input.files[0]) {
        setModalCommentImagePreview(input.files[0]);
    }
}

function setModalCommentImagePreview(fileOrBlob) {
    modalPastedImageBlob = fileOrBlob;
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('modal-comment-preview-img').src = e.target.result;
        document.getElementById('modal-comment-image-preview').classList.remove('hidden');
    };
    reader.readAsDataURL(fileOrBlob);
}

function clearModalCommentImage() {
    modalPastedImageBlob = null;
    document.getElementById('modal-comment-preview-img').src = '';
    document.getElementById('modal-comment-image-preview').classList.add('hidden');
    document.getElementById('modal-comment-image-file').value = '';
}

async function submitTaskCommentAjax(e) {
    e.preventDefault();
    const taskId = document.getElementById('modal-task-id-input').value;
    const content = document.getElementById('modal-task-comment-input').value.trim();
    if (!content && !modalPastedImageBlob) {
        showToast('Escribe un comentario o pega una imagen', 'error');
        return;
    }

    const btn = document.getElementById('modal-submit-comment-btn');
    btn.disabled = true;
    btn.innerText = 'Enviando...';

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('project_id', PROJECT_ID);
    formData.append('task_id', taskId);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('content', content);

    if (modalPastedImageBlob) {
        formData.append('image', modalPastedImageBlob, 'task_comment.png');
    }

    try {
        const res = await fetch('api/comments.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const c = data.comment;
            
            const feed = document.getElementById('modal-task-comments-feed');
            const emptyMsg = feed.querySelector('p');
            if (emptyMsg) emptyMsg.remove();

            const div = document.createElement('div');
            div.id = `modal-comment-card-${c.id}`;
            div.className = "bg-white p-3 rounded-2xl border border-slate-200/70 shadow-2xs flex items-start gap-3 group";
            div.innerHTML = `
                <div class="w-7 h-7 rounded-full bg-brand-50 text-brand-700 font-bold text-[11px] flex items-center justify-center flex-shrink-0">
                    ${c.initials}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-0.5">
                        <h4 class="text-xs font-bold text-slate-900">${c.author_name}</h4>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] text-slate-400">${c.created_at}</span>
                            <div class="opacity-0 group-hover:opacity-100 transition flex items-center gap-1">
                                <button onclick="openEditCommentModal(${c.id}, ${jsAttrArg(c.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                    <i data-lucide="edit-2" class="w-3 h-3"></i>
                                </button>
                                <button onclick="deleteCommentAjax(${c.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                    <i data-lucide="trash-2" class="w-3 h-3"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    ${c.content ? `<p id="modal-comment-text-${c.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${c.content}</p>` : ''}
                    ${c.image_url ? `<div class="mt-2"><img src="${c.image_url}" class="max-h-56 rounded-xl border border-slate-200 cursor-pointer" onclick="openImageModal(this.src)"></div>` : ''}
                </div>
            `;
            feed.appendChild(div);

            document.querySelectorAll(`.task-comments-count-${taskId}`).forEach(span => {
                span.innerText = parseInt(span.innerText || '0') + 1;
            });
            const bTaskBadge = document.getElementById('modal-task-comments-badge-count');
            if (bTaskBadge) bTaskBadge.innerText = parseInt(bTaskBadge.innerText || '0') + 1;

            const generalFeed = document.getElementById('comments-feed');
            if (generalFeed) {
                const genCard = document.createElement('div');
                genCard.id = `comment-card-${c.id}`;
                genCard.className = "bg-white rounded-2xl border border-slate-200/70 p-4 shadow-2xs flex items-start gap-3.5 group";
                genCard.innerHTML = `
                    <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                        ${c.initials}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center justify-between gap-1 mb-1">
                            <div class="flex items-center gap-2">
                                <h4 class="text-xs font-bold text-slate-900">${c.author_name}</h4>
                                <button onclick="openTaskDetailModal(${taskId})" class="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 bg-brand-50 px-2 py-0.5 rounded-md hover:bg-brand-100 transition">
                                    <i data-lucide="check-square" class="w-3 h-3"></i>
                                    <span class="truncate max-w-[200px]">Tarea: ${c.task_title || 'Tarea'}</span>
                                </button>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] text-slate-400">${c.created_at}</span>
                                <div class="opacity-0 group-hover:opacity-100 transition flex items-center gap-1">
                                    <button onclick="openEditCommentModal(${c.id}, ${jsAttrArg(c.raw_content || '')})" class="p-1 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                        <i data-lucide="edit-2" class="w-3 h-3"></i>
                                    </button>
                                    <button onclick="deleteCommentAjax(${c.id})" class="p-1 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                        <i data-lucide="trash-2" class="w-3 h-3"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        ${c.content ? `<p id="comment-text-${c.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${c.content}</p>` : ''}
                        ${c.image_url ? `<div class="mt-2.5"><img src="${c.image_url}" class="max-h-72 rounded-xl border border-slate-200 shadow-2xs hover:opacity-95 cursor-pointer transition" onclick="openImageModal(this.src)"></div>` : ''}
                    </div>
                `;
                generalFeed.prepend(genCard);
                document.getElementById('comments-empty-state').classList.add('hidden');
                const bComms = document.getElementById('badge-comments-count');
                bComms.innerText = parseInt(bComms.innerText || '0') + 1;
            }

            document.getElementById('modal-task-comment-input').value = '';
            clearModalCommentImage();
            showToast('Comentario añadido a la tarea');
            lucide.createIcons();
        }
    } catch (err) {
        showToast('Error al enviar comentario', 'error');
    } finally {
        btn.disabled = false;
        btn.innerText = 'Enviar Comentario';
    }
}

async function submitTaskAjax(e) {
    e.preventDefault();
    const btn = document.getElementById('submit-task-btn');
    btn.disabled = true;
    btn.innerText = 'Creando Tarea...';

    const title = document.getElementById('task-title-input').value.trim();
    const description = document.getElementById('task-desc-input').value.trim();
    const priority = document.getElementById('task-prio-input').value;
    const dueDate = document.getElementById('task-date-input').value;
    const assignedTo = document.getElementById('task-assigned-input').value;

    // Comentario inicial
    const initialComment = document.getElementById('create-task-comment-text')?.value.trim() || '';

    // Checklist inicial
    const chkTitle = document.getElementById('create-task-chk-title')?.value.trim() || '';
    const chkItemsRaw = document.getElementById('create-task-chk-items')?.value || '';
    const chkItems = chkItemsRaw.split('\n').map(l => l.trim()).filter(l => l.length > 0);

    // Adjuntos iniciales
    const fileInput = document.getElementById('create-task-file-input');
    const hasFile = fileInput && fileInput.files.length > 0;
    const linkTitle = document.getElementById('create-task-link-title')?.value.trim() || '';
    const linkUrl = document.getElementById('create-task-link-url')?.value.trim() || '';

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('title', title);
    formData.append('description', description);
    formData.append('priority', priority);
    formData.append('due_date', dueDate);
    formData.append('assigned_user_id', assignedTo);

    try {
        // 1. Crear Tarea
        const res = await fetch('api/tasks.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (!data.success) {
            showToast(data.message || 'Error al crear la tarea', 'error');
            return;
        }

        const newTaskId = data.task.id;

        // 2. Si hay comentario inicial o imagen pegada, enviarlo
        if (initialComment || createTaskCommentBlob) {
            const commentData = new FormData();
            commentData.append('action', 'create');
            commentData.append('project_id', PROJECT_ID);
            commentData.append('task_id', newTaskId);
            commentData.append('csrf_token', CSRF_TOKEN);
            commentData.append('content', initialComment);
            if (createTaskCommentBlob) {
                commentData.append('image', createTaskCommentBlob, 'initial_comment.png');
            }
            await fetch('api/comments.php', { method: 'POST', body: commentData });
        }

        // 3. Si hay checklist inicial, crearlo y agregar sus items
        if (chkTitle || chkItems.length > 0) {
            const chkData = new FormData();
            chkData.append('action', 'create_checklist');
            chkData.append('project_id', PROJECT_ID);
            chkData.append('task_id', newTaskId);
            chkData.append('csrf_token', CSRF_TOKEN);
            chkData.append('title', chkTitle || 'Pasos iniciales');

            const chkRes = await fetch('api/checklists.php', { method: 'POST', body: chkData });
            const chkJson = await chkRes.json();
            if (chkJson.success && chkJson.checklist && chkItems.length > 0) {
                for (const itemTitle of chkItems) {
                    const itemData = new FormData();
                    itemData.append('action', 'add_item');
                    itemData.append('project_id', PROJECT_ID);
                    itemData.append('csrf_token', CSRF_TOKEN);
                    itemData.append('checklist_id', chkJson.checklist.id);
                    itemData.append('title', itemTitle);
                    await fetch('api/checklists.php', { method: 'POST', body: itemData });
                }
            }
        }

        // 4. Si hay archivo inicial, subirlo
        if (hasFile) {
            const fData = new FormData();
            fData.append('action', 'upload');
            fData.append('project_id', PROJECT_ID);
            fData.append('task_id', newTaskId);
            fData.append('csrf_token', CSRF_TOKEN);
            fData.append('attachment', fileInput.files[0]);
            await fetch('api/files.php', { method: 'POST', body: fData });
        }

        // 5. Si hay enlace inicial, guardarlo
        if (linkTitle && linkUrl) {
            const lData = new FormData();
            lData.append('action', 'create');
            lData.append('project_id', PROJECT_ID);
            lData.append('task_id', newTaskId);
            lData.append('csrf_token', CSRF_TOKEN);
            lData.append('title', linkTitle);
            lData.append('url', linkUrl);
            await fetch('api/links.php', { method: 'POST', body: lData });
        }

        showToast('Tarea creada con todos sus datos asociados');
        closeModal('create-task-modal');
        setTimeout(() => location.reload(), 450);

    } catch (err) {
        showToast('Error al procesar creación', 'error');
    } finally {
        btn.disabled = false;
        btn.innerText = 'Guardar Tarea';
    }
}

async function updateTaskStatus(taskId, status) {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('task_id', taskId);
    formData.append('status', status);

    try {
        await fetch('api/tasks.php', { method: 'POST', body: formData });
        showToast('Estado de tarea actualizado');
        setTimeout(() => location.reload(), 400);
    } catch (err) {
        showToast('Error al actualizar estado', 'error');
    }
}

async function toggleTaskCompleteFromList(taskId, checkbox) {
    const newStatus = checkbox.checked ? 'completada' : 'pendiente';
    await updateTaskStatus(taskId, newStatus);
}

async function deleteTask(taskId) {
    if (!confirm('¿Eliminar esta tarea y sus comentarios?')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('task_id', taskId);

    try {
        const res = await fetch('api/tasks.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast('Tarea eliminada');
            setTimeout(() => location.reload(), 300);
        }
    } catch (err) {
        showToast('Error al eliminar', 'error');
    }
}

// ----------------- CHECKLISTS (AJAX) -----------------
async function submitChecklistAjax(e) {
    e.preventDefault();
    const input = document.getElementById('checklist-title-input');
    const title = input.value.trim();
    const taskId = document.getElementById('checklist-task-select').value;

    const formData = new FormData();
    formData.append('action', 'create_checklist');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('title', title);
    if (taskId) formData.append('task_id', taskId);

    try {
        const res = await fetch('api/checklists.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            closeModal('create-checklist-modal');
            location.reload();
        }
    } catch (err) {
        showToast('Error al crear checklist', 'error');
    }
}

async function addChecklistItemAjax(e, checklistId) {
    e.preventDefault();
    const input = document.getElementById(`new-item-input-${checklistId}`);
    const title = input.value.trim();

    const formData = new FormData();
    formData.append('action', 'add_item');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('checklist_id', checklistId);
    formData.append('title', title);

    try {
        const res = await fetch('api/checklists.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            if (currentTaskDetailId) {
                openTaskDetailModal(currentTaskDetailId);
                setTimeout(() => switchTaskDetailTab('checklists'), 200);
            } else {
                location.reload();
            }
        }
    } catch (err) {
        showToast('Error al añadir paso', 'error');
    }
}

async function toggleChecklistItemAjax(itemId, checklistId, checkbox) {
    const isCompleted = checkbox.checked ? 1 : 0;
    const formData = new FormData();
    formData.append('action', 'toggle_item');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('item_id', itemId);
    formData.append('is_completed', isCompleted);

    try {
        await fetch('api/checklists.php', { method: 'POST', body: formData });
        recalcChecklistStats(checklistId);
    } catch (err) {
        checkbox.checked = !checkbox.checked;
    }
}

async function deleteChecklistItem(itemId, checklistId) {
    const formData = new FormData();
    formData.append('action', 'delete_item');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('item_id', itemId);

    try {
        const res = await fetch('api/checklists.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            if (currentTaskDetailId) {
                openTaskDetailModal(currentTaskDetailId);
                setTimeout(() => switchTaskDetailTab('checklists'), 200);
            } else {
                const el = document.getElementById(`chk-item-${itemId}`);
                if (el) el.remove();
                recalcChecklistStats(checklistId);
            }
            showToast('Paso eliminado');
        }
    } catch (err) {
        showToast('Error al eliminar paso', 'error');
    }
}

async function deleteChecklist(checklistId) {
    if (!confirm('¿Eliminar esta lista completa?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_checklist');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('checklist_id', checklistId);

    try {
        const res = await fetch('api/checklists.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            if (currentTaskDetailId) {
                openTaskDetailModal(currentTaskDetailId);
                setTimeout(() => switchTaskDetailTab('checklists'), 200);
            } else {
                location.reload();
            }
            showToast('Lista eliminada');
        }
    } catch (err) {
        showToast('Error al eliminar lista', 'error');
    }
}

function recalcChecklistStats(chkId) {
    const list = document.getElementById(`chk-items-list-${chkId}`);
    if (!list) return;
    const total = list.querySelectorAll('input[type="checkbox"]').length;
    const completed = list.querySelectorAll('input[type="checkbox"]:checked').length;
    const pct = total > 0 ? Math.round((completed / total) * 100) : 0;

    const pText = document.getElementById(`chk-progress-text-${chkId}`);
    if (pText) {
        pText.querySelector('.chk-completed').innerText = completed;
        pText.querySelector('.chk-total').innerText = total;
        pText.querySelector('.chk-pct').innerText = pct;
    }
    const bar = document.getElementById(`chk-bar-${chkId}`);
    if (bar) bar.style.width = pct + '%';
}

// ----------------- TICKETS (AJAX) -----------------
async function submitTicketAjax(e) {
    e.preventDefault();
    const btn = document.getElementById('submit-ticket-btn');
    btn.disabled = true;

    const title = document.getElementById('ticket-title-input').value;
    const description = document.getElementById('ticket-desc-input').value;
    const priority = document.getElementById('ticket-prio-input').value;

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('title', title);
    formData.append('description', description);
    formData.append('priority', priority);

    try {
        const res = await fetch('api/tickets.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            closeModal('create-ticket-modal');
            location.reload();
        }
    } catch (err) {
        showToast('Error al registrar ticket', 'error');
    } finally {
        btn.disabled = false;
    }
}

async function updateTicketStatus(ticketId, status) {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('ticket_id', ticketId);
    formData.append('status', status);

    try {
        await fetch('api/tickets.php', { method: 'POST', body: formData });
        showToast('Estado del ticket actualizado');
    } catch (err) {
        showToast('Error al actualizar ticket', 'error');
    }
}

async function deleteTicket(ticketId) {
    if (!confirm('¿Eliminar este ticket?')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('ticket_id', ticketId);

    try {
        const res = await fetch('api/tickets.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const el = document.getElementById(`ticket-card-${ticketId}`);
            if (el) el.remove();
            showToast('Ticket eliminado');
        }
    } catch (err) {
        showToast('Error al eliminar ticket', 'error');
    }
}

// Respuestas en Tickets
function openTicketReplyInput(ticketId) {
    const panel = document.getElementById(`ticket-replies-panel-${ticketId}`);
    if (panel && panel.classList.contains('hidden')) {
        toggleTicketReplies(ticketId);
    }
    setTimeout(() => {
        const txt = document.getElementById(`ticket-reply-text-${ticketId}`);
        if (txt) txt.focus();
    }, 150);
}

async function toggleTicketReplies(ticketId) {
    const panel = document.getElementById(`ticket-replies-panel-${ticketId}`);
    const chevron = document.getElementById(`chevron-replies-${ticketId}`);
    if (!panel) return;

    if (panel.classList.contains('hidden')) {
        panel.classList.remove('hidden');
        if (chevron) chevron.classList.add('rotate-180');
        await loadTicketReplies(ticketId);
    } else {
        panel.classList.add('hidden');
        if (chevron) chevron.classList.remove('rotate-180');
    }
}

async function loadTicketReplies(ticketId) {
    const feed = document.getElementById(`ticket-replies-feed-${ticketId}`);
    if (!feed) return;

    try {
        const res = await fetch(`api/tickets.php?action=list_replies&project_id=${PROJECT_ID}&ticket_id=${ticketId}&csrf_token=${CSRF_TOKEN}`);
        const data = await res.json();
        if (data.success) {
            if (!data.replies || data.replies.length === 0) {
                feed.innerHTML = '<p class="text-xs text-slate-400 italic py-2">No hay respuestas en este ticket todavía.</p>';
            } else {
                feed.innerHTML = data.replies.map(r => `
                    <div id="tk-reply-item-${r.id}" class="bg-slate-50/80 p-3 rounded-xl border border-slate-200/60 flex items-start gap-2.5 group">
                        <div class="w-6 h-6 rounded-full bg-slate-200 text-slate-700 font-bold text-[10px] flex items-center justify-center shrink-0">
                            ${r.initials}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-1 mb-1">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="text-xs font-bold text-slate-800">${r.author_name}</span>
                                    <span class="text-[9px] font-bold px-1.5 py-0.2 rounded border ${r.role_class}">${r.role_label}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[10px] text-slate-400">${r.created_at}</span>
                                    ${r.can_delete ? `
                                        <button onclick="deleteTicketReplyAjax(${r.id}, ${ticketId})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded opacity-0 group-hover:opacity-100 transition" title="Eliminar respuesta">
                                            <i data-lucide="trash-2" class="w-3 h-3"></i>
                                        </button>
                                    ` : ''}
                                </div>
                            </div>
                            <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${r.message}</p>
                        </div>
                    </div>
                `).join('');
            }
            const badge = document.getElementById(`ticket-replies-badge-${ticketId}`);
            if (badge) badge.innerText = data.replies ? data.replies.length : 0;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        } else {
            feed.innerHTML = `<p class="text-xs text-rose-500 py-2">${data.message || 'Error al cargar respuestas'}</p>`;
        }
    } catch (err) {
        feed.innerHTML = '<p class="text-xs text-rose-500 py-2">Error de conexión al cargar respuestas</p>';
    }
}

async function submitTicketReplyAjax(e, ticketId) {
    e.preventDefault();
    const txt = document.getElementById(`ticket-reply-text-${ticketId}`);
    const message = txt ? txt.value.trim() : '';
    if (!message) return;

    const btn = document.getElementById(`btn-submit-tk-reply-${ticketId}`);
    if (btn) {
        btn.disabled = true;
        btn.innerText = 'Enviando...';
    }

    const formData = new FormData();
    formData.append('action', 'add_reply');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('ticket_id', ticketId);
    formData.append('message', message);

    try {
        const res = await fetch('api/tickets.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            txt.value = '';
            showToast('Respuesta registrada en el ticket');
            await loadTicketReplies(ticketId);
        } else {
            showToast(data.message || 'Error al responder ticket', 'error');
        }
    } catch (err) {
        showToast('Error de conexión', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerText = 'Enviar Respuesta';
        }
    }
}

async function deleteTicketReplyAjax(replyId, ticketId) {
    if (!confirm('¿Eliminar esta respuesta?')) return;

    const formData = new FormData();
    formData.append('action', 'delete_reply');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('reply_id', replyId);

    try {
        const res = await fetch('api/tickets.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const el = document.getElementById(`tk-reply-item-${replyId}`);
            if (el) el.remove();
            const badge = document.getElementById(`ticket-replies-badge-${ticketId}`);
            if (badge) {
                badge.innerText = Math.max(0, parseInt(badge.innerText || '1') - 1);
            }
            showToast('Respuesta eliminada');
        } else {
            showToast(data.message || 'Error al eliminar', 'error');
        }
    } catch (err) {
        showToast('Error de conexión', 'error');
    }
}

// ----------------- COMENTARIOS GENERALES (AJAX) -----------------
const commentInput = document.getElementById('comment-content-input');
if (commentInput) {
    commentInput.addEventListener('paste', (e) => {
        const items = (e.clipboardData || e.originalEvent?.clipboardData)?.items;
        if (!items) return;
        for (let item of items) {
            if (item.type.indexOf('image') === 0) {
                const blob = item.getAsFile();
                if (blob) {
                    e.preventDefault();
                    setImagePreview(blob);
                    showToast('¡Captura pegada!', 'success');
                    break;
                }
            }
        }
    });
}

function handleCommentImageSelect(input) {
    if (input.files && input.files[0]) {
        setImagePreview(input.files[0]);
    }
}

function setImagePreview(fileOrBlob) {
    pastedImageBlob = fileOrBlob;
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('comment-preview-img').src = e.target.result;
        document.getElementById('comment-image-preview-container').classList.remove('hidden');
    };
    reader.readAsDataURL(fileOrBlob);
}

function clearCommentImage() {
    pastedImageBlob = null;
    document.getElementById('comment-preview-img').src = '';
    document.getElementById('comment-image-preview-container').classList.add('hidden');
    document.getElementById('comment-image-file').value = '';
}

async function submitCommentAjax(e) {
    if (e) e.preventDefault();
    const content = document.getElementById('comment-content-input').value.trim();
    if (!content && !pastedImageBlob) {
        showToast('Escribe una nota o adjunta una imagen', 'error');
        return;
    }

    const btn = document.getElementById('submit-comment-btn');
    btn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('content', content);

    if (pastedImageBlob) {
        formData.append('image', pastedImageBlob, 'comment_image.png');
    }

    try {
        const res = await fetch('api/comments.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            location.reload();
        }
    } catch (err) {
        showToast('Error al publicar nota', 'error');
    } finally {
        btn.disabled = false;
    }
}

function openImageModal(src) {
    document.getElementById('image-viewer-img').src = src;
    document.getElementById('image-viewer-modal').classList.remove('hidden');
}

function closeImageModal() {
    document.getElementById('image-viewer-modal').classList.add('hidden');
}

// ----------------- EDICIÓN Y ELIMINACIÓN DE COMENTARIOS -----------------
// Serializa un valor para usarlo como argumento JS dentro de un atributo HTML (onclick="...")
// JSON.stringify sin escapar permite romper el atributo con comillas e inyectar código.
function jsAttrArg(value) {
    return JSON.stringify(value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function openEditCommentModal(commentId, currentText) {
    document.getElementById('edit-comment-id').value = commentId;
    document.getElementById('edit-comment-content').value = currentText || '';
    openModal('edit-comment-modal');
    setTimeout(() => document.getElementById('edit-comment-content').focus(), 150);
}

async function submitEditCommentAjax(e) {
    e.preventDefault();
    const commentId = document.getElementById('edit-comment-id').value;
    const content = document.getElementById('edit-comment-content').value.trim();
    if (!content || !commentId) return;

    const btn = document.getElementById('btn-save-comment-edit');
    btn.disabled = true;
    btn.innerText = 'Guardando...';

    const formData = new FormData();
    formData.append('action', 'update');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('comment_id', commentId);
    formData.append('content', content);

    try {
        const res = await fetch('api/comments.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            // Actualizar en el muro general si existe
            const elGen = document.getElementById(`comment-text-${commentId}`);
            if (elGen) elGen.innerText = content;

            // Actualizar en el modal de tarea si existe
            const elMod = document.getElementById(`modal-comment-text-${commentId}`);
            if (elMod) elMod.innerText = content;

            closeModal('edit-comment-modal');
            showToast('Comentario actualizado');
        } else {
            showToast(data.message || 'Error al actualizar comentario', 'error');
        }
    } catch (err) {
        showToast('Error de conexión', 'error');
    } finally {
        btn.disabled = false;
        btn.innerText = 'Guardar Cambios';
    }
}

async function deleteCommentAjax(commentId) {
    if (!confirm('¿Eliminar este comentario?')) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('comment_id', commentId);

    try {
        const res = await fetch('api/comments.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            // Quitar de muro general
            const elGen = document.getElementById(`comment-card-${commentId}`);
            if (elGen) elGen.remove();

            // Quitar de modal de tarea
            const elMod = document.getElementById(`modal-comment-card-${commentId}`);
            if (elMod) elMod.remove();

            // Actualizar contadores
            const bComms = document.getElementById('badge-comments-count');
            if (bComms) {
                const currentCount = Math.max(0, parseInt(bComms.innerText || '1') - 1);
                bComms.innerText = currentCount;
            }

            if (data.task_id) {
                document.querySelectorAll(`.task-comments-count-${data.task_id}`).forEach(span => {
                    span.innerText = Math.max(0, parseInt(span.innerText || '1') - 1);
                });
                const bTaskBadge = document.getElementById('modal-task-comments-badge-count');
                if (bTaskBadge) {
                    bTaskBadge.innerText = Math.max(0, parseInt(bTaskBadge.innerText || '1') - 1);
                }
            }

            showToast('Comentario eliminado');
        } else {
            showToast(data.message || 'Error al eliminar comentario', 'error');
        }
    } catch (err) {
        showToast('Error de conexión', 'error');
    }
}

// ----------------- RESPUESTAS A COMENTARIOS (HILOS) -----------------
function toggleReplyBox(parentId) {
    const box = document.getElementById(`reply-box-${parentId}`);
    if (!box) return;
    if (box.classList.contains('hidden')) {
        box.classList.remove('hidden');
        const txt = document.getElementById(`reply-content-${parentId}`);
        if (txt) setTimeout(() => txt.focus(), 100);
    } else {
        box.classList.add('hidden');
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function submitReplyAjax(e, parentId, taskId) {
    e.preventDefault();
    const input = document.getElementById(`reply-content-${parentId}`);
    const content = input ? input.value.trim() : '';
    if (!content) return;

    const btn = document.getElementById(`btn-submit-reply-${parentId}`);
    if (btn) {
        btn.disabled = true;
        btn.innerText = 'Enviando...';
    }

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('parent_id', parentId);
    if (taskId) formData.append('task_id', taskId);
    formData.append('content', content);

    try {
        const res = await fetch('api/comments.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const rep = data.comment;
            input.value = '';
            toggleReplyBox(parentId);

            const repContainer = document.getElementById(`replies-container-${parentId}`);
            if (repContainer) {
                repContainer.classList.remove('hidden');
                const repDiv = document.createElement('div');
                repDiv.id = `comment-card-${rep.id}`;
                repDiv.className = "bg-slate-50/70 p-3 rounded-xl border border-slate-200/50 flex items-start gap-2.5 group/reply";
                repDiv.innerHTML = `
                    <div class="w-6 h-6 rounded-full bg-slate-200 text-slate-700 font-bold text-[10px] flex items-center justify-center flex-shrink-0">
                        ${rep.initials}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between mb-0.5">
                            <h5 class="text-xs font-bold text-slate-800">${rep.author_name}</h5>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[9px] text-slate-400">${rep.created_at}</span>
                                <div class="opacity-0 group-hover/reply:opacity-100 transition flex items-center gap-0.5">
                                    <button onclick="openEditCommentModal(${rep.id}, ${jsAttrArg(rep.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                        <i data-lucide="edit-2" class="w-2.5 h-2.5"></i>
                                    </button>
                                    <button onclick="deleteCommentAjax(${rep.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                        <i data-lucide="trash-2" class="w-2.5 h-2.5"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p id="comment-text-${rep.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${rep.content}</p>
                    </div>
                `;
                repContainer.appendChild(repDiv);
            }

            // Si está en modal de tarea, sincronizar también
            const mRepContainer = document.getElementById(`modal-replies-container-${parentId}`);
            if (mRepContainer) {
                mRepContainer.classList.remove('hidden');
                const mDiv = document.createElement('div');
                mDiv.id = `modal-comment-card-${rep.id}`;
                mDiv.className = "bg-slate-50/70 p-2.5 rounded-xl border border-slate-200/50 flex items-start gap-2.5 group/mreply";
                mDiv.innerHTML = `
                    <div class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 font-bold text-[9px] flex items-center justify-center flex-shrink-0">
                        ${rep.initials}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between mb-0.5">
                            <h5 class="text-[11px] font-bold text-slate-800">${rep.author_name}</h5>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[9px] text-slate-400">${rep.created_at}</span>
                                <div class="opacity-0 group-hover/mreply:opacity-100 transition flex items-center gap-0.5">
                                    <button onclick="openEditCommentModal(${rep.id}, ${jsAttrArg(rep.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                        <i data-lucide="edit-2" class="w-2.5 h-2.5"></i>
                                    </button>
                                    <button onclick="deleteCommentAjax(${rep.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                        <i data-lucide="trash-2" class="w-2.5 h-2.5"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p id="modal-comment-text-${rep.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${rep.content}</p>
                    </div>
                `;
                mRepContainer.appendChild(mDiv);
            }

            showToast('Respuesta publicada con éxito');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        } else {
            showToast(data.message || 'Error al enviar respuesta', 'error');
        }
    } catch (err) {
        showToast('Error de conexión', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerText = 'Enviar Respuesta';
        }
    }
}

function toggleModalReplyBox(parentId) {
    const box = document.getElementById(`modal-reply-box-${parentId}`);
    if (!box) return;
    if (box.classList.contains('hidden')) {
        box.classList.remove('hidden');
        const txt = document.getElementById(`modal-reply-content-${parentId}`);
        if (txt) setTimeout(() => txt.focus(), 100);
    } else {
        box.classList.add('hidden');
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function submitModalReplyAjax(e, parentId) {
    e.preventDefault();
    const taskId = document.getElementById('modal-task-id-input') ? document.getElementById('modal-task-id-input').value : null;
    const input = document.getElementById(`modal-reply-content-${parentId}`);
    const content = input ? input.value.trim() : '';
    if (!content) return;

    const btn = document.getElementById(`btn-modal-reply-${parentId}`);
    if (btn) {
        btn.disabled = true;
        btn.innerText = '...';
    }

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('parent_id', parentId);
    if (taskId) formData.append('task_id', taskId);
    formData.append('content', content);

    try {
        const res = await fetch('api/comments.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            const rep = data.comment;
            input.value = '';
            toggleModalReplyBox(parentId);

            const mRepContainer = document.getElementById(`modal-replies-container-${parentId}`);
            if (mRepContainer) {
                mRepContainer.classList.remove('hidden');
                const mDiv = document.createElement('div');
                mDiv.id = `modal-comment-card-${rep.id}`;
                mDiv.className = "bg-slate-50/70 p-2.5 rounded-xl border border-slate-200/50 flex items-start gap-2.5 group/mreply";
                mDiv.innerHTML = `
                    <div class="w-5 h-5 rounded-full bg-slate-200 text-slate-700 font-bold text-[9px] flex items-center justify-center flex-shrink-0">
                        ${rep.initials}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between mb-0.5">
                            <h5 class="text-[11px] font-bold text-slate-800">${rep.author_name}</h5>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[9px] text-slate-400">${rep.created_at}</span>
                                <div class="opacity-0 group-hover/mreply:opacity-100 transition flex items-center gap-0.5">
                                    <button onclick="openEditCommentModal(${rep.id}, ${jsAttrArg(rep.raw_content || '')})" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                        <i data-lucide="edit-2" class="w-2.5 h-2.5"></i>
                                    </button>
                                    <button onclick="deleteCommentAjax(${rep.id})" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                        <i data-lucide="trash-2" class="w-2.5 h-2.5"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p id="modal-comment-text-${rep.id}" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line">${rep.content}</p>
                    </div>
                `;
                mRepContainer.appendChild(mDiv);
            }

            showToast('Respuesta enviada');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        } else {
            showToast(data.message || 'Error al enviar respuesta', 'error');
        }
    } catch (err) {
        showToast('Error de conexión', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerText = 'Responder';
        }
    }
}

// ----------------- CARPETAS Y FILTROS EN ADJUNTOS -----------------
async function submitFolderAjax(e) {
    e.preventDefault();
    const name = document.getElementById('folder-name-input').value.trim();
    const colorInput = document.querySelector('input[name="folder_color"]:checked');
    const color = colorInput ? colorInput.value : '#6366F1';

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('name', name);
    formData.append('color', color);

    try {
        const res = await fetch('api/folders.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            closeModal('create-folder-modal');
            location.reload();
        }
    } catch (err) {
        showToast('Error al crear carpeta', 'error');
    }
}

async function deleteFolderAjax(folderId) {
    if (!confirm('¿Eliminar esta carpeta? Los archivos y enlaces contenidos se conservarán en la raíz.')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('folder_id', folderId);

    try {
        const res = await fetch('api/folders.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            location.reload();
        }
    } catch (err) {
        showToast('Error al eliminar carpeta', 'error');
    }
}

function filterByFolder(folderId, buttonElement) {
    document.querySelectorAll('.folder-pill').forEach(btn => {
        btn.classList.remove('bg-brand-600', 'text-white', 'shadow-2xs');
        btn.classList.add('bg-white', 'text-slate-600', 'border', 'border-slate-200/80');
    });
    if (buttonElement) {
        buttonElement.classList.remove('bg-white', 'text-slate-600', 'border', 'border-slate-200/80');
        buttonElement.classList.add('bg-brand-600', 'text-white', 'shadow-2xs');
    }

    const files = document.querySelectorAll('.file-item-card');
    const links = document.querySelectorAll('.link-item-card');
    let visibleFiles = 0;
    let visibleLinks = 0;

    files.forEach(card => {
        const cardFolder = card.getAttribute('data-folder-id');
        if (folderId === 'all' || cardFolder == folderId) {
            card.classList.remove('hidden');
            visibleFiles++;
        } else {
            card.classList.add('hidden');
        }
    });

    links.forEach(card => {
        const cardFolder = card.getAttribute('data-folder-id');
        if (folderId === 'all' || cardFolder == folderId) {
            card.classList.remove('hidden');
            visibleLinks++;
        } else {
            card.classList.add('hidden');
        }
    });

    document.getElementById('files-empty-state').classList.toggle('hidden', visibleFiles > 0);
    document.getElementById('files-grid').classList.toggle('hidden', visibleFiles === 0);
    document.getElementById('links-empty-state').classList.toggle('hidden', visibleLinks > 0);
    document.getElementById('links-grid').classList.toggle('hidden', visibleLinks === 0);
}

async function submitFileAjax(e) {
    e.preventDefault();
    const fileInput = document.getElementById('file-attachment-input');
    if (!fileInput.files.length) return;

    const folderId = document.getElementById('file-folder-select').value;
    const taskId = document.getElementById('file-task-select').value;
    const btn = document.getElementById('submit-file-btn');
    btn.disabled = true;
    btn.innerText = 'Subiendo...';

    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('attachment', fileInput.files[0]);
    if (folderId) formData.append('folder_id', folderId);
    if (taskId) formData.append('task_id', taskId);

    try {
        const res = await fetch('api/files.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            closeModal('upload-file-modal');
            location.reload();
        } else {
            showToast(data.message || 'Error al subir archivo', 'error');
        }
    } catch (err) {
        showToast('Error al subir archivo', 'error');
    } finally {
        btn.disabled = false;
        btn.innerText = 'Subir Archivo';
    }
}

async function deleteFileAjax(fileId) {
    if (!confirm('¿Eliminar este archivo?')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('file_id', fileId);

    try {
        const res = await fetch('api/files.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            location.reload();
        }
    } catch (err) {
        showToast('Error al eliminar archivo', 'error');
    }
}

async function submitLinkAjax(e) {
    e.preventDefault();
    const title = document.getElementById('link-title-input').value.trim();
    const url = document.getElementById('link-url-input').value.trim();
    const folderId = document.getElementById('link-folder-select').value;
    const taskId = document.getElementById('link-task-select').value;

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('title', title);
    formData.append('url', url);
    if (folderId) formData.append('folder_id', folderId);
    if (taskId) formData.append('task_id', taskId);

    try {
        const res = await fetch('api/links.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            closeModal('create-link-modal');
            location.reload();
        }
    } catch (err) {
        showToast('Error al guardar enlace', 'error');
    }
}

async function deleteLinkAjax(linkId) {
    if (!confirm('¿Eliminar este enlace?')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('link_id', linkId);

    try {
        const res = await fetch('api/links.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            location.reload();
        }
    } catch (err) {
        showToast('Error al eliminar enlace', 'error');
    }
}

// ----------------- COSTOS Y FACTURACIÓN (AJAX) -----------------
async function submitCreateInvoiceAjax(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-submit-invoice');
    if (btn) btn.disabled = true;

    const invoiceNumber = document.getElementById('inv-number-input').value.trim();
    const currency = document.getElementById('inv-currency-input').value;
    const concept = document.getElementById('inv-concept-input').value.trim();
    const amount = document.getElementById('inv-amount-input').value;
    const dueDate = document.getElementById('inv-due-date-input').value;
    const status = document.getElementById('inv-status-input').value;
    const notes = document.getElementById('inv-notes-input').value.trim();

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('invoice_number', invoiceNumber);
    formData.append('currency', currency);
    formData.append('concept', concept);
    formData.append('amount', amount);
    formData.append('due_date', dueDate);
    formData.append('status', status);
    formData.append('notes', notes);

    try {
        const res = await fetch('api/invoices.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast(data.message || 'Factura guardada');
            closeModal('create-invoice-modal');
            location.hash = 'finanzas';
            location.reload();
        } else {
            showToast(data.message || 'Error al registrar factura', 'error');
        }
    } catch (err) {
        showToast('Error de conexión', 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function submitUpdateBudgetAjax(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-submit-budget');
    if (btn) btn.disabled = true;

    const budget = document.getElementById('budget-amount-input').value;
    const currency = document.getElementById('budget-currency-input').value;

    const formData = new FormData();
    formData.append('action', 'update_budget');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('budget', budget);
    formData.append('currency', currency);

    try {
        const res = await fetch('api/invoices.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast(data.message || 'Costo actualizado');
            closeModal('edit-budget-modal');
            location.hash = 'finanzas';
            location.reload();
        } else {
            showToast(data.message || 'Error al actualizar costo', 'error');
        }
    } catch (err) {
        showToast('Error de conexión', 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function updateInvoiceStatusAjax(invoiceId, newStatus) {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('invoice_id', invoiceId);
    formData.append('status', newStatus);

    try {
        const res = await fetch('api/invoices.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast('Estado de factura actualizado');
            const badge = document.getElementById(`inv-badge-${invoiceId}`);
            if (badge) {
                badge.innerText = newStatus;
                const badgeClasses = {
                    'pagada': 'bg-emerald-50 text-emerald-700 border-emerald-200/60',
                    'pendiente': 'bg-amber-50 text-amber-700 border-amber-200/60',
                    'anulada': 'bg-rose-50 text-rose-700 border-rose-200/60'
                };
                badge.className = `inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium capitalize ${badgeClasses[newStatus] || 'bg-slate-50 text-slate-700 border-slate-200/60'}`;
            }
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(data.message || 'Error al actualizar estado', 'error');
        }
    } catch (err) {
        showToast('Error de conexión', 'error');
    }
}

async function deleteInvoiceAjax(invoiceId) {
    if (!confirm('¿Eliminar esta factura del proyecto?')) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('project_id', PROJECT_ID);
    formData.append('csrf_token', CSRF_TOKEN);
    formData.append('invoice_id', invoiceId);

    try {
        const res = await fetch('api/invoices.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast('Factura eliminada');
            const row = document.getElementById(`invoice-row-${invoiceId}`);
            if (row) row.remove();
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(data.message || 'Error al eliminar', 'error');
        }
    } catch (err) {
        showToast('Error de conexión', 'error');
    }
}
