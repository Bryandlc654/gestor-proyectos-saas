<?php
// includes/project/tab-comments.php - Pestaña Comentarios y notas.
// Parcial de project-view.php: usa las variables preparadas en esa página.
?>
        <!-- ==================== TAB 4: COMENTARIOS & NOTAS ==================== -->
        <section id="tab-comments" class="tab-content hidden space-y-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-semibold text-base text-slate-900">Notas & Acuerdos</h2>
                    <span class="text-xs text-slate-400"><?= count($comments) ?> hilos</span>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-2xs">
                <form id="comment-form" onsubmit="submitCommentAjax(event)" class="space-y-3">
                    <textarea id="comment-content-input" rows="2" placeholder="Escribe una nota rápida o pega una captura aquí con Ctrl+V..." class="w-full p-3 rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:ring-1 focus:ring-brand-500 leading-relaxed"></textarea>
                    
                    <div id="comment-image-preview-container" class="hidden relative inline-block border border-slate-200 rounded-xl p-1.5 bg-slate-50">
                        <img id="comment-preview-img" src="" alt="Vista previa" class="h-24 max-w-xs object-cover rounded-lg">
                        <button type="button" onclick="clearCommentImage()" class="absolute -top-2 -right-2 w-5 h-5 rounded-full bg-rose-600 text-white flex items-center justify-center shadow hover:bg-rose-700 transition" title="Quitar">
                            <i data-lucide="x" class="w-3 h-3"></i>
                        </button>
                    </div>

                    <input type="file" id="comment-image-file" accept="image/*" class="hidden" onchange="handleCommentImageSelect(this)">

                    <div class="flex items-center justify-between pt-1 border-t border-slate-100">
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="document.getElementById('comment-image-file').click()" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-slate-500 hover:text-brand-600 hover:bg-brand-50 text-xs font-medium transition" title="Adjuntar imagen">
                                <i data-lucide="image" class="w-4 h-4"></i>
                                <span>Imagen</span>
                            </button>
                            <span class="text-[11px] text-slate-400 hidden sm:inline">• <kbd class="px-1 py-0.5 bg-slate-100 rounded text-slate-500 font-mono text-[10px]">Ctrl + V</kbd> para pegar captura</span>
                        </div>

                        <button type="submit" id="submit-comment-btn" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs rounded-xl shadow-xs transition">
                            <i data-lucide="send" class="w-3 h-3"></i>
                            <span>Publicar</span>
                        </button>
                    </div>
                </form>
            </div>

            <div id="comments-empty-state" class="text-center py-8 text-xs text-slate-400 italic <?= empty($comments) ? '' : 'hidden' ?>">
                No hay notas publicadas en este proyecto todavía.
            </div>

            <div id="comments-feed" class="space-y-4">
                <?php foreach ($comments as $comm): ?>
                    <?php $commReplies = $repliesByParent[$comm['id']] ?? []; ?>
                    <div id="comment-card-<?= $comm['id'] ?>" class="bg-white rounded-2xl border border-slate-200/70 p-4 shadow-2xs space-y-3 group">
                        <!-- Comentario Padre -->
                        <div class="flex items-start gap-3.5">
                            <div class="w-8 h-8 rounded-full bg-brand-50 text-brand-700 font-bold text-xs flex items-center justify-center flex-shrink-0">
                                <?= strtoupper(substr($comm['author_name'], 0, 2)) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center justify-between gap-1 mb-1">
                                    <div class="flex items-center gap-2">
                                        <h4 class="text-xs font-bold text-slate-900"><?= e($comm['author_name']) ?></h4>
                                        <?php if (!empty($comm['task_id']) && !empty($comm['task_title'])): ?>
                                            <button onclick="openTaskDetailModal(<?= $comm['task_id'] ?>)" class="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 bg-brand-50 px-2 py-0.5 rounded-md hover:bg-brand-100 transition">
                                                <i data-lucide="check-square" class="w-3 h-3"></i>
                                                <span class="truncate max-w-[200px]">Tarea: <?= e($comm['task_title']) ?></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] text-slate-400"><?= formatDate($comm['created_at'], true) ?></span>
                                        <?php if ($comm['user_id'] == $user['id'] || isAdmin()): ?>
                                            <div class="opacity-0 group-hover:opacity-100 transition flex items-center gap-1">
                                                <button onclick="openEditCommentModal(<?= $comm['id'] ?>, <?= htmlspecialchars(json_encode($comm['content']), ENT_QUOTES, 'UTF-8') ?>)" class="p-1 text-slate-400 hover:text-brand-600 rounded" title="Editar comentario">
                                                    <i data-lucide="edit-2" class="w-3 h-3"></i>
                                                </button>
                                                <button onclick="deleteCommentAjax(<?= $comm['id'] ?>)" class="p-1 text-slate-400 hover:text-rose-600 rounded" title="Eliminar comentario">
                                                    <i data-lucide="trash-2" class="w-3 h-3"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!empty($comm['content'])): ?>
                                    <p id="comment-text-<?= $comm['id'] ?>" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line"><?= e($comm['content']) ?></p>
                                <?php endif; ?>

                                <?php if (!empty($comm['image_path'])): ?>
                                    <div class="mt-2.5">
                                        <img src="uploads/<?= e($comm['image_path']) ?>" alt="Imagen adjunta" class="max-h-72 rounded-xl border border-slate-200 shadow-2xs hover:opacity-95 cursor-pointer transition" onclick="openImageModal(this.src)">
                                    </div>
                                <?php endif; ?>

                                <!-- Botón Responder -->
                                <div class="mt-2 flex items-center gap-3">
                                    <button onclick="toggleReplyBox(<?= $comm['id'] ?>)" class="text-[11px] font-semibold text-brand-600 hover:text-brand-700 inline-flex items-center gap-1 transition">
                                        <i data-lucide="reply" class="w-3 h-3"></i>
                                        <span>Responder</span>
                                    </button>
                                    <?php if (!empty($commReplies)): ?>
                                        <span class="text-[10px] text-slate-400 font-medium">
                                            <?= count($commReplies) ?> <?= count($commReplies) === 1 ? 'respuesta' : 'respuestas' ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Respuestas anidadas -->
                        <div id="replies-container-<?= $comm['id'] ?>" class="space-y-2.5 pl-6 sm:pl-10 border-l-2 border-slate-100 <?= empty($commReplies) ? 'hidden' : '' ?>">
                            <?php foreach ($commReplies as $rep): ?>
                                <div id="comment-card-<?= $rep['id'] ?>" class="bg-slate-50/70 p-3 rounded-xl border border-slate-200/50 flex items-start gap-2.5 group/reply">
                                    <div class="w-6 h-6 rounded-full bg-slate-200 text-slate-700 font-bold text-[10px] flex items-center justify-center flex-shrink-0">
                                        <?= strtoupper(substr($rep['author_name'], 0, 2)) ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between mb-0.5">
                                            <h5 class="text-xs font-bold text-slate-800"><?= e($rep['author_name']) ?></h5>
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-[9px] text-slate-400"><?= formatDate($rep['created_at'], true) ?></span>
                                                <?php if ($rep['user_id'] == $user['id'] || isAdmin()): ?>
                                                    <div class="opacity-0 group-hover/reply:opacity-100 transition flex items-center gap-0.5">
                                                        <button onclick="openEditCommentModal(<?= $rep['id'] ?>, <?= htmlspecialchars(json_encode($rep['content']), ENT_QUOTES, 'UTF-8') ?>)" class="p-0.5 text-slate-400 hover:text-brand-600 rounded" title="Editar">
                                                            <i data-lucide="edit-2" class="w-2.5 h-2.5"></i>
                                                        </button>
                                                        <button onclick="deleteCommentAjax(<?= $rep['id'] ?>)" class="p-0.5 text-slate-400 hover:text-rose-600 rounded" title="Eliminar">
                                                            <i data-lucide="trash-2" class="w-2.5 h-2.5"></i>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($rep['content'])): ?>
                                            <p id="comment-text-<?= $rep['id'] ?>" class="text-xs text-slate-600 leading-relaxed whitespace-pre-line"><?= e($rep['content']) ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($rep['image_path'])): ?>
                                            <div class="mt-2">
                                                <img src="uploads/<?= e($rep['image_path']) ?>" class="max-h-48 rounded-lg border border-slate-200 cursor-pointer" onclick="openImageModal(this.src)">
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Caja para responder desplegable -->
                        <div id="reply-box-<?= $comm['id'] ?>" class="hidden pl-6 sm:pl-10 pt-2 border-l-2 border-slate-100">
                            <form onsubmit="submitReplyAjax(event, <?= $comm['id'] ?>, <?= !empty($comm['task_id']) ? $comm['task_id'] : 'null' ?>)" class="space-y-2">
                                <textarea id="reply-content-<?= $comm['id'] ?>" rows="2" placeholder="Escribe tu respuesta a <?= e($comm['author_name']) ?>..." required class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-brand-500 bg-slate-50/50 leading-relaxed"></textarea>
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" onclick="toggleReplyBox(<?= $comm['id'] ?>)" class="px-3 py-1 rounded-lg text-xs text-slate-500 hover:bg-slate-100">Cancelar</button>
                                    <button type="submit" id="btn-submit-reply-<?= $comm['id'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs rounded-xl shadow-xs transition">
                                        <i data-lucide="send" class="w-3 h-3"></i>
                                        <span>Enviar Respuesta</span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
