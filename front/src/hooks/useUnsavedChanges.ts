import { useEffect } from 'react';
import { useBlocker } from 'react-router-dom';
import { useConfirm } from '../components/feedback';

/**
 * Protege formularios con cambios sin guardar:
 * - intercepta navegaciones internas de React Router;
 * - pide confirmación antes de recargar o cerrar la pestaña.
 */
export function useUnsavedChanges(dirty: boolean, description = 'Tienes cambios sin guardar. Si sales ahora se perderán.') {
  const confirm = useConfirm();
  const blocker = useBlocker(({ currentLocation, nextLocation }) => dirty && currentLocation.pathname !== nextLocation.pathname);

  useEffect(() => {
    if (blocker.state !== 'blocked') return;
    let active = true;
    void confirm({
      title: 'Cambios sin guardar',
      description,
      confirmLabel: 'Salir sin guardar',
      cancelLabel: 'Seguir editando',
      tone: 'danger',
    }).then((leave) => {
      if (!active) return;
      if (leave) blocker.proceed?.();
      else blocker.reset?.();
    });
    return () => { active = false; };
  }, [blocker.state, confirm, description]);

  useEffect(() => {
    if (!dirty) return;
    const handler = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, [dirty]);

  return blocker;
}
