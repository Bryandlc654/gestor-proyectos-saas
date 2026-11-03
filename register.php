<?php
// register.php - Registro de nuevo usuario
require_once __DIR__ . '/config/app.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$name = '';
$email = '';
$role = 'colaborador';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $roleInput = $_POST['role'] ?? 'colaborador';
    $role = in_array($roleInput, ['colaborador', 'project_manager', 'cliente'], true) ? $roleInput : 'colaborador';
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf($token)) {
        $error = 'Token de seguridad inválido. Intenta de nuevo.';
    } elseif (empty($name) || empty($email) || empty($password)) {
        $error = 'Todos los campos son obligatorios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo electrónico no tiene un formato válido.';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif ($password !== $password_confirm) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        $db = getDB();

        // Verificar si el email ya existe
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Este correo electrónico ya está registrado.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $insert = $db->prepare("INSERT INTO users (name, email, role, password) VALUES (?, ?, ?, ?)");
            $insert->execute([$name, $email, $role, $hashedPassword]);
            $newUserId = $db->lastInsertId();

            // Auto-login del usuario
            session_regenerate_id(true);
            $_SESSION['user_id'] = $newUserId;
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role'] = $role;

            // Crear un proyecto de bienvenida si es PM
            if ($role === 'project_manager') {
                $defaultProject = $db->prepare("INSERT INTO projects (user_id, name, description, category, status, color, deadline) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(CURRENT_DATE, INTERVAL 14 DAY))");
                $defaultProject->execute([$newUserId, 'Mi Primer Proyecto', 'Bienvenido a FlowTasks. Aquí puedes organizar tus tareas, checklists y recursos.', 'Desarrollo Web', 'activo', '#6366F1']);
                $projId = $db->lastInsertId();

                // Crear tareas de bienvenida
                $db->prepare("INSERT INTO tasks (project_id, title, description, priority, status) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$projId, 'Explorar las secciones del proyecto', 'Revisa las pestañas de Checklists, Tickets, Archivos y Enlaces.', 'alta', 'pendiente']);
            }
            // Colaboradores y clientes no se vinculan automáticamente a ningún proyecto:
            // un administrador o PM debe asignarlos explícitamente.

            setFlash('success', '¡Cuenta creada con éxito! Rol asignado: ' . ucfirst(str_replace('_', ' ', $role)));
            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Cuenta - FlowTasks</title>
    
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
                <i data-lucide="user-plus" class="w-6 h-6"></i>
            </div>
            <h1 class="font-semibold text-xl sm:text-2xl text-slate-900 tracking-tight">Crear una cuenta</h1>
            <p class="text-sm text-slate-500 mt-1">Comienza a organizar tus proyectos y equipos hoy</p>
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
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Nombre completo</label>
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
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Nivel de Acceso / Rol</label>
                    <div class="relative">
                        <i data-lucide="badge-check" class="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5"></i>
                        <select name="role" class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm bg-white">
                            <option value="colaborador" <?= $role === 'colaborador' ? 'selected' : '' ?>>Colaborador (gestionar tareas, comentarios y adjuntos)</option>
                            <option value="project_manager" <?= $role === 'project_manager' ? 'selected' : '' ?>>Project Manager (crear y gestionar proyectos completos)</option>
                            <option value="cliente" <?= $role === 'cliente' ? 'selected' : '' ?>>Cliente (seguimiento, solo lectura + tickets y feedback)</option>
                        </select>
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
                        <span>Registrarse Gratis</span>
                        <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </form>
        </div>

        <p class="text-center text-sm text-slate-500">
            ¿Ya tienes una cuenta?
            <a href="login.php" class="font-medium text-slate-900 hover:underline underline-offset-4">Inicia sesión</a>
        </p>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
