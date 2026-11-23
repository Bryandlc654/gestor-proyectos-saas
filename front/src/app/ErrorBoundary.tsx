import { Component, type ErrorInfo, type ReactNode } from 'react';
import { isChunkLoadError, reloadForNewVersion } from '../lib/chunkReload';

export class ErrorBoundary extends Component<{ children: ReactNode }, { hasError: boolean }> {
  state = { hasError: false };

  static getDerivedStateFromError(): { hasError: boolean } {
    return { hasError: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Tras un despliegue, una pantalla puede pedir un archivo de la versión anterior: recargar lo resuelve.
    if (isChunkLoadError(error) && reloadForNewVersion()) return;
    console.error('React UI error', error, info);
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <div className="flex h-full items-center justify-center bg-slate-50 p-6">
          <div className="max-w-md rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
            <h1 className="text-base font-semibold text-slate-900">Algo salió mal</h1>
            <p className="mt-1 text-xs text-slate-500">Recarga la página para volver a intentarlo.</p>
            <div className="mt-4 flex justify-center gap-2">
              <button type="button" onClick={() => window.location.reload()} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white">
                Recargar
              </button>
              <a href="/app/login" className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700">Iniciar sesión</a>
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
