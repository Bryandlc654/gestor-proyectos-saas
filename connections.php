<?php
// connections.php - Conexiones con otras agencias mediante código (solo administradores)
require_once __DIR__ . '/config/app.php';
requireLogin();

if (!isAdmin()) {
    setFlash('error', 'Acceso denegado. Solo los administradores gestionan las conexiones con otras agencias.');
    header('Location: dashboard.php');
    exit;
}

define('PAGE_TITLE', 'Agencias conectadas');
$user = currentUser();
$agencyId = currentAgencyId();
$connectCode = agencyRepository()->connectCode($agencyId);
$groups = connectionRepository()->forAgency($agencyId);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';

// Fila de una agencia con sus acciones
$agencyRow = function (array $c, string $meta, array $actions): void { ?>
    <li class="flex items-center justify-between gap-3 px-4 py-3">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-8 h-8 rounded-lg bg-slate-100 text-slate-700 font-medium text-xs flex items-center justify-center flex-shrink-0">
                <?= e(mb_strtoupper(mb_substr($c['agency_name'], 0, 2))) ?>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-medium text-slate-900 truncate"><?= e($c['agency_name']) ?></p>
                <p class="text-xs text-slate-400"><?= $meta ?></p>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
            <?php foreach ($actions as [$action, $label, $primary, $confirm]): ?>
                <button type="button" onclick="connectionAction('<?= $action ?>', <?= (int)$c['id'] ?>, <?= $confirm ? "'" . e($confirm) . "'" : 'null' ?>)"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition <?= $primary ? 'text-white bg-brand-600 hover:bg-brand-700' : 'text-slate-600 border border-slate-200 hover:bg-slate-50' ?>">
                    <?= e($label) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </li>
<?php };
?>

<main class="flex-1 flex flex-col min-w-0 bg-slate-50 overflow-y-auto">
    <header class="h-16 flex-shrink-0 bg-white/90 backdrop-blur border-b border-slate-200/70 px-4 sm:px-8 flex items-center gap-3 sticky top-0 z-10">
        <button onclick="toggleSidebar()" class="p-1.5 -ml-1.5 rounded-md text-slate-400 hover:text-slate-900 hover:bg-slate-100 transition" title="Alternar menú lateral">
            <i data-lucide="menu" class="w-5 h-5"></i>
        </button>
        <h1 class="font-semibold text-base sm:text-lg text-slate-900 tracking-tight">Agencias conectadas</h1>
    </header>

    <div class="p-4 sm:p-8 max-w-4xl w-full mx-auto space-y-6">
        <p class="text-sm text-slate-500">
            Conéctate con otras agencias para trabajar juntas en proyectos. Conectarse no comparte nada por sí solo:
            cada agencia sigue viendo únicamente su propio contenido hasta que decida compartir un proyecto.
        </p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Código propio -->
            <section class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs p-5 space-y-3">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Tu código de agencia</h2>
                    <p class="text-xs text-slate-400">Compártelo con la agencia que quieras conectar.</p>
                </div>
                <div class="flex items-center gap-2">
                    <span id="connect-code" class="flex-1 text-center font-mono text-xl tracking-[0.2em] font-semibold text-slate-900 bg-slate-50 border border-slate-200 rounded-xl py-2.5"><?= e($connectCode) ?></span>
                    <button type="button" onclick="copyConnectCode(this)" class="px-3.5 py-2.5 rounded-xl text-xs font-semibold text-slate-700 border border-slate-200 hover:bg-slate-50 transition">Copiar</button>
                </div>
                <button type="button" onclick="regenerateCode()" class="text-xs text-slate-500 hover:text-slate-900 hover:underline">Generar un código nuevo</button>
            </section>

            <!-- Conectar con otra agencia -->
            <section class="bg-white rounded-2xl border border-slate-200/80 shadow-2xs p-5 space-y-3">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Conectar con otra agencia</h2>
                    <p class="text-xs text-slate-400">Introduce su código. La otra agencia tendrá que aceptar la solicitud.</p>
                </div>
                <form id="connect-form" onsubmit="requestConnection(event)" class="flex items-center gap-2">
                    <input type="text" name="code" required maxlength="12" autocomplete="off" placeholder="ABCD-2345"
                        class="flex-1 min-w-0 font-mono uppercase tracking-widest text-sm px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-1 focus:ring-brand-500">
                    <button type="submit" id="btn-connect" class="px-4 py-2.5 rounded-xl text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 shadow-2xs transition">Conectar</button>
                </form>
            </section>
        </div>

        <?php if (!empty($groups['incoming'])): ?>
            <section class="space-y-2">
                <h2 class="text-xs font-semibold text-slate-500">Solicitudes recibidas (<?= count($groups['incoming']) ?>)</h2>
                <ul class="bg-white divide-y divide-slate-100 rounded-2xl border border-brand-200/80 shadow-2xs">
                    <?php foreach ($groups['incoming'] as $c) {
                        $agencyRow($c, 'Quiere conectarse · ' . formatDate($c['created_at']), [
                            ['accept', 'Aceptar', true, null],
                            ['remove', 'Rechazar', false, '¿Rechazar la solicitud de ' . $c['agency_name'] . '?'],
                        ]);
                    } ?>
                </ul>
            </section>
        <?php endif; ?>

        <section class="space-y-2">
            <h2 class="text-xs font-semibold text-slate-500">Agencias conectadas (<?= count($groups['connected']) ?>)</h2>
            <?php if (!empty($groups['connected'])): ?>
                <ul class="bg-white divide-y divide-slate-100 rounded-2xl border border-slate-200/80 shadow-2xs">
                    <?php foreach ($groups['connected'] as $c) {
                        $agencyRow($c, 'Conectadas desde ' . formatDate($c['accepted_at']), [
                            ['remove', 'Desconectar', false, '¿Desconectar de ' . $c['agency_name'] . '?'],
                        ]);
                    } ?>
                </ul>
            <?php else: ?>
                <div class="bg-white rounded-2xl border border-dashed border-slate-200 p-6 text-center text-sm text-slate-400">
                    Aún no estás conectado con ninguna agencia.
                </div>
            <?php endif; ?>
        </section>

        <?php if (!empty($groups['outgoing'])): ?>
            <section class="space-y-2">
                <h2 class="text-xs font-semibold text-slate-500">Solicitudes enviadas</h2>
                <ul class="bg-white divide-y divide-slate-100 rounded-2xl border border-slate-200/80 shadow-2xs">
                    <?php foreach ($groups['outgoing'] as $c) {
                        $agencyRow($c, 'Pendiente de respuesta · ' . formatDate($c['created_at']), [
                            ['remove', 'Cancelar', false, '¿Cancelar la solicitud a ' . $c['agency_name'] . '?'],
                        ]);
                    } ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</main>

