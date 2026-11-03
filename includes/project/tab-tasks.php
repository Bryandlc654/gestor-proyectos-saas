<?php
// includes/project/tab-tasks.php - Pestaña Tareas (Kanban y lista).
// Parcial de project-view.php: usa las variables preparadas en esa página.
?>
        <!-- ==================== TAB 1: TAREAS (KANBAN O LISTA) ==================== -->
        <section id="tab-tasks" class="tab-content space-y-5">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-semibold text-base text-slate-900">Tareas</h2>
                    <span class="text-xs text-slate-400"><?= count($tasks) ?> en total</span>
                </div>

                <div class="flex items-center gap-2.5">
                    <div class="bg-slate-100 p-0.5 rounded-lg flex items-center">
                        <button onclick="setTaskView('kanban')" id="view-btn-kanban" class="px-2.5 py-1 rounded-md text-xs font-semibold flex items-center gap-1.5 bg-white text-slate-800 shadow-2xs transition">
                            <i data-lucide="columns-3" class="w-3.5 h-3.5"></i>
                            <span class="hidden sm:inline">Tablero</span>
                        </button>
                        <button onclick="setTaskView('list')" id="view-btn-list" class="px-2.5 py-1 rounded-md text-xs font-medium flex items-center gap-1.5 text-slate-500 hover:text-slate-800 transition">
                            <i data-lucide="list" class="w-3.5 h-3.5"></i>
                            <span class="hidden sm:inline">Lista</span>
                        </button>
                    </div>

                    <?php if ($canManageTasks): ?>
                        <button onclick="openModal('create-task-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs shadow-xs transition">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            <span>Nueva</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Estado Vacío -->
            <div id="tasks-empty-state" class="bg-white rounded-2xl border border-dashed border-slate-200 p-12 text-center <?= empty($tasks) ? '' : 'hidden' ?>">
                <div class="w-12 h-12 bg-indigo-50 text-brand-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="check-square" class="w-6 h-6"></i>
                </div>
                <h3 class="text-sm font-semibold text-slate-800">No hay tareas creadas todavía</h3>
                <p class="text-xs text-slate-500 mt-1 mb-4">Comienza agregando una tarea al proyecto.</p>
                <?php if ($canManageTasks): ?>
                    <button onclick="openModal('create-task-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 text-white text-xs font-semibold rounded-lg shadow-xs">
                        + Agregar Tarea
                    </button>
                <?php endif; ?>
            </div>

            <!-- VISTA KANBAN -->
            <div id="tasks-kanban-view" class="grid grid-cols-1 md:grid-cols-3 gap-5 <?= empty($tasks) ? 'hidden' : '' ?>">
                <!-- Columna: Pendientes -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between px-1">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span>
                            <h3 class="font-display font-semibold text-slate-800 text-xs sm:text-sm">Por Hacer</h3>
                        </div>
                        <span id="count-kanban-pendiente" class="text-xs font-medium text-slate-400 tabular-nums"><?= count($tasksPending) ?></span>
                    </div>

                    <div id="kanban-col-pendiente" class="kanban-column space-y-3 min-h-[150px] p-2 bg-slate-100/50 rounded-2xl border border-slate-200/50">
                        <?php foreach ($tasksPending as $task): ?>
                            <?php include dirname(__DIR__) . '/task-kanban-card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Columna: En Progreso -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between px-1">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                            <h3 class="font-display font-semibold text-slate-800 text-xs sm:text-sm">En Progreso</h3>
                        </div>
                        <span id="count-kanban-en_progreso" class="text-xs font-medium text-slate-400 tabular-nums"><?= count($tasksInProgress) ?></span>
                    </div>

                    <div id="kanban-col-en_progreso" class="kanban-column space-y-3 min-h-[150px] p-2 bg-slate-100/50 rounded-2xl border border-slate-200/50">
                        <?php foreach ($tasksInProgress as $task): ?>
                            <?php include dirname(__DIR__) . '/task-kanban-card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Columna: Completadas -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between px-1">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            <h3 class="font-display font-semibold text-slate-800 text-xs sm:text-sm">Completadas</h3>
                        </div>
                        <span id="count-kanban-completada" class="text-xs font-medium text-slate-400 tabular-nums"><?= count($tasksCompleted) ?></span>
                    </div>

                    <div id="kanban-col-completada" class="kanban-column space-y-3 min-h-[150px] p-2 bg-slate-100/50 rounded-2xl border border-slate-200/50">
                        <?php foreach ($tasksCompleted as $task): ?>
                            <?php include dirname(__DIR__) . '/task-kanban-card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- VISTA LISTA -->
            <div id="tasks-list-view" class="hidden space-y-2">
                <div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden shadow-2xs divide-y divide-slate-100" id="tasks-list-container">
                    <?php foreach ($tasks as $task): ?>
                        <div id="task-list-row-<?= $task['id'] ?>" class="p-3.5 sm:px-5 flex items-center justify-between gap-3 hover:bg-slate-50/70 transition group">
                            <div class="flex items-center gap-3 min-w-0">
                                <?php if ($canChangeStatus): ?>
                                    <input type="checkbox" onchange="toggleTaskCompleteFromList(<?= $task['id'] ?>, this)" <?= $task['status'] === 'completada' ? 'checked' : '' ?> class="w-4 h-4 rounded text-brand-600 focus:ring-brand-500 border-slate-300 transition cursor-pointer">
                                <?php else: ?>
                                    <input type="checkbox" disabled <?= $task['status'] === 'completada' ? 'checked' : '' ?> class="w-4 h-4 rounded text-slate-300 border-slate-200 cursor-not-allowed">
                                <?php endif; ?>
                                
                                <div class="min-w-0 cursor-pointer" onclick="openTaskDetailModal(<?= $task['id'] ?>)">
                                    <h4 class="font-display font-semibold text-xs sm:text-sm text-slate-900 group-hover:text-brand-600 transition <?= $task['status'] === 'completada' ? 'line-through text-slate-400' : '' ?>">
                                        <?= e($task['title']) ?>
                                    </h4>
                                    <div class="flex items-center gap-2 mt-0.5 text-[11px] text-slate-400">
                                        <span><?= formatDate($task['due_date']) ?></span>
                                        <?php if (!empty($task['assigned_to'])): ?>
                                            <span>•</span>
                                            <span class="text-slate-600 font-medium"><?= e($task['assigned_to']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 flex-shrink-0">
                                <?php if (!$isClient): ?>
                                    <button onclick="openTaskDetailModal(<?= $task['id'] ?>)" class="flex items-center gap-1 text-[11px] text-slate-400 hover:text-brand-600 p-1 rounded transition" title="Ver comentarios">
                                        <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
                                        <span class="task-comments-count-<?= $task['id'] ?>"><?= (int)$task['comments_count'] ?></span>
                                    </button>
                                <?php endif; ?>

                                <?php
                                    $prioClass = match($task['priority']) {
                                        'urgente' => 'bg-rose-50 text-rose-700 border-rose-200/60',
                                        'alta'    => 'bg-amber-50 text-amber-700 border-amber-200/60',
                                        'media'   => 'bg-indigo-50 text-indigo-700 border-indigo-200/60',
                                        default   => 'bg-slate-50 text-slate-600 border-slate-200/60'
                                    };
                                ?>
                                <span class="text-[11px] font-medium capitalize px-2 py-0.5 rounded-full <?= $prioClass ?>">
                                    <?= e($task['priority']) ?>
                                </span>

                                <?php if ($canChangeStatus): ?>
                                    <select onchange="updateTaskStatus(<?= $task['id'] ?>, this.value)" class="text-[11px] font-semibold rounded-lg px-2 py-1 border border-slate-200 bg-slate-50 text-slate-700 cursor-pointer focus:outline-none focus:ring-1 focus:ring-brand-500">
                                        <option value="pendiente" <?= $task['status'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                        <option value="en_progreso" <?= $task['status'] === 'en_progreso' ? 'selected' : '' ?>>En Progreso</option>
                                        <option value="completada" <?= $task['status'] === 'completada' ? 'selected' : '' ?>>Completada</option>
                                    </select>
                                <?php else: ?>
                                    <span class="text-[10px] font-semibold text-slate-500 capitalize bg-slate-100 px-2 py-0.5 rounded-md">
                                        <?= str_replace('_', ' ', $task['status']) ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($canDeleteTasks): ?>
                                    <button onclick="deleteTask(<?= $task['id'] ?>)" class="p-1 text-slate-400 hover:text-rose-600 transition" title="Eliminar tarea">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
