import { useQuery } from '@tanstack/react-query';
import { createContext, useContext, useEffect, type ReactNode } from 'react';
import { ApiError, apiGet, setCsrfToken } from '../../lib/api';
import type { Bootstrap } from '../../types';

const BootstrapContext = createContext<Bootstrap | null>(null);

export function useBootstrap(): Bootstrap {
  const value = useContext(BootstrapContext);
  if (!value) throw new Error('useBootstrap debe usarse dentro de BootstrapProvider');
  return value;
}

export function BootstrapProvider({ children }: { children: ReactNode }) {
  const query = useQuery({
    queryKey: ['bootstrap'],
    queryFn: async () => {
      const data = await apiGet<Bootstrap>('bootstrap.php');
      setCsrfToken(data.csrfToken);
      return data;
    },
  });

  useEffect(() => {
    if (query.data) setCsrfToken(query.data.csrfToken);
  }, [query.data]);

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 401) {
      window.location.replace('/app/login');
    }
  }, [query.error]);

  const unauthorized = query.error instanceof ApiError && query.error.status === 401;

  if (query.isLoading || unauthorized) {
    return (
      <div className="flex h-full items-center justify-center bg-slate-50">
        <div className="flex flex-col items-center gap-3">
          <div className="relative flex h-16 w-16 items-center justify-center">
            <span className="absolute inset-0 animate-ping rounded-full bg-brand-100/70" />
            <img src={`${import.meta.env.BASE_URL}favicon.png`} alt="Cargando Vértice" className="relative h-12 w-12" />
          </div>
          <p className="text-[11px] font-medium text-slate-400">{unauthorized ? 'Redirigiendo al inicio de sesión…' : 'Cargando…'}</p>
        </div>
      </div>
    );
  }

  if (query.error || !query.data) {
    return (
      <div className="flex h-full items-center justify-center bg-slate-50 p-6">
        <div className="max-w-md rounded-2xl border border-rose-200 bg-white p-6 text-center shadow-sm">
          <h1 className="text-base font-semibold text-slate-900">No se pudo cargar la aplicación</h1>
          <p className="mt-1 text-xs text-slate-500">
            {query.error instanceof Error ? query.error.message : 'Revisa tu sesión e inténtalo de nuevo.'}
          </p>
          <a href="/app/login" className="mt-4 inline-flex rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white">
            Ir al inicio de sesión
          </a>
        </div>
      </div>
    );
  }

  return <BootstrapContext.Provider value={query.data}>{children}</BootstrapContext.Provider>;
}