<script>
async function postConnections(fields) {
    const formData = new FormData();
    formData.append('csrf_token', '<?= csrf_token() ?>');
    Object.entries(fields).forEach(([key, value]) => formData.append(key, value));
    const res = await fetch('api/connections.php', { method: 'POST', body: formData });
    return res.json();
}

async function requestConnection(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-connect');
    btn.disabled = true;
    try {
        const data = await postConnections({ action: 'request', code: e.target.code.value });
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'No se pudo enviar la solicitud');
        }
    } catch (err) {
        alert('Error de conexión con el servidor');
    } finally {
        btn.disabled = false;
    }
}

async function connectionAction(action, connectionId, confirmText) {
    if (confirmText && !confirm(confirmText)) {
        return;
    }
    try {
        const data = await postConnections({ action, connection_id: connectionId });
        if (data.success) {
            window.location.reload();
        } else {
            alert(data.message || 'No se pudo completar la acción');
        }
    } catch (err) {
        alert('Error de conexión con el servidor');
    }
}

async function regenerateCode() {
    if (!confirm('¿Generar un código nuevo? El actual dejará de servir para nuevas solicitudes. Las conexiones existentes se mantienen.')) {
        return;
    }
    try {
        const data = await postConnections({ action: 'regenerate_code' });
        if (data.success) {
            document.getElementById('connect-code').innerText = data.code;
        } else {
            alert(data.message || 'No se pudo generar el código');
        }
    } catch (err) {
        alert('Error de conexión con el servidor');
    }
}

async function copyConnectCode(btn) {
    const code = document.getElementById('connect-code').innerText;
    try {
        await navigator.clipboard.writeText(code);
        btn.innerText = 'Copiado';
        setTimeout(() => { btn.innerText = 'Copiar'; }, 2000);
    } catch (err) {
        prompt('Copia el código:', code);
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
