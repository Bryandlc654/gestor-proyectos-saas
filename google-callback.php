<?php
// google-callback.php - Recibe la respuesta de Google y crea la sesión.
require_once __DIR__ . '/config/app.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

$stateData = $_SESSION['google_oauth'] ?? null;
unset($_SESSION['google_oauth']);

$fallback = '/login';
if (is_array($stateData) && !empty($stateData['agency_slug']) && preg_match('/^[a-z0-9-]{3,50}$/', (string)$stateData['agency_slug'])) {
    $fallback = '/' . $stateData['agency_slug'];
}

if (isLoggedIn()) {
    header('Location: /app/dashboard');
    exit;
}

$service = googleOAuthService();
if (!$service->isConfigured()) {
    setFlash('error', 'El inicio de sesión con Google todavía no está configurado.');
    header('Location: ' . $fallback);
    exit;
}

$state = (string)($_GET['state'] ?? '');
if (!is_array($stateData)
    || empty($stateData['state'])
    || $state === ''
    || !hash_equals((string)$stateData['state'], $state)) {
    setFlash('error', 'La solicitud de Google no es válida o ha caducado. Inténtalo de nuevo.');
    header('Location: ' . $fallback);
    exit;
}

if (time() - (int)($stateData['created_at'] ?? 0) > 600) {
    setFlash('error', 'La solicitud de Google ha caducado. Inténtalo de nuevo.');
    header('Location: ' . $fallback);
    exit;
}

if (!empty($_GET['error'])) {
    setFlash('error', 'Cancelaste o no autorizaste el inicio de sesión con Google.');
    header('Location: ' . $fallback);
    exit;
}

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') {
    setFlash('error', 'Google no devolvió el código de autorización. Inténtalo de nuevo.');
    header('Location: ' . $fallback);
    exit;
}

try {
    $tokens = $service->exchangeCode($code);
    $claims = !empty($tokens['id_token'])
        ? $service->validateIdToken((string)$tokens['id_token'])
        : $service->fetchUserInfo((string)($tokens['access_token'] ?? ''));

    $googleSub = trim((string)($claims['sub'] ?? ''));
    $email = strtolower(trim((string)($claims['email'] ?? '')));
    if ($googleSub === '' || $email === '') {
        throw new DomainException('Google no devolvió los datos necesarios para iniciar sesión.');
    }

    $db = getDB();
    $fields = 'id, agency_id, name, email, role, avatar, google_sub, email_verified_at, two_factor_enabled, session_version';

    $stmt = $db->prepare("SELECT {$fields} FROM users WHERE google_sub = ? LIMIT 1");
    $stmt->execute([$googleSub]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($user === null) {
        $stmt = $db->prepare("SELECT {$fields} FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($user === null) {
            throw new DomainException('No existe una cuenta de Vértice con este correo. Regístrate o pide una invitación a tu agencia.');
        }
        if (!empty($user['google_sub'])) {
            throw new DomainException('Esta cuenta de Vértice ya está vinculada a otra cuenta de Google.');
        }
        if (empty($user['email_verified_at'])) {
            throw new DomainException('Tu cuenta existe, pero el correo aún no está verificado. Inicia sesión con tu contraseña y verifica el correo antes de usar Google.');
        }

        // Primera vez: vincula la cuenta de Google al usuario existente.
        $db->prepare("UPDATE users SET google_sub = ?, google_linked_at = NOW() WHERE id = ?")
            ->execute([$googleSub, (int)$user['id']]);
        $user['google_sub'] = $googleSub;
    }

    $agencyId = (int)($stateData['agency_id'] ?? 0);
    if ($agencyId > 0 && (int)$user['agency_id'] !== $agencyId) {
        throw new DomainException('Este correo no pertenece a la agencia indicada.');
    }

    clearLoginAttempts($email);

    if ((int)$user['two_factor_enabled'] === 1) {
        session_regenerate_id(true);
        $_SESSION['2fa_pending_user_id'] = (int)$user['id'];
        $_SESSION['2fa_attempts'] = 0;
        header('Location: /two-factor');
        exit;
    }

    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_attempts']);
    startUserSession($user);
    activityRepository()->logByUser((int)$user['id'], null, 'auth.google', 'Inició sesión con Google');

    setFlash('success', '¡Bienvenido de vuelta, ' . $user['name'] . '!');
    header('Location: /app/dashboard');
    exit;
} catch (DomainException $e) {
    setFlash('error', $e->getMessage());
    header('Location: ' . $fallback);
    exit;
} catch (Throwable $e) {
    error_log('google-callback: ' . $e->getMessage());
    setFlash('error', 'No se pudo iniciar sesión con Google. Inténtalo de nuevo.');
    header('Location: ' . $fallback);
    exit;
}
