<?php
// includes/task-kanban-card.php - Tarjeta moderna para el Tablero Kanban
$prioClass = match($task['priority']) {
    'urgente' => 'bg-rose-50 text-rose-700 border-rose-200/60',
    'alta'    => 'bg-amber-50 text-amber-700 border-amber-200/60',
    'media'   => 'bg-indigo-50 text-indigo-700 border-indigo-200/60',
    default   => 'bg-slate-50 text-slate-600 border-slate-200/60'
};
?>
<div id="kanban-card-<?= $task['id'] ?>" class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs hover:shadow-sm hover:border-slate-300 transition-all flex flex-col justify-between group cursor-pointer" onclick="openTaskDetailModal(<?= $task['id'] ?>)">
    <div>
        <div class="flex items-center justify-between gap-2 mb-2.5" onclick="event.stopPropagation()">
            <span class="text-[11px] font-medium capitalize px-2 py-0.5 rounded-full <?= $prioClass ?>">
                <?= e($task['priority']) ?>
            </span>

            <!-- Selector para mover de columna en Kanban según permisos -->
            <?php if (canChangeTaskStatus()): ?>
                <select onchange="updateTaskStatus(<?= $task['id'] ?>, this.value)" class="text-[10px] font-semibold rounded-lg px-2 py-0.5 border border-slate-200 bg-slate-50 text-slate-700 cursor-pointer focus:outline-none focus:ring-1 focus:ring-brand-500">
                    <option value="pendiente" <?= $task['status'] === 'pendiente' ? 'selected' : '' ?>>Por Hacer</option>
                    <option value="en_progreso" <?= $task['status'] === 'en_progreso' ? 'selected' : '' ?>>En Progreso</option>
                    <option value="completada" <?= $task['status'] === 'completada' ? 'selected' : '' ?>>Completada</option>
                </select>
            <?php else: ?>
                <span class="text-[10px] font-semibold text-slate-500 capitalize bg-slate-100 px-2 py-0.5 rounded-md">
                    <?= str_replace('_', ' ', $task['status']) ?>
                </span>
            <?php endif; ?>
        </div>

        <h4 class="font-display font-semibold text-xs sm:text-sm text-slate-900 group-hover:text-brand-600 transition leading-snug mb-1.5 <?= $task['status'] === 'completada' ? 'line-through text-slate-400' : '' ?>">
            <?= e($task['title']) ?>
        </h4>

        <?php if (!empty($task['description'])): ?>
            <p class="text-[11px] text-slate-500 line-clamp-2 leading-relaxed mb-3">
                <?= e($task['description']) ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="pt-2.5 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400 gap-2" onclick="event.stopPropagation()">
        <!-- Colaborador y Fecha -->
        <div class="flex flex-wrap items-center gap-1.5 min-w-0">
            <?php if (!empty($task['assigned_to'])): ?>
                <span class="inline-flex items-center gap-1 bg-slate-100 px-1.5 py-0.5 rounded-md text-[10px] font-medium text-slate-700 truncate max-w-[110px]" title="<?= e($task['assigned_to']) ?>">
                    <span class="w-1.5 h-1.5 rounded-full bg-brand-500 flex-shrink-0"></span>
                    <span class="truncate"><?= e($task['assigned_to']) ?></span>
                </span>
            <?php endif; ?>
            <span class="inline-flex items-center gap-1 text-[10px] text-slate-400 whitespace-nowrap">
                <i data-lucide="calendar" class="w-3 h-3 text-slate-400"></i>
                <span><?= formatDate($task['due_date']) ?></span>
            </span>
        </div>

        <div class="flex items-center gap-2 flex-shrink-0">
            <!-- Contador de Comentarios de la Tarea (no visible para clientes) -->
            <?php if (!isCliente()): ?>
                <span class="inline-flex items-center gap-1 text-[10px] font-medium text-slate-400 group-hover:text-brand-600 transition" title="Comentarios">
                    <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
                    <span class="task-comments-count-<?= $task['id'] ?>"><?= (int)$task['comments_count'] ?></span>
                </span>
            <?php endif; ?>

            <?php if (canDeleteTasks()): ?>
                <button onclick="deleteTask(<?= $task['id'] ?>)" class="opacity-0 group-hover:opacity-100 p-1 text-slate-400 hover:text-rose-600 transition" title="Eliminar">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>
