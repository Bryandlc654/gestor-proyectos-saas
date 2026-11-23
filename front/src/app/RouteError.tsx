import { useEffect, useState } from 'react';
import { useRouteError } from 'react-router-dom';
import { canReloadForNewVersion, isChunkLoadError, reloadForNewVersion } from '../lib/chunkReload';

/** Pantalla de error de las rutas (sustituye a "Unexpected Application Error" de React Router). */
export function RouteError() {
  const error = useRouteError();
  const chunkError = isChunkLoadError(error);
  const [reloading] = useState(() => chunkError && canReloadForNewVersion());

  useEffect(() => {
    if (chunkError) reloadForNewVersion();
    else console.error('Error de ruta', error);
  }, [chunkError, error]);

  if (reloading) {
    return <div className="flex min-h-screen items-center justify-center bg-slate-50 text-xs text-slate-500">Actualizando a la última versión…</div>;
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
      <div className="max-w-md rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
        <h1 className="text-base font-semibold text-slate-900">{chunkError ? 'Hay una nueva versión de la aplicación' : 'Algo salió mal'}</h1>
        <p className="mt-1 text-xs text-slate-500">{chunkError ? 'Recarga la página para cargar la última versión.' : 'Recarga la página para volver a intentarlo. Si el problema continúa, avísanos desde Soporte.'}</p>
        <div className="mt-4 flex justify-center gap-2">
          <button type="button" onClick={() => window.location.reload()} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700">Recargar</button>
          <a href="/app/dashboard" className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Ir al inicio</a>
        </div>
      </div>
    </div>
  );
}

