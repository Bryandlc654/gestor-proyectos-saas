<?php
// register.php - Registro de una agencia nueva (su dueño queda como administrador)
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$agencyName = '';
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agencyName = trim($_POST['agency_name'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido. Intenta de nuevo.';
    } elseif ($password !== $password_confirm) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        try {
            $user = accountService()->registerAgency($agencyName, $name, $email, $password);
            startUserSession($user);
            setFlash('success', '¡Agencia creada! Ahora invita a tu equipo y a tus clientes desde Usuarios.');
            header('Location: dashboard.php');
            exit;
        } catch (DomainException $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear agencia - FlowTasks</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Estilos compilados con Tailwind (npm run build:css) -->
    <link rel="stylesheet" href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?: 1 ?>">
    <script src="https://unpkg.com/lucide@1.59.0/dist/umd/lucide.min.js" integrity="sha384-4VQAGBhahgTiMVvXUqbh6qcs45zGzR/8znv7XORRfWkxZfzRbhLL5Uh+fSdvdqdM" crossorigin="anonymous"></script>
</head>
<body class="min-h-full flex items-center justify-center p-4 bg-slate-50">

    <div class="max-w-sm w-full space-y-6">
        <div class="text-center">
            <div class="inline-flex w-10 h-10 rounded-xl items-center justify-center text-white mb-4 bg-slate-900">
                <i data-lucide="building-2" class="w-6 h-6"></i>
            </div>
            <h1 class="font-semibold text-xl sm:text-2xl text-slate-900 tracking-tight">Crea tu agencia</h1>
            <p class="text-sm text-slate-500 mt-1">Organiza tus proyectos, tu equipo y tus clientes en un solo lugar</p>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/70 p-6 sm:p-8">
            <?php if (!empty($error)): ?>
                <div class="mb-5 flex items-center gap-3 p-3.5 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl text-xs sm:text-sm">
                    <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Nombre de la agencia</label>
                    <div class="relative">
                        <i data-lucide="building-2" class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5"></i>
                        <input type="text" name="agency_name" value="<?= e($agencyName) ?>" required maxlength="150" placeholder="Estudio Creativo" class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Tu nombre completo</label>
                    <div class="relative">
                        <i data-lucide="user" class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5"></i>
                        <input type="text" name="name" value="<?= e($name) ?>" required placeholder="Carlos Mendoza" class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Correo electrónico</label>
                    <div class="relative">
                        <i data-lucide="mail" class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5"></i>
                        <input type="email" name="email" value="<?= e($email) ?>" required placeholder="carlos@empresa.com" class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Contraseña (mínimo 8 caracteres)</label>
                    <div class="relative">
                        <i data-lucide="lock" class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5"></i>
                        <input type="password" name="password" required placeholder="••••••••" class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Confirmar contraseña</label>
                    <div class="relative">
                        <i data-lucide="shield-check" class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5"></i>
                        <input type="password" name="password_confirm" required placeholder="••••••••" class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm">
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full py-2.5 px-4 bg-brand-600 hover:bg-brand-700 text-white font-medium text-sm rounded-xl transition-colors flex items-center justify-center gap-2">
                        <span>Crear agencia</span>
                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </form>
        </div>

        <p class="text-center text-sm text-slate-500">
            ¿Ya tienes una cuenta?
            <a href="login.php" class="font-medium text-slate-900 hover:underline underline-offset-4">Inicia sesión</a>
        </p>
        <p class="text-center text-xs text-slate-400">¿Te unes al equipo o eres cliente de una agencia? Pide a la agencia tu enlace de invitación.</p>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
