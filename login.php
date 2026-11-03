<?php
// login.php - Inicio de sesión moderno y limpio
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';
$appLogo = getAppSetting('app_logo', '');
$appName = getAppSetting('app_name', 'FlowTasks');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf($token)) {
        $error = 'Token de seguridad inválido. Por favor intenta nuevamente.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Por favor completa todos los campos.';
    } elseif (isLoginThrottled($email, $_SERVER['REMOTE_ADDR'] ?? '')) {
        $error = 'Demasiados intentos fallidos. Espera unos minutos antes de volver a intentarlo.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, name, email, role, password FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            clearLoginAttempts($email);
            // Regenerar ID de sesión para prevenir Session Fixation
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'] ?? 'colaborador';

            setFlash('success', '¡Bienvenido de vuelta, ' . $user['name'] . '!');
            header('Location: dashboard.php');
            exit;
        } else {
            recordFailedLogin($email, $_SERVER['REMOTE_ADDR'] ?? '');
            $error = 'Correo electrónico o contraseña incorrectos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - FlowTasks</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    
    <!-- Estilos compilados con Tailwind (npm run build:css) -->
    <link rel="stylesheet" href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?: 1 ?>">
    <script src="https://unpkg.com/lucide@1.59.0/dist/umd/lucide.min.js" integrity="sha384-4VQAGBhahgTiMVvXUqbh6qcs45zGzR/8znv7XORRfWkxZfzRbhLL5Uh+fSdvdqdM" crossorigin="anonymous"></script>
</head>
<body class="min-h-full flex items-center justify-center p-4 selection:bg-brand-500 selection:text-white bg-radial from-slate-50 via-indigo-50/20 to-slate-100">
    
    <?php require_once __DIR__ . '/includes/alert.php'; ?>

    <div class="max-w-md w-full space-y-6">
        <!-- Logo e Introducción -->
        <div class="text-center">
            <?php if (!empty($appLogo) && file_exists(__DIR__ . '/' . $appLogo)): ?>
                <div class="inline-flex max-w-[220px] max-h-16 items-center justify-center mb-4">
                    <img src="<?= e($appLogo) ?>?v=<?= time() ?>" alt="<?= e($appName) ?>" class="max-h-16 max-w-full object-contain">
                </div>
            <?php else: ?>
                <div class="inline-flex w-12 h-12 rounded-2xl bg-gradient-to-tr from-brand-600 to-indigo-400 items-center justify-center text-white shadow-lg shadow-brand-500/30 mb-3">
                    <i data-lucide="layers" class="w-6 h-6"></i>
                </div>
            <?php endif; ?>
            <h1 class="font-display font-extrabold text-2xl sm:text-3xl text-slate-900 tracking-tight">Bienvenido a <?= e($appName) ?></h1>
            <p class="text-sm text-slate-500 mt-1">Gestión ágil de proyectos, tareas, tickets y recursos</p>
        </div>

        <!-- Tarjeta de Login -->
        <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/60 border border-slate-100 p-8 sm:p-10">
            <?php if (!empty($error)): ?>
                <div class="mb-5 flex items-center gap-3 p-3.5 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl text-xs sm:text-sm">
                    <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-700 mb-1.5">Correo Electrónico</label>
                    <div class="relative">
                        <i data-lucide="mail" class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5"></i>
                        <input type="email" id="email-field" name="email" value="<?= e($email) ?>" required placeholder="nombre@ejemplo.com" class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-700">Contraseña</label>
                    </div>
                    <div class="relative">
                        <i data-lucide="lock" class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5"></i>
                        <input type="password" id="password-field" name="password" required placeholder="••••••••" class="w-full pl-10 pr-10 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute right-3.5 top-3 text-slate-400 hover:text-slate-600">
                            <i data-lucide="eye" id="eye-icon" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full py-3 px-4 bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm rounded-xl shadow-md shadow-brand-500/25 transition-all transform active:scale-[0.99] flex items-center justify-center gap-2">
                        <span>Iniciar Sesión</span>
                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Enlace a Registro -->
        <p class="text-center text-sm text-slate-500">
            ¿No tienes una cuenta?
            <a href="register.php" class="font-semibold text-brand-600 hover:text-brand-700 hover:underline">Regístrate gratis</a>
        </p>
    </div>

    <script>
        lucide.createIcons();

        function togglePasswordVisibility() {
            const passInput = document.getElementById('password-field');
            if (passInput.type === 'password') {
                passInput.type = 'text';
            } else {
                passInput.type = 'password';
            }
        }
    </script>
</body>
</html>
