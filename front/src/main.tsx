import { StrictMode } from 'react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import './index.css';

const container = document.getElementById('root')!;

if (window.location.pathname === '/') {
  // Landing pública en la raíz: index.php sirve el HTML ya prerenderizado, así que se hidrata
  // sin cargar el router ni el resto de la aplicación.
  void import('./features/landing/LandingPage').then(({ LandingPage }) => {
    if (container.hasChildNodes()) {
      hydrateRoot(container, <LandingPage />);
    } else {
      createRoot(container).render(<StrictMode><LandingPage /></StrictMode>);
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
  );
}
