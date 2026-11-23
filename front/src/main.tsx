import { StrictMode } from 'react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import './index.css';
import { installChunkReloadHandler, isChunkLoadError, reloadForNewVersion } from './lib/chunkReload';

installChunkReloadHandler();

const container = document.getElementById('root')!;

const path = window.location.pathname.replace(/\/+$/, '') || '/';

if (path === '/' || path === '/software-para-agencias') {
  // Páginas públicas: el PHP sirve el HTML ya prerenderizado, así que se hidrata
  // sin cargar el router ni el resto de la aplicación.
  void import('./features/landing/PublicSite').then(({ LandingPage, AgencyPage }) => {
    const page = path === '/' ? <LandingPage /> : <AgencyPage />;
    if (container.hasChildNodes()) {
      hydrateRoot(container, page);
    } else {
      createRoot(container).render(<StrictMode>{page}</StrictMode>);
    }
  });
} else {
  void Promise.all([import('./app/App'), import('./app/ErrorBoundary'), import('./app/providers')]).then(
    ([{ App }, { ErrorBoundary }, { Providers }]) => {
      createRoot(container).render(
        <StrictMode>
          <ErrorBoundary>
            <Providers>
              <App />
            </Providers>
          </ErrorBoundary>
        </StrictMode>,
      );
    },
    (error: unknown) => {
      // La propia carga inicial falló (p. ej. index de una versión anterior en caché).
      if (!(isChunkLoadError(error) && reloadForNewVersion())) throw error;
    },
  );
}
