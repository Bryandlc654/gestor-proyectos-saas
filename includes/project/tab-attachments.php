<?php
// includes/project/tab-attachments.php - Pestaña Adjuntos (archivos, enlaces y carpetas).
// Parcial de project-view.php: usa las variables preparadas en esa página.
?>
        <!-- ==================== TAB 5: ADJUNTOS ==================== -->
        <section id="tab-adjuntos" class="tab-content hidden space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-semibold text-base text-slate-900">Adjuntos y Recursos</h2>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-2xs font-semibold bg-slate-100 text-slate-500">
                        <?= count($files) + count($links) ?>
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <?php if (!$isClient && $canManageAttachments): ?>
                        <button onclick="openModal('create-folder-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs border border-slate-200/80 shadow-2xs transition">
                            <i data-lucide="folder-plus" class="w-3.5 h-3.5 text-slate-500"></i>
                            <span>Carpeta</span>
                        </button>
                    <?php endif; ?>
                    <button onclick="openModal('upload-file-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs shadow-2xs transition">
                        <i data-lucide="upload" class="w-3.5 h-3.5"></i>
                        <span>Subir</span>
                    </button>
                    <?php if (!$isClient && $canManageAttachments): ?>
                        <button onclick="openModal('create-link-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs border border-slate-200/80 shadow-2xs transition">
                            <i data-lucide="link" class="w-3.5 h-3.5 text-slate-500"></i>
                            <span>Enlace</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Navegador de Carpetas (Oculto para clientes) -->
            <?php if (!$isClient): ?>
            <div class="space-y-2">
                <span class="text-xs font-medium text-slate-500">Carpetas del Proyecto</span>
                <div id="folders-bar" class="flex items-center gap-2 overflow-x-auto pb-1">
                    <button onclick="filterByFolder('all', this)" class="folder-pill active inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-brand-600 text-white shadow-2xs transition whitespace-nowrap">
                        <i data-lucide="layers" class="w-3.5 h-3.5"></i>
                        <span>Todos los adjuntos</span>
                    </button>

                    <button onclick="filterByFolder('root', this)" class="folder-pill inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-white hover:bg-slate-50 text-slate-600 border border-slate-200/80 transition whitespace-nowrap">
                        <i data-lucide="folder" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>Sin carpeta (Raíz)</span>
                    </button>

                    <?php foreach ($folders as $fol): ?>
                        <div id="folder-pill-container-<?= $fol['id'] ?>" class="inline-flex items-center group">
                            <button onclick="filterByFolder(<?= $fol['id'] ?>, this)" class="folder-pill inline-flex items-center gap-2 px-3.5 py-1.5 <?= $canManageAttachments ? 'rounded-l-xl' : 'rounded-xl' ?> text-xs font-semibold bg-white hover:bg-slate-50 text-slate-700 border <?= $canManageAttachments ? 'border-r-0' : '' ?> border-slate-200/80 transition whitespace-nowrap">
                                <span class="w-2.5 h-2.5 rounded-full" style="background-color: <?= e($fol['color']) ?>;"></span>
                                <span><?= e($fol['name']) ?></span>
                            </button>
                            <?php if ($canManageAttachments): ?>
                                <button onclick="deleteFolderAjax(<?= $fol['id'] ?>)" class="px-2 py-1.5 rounded-r-xl border border-l-0 border-slate-200/80 bg-white hover:bg-rose-50 text-slate-300 hover:text-rose-600 transition" title="Eliminar carpeta">
                                    <i data-lucide="x" class="w-3 h-3"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Subsección 1: Archivos Adjuntos -->
            <div class="space-y-3">
                <div class="flex items-center gap-2">
                    <i data-lucide="file-text" class="w-4 h-4 text-brand-600"></i>
                    <h3 class="font-display font-semibold text-slate-800 text-sm">Archivos Descargables</h3>
                    <span id="badge-files-count" class="px-2 py-0.5 rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"><?= count($files) ?></span>
                </div>

                <div id="files-empty-state" class="bg-white rounded-2xl border border-dashed border-slate-200 p-8 text-center <?= empty($files) ? '' : 'hidden' ?>">
                    <i data-lucide="paperclip" class="w-7 h-7 text-slate-300 mx-auto mb-1.5"></i>
                    <h4 class="text-xs font-semibold text-slate-700">Sin archivos en esta vista</h4>
                </div>

                <div id="files-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5 <?= empty($files) ? 'hidden' : '' ?>">
                    <?php foreach ($files as $file): ?>
                        <div id="file-card-<?= $file['id'] ?>" data-folder-id="<?= $file['folder_id'] ?: 'root' ?>" class="file-item-card bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs hover:border-slate-300 transition flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="file" class="w-4 h-4"></i>
                                </div>
                                <div class="min-w-0">
                                    <h4 class="font-semibold text-slate-900 text-xs truncate" title="<?= e($file['original_name']) ?>">
                                        <?= e($file['original_name']) ?>
                                    </h4>

                                    <!-- Chip de Tarea si pertenece a una tarea -->
                                    <?php if (!empty($file['task_id']) && !empty($file['task_title'])): ?>
                                        <button onclick="openTaskDetailModal(<?= $file['task_id'] ?>)" class="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 bg-brand-50 px-1.5 py-0.5 rounded hover:bg-brand-100 transition mt-0.5">
                                            <i data-lucide="check-square" class="w-3 h-3"></i>
                                            <span class="truncate max-w-[140px]">Tarea: <?= e($file['task_title']) ?></span>
                                        </button>
                                    <?php endif; ?>

                                    <p class="text-[10px] text-slate-400 mt-0.5">
                                        <?= formatBytes($file['file_size']) ?> • <?= formatDate($file['created_at']) ?>
                                        <?php if (!empty($file['folder_name'])): ?>
                                            • <span class="text-brand-600 font-medium"><?= e($file['folder_name']) ?></span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-1 flex-shrink-0">
                                <a href="api/files.php?action=download&id=<?= $file['id'] ?>" class="p-1.5 rounded-lg bg-slate-100 hover:bg-brand-50 text-slate-600 hover:text-brand-600 transition" title="Descargar">
                                    <i data-lucide="download" class="w-3.5 h-3.5"></i>
                                </a>
                                <?php if ($canManageAttachments || $file['user_id'] == $user['id']): ?>
                                <button onclick="deleteFileAjax(<?= $file['id'] ?>)" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" title="Eliminar">
                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!$isClient): ?>
            <!-- Subsección 2: Enlaces (Solo equipo interno) -->
            <div class="space-y-3 pt-3 border-t border-slate-200/60">
                <div class="flex items-center gap-2">
                    <i data-lucide="link" class="w-4 h-4 text-emerald-600"></i>
                    <h3 class="font-display font-semibold text-slate-800 text-sm">Enlaces y Accesos Directos</h3>
                    <span id="badge-links-count" class="px-2 py-0.5 rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"><?= count($links) ?></span>
                </div>

                <div id="links-empty-state" class="bg-white rounded-2xl border border-dashed border-slate-200 p-8 text-center <?= empty($links) ? '' : 'hidden' ?>">
                    <i data-lucide="link-2" class="w-7 h-7 text-slate-300 mx-auto mb-1.5"></i>
                    <h4 class="text-xs font-semibold text-slate-700">Sin enlaces en esta vista</h4>
                </div>

                <div id="links-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5 <?= empty($links) ? 'hidden' : '' ?>">
                    <?php foreach ($links as $link): ?>
                        <div id="link-card-<?= $link['id'] ?>" data-folder-id="<?= $link['folder_id'] ?: 'root' ?>" class="link-item-card bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs hover:border-slate-300 transition flex items-start justify-between gap-3 group">
                            <div class="flex items-start gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-xl bg-slate-100 group-hover:bg-indigo-50 text-slate-600 group-hover:text-brand-600 flex items-center justify-center flex-shrink-0 transition">
                                    <i data-lucide="external-link" class="w-4 h-4"></i>
                                </div>
                                <div class="min-w-0">
                                    <h4 class="font-semibold text-slate-900 text-xs truncate"><?= e($link['title']) ?></h4>

                                    <!-- Chip de Tarea si pertenece a una tarea -->
                                    <?php if (!empty($link['task_id']) && !empty($link['task_title'])): ?>
                                        <button onclick="openTaskDetailModal(<?= $link['task_id'] ?>)" class="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 bg-brand-50 px-1.5 py-0.5 rounded hover:bg-brand-100 transition mt-0.5">
                                            <i data-lucide="check-square" class="w-3 h-3"></i>
                                            <span class="truncate max-w-[140px]">Tarea: <?= e($link['task_title']) ?></span>
                                        </button>
                                    <?php endif; ?>

                                    <a href="<?= isSafeUrl($link['url']) ? e($link['url']) : '#' ?>" target="_blank" rel="noopener noreferrer" class="text-[11px] text-brand-600 hover:underline truncate block mt-0.5">
                                        <?= e($link['url']) ?>
                                    </a>
                                </div>
                            </div>
                            <button onclick="deleteLinkAjax(<?= $link['id'] ?>)" class="p-1 text-slate-400 hover:text-rose-600 transition" title="Eliminar enlace">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </section>
