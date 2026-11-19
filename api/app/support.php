<?php
// api/app/support.php - Solicitudes de soporte para React.
require_once __DIR__ . '/_bootstrap.php';

use App\Support\EmailTemplate;

if (!in_array($role, ['admin', 'project_manager'], true) || !moduleAllowed('support')) {
    appJson(['error' => 'Módulo de soporte no disponible'], 403);
}

$supportEmail = trim((string)env('SUPPORT_EMAIL', ''));
if ($supportEmail === '') {
    $supportEmail = trim((string)env('CONTACT_EMAIL', ''));
}
if ($supportEmail === '') {
    $supportEmail = trim((string)env('MAIL_FROM', ''));
}
if (!filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
    $supportEmail = '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $category = (string)($_POST['category'] ?? 'otro');
        $priority = (string)($_POST['priority'] ?? 'media');
        if ($subject === '' || mb_strlen($subject) > 150) appJson(['error' => 'Escribe un asunto de hasta 150 caracteres.'], 422);
        if (mb_strlen($message) < 10) appJson(['error' => 'Cuéntanos un poco más: el mensaje debe tener al menos 10 caracteres.'], 422);
        if (mb_strlen($message) > 5000) appJson(['error' => 'El mensaje no puede superar los 5000 caracteres.'], 422);
        if (!in_array($category, ['tecnico', 'facturacion', 'cuenta', 'otro'], true)) $category = 'otro';
        if (!in_array($priority, ['baja', 'media', 'alta'], true)) $priority = 'media';

        $stmtRate = getDB()->prepare('SELECT COUNT(*) FROM support_requests WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $stmtRate->execute([$userId]);
        if ((int)$stmtRate->fetchColumn() >= 5) appJson(['error' => 'Has enviado varias solicitudes en la última hora. Espera un poco antes de enviar otra.'], 429);

        $imagePath = null;
        $hasImage = isset($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($hasImage) {
            $imageFile = $_FILES['image'];
            $uploadError = (int)($imageFile['error'] ?? UPLOAD_ERR_NO_FILE);
            $mime = ($uploadError === UPLOAD_ERR_OK && is_uploaded_file($imageFile['tmp_name'] ?? '')) ? detectMime($imageFile['tmp_name']) : '';
            $extension = ALLOWED_IMAGE_MIMES[$mime] ?? null;

            if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                appJson(['error' => 'La imagen supera el límite de 5 MB.'], 422);
            }
            if ($uploadError !== UPLOAD_ERR_OK) {
                appJson(['error' => 'No se pudo recibir la imagen. Inténtalo de nuevo.'], 422);
            }
            if ((int)($imageFile['size'] ?? 0) > 5 * 1024 * 1024) {
                appJson(['error' => 'La imagen no debe superar los 5 MB.'], 422);
            }
            if ($extension === null) {
                appJson(['error' => 'Formato de imagen no soportado. Usa PNG, JPG, WEBP o GIF.'], 422);
            }

            $uploadDir = dirname(__DIR__, 2) . '/uploads/support/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $storedName = 'support_' . bin2hex(random_bytes(12)) . '.' . $extension;
            if (!move_uploaded_file($imageFile['tmp_name'], $uploadDir . $storedName)) {
                appJson(['error' => 'No se pudo guardar la imagen. Inténtalo de nuevo.'], 422);
            }
            $imagePath = 'support/' . $storedName;
        }

        getDB()->prepare('INSERT INTO support_requests (agency_id, user_id, contact_email, subject, category, priority, status, message, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$agencyId, $userId, $user['email'], $subject, $category, $priority, 'abierto', $message, $imagePath]);
        $requestId = (int)getDB()->lastInsertId();

        activityRepository()->logByUser($userId, null, 'support.request_created', 'Envió la solicitud de soporte "' . $subject . '"');

        $categoryLabels = ['tecnico' => 'Técnico', 'facturacion' => 'Facturación', 'cuenta' => 'Cuenta y acceso', 'otro' => 'Otro'];
        $priorityLabels = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta'];
        $agencyName = (string)(currentAgency()['name'] ?? '');
        $supportSent = false;

        if ($supportEmail !== '') {
            $imageLine = $imagePath !== null ? "\n\nImagen adjunta: " . appUrl() . '/uploads/' . $imagePath : '';
            $details = "Agencia: {$agencyName}\n"
                . "Usuario: {$user['name']} ({$user['email']})\n"
                . 'Categoría: ' . $categoryLabels[$category] . "\n"
                . 'Prioridad: ' . $priorityLabels[$priority] . "\n"
                . 'Referencia: #' . $requestId . "\n\n"
                . $message . $imageLine;

            try {
                $html = EmailTemplate::render([
                    'eyebrow' => 'Solicitud de soporte',
                    'title' => $subject,
                    'preheader' => $agencyName . ' · ' . $categoryLabels[$category] . ' · ' . $priorityLabels[$priority],
                    'paragraphs' => [
                        'Agencia: ' . $agencyName,
                        'Usuario: ' . $user['name'] . ' (' . $user['email'] . ')',
                        'Categoría: ' . $categoryLabels[$category] . ' · Prioridad: ' . $priorityLabels[$priority],
                        $message . $imageLine,
                    ],
                    'cta_label' => 'Responder a ' . $user['email'],
                    'cta_url' => 'mailto:' . $user['email'] . '?subject=' . rawurlencode('Re: ' . $subject),
                    'note' => 'Solicitud #' . $requestId . ' recibida desde ' . $agencyName . '.',
                    'app_name' => mailBrandName(),
                    'app_url' => appUrl(),
                ]);
                $supportSent = mailer()->send($supportEmail, 'Soporte · ' . $agencyName . ' · ' . $subject, $details, $html);
            } catch (\Throwable $e) {
                error_log('Soporte email: ' . $e->getMessage());
            }
        }

        try {
            $confirmationHtml = EmailTemplate::render([
                'eyebrow' => 'Soporte',
                'title' => 'Hemos recibido tu solicitud',
                'preheader' => 'Referencia #' . $requestId . ' · ' . $subject,
                'paragraphs' => [
                    'Hola ' . $user['name'] . ',',
                    'Tu solicitud "' . $subject . '" se registró correctamente. Nuestro equipo te responderá a este correo.',
                    $message . ($imagePath !== null ? "\n\nImagen adjunta: " . appUrl() . '/uploads/' . $imagePath : ''),
                ],
                'note' => 'Referencia #' . $requestId . ' · Categoría: ' . $categoryLabels[$category] . ' · Prioridad: ' . $priorityLabels[$priority],
                'app_name' => mailBrandName(),
                'app_url' => appUrl(),
            ]);
            mailer()->send(
                $user['email'],
                'Hemos recibido tu solicitud · ' . $subject,
                "Hola " . $user['name'] . ",\n\nTu solicitud \"" . $subject . "\" se registró correctamente (referencia #" . $requestId . ").\n\nNuestro equipo te responderá a este correo.\n\n" . $message,
                $confirmationHtml
            );
        } catch (\Throwable $e) {
            error_log('Soporte confirmación: ' . $e->getMessage());
        }

        appJson([
            'success' => true,
            'id' => $requestId,
            'mailSent' => $supportSent,
            'message' => $supportSent
                ? 'Solicitud enviada. Te responderemos por correo.'
                : 'Solicitud guardada, pero no pudimos avisar por correo al equipo de soporte. Podrás verla en el historial.',
        ]);
    }

    $requestId = (int)($_POST['request_id'] ?? 0);
    $stmt = getDB()->prepare('SELECT id, subject FROM support_requests WHERE id = ? AND agency_id = ?');
    $stmt->execute([$requestId, $agencyId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($request === false) appJson(['error' => 'Solicitud no encontrada'], 404);
    if (in_array($action, ['resolve', 'reopen'], true)) {
        $newStatus = $action === 'resolve' ? 'resuelto' : 'abierto';
        getDB()->prepare('UPDATE support_requests SET status = ?, resolved_at = ' . ($newStatus === 'resuelto' ? 'NOW()' : 'NULL') . ' WHERE id = ? AND agency_id = ?')
            ->execute([$newStatus, $requestId, $agencyId]);
        activityRepository()->logByUser($userId, null, $action === 'resolve' ? 'support.request_resolved' : 'support.request_reopened', ($action === 'resolve' ? 'Marcó como resuelta' : 'Reabrió') . ' la solicitud "' . $request['subject'] . '"');
        appJson(['success' => true, 'status' => $newStatus]);
    }
    appJson(['error' => 'Acción no válida'], 400);
}

appMethod('GET');

$perPage = 20;
$stmtTotal = getDB()->prepare('SELECT COUNT(*) FROM support_requests WHERE agency_id = ?');
$stmtTotal->execute([$agencyId]);
$total = (int)$stmtTotal->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;

$stmt = getDB()->prepare("
    SELECT r.*, u.name AS user_name
    FROM support_requests r
    LEFT JOIN users u ON u.id = r.user_id
    WHERE r.agency_id = ?
    ORDER BY r.created_at DESC, r.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute([$agencyId]);

$requests = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $requests[] = [
        'id' => (int)$row['id'],
        'subject' => $row['subject'],
        'message' => $row['message'],
        'category' => $row['category'],
        'priority' => $row['priority'],
        'status' => $row['status'],
        'user' => $row['user_name'],
        'contactEmail' => $row['contact_email'],
        'createdAt' => $row['created_at'],
        'resolvedAt' => $row['resolved_at'],
        'imagePath' => $row['image_path'],
    ];
}

appJson([
    'requests' => $requests,
    'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'pages' => $pages],
    'supportEmail' => $supportEmail,
    'brandName' => mailBrandName(),
]);
