<?php
// includes/project/tab-tickets.php - Pestaña Tickets y soporte.
// Parcial de project-view.php: usa las variables preparadas en esa página.
?>
        <!-- ==================== TAB 3: TICKETS & SOPORTE ==================== -->
        <section id="tab-tickets" class="tab-content hidden space-y-5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h2 class="font-display font-bold text-base text-slate-900">Tickets</h2>
                    <span class="text-xs text-slate-400"><?= count($tickets) ?> registrados</span>
                </div>
                <button onclick="openModal('create-ticket-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs shadow-xs transition">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                    <span>Nuevo Ticket</span>
                </button>
            </div>

            <div id="tickets-empty-state" class="bg-white rounded-2xl border border-dashed border-slate-200 p-12 text-center <?= empty($tickets) ? '' : 'hidden' ?>">
                <div class="w-12 h-12 bg-indigo-50 text-brand-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="life-buoy" class="w-6 h-6"></i>
                </div>
                <h3 class="text-sm font-semibold text-slate-800">No hay tickets reportados</h3>
                <p class="text-xs text-slate-500 mt-1 mb-4">Todo marcha según lo planeado sin incidencias abiertas.</p>
                <button onclick="openModal('create-ticket-modal')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-brand-600 text-white text-xs font-semibold rounded-lg shadow-xs">
                    + Reportar Ticket
                </button>
            </div>

            <div id="tickets-container" class="space-y-4 <?= empty($tickets) ? 'hidden' : '' ?>">
                <?php foreach ($tickets as $ticket): ?>
                    <div id="ticket-card-<?= $ticket['id'] ?>" class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-2xs space-y-3.5">
                        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                            <div class="space-y-1.5 flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-mono font-semibold text-slate-400">#TK-<?= $ticket['id'] ?></span>
                                    <h3 class="font-display font-bold text-slate-900 text-sm sm:text-base"><?= e($ticket['title']) ?></h3>
                                    <?php
                                        $ticketPrio = match($ticket['priority']) {
                                            'critica' => 'bg-rose-50 text-rose-700 border-rose-200/60',
                                            'alta'    => 'bg-amber-50 text-amber-700 border-amber-200/60',
                                            'media'   => 'bg-blue-50 text-blue-700 border-blue-200/60',
                                            default   => 'bg-slate-50 text-slate-600 border-slate-200/60'
                                        };
                                    ?>
                                    <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md border <?= $ticketPrio ?>">
                                        <?= e($ticket['priority']) ?>
                                    </span>
                                </div>
                                <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line"><?= e($ticket['description']) ?></p>
                                <div class="flex items-center gap-3 text-[11px] text-slate-400 pt-0.5">
                                    <span>Reportado por: <strong class="text-slate-700"><?= e($ticket['author_name']) ?></strong></span>
                                    <span>•</span>
                                    <span><?= formatDate($ticket['created_at'], true) ?></span>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 self-end sm:self-start">
                                <select onchange="updateTicketStatus(<?= $ticket['id'] ?>, this.value)" class="text-xs font-semibold rounded-xl px-2.5 py-1.5 border border-slate-200 bg-slate-50 text-slate-800 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                    <option value="abierto" <?= $ticket['status'] === 'abierto' ? 'selected' : '' ?>>Abierto</option>
                                    <option value="en_revision" <?= $ticket['status'] === 'en_revision' ? 'selected' : '' ?>>En Revisión</option>
                                    <option value="resuelto" <?= $ticket['status'] === 'resuelto' ? 'selected' : '' ?>>Resuelto</option>
                                    <option value="cerrado" <?= $ticket['status'] === 'cerrado' ? 'selected' : '' ?>>Cerrado</option>
                                </select>

                                <button onclick="deleteTicket(<?= $ticket['id'] ?>)" class="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition" title="Eliminar ticket">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Barra de Respuestas del Ticket -->
                        <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                            <button onclick="toggleTicketReplies(<?= $ticket['id'] ?>)" id="btn-toggle-replies-<?= $ticket['id'] ?>" class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-600 hover:text-brand-700 transition">
                                <i data-lucide="message-square" class="w-3.5 h-3.5"></i>
                                <span>Ver conversación</span>
                                <span id="ticket-replies-badge-<?= $ticket['id'] ?>" class="ml-1 px-1.5 py-0.2 rounded-full bg-brand-50 text-brand-700 text-[10px] font-bold">
                                    <?= (int)$ticket['replies_count'] ?>
                                </span>
                                <i data-lucide="chevron-down" id="chevron-replies-<?= $ticket['id'] ?>" class="w-3 h-3 transition-transform"></i>
                            </button>

                            <button onclick="openTicketReplyInput(<?= $ticket['id'] ?>)" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium text-slate-600 hover:text-brand-600 hover:bg-brand-50 transition">
                                <i data-lucide="reply" class="w-3.5 h-3.5"></i>
                                <span>Responder Ticket</span>
                            </button>
                        </div>

                        <!-- Panel Colapsable de Respuestas del Ticket -->
                        <div id="ticket-replies-panel-<?= $ticket['id'] ?>" class="hidden pt-2 space-y-3">
                            <div id="ticket-replies-feed-<?= $ticket['id'] ?>" class="space-y-2.5 pl-4 sm:pl-6 border-l-2 border-brand-100 max-h-80 overflow-y-auto">
                                <div class="text-xs text-slate-400 py-2">Cargando respuestas...</div>
                            </div>

                            <!-- Formulario de Respuesta al Ticket -->
                            <form onsubmit="submitTicketReplyAjax(event, <?= $ticket['id'] ?>)" class="pl-4 sm:pl-6 border-l-2 border-brand-100 pt-2 space-y-2">
                                <textarea id="ticket-reply-text-<?= $ticket['id'] ?>" rows="2" placeholder="Escribe tu respuesta o resolución para este ticket..." required class="w-full p-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:ring-1 focus:ring-brand-500 bg-slate-50/60 leading-relaxed"></textarea>
                                <div class="flex items-center justify-end gap-2">
                                    <button type="submit" id="btn-submit-tk-reply-<?= $ticket['id'] ?>" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs rounded-xl shadow-xs transition">
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
