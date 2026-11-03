<?php
// includes/global-task-card.php - Tarjeta visual de tarea multi-proyecto para el Tablero Kanban Global
$prioClass = match($task['priority']) {
    'urgente' => 'bg-rose-50 text-rose-700 border-rose-200/60',
    'alta'    => 'bg-amber-50 text-amber-700 border-amber-200/60',
    'media'   => 'bg-indigo-50 text-indigo-700 border-indigo-200/60',
    default   => 'bg-slate-50 text-slate-600 border-slate-200/60'
};
?>
<div id="global-task-card-<?= $task['id'] ?>" class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs hover:shadow-sm hover:border-slate-300 transition-all flex flex-col justify-between group">
    <div>
        <!-- Pill de Proyecto Asociado y Prioridad -->
        <div class="flex items-center justify-between gap-2 mb-2">
            <a href="project-view.php?id=<?= $task['project_id'] ?>#tasks" class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-semibold transition truncate max-w-[170px]" title="<?= e($task['project_name']) ?>">
                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background-color: <?= e($task['project_color'] ?: '#6366F1') ?>;"></span>
                <span class="truncate"><?= e($task['project_name']) ?></span>
            </a>

            <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md border <?= $prioClass ?>">
                <?= e($task['priority']) ?>
            </span>
        </div>

        <!-- Título de la Tarea -->
        <h4 class="font-display font-semibold text-xs sm:text-sm text-slate-900 group-hover:text-brand-600 transition leading-snug mb-1.5 <?= $task['status'] === 'completada' ? 'line-through text-slate-400' : '' ?>">
            <a href="project-view.php?id=<?= $task['project_id'] ?>#tasks">
                <?= e($task['title']) ?>
            </a>
        </h4>

        <?php if (!empty($task['description'])): ?>
            <p class="text-[11px] text-slate-400 line-clamp-2 leading-relaxed mb-3">
                <?= e($task['description']) ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="pt-2.5 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-400 gap-2">
        <!-- Colaborador y Fecha -->
        <div class="flex flex-wrap items-center gap-1.5 min-w-0">
            <?php if (!empty($task['assigned_to'])): ?>
                <span class="inline-flex items-center gap-1 bg-slate-100 px-1.5 py-0.5 rounded-md text-[10px] font-medium text-slate-700 truncate max-w-[100px]" title="<?= e($task['assigned_to']) ?>">
                    <span class="w-1.5 h-1.5 rounded-full bg-brand-500 flex-shrink-0"></span>
                    <span class="truncate"><?= e($task['assigned_to']) ?></span>
                </span>
            <?php endif; ?>

            <?php if ($task['due_date']): ?>
                <span class="inline-flex items-center gap-1 text-[10px] text-slate-400 whitespace-nowrap">
                    <i data-lucide="calendar" class="w-3 h-3 text-slate-400"></i>
                    <span><?= formatDate($task['due_date']) ?></span>
                </span>
            <?php endif; ?>
        </div>

        <!-- Acciones Rápidas (Cambio de columna o eliminar) -->
        <div class="flex items-center gap-1.5 flex-shrink-0">
            <?php if ($canChangeStatus): ?>
                <select onchange="updateTaskStatusGlobal(<?= $task['id'] ?>, <?= $task['project_id'] ?>, this.value)" class="text-[10px] font-semibold rounded-lg px-2 py-0.5 border border-slate-200 bg-slate-50 text-slate-700 cursor-pointer focus:outline-none focus:ring-1 focus:ring-brand-500">
                    <option value="pendiente" <?= $task['status'] === 'pendiente' ? 'selected' : '' ?>>Por Hacer</option>
                    <option value="en_progreso" <?= $task['status'] === 'en_progreso' ? 'selected' : '' ?>>En Progreso</option>
                    <option value="completada" <?= $task['status'] === 'completada' ? 'selected' : '' ?>>Completada</option>
                </select>
            <?php endif; ?>

            <?php if ($canDeleteTasks): ?>
                <button onclick="deleteTaskGlobal(<?= $task['id'] ?>, <?= $task['project_id'] ?>)" class="opacity-0 group-hover:opacity-100 p-1 text-slate-400 hover:text-rose-600 transition" title="Eliminar tarea">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>
