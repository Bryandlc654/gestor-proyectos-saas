<?php
// login.php - Inicio de sesión moderno y limpio
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';
// Antes de iniciar sesión no hay agencia: se muestra la marca de la plataforma
$appLogo = '';
$appName = 'FlowTasks';

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
        $stmt = $db->prepare("SELECT id, agency_id, name, email, role, password FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            clearLoginAttempts($email);
            // Regenera el ID de sesión (previene Session Fixation) y guarda usuario y agencia
            startUserSession($user);

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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Estilos compilados con Tailwind (npm run build:css) -->
    <link rel="stylesheet" href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?: 1 ?>">
    <script src="https://unpkg.com/lucide@1.59.0/dist/umd/lucide.min.js" integrity="sha384-4VQAGBhahgTiMVvXUqbh6qcs45zGzR/8znv7XORRfWkxZfzRbhLL5Uh+fSdvdqdM" crossorigin="anonymous"></script>
</head>
<body class="min-h-full flex items-center justify-center p-4 bg-slate-50">
    
    <?php require_once __DIR__ . '/includes/alert.php'; ?>

    <div class="max-w-sm w-full space-y-6">
        <!-- Logo e Introducción -->
        <div class="text-center">
            <?php if (!empty($appLogo) && file_exists(__DIR__ . '/' . $appLogo)): ?>
                <div class="inline-flex max-w-[220px] max-h-16 items-center justify-center mb-4">
                    <img src="<?= e($appLogo) ?>?v=<?= time() ?>" alt="<?= e($appName) ?>" class="max-h-16 max-w-full object-contain">
                </div>
            <?php else: ?>
                <div class="inline-flex w-10 h-10 rounded-xl items-center justify-center text-white mb-4 bg-slate-900">
                    <i data-lucide="layers" class="w-5 h-5"></i>
                </div>
            <?php endif; ?>
            <h1 class="font-semibold text-xl sm:text-2xl text-slate-900 tracking-tight">Bienvenido a <?= e($appName) ?></h1>
            <p class="text-sm text-slate-500 mt-1">Gestión ágil de proyectos, tareas, tickets y recursos</p>
        </div>

        <!-- Tarjeta de Login -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/70 p-6 sm:p-8">
            <?php if (!empty($error)): ?>
                <div class="mb-5 flex items-center gap-3 p-3.5 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl text-xs sm:text-sm">
                    <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Correo electrónico</label>
                    <div class="relative">
                        <i data-lucide="mail" class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5"></i>
                        <input type="email" id="email-field" name="email" value="<?= e($email) ?>" required placeholder="nombre@ejemplo.com" class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm">
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-sm font-medium text-slate-700">Contraseña</label>
                    </div>
                    <div class="relative">
                        <i data-lucide="lock" class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5"></i>
                        <input type="password" id="password-field" name="password" required placeholder="••••••••" class="w-full pl-10 pr-10 py-2.5 rounded-xl border border-slate-200 text-sm">
                        <button type="button" onclick="togglePasswordVisibility()" class="absolute right-3.5 top-3 text-slate-400 hover:text-slate-600">
                            <i data-lucide="eye" id="eye-icon" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full py-2.5 px-4 bg-brand-600 hover:bg-brand-700 text-white font-medium text-sm rounded-xl transition-colors flex items-center justify-center gap-2">
                        <span>Iniciar sesión</span>
                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Enlace a Registro -->
        <p class="text-center text-sm text-slate-500">
            ¿No tienes una cuenta?
            <a href="register.php" class="font-medium text-slate-900 hover:underline underline-offset-4">Regístrate gratis</a>
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
