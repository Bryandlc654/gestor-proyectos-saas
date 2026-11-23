<?php

namespace App\Upload;

use App\Support\Format;
use finfo;

/**
 * Validación y almacenamiento de archivos subidos en uploads/.
 * Lista blanca de extensiones + comprobación del tipo MIME real del contenido.
 */
final class AttachmentStorage
{
    // 50 MB: holgado para documentos y vídeos cortos, y por debajo del tope de 100 MB por petición de Cloudflare.
    public const DEFAULT_MAX_BYTES = 50 * 1024 * 1024;

    public const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'key', 'pages', 'numbers',
        'txt', 'csv', 'md', 'rtf', 'json', 'xml',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'tif', 'tiff', 'bmp',
        'zip', 'rar', '7z', 'tar', 'gz',
        'mp3', 'wav', 'm4a', 'ogg', 'aac', 'mp4', 'mov', 'webm', 'avi', 'mkv', 'm4v',
        'fig', 'psd', 'ai', 'sketch', 'xd', 'eps', 'indd',
    ];

    // Tipos MIME de imagen admitidos (comentarios y logo) => extensión con la que se guardan
    public const IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    // Contenido que el navegador o el servidor podrían interpretar, aunque venga disfrazado
    private const FORBIDDEN_MIME_PATTERN = '#^(text/html|application/xhtml|image/svg|application/x-(php|httpd-php|sh|executable|msdownload))#';

    // Tipos que el navegador puede mostrar dentro de la app sin riesgo (nunca HTML/SVG, que ejecutarían scripts)
    private const PREVIEW_TYPES = [
        'application/pdf' => 'application/pdf',
        'image/jpeg' => 'image/jpeg',
        'image/png' => 'image/png',
        'image/webp' => 'image/webp',
        'image/gif' => 'image/gif',
        'image/bmp' => 'image/bmp',
        'audio/mpeg' => 'audio/mpeg',
        'audio/wav' => 'audio/wav',
        'audio/x-wav' => 'audio/wav',
        'audio/ogg' => 'audio/ogg',
        'audio/mp4' => 'audio/mp4',
        'audio/x-m4a' => 'audio/mp4',
        'video/mp4' => 'video/mp4',
        'video/webm' => 'video/webm',
        'video/quicktime' => 'video/mp4',
        'text/plain' => 'text/plain; charset=utf-8',
        'text/csv' => 'text/plain; charset=utf-8',
        'text/markdown' => 'text/plain; charset=utf-8',
        'text/x-markdown' => 'text/plain; charset=utf-8',
        'application/json' => 'text/plain; charset=utf-8',
    ];

    /** Content-Type con el que se puede mostrar el archivo en el navegador, o null si no admite vista previa. */
    public static function previewContentType(?string $mime): ?string
    {
        return self::PREVIEW_TYPES[strtolower(trim((string)$mime))] ?? null;
    }

    /** Envía un archivo para mostrarlo en el navegador (inline) y termina la petición. */
    public static function sendInline(string $path, string $originalName, string $contentType): never
    {
        $name = basename($originalName);
        $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header_remove('Content-Encoding');
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Cache-Control: private, max-age=300');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function __construct(private readonly string $uploadDir)
    {
    }

    public static function detectMime(string $path): string
    {
        return (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    }

    /**
     * Valida nombre, tamaño y contenido. Devuelve el mensaje de error o null si es válido.
     */
    public function validate(string $originalName, int $size, string $path, int $maxBytes = self::DEFAULT_MAX_BYTES): ?string
    {
        if ($size > $maxBytes) {
            return 'El archivo no debe superar los ' . Format::bytes($maxBytes) . '.';
        }

        $ext = strtolower(pathinfo(basename($originalName), PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return 'Tipo de archivo no permitido.';
        }

        if (preg_match(self::FORBIDDEN_MIME_PATTERN, self::detectMime($path))) {
            return 'El contenido del archivo no está permitido.';
        }

        return null;
    }

    /**
     * Valida y guarda un archivo de $_FILES.
     * Devuelve ['ok' => true, 'stored_name', 'original_name', 'size', 'mime'] o ['ok' => false, 'error'].
     */
    public function store(array $file, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return ['ok' => false, 'error' => 'Error al recibir el archivo. Asegúrate de que no exceda el límite permitido.'];
        }

        $originalName = basename($file['name']);
        $error = $this->validate($originalName, (int)$file['size'], $file['tmp_name'], $maxBytes);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $mime = self::detectMime($file['tmp_name']);

        if (!move_uploaded_file($file['tmp_name'], rtrim($this->uploadDir, '/') . '/' . $storedName)) {
            return ['ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.'];
        }

        return ['ok' => true, 'stored_name' => $storedName, 'original_name' => $originalName, 'size' => (int)$file['size'], 'mime' => $mime];
    }
}
