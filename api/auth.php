<?php
// api/auth.php - Login, 2FA y recuperación de contraseña para React.
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function authJson(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (($_GET['verify'] ?? '') !== '') {
        $verifiedUser = emailVerificationService()->verify((string)$_GET['verify']);
        authJson($verifiedUser !== null
            ? ['success' => true, 'name' => $verifiedUser['name'] ?? null]
            : ['error' => 'El enlace no es válido o caducó.'], $verifiedUser !== null ? 200 : 422);
    }
    if (($_GET['invite'] ?? '') !== '') {
        $invitation = invitationRepository()->findValid((string)$_GET['invite']);
        authJson($invitation !== null ? [
            'success' => true,
            'email' => $invitation['email'],
            'role' => $invitation['role'],
            'agency' => $invitation['agency_name'] ?? null,
        ] : ['error' => 'La invitación no es válida o caducó.'], $invitation !== null ? 200 : 422);
    }
    $googleEnabled = trim((string)env('GOOGLE_CLIENT_ID', '')) !== '' && trim((string)env('GOOGLE_CLIENT_SECRET', '')) !== '';
    authJson(['csrfToken' => csrf_token(), 'loggedIn' => isLoggedIn(), 'googleEnabled' => $googleEnabled]);
}

$action = (string)($_POST['action'] ?? '');
if (!verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
    authJson(['error' => 'Token de seguridad inválido. Recarga la página.'], 403);
}

if ($action === 'login') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' || $password === '') authJson(['error' => 'Completa correo y contraseña.'], 422);
    if (isLoginThrottled($email, $_SERVER['REMOTE_ADDR'] ?? '')) authJson(['error' => 'Demasiados intentos. Espera unos minutos.'], 429);

    $stmt = getDB()->prepare('SELECT id, agency_id, name, email, role, avatar, password, two_factor_enabled, session_version FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($password, (string)$user['password'])) {
        recordFailedLogin($email, $_SERVER['REMOTE_ADDR'] ?? '');
        authJson(['error' => 'Correo o contraseña incorrectos.'], 401);
    }

    clearLoginAttempts($email);
    if ((int)$user['two_factor_enabled'] === 1) {
        session_regenerate_id(true);
        $_SESSION['2fa_pending_user_id'] = (int)$user['id'];
        $_SESSION['2fa_attempts'] = 0;
        authJson(['success' => true, 'requires2fa' => true]);
    }

    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_attempts']);
    startUserSession($user);
    setFlash('success', '¡Bienvenido de vuelta, ' . $user['name'] . '!');
    authJson(['success' => true, 'requires2fa' => false, 'redirect' => '/app/dashboard']);
}

if ($action === 'verify_2fa') {
    $pendingUserId = (int)($_SESSION['2fa_pending_user_id'] ?? 0);
    if ($pendingUserId <= 0) authJson(['error' => 'La sesión de verificación caducó.'], 401);
    $attempts = (int)($_SESSION['2fa_attempts'] ?? 0);
    if ($attempts >= 5) {
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_attempts']);
        authJson(['error' => 'Demasiados intentos. Inicia sesión de nuevo.'], 429);
    }
    $result = twoFactorService()->verifyLogin($pendingUserId, trim((string)($_POST['code'] ?? '')));
    if ($result === null) {
        $_SESSION['2fa_attempts'] = $attempts + 1;
        activityRepository()->logByUser($pendingUserId, null, '2fa.failed', 'Falló un intento de doble factor');
        authJson(['error' => 'Código incorrecto.'], 401);
    }
    $stmt = getDB()->prepare('SELECT id, agency_id, name, email, role, avatar, session_version FROM users WHERE id = ?');
    $stmt->execute([$pendingUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) authJson(['error' => 'Usuario no encontrado.'], 404);
    startUserSession($user);
    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_attempts']);
    activityRepository()->logByUser((int)$user['id'], null, 'auth.2fa', 'Inició sesión con doble factor');
    authJson(['success' => true, 'redirect' => '/app/dashboard']);
}

if ($action === 'forgot_password') {
    $email = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) authJson(['error' => 'Correo no válido.'], 422);
    passwordResetService()->request($email);
    authJson(['success' => true]);
}

if ($action === 'reset_password') {
    try {
        passwordResetService()->reset((string)($_POST['token'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['password_confirm'] ?? ''));
        authJson(['success' => true]);
    } catch (\DomainException $e) {
        authJson(['error' => $e->getMessage()], 422);
    }
}

if ($action === 'register') {
    $agencyName = trim((string)($_POST['agency_name'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
    if ($password !== $passwordConfirm) authJson(['error' => 'Las contraseñas no coinciden.'], 422);
    try {
        $newUser = accountService()->registerAgency($agencyName, $name, $email, $password);
        startUserSession($newUser);
        try { emailVerificationService()->send((int)$newUser['id'], $newUser['email'], $newUser['name']); } catch (\Throwable $e) { error_log('verify mail: ' . $e->getMessage()); }
        authJson(['success' => true, 'redirect' => '/app/dashboard']);
    } catch (\DomainException $e) {
        authJson(['error' => $e->getMessage()], 422);
    }
}

if ($action === 'resend_verification') {
    if (!isLoggedIn()) authJson(['error' => 'Inicia sesión para reenviar el correo.'], 401);
    $current = currentUser();
    if (!emailVerificationService()->isVerified((int)$current['id'])) {
        emailVerificationService()->send((int)$current['id'], $current['email'], $current['name']);
    }
    authJson(['success' => true]);
}

if ($action === 'accept_invitation') {
    try {
        $newUser = accountService()->acceptInvitation((string)($_POST['token'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['password'] ?? ''));
        startUserSession($newUser);
        authJson(['success' => true, 'redirect' => '/app/dashboard']);
    } catch (\DomainException $e) {
        authJson(['error' => $e->getMessage()], 422);
    }
}

authJson(['error' => 'Acción no válida'], 400);
