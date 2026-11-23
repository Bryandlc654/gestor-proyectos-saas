// Recuperación tras un despliegue: si el navegador tiene cargada una versión anterior de la app
// (o pidió un archivo justo mientras se subía la nueva), importar una pantalla falla con
// "Failed to fetch dynamically imported module". Recargar trae el index nuevo y lo resuelve.

const STORAGE_KEY = 'vertice:chunk-reload-at';
const MIN_INTERVAL_MS = 15_000; // evita bucles de recarga si el archivo realmente no existe

export function isChunkLoadError(error: unknown): boolean {
  const message = error instanceof Error ? `${error.name} ${error.message}` : String(error ?? '');
  return /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed|Unable to preload CSS|ChunkLoadError|Loading chunk [\w-]+ failed/i.test(message);
}

/** ¿Se puede recargar ya? No, si se recargó hace poco por el mismo motivo (evita bucles). */
export function canReloadForNewVersion(): boolean {
  try {
    return Date.now() - Number(sessionStorage.getItem(STORAGE_KEY) ?? 0) >= MIN_INTERVAL_MS;
  } catch {
    return false; // almacenamiento bloqueado: sin forma de evitar bucles, mejor mostrar el aviso
  }
}

/** Recarga la página una vez. Devuelve false si ya se recargó hace poco (para mostrar un aviso en su lugar). */
export function reloadForNewVersion(): boolean {
  if (!canReloadForNewVersion()) return false;
  try { sessionStorage.setItem(STORAGE_KEY, String(Date.now())); } catch { /* almacenamiento bloqueado */ }
  window.location.reload();
  return true;
}

/** Vite emite este evento cuando falla la precarga de un módulo o CSS. */
export function installChunkReloadHandler(): void {
  window.addEventListener('vite:preloadError', (event) => {
    if (reloadForNewVersion()) event.preventDefault();
  });
}
