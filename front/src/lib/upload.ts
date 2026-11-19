// Reglas de imágenes en comentarios: las mismas que valida api/comments.php.
export const COMMENT_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
export const COMMENT_IMAGE_ACCEPT = COMMENT_IMAGE_TYPES.join(',');
export const COMMENT_IMAGE_MAX_BYTES = 10 * 1024 * 1024;

/** Mensaje de error si la imagen no se puede subir como adjunto de comentario, o null si es válida. */
export function commentImageError(file: File): string | null {
  if (!COMMENT_IMAGE_TYPES.includes(file.type)) return 'Formato de imagen no soportado (usa PNG, JPG, WEBP o GIF).';
  if (file.size > COMMENT_IMAGE_MAX_BYTES) return 'La imagen supera el límite de 10 MB.';
  return null;
}
