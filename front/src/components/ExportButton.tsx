import { AlertCircle, Check, Loader2, X } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useDialog } from './feedback';

type ExportStatus = 'idle' | 'loading' | 'success' | 'error';

export function ExportButton({ url, format, className, children, title }: { url: string; format: 'csv' | 'pdf'; className?: string; children: ReactNode; title?: string }) {
  const [status, setStatus] = useState<ExportStatus>('idle');
  const [filename, setFilename] = useState('');
  const [errorMessage, setErrorMessage] = useState('');
  const closeTimer = useRef<number | null>(null);

  useEffect(() => () => {
    if (closeTimer.current !== null) window.clearTimeout(closeTimer.current);
  }, []);

  const close = () => {
    if (closeTimer.current !== null) window.clearTimeout(closeTimer.current);
    setStatus('idle');
  };
  const dialogRef = useDialog(status === 'success' || status === 'error', close);

  const run = async () => {
    if (status === 'loading') return;
    setStatus('loading');
    setErrorMessage('');
    try {
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: '*/*' } });
      if (!response.ok) {
        const text = await response.text().catch(() => '');
        throw new Error(text && text.length < 240 ? text.replace(/<[^>]+>/g, ' ').trim() : `No se pudo generar el archivo (${response.status}).`);
      }
      const contentType = response.headers.get('content-type') ?? '';
      if (contentType.includes('text/html')) {
        throw new Error('La sesión caducó o no tienes permisos para exportar. Recarga la página e inténtalo de nuevo.');
      }
      const disposition = response.headers.get('content-disposition') ?? '';
      const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
      const resolvedName = match ? decodeURIComponent(match[1]) : `vertice-export-${Date.now()}.${format}`;
      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = objectUrl;
      anchor.download = resolvedName;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1200);
      setFilename(resolvedName);
      setStatus('success');
      closeTimer.current = window.setTimeout(() => setStatus('idle'), 2600);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : 'No se pudo generar el archivo.');
      setStatus('error');
    }
  };

  return (
    <>
      <button type="button" onClick={run} disabled={status === 'loading'} className={className} title={title}>
        {status === 'loading' ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : children}
      </button>

      {(status === 'success' || status === 'error') && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" onClick={status === 'success' ? close : undefined}>
          <div ref={dialogRef} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" className="export-modal-card relative w-full max-w-sm rounded-2xl border border-slate-100 bg-white p-6 text-center shadow-2xl">
            {status === 'success' ? (
              <>
                <div className="relative mx-auto mb-4 flex h-16 w-16 items-center justify-center">
                  <span className="export-success-ring absolute inset-0 rounded-full bg-emerald-100" />
                  <span className="relative flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500 text-white shadow-lg shadow-emerald-500/30">
                    <Check className="h-7 w-7" strokeWidth={2.5} />
                  </span>
                </div>
                <h3 className="text-base font-semibold text-slate-900">Exportado</h3>
                <p className="mt-1 text-xs text-slate-500">Tu archivo {format.toUpperCase()} se ha descargado correctamente.</p>
                <p className="mx-auto mt-3 max-w-full truncate rounded-lg bg-slate-50 px-3 py-2 font-mono text-[10px] text-slate-500" title={filename}>{filename}</p>
                <button type="button" onClick={close} className="mt-5 rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white transition hover:bg-slate-800">Cerrar</button>
              </>
            ) : (
              <>
                <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-rose-50 text-rose-600">
                  <AlertCircle className="h-7 w-7" />
                </div>
                <h3 className="text-base font-semibold text-slate-900">No se pudo exportar</h3>
                <p className="mt-1 text-xs text-slate-500">{errorMessage}</p>
                <div className="mt-5 flex items-center justify-center gap-2">
                  <button type="button" onClick={close} className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50">Cerrar</button>
                  <button type="button" onClick={run} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700">Reintentar</button>
                </div>
              </>
            )}
            {status === 'success' && (
              <button type="button" onClick={close} className="absolute right-4 top-4 rounded-lg p-1 text-slate-300 transition hover:text-slate-500" aria-label="Cerrar"><X className="h-4 w-4" /></button>
            )}
          </div>
        </div>
      )}
    </>
  );
}
