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
    public const DEFAULT_MAX_BYTES = 20 * 1024 * 1024;

    public const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'txt', 'csv', 'md', 'rtf',
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'zip', 'rar', '7z',
        'mp3', 'mp4', 'mov', 'webm',
        'fig', 'psd', 'ai', 'sketch',
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
