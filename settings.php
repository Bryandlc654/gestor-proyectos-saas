<?php
// settings.php - Módulo de Configuración del Sistema (Logo, Marca y Personalización)
require_once __DIR__ . '/config/app.php';
requireLogin();

$user = currentUser();
$userRole = currentUserRole();

// Solo el Administrador puede cambiar la configuración global del sistema
if (!isAdmin()) {
    setFlash('error', 'Solo los administradores pueden acceder al módulo de configuración.');
    header('Location: dashboard.php');
    exit;
}

define('PAGE_TITLE', 'Configuración del Sistema');
$db = getDB();

$currentLogo = getAppSetting('app_logo', '');
$appName = getAppSetting('app_name', 'FlowTasks');

// Procesar cambios en la configuración
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verify_csrf($csrf)) {
        setFlash('error', 'Token de seguridad inválido.');
        header('Location: settings.php');
        exit;
    }

    if ($action === 'save_branding') {
        $newAppName = trim($_POST['app_name'] ?? '');
        if (!empty($newAppName)) {
            setAppSetting('app_name', $newAppName);
        }

        // Subida de nuevo logo
        if (!empty($_FILES['app_logo']['name']) && $_FILES['app_logo']['error'] === UPLOAD_ERR_OK) {
            // SVG no se admite: puede contener scripts. El formato se valida por el contenido real.
            $mime = detectMime($_FILES['app_logo']['tmp_name']);
            $extension = ALLOWED_IMAGE_MIMES[$mime] ?? null;

            if ($extension !== null) {
                $uploadDir = __DIR__ . '/uploads/logo/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $filename = 'app_logo_' . time() . '.' . $extension;
                $targetPath = $uploadDir . $filename;

                if (move_uploaded_file($_FILES['app_logo']['tmp_name'], $targetPath)) {
                    // Eliminar logo anterior si existía
                    if (!empty($currentLogo) && file_exists(__DIR__ . '/' . $currentLogo)) {
                        @unlink(__DIR__ . '/' . $currentLogo);
                    }
                    $dbLogoPath = 'uploads/logo/' . $filename;
                    setAppSetting('app_logo', $dbLogoPath);
                    $currentLogo = $dbLogoPath;
                    setFlash('success', '¡Logo y nombre de la aplicación actualizados exitosamente!');
                } else {
                    setFlash('error', 'Ocurrió un error al guardar el archivo del logo.');
                }
            } else {
                setFlash('error', 'Formato no soportado. Por favor sube una imagen en PNG, JPG, WEBP o GIF.');
            }
        } else {
            setFlash('success', 'Configuración de marca guardada.');
        }

        header('Location: settings.php');
        exit;
    }

    if ($action === 'remove_logo') {
        if (!empty($currentLogo) && file_exists(__DIR__ . '/' . $currentLogo)) {
            @unlink(__DIR__ . '/' . $currentLogo);
        }
        setAppSetting('app_logo', '');
        setFlash('success', 'Logo restablecido al diseño predeterminado.');
        header('Location: settings.php');
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<!-- Contenido Principal -->
<main class="flex-1 flex flex-col min-w-0 bg-[#F8FAFC] overflow-y-auto">
    <!-- Barra Superior -->
    <header class="min-h-[4.5rem] sm:min-h-[5rem] py-4 sm:py-5 bg-white border-b border-slate-200/80 px-6 sm:px-10 flex items-center justify-between sticky top-0 z-10">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar()" class="p-2 -ml-2 rounded-xl text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition" title="Alternar menú lateral">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
            <div>
                <h1 class="font-display font-bold text-lg sm:text-xl text-slate-900 tracking-tight">Configuración del Sistema</h1>
            </div>
        </div>
    </header>

    <div class="p-4 sm:p-8 max-w-4xl w-full mx-auto space-y-6">

        <!-- Tarjeta de Identidad de Marca y Logo -->
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-2xs overflow-hidden">
            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-brand-600 flex items-center justify-center font-bold">
                        <i data-lucide="image" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h3 class="font-display font-bold text-slate-900 text-base">Identidad de Marca & Logo</h3>
                        <p class="text-xs text-slate-400">Personaliza el logo y nombre que se mostrarán en la barra lateral y en la pantalla de inicio de sesión</p>
                    </div>
                </div>
            </div>

            <form action="settings.php" method="POST" enctype="multipart/form-data" class="p-6 sm:p-8 space-y-6">
                <input type="hidden" name="action" value="save_branding">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <!-- Vista Previa Actual del Logo -->
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-2">Vista Previa Actual</label>
                    <div class="p-5 rounded-2xl bg-slate-50/70 border border-slate-200/80 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div class="flex items-center gap-3.5">
                            <?php if (!empty($currentLogo) && file_exists(__DIR__ . '/' . $currentLogo)): ?>
                                <div class="h-14 max-w-[200px] p-2 bg-white rounded-xl border border-slate-200 shadow-2xs flex items-center justify-center">
                                    <img src="<?= e($currentLogo) ?>?v=<?= time() ?>" alt="Logo actual" class="max-h-full max-w-full object-contain">
                                </div>
                            <?php else: ?>
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-400 flex items-center justify-center text-white shadow-md shadow-brand-500/20 flex-shrink-0">
                                    <i data-lucide="layers" class="w-6 h-6"></i>
                                </div>
                            <?php endif; ?>

                            <div>
                                <h4 class="font-display font-bold text-base text-slate-900">
                                    <?= e($appName) ?>
                                </h4>
                                <span class="text-xs text-slate-400">
                                    <?= (!empty($currentLogo) && file_exists(__DIR__ . '/' . $currentLogo)) ? 'Logo personalizado cargado' : 'Usando icono predeterminado' ?>
                                </span>
                            </div>
                        </div>

                        <?php if (!empty($currentLogo) && file_exists(__DIR__ . '/' . $currentLogo)): ?>
                            <button type="submit" form="form-remove-logo" class="px-3 py-1.5 rounded-xl border border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 font-semibold text-xs transition flex items-center gap-1.5">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                <span>Restablecer Logo</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Nombre de la Aplicación -->
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Nombre del Software / Empresa *</label>
                    <input type="text" name="app_name" value="<?= e($appName) ?>" required placeholder="Ej: FlowTasks" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-1 focus:ring-brand-500 bg-white">
                </div>

                <!-- Subir Nuevo Archivo de Logo -->
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">Subir Nuevo Logo</label>
                    <div class="border-2 border-dashed border-slate-200 hover:border-brand-400 rounded-2xl p-6 text-center bg-slate-50/40 hover:bg-slate-50 transition cursor-pointer relative">
                        <input type="file" name="app_logo" id="logo-file-input" accept="image/png, image/jpeg, image/webp, image/gif" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="previewLogo(this)">
                        
                        <div id="dropzone-default">
                            <div class="w-10 h-10 rounded-xl bg-brand-50 text-brand-600 flex items-center justify-center mx-auto mb-2">
                                <i data-lucide="upload-cloud" class="w-5 h-5"></i>
                            </div>
                            <p class="text-xs font-semibold text-slate-700">Haz clic o arrastra tu archivo de logo aquí</p>
                            <p class="text-[11px] text-slate-400 mt-1">Formatos recomendados: PNG o WEBP con fondo transparente (máx. 2MB)</p>
                        </div>

                        <div id="dropzone-preview" class="hidden flex items-center justify-center gap-3">
                            <img id="logo-preview-img" src="" alt="Vista previa" class="h-12 max-w-[180px] object-contain p-1 bg-white rounded border border-slate-200">
                            <span id="logo-file-name" class="text-xs font-semibold text-slate-700"></span>
                        </div>
                    </div>
                </div>

                <!-- Botón Guardar -->
                <div class="pt-4 border-t border-slate-100 flex items-center justify-end">
                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-sm shadow-brand-500/25 transition">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        <span>Guardar Cambios</span>
                    </button>
                </div>
            </form>

            <form id="form-remove-logo" action="settings.php" method="POST" onsubmit="return confirm('¿Restablecer el logo al icono original?');" class="hidden">
                <input type="hidden" name="action" value="remove_logo">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            </form>
        </div>

    </div>
</main>

<script>
function previewLogo(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('dropzone-default').classList.add('hidden');
            const previewBox = document.getElementById('dropzone-preview');
            previewBox.classList.remove('hidden');
            document.getElementById('logo-preview-img').src = e.target.result;
            document.getElementById('logo-file-name').innerText = file.name;
        }
        reader.readAsDataURL(file);
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
