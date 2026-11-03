    </div><!-- Cierre de flex h-screen -->

    <!-- Modal Global: Crear Proyecto -->
    <div id="create-project-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs hidden transition-opacity">
        <div class="bg-white rounded-2xl max-w-lg w-full shadow-2xl border border-slate-100 overflow-hidden transform transition-all">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center">
                        <i data-lucide="folder-plus" class="w-4 h-4"></i>
                    </div>
                    <h3 class="font-display font-semibold text-slate-800 text-base">Crear Nuevo Proyecto</h3>
                </div>
                <button type="button" onclick="closeModal('create-project-modal')" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <form action="actions/project-actions.php" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1.5">Nombre del Proyecto *</label>
                    <input type="text" name="name" required placeholder="Ej: Rediseño Sitio Web 2026" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1.5">Descripción</label>
                    <textarea name="description" rows="2" placeholder="Objetivos y alcance general del proyecto..." class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1.5">Categoría</label>
                        <select name="category" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 bg-white">
                            <option value="Desarrollo Web">Desarrollo Web</option>
                            <option value="Diseño UI/UX">Diseño UI/UX</option>
                            <option value="Marketing & SEO">Marketing & SEO</option>
                            <option value="App Móvil">App Móvil</option>
                            <option value="Soporte & IT">Soporte & IT</option>
                            <option value="Otro">Otro</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1.5">Fecha Límite</label>
                        <input type="date" name="deadline" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 bg-white">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-700 mb-1.5">Costo / Presupuesto Total</label>
                        <input type="number" step="0.01" min="0" name="budget" placeholder="0.00" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 bg-white font-semibold">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1.5">Moneda</label>
                        <select name="currency" class="w-full px-3.5 py-2 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 bg-white font-mono">
                            <option value="USD">USD ($)</option>
                            <option value="EUR">EUR (€)</option>
                            <option value="MXN">MXN ($)</option>
                            <option value="PEN">PEN (S/)</option>
                            <option value="COP">COP ($)</option>
                            <option value="CLP">CLP ($)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1.5">Color de Identidad</label>
                    <div class="flex items-center gap-3">
                        <label class="cursor-pointer">
                            <input type="radio" name="color" value="#4F46E5" class="sr-only peer" checked>
                            <div class="w-7 h-7 rounded-full bg-indigo-600 ring-2 ring-transparent peer-checked:ring-offset-2 peer-checked:ring-indigo-600 transition-all"></div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="color" value="#0EA5E9" class="sr-only peer">
                            <div class="w-7 h-7 rounded-full bg-sky-500 ring-2 ring-transparent peer-checked:ring-offset-2 peer-checked:ring-sky-500 transition-all"></div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="color" value="#10B981" class="sr-only peer">
                            <div class="w-7 h-7 rounded-full bg-emerald-500 ring-2 ring-transparent peer-checked:ring-offset-2 peer-checked:ring-emerald-500 transition-all"></div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="color" value="#F59E0B" class="sr-only peer">
                            <div class="w-7 h-7 rounded-full bg-amber-500 ring-2 ring-transparent peer-checked:ring-offset-2 peer-checked:ring-amber-500 transition-all"></div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="color" value="#EC4899" class="sr-only peer">
                            <div class="w-7 h-7 rounded-full bg-pink-500 ring-2 ring-transparent peer-checked:ring-offset-2 peer-checked:ring-pink-500 transition-all"></div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="color" value="#64748B" class="sr-only peer">
                            <div class="w-7 h-7 rounded-full bg-slate-500 ring-2 ring-transparent peer-checked:ring-offset-2 peer-checked:ring-slate-500 transition-all"></div>
                        </label>
                    </div>
                </div>

                <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-100">
                    <button type="button" onclick="closeModal('create-project-modal')" class="px-4 py-2 rounded-xl text-sm font-medium text-slate-600 hover:bg-slate-100 transition">Cancelar</button>
                    <button type="submit" class="px-5 py-2 rounded-xl text-sm font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-sm shadow-brand-500/30 transition">Crear Proyecto</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts de Interacción UI -->
    <script>
        // Renderizar iconos de Lucide
        lucide.createIcons();

        // Control de Modales
        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        }

        // Cerrar modal al hacer clic en el fondo exterior
        window.addEventListener('click', (e) => {
            if (e.target.classList.contains('backdrop-blur-xs') || e.target.id.endsWith('-modal')) {
                e.target.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        });

        // Control y alternancia del Sidebar (Desktop y Móvil)
        const sidebar = document.getElementById('main-sidebar');
        const backdrop = document.getElementById('mobile-backdrop');
        const openBtn = document.getElementById('open-mobile-menu');
        const closeBtn = document.getElementById('close-mobile-menu');

        function toggleSidebar() {
            if (!sidebar) return;
            const isMobile = window.innerWidth < 768;
            if (isMobile) {
                const isHidden = sidebar.classList.contains('-translate-x-full');
                if (isHidden) {
                    sidebar.classList.remove('-translate-x-full');
                    if (backdrop) backdrop.classList.remove('hidden');
                } else {
                    sidebar.classList.add('-translate-x-full');
                    if (backdrop) backdrop.classList.add('hidden');
                }
            } else {
                // Modo Desktop: alternar entre modo expandido y modo colapsado (solo iconos)
                sidebar.classList.remove('md:hidden');
                sidebar.classList.toggle('sidebar-collapsed');
                const isCollapsed = sidebar.classList.contains('sidebar-collapsed');
                localStorage.setItem('flowtasks_sidebar_collapsed', isCollapsed ? '1' : '0');
            }
        }

        // Restaurar estado guardado del sidebar en escritorio
        if (sidebar && window.innerWidth >= 768) {
            sidebar.classList.remove('md:hidden');
            if (localStorage.getItem('flowtasks_sidebar_collapsed') === '1') {
                sidebar.classList.add('sidebar-collapsed');
            }
        }

        if (openBtn) {
            openBtn.addEventListener('click', toggleSidebar);
        }
        if (closeBtn && sidebar && backdrop) {
            closeBtn.addEventListener('click', () => {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
            });
        }
        if (backdrop && sidebar) {
            backdrop.addEventListener('click', () => {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
            });
        }
    </script>
</body>
</html>
