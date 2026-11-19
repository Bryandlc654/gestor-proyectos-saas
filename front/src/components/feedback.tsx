import { AlertTriangle, CheckCircle2, Info, Loader2, X } from 'lucide-react';
import { createContext, useCallback, useContext, useEffect, useRef, useState, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

type ToastTone = 'success' | 'error' | 'info';
export interface ToastAction {
  label: string;
  onClick: () => void | Promise<void>;
}
interface ToastOptions {
  action?: ToastAction;
  duration?: number;
}
interface ToastItem { id: number; message: string; tone: ToastTone; action?: ToastAction }

const ToastContext = createContext<{ toast: (message: string, tone?: ToastTone, options?: ToastOptions) => void } | null>(null);

export function useToast() {
  const context = useContext(ToastContext);
  if (!context) throw new Error('useToast debe usarse dentro de ToastProvider');
  return context.toast;
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const counter = useRef(0);

  const remove = useCallback((id: number) => setItems((current) => current.filter((item) => item.id !== id)), []);

  const toast = useCallback((message: string, tone: ToastTone = 'success', options?: ToastOptions) => {
    const id = ++counter.current;
    setItems((current) => [...current.slice(-3), { id, message, tone, action: options?.action }]);
    window.setTimeout(() => remove(id), options?.duration ?? (options?.action ? 6500 : 3600));
  }, [remove]);

  return (
    <ToastContext.Provider value={{ toast }}>
      {children}
      <div className="pointer-events-none fixed bottom-20 right-5 z-[100] flex w-[calc(100%-2.5rem)] max-w-xs flex-col gap-2 md:bottom-5">
        {items.map((item) => (
          <div key={item.id} className={`toast-in pointer-events-auto flex items-start gap-2.5 rounded-xl border bg-white px-4 py-3 text-xs font-semibold shadow-lg ${item.tone === 'error' ? 'border-rose-200 text-rose-700' : item.tone === 'info' ? 'border-slate-200 text-slate-700' : 'border-emerald-200 text-emerald-700'}`} role="status">
            {item.tone === 'error' ? <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" /> : item.tone === 'info' ? <Info className="mt-0.5 h-4 w-4 flex-shrink-0" /> : <CheckCircle2 className="mt-0.5 h-4 w-4 flex-shrink-0" />}
            <span className="flex-1 leading-snug">{item.message}</span>
            {item.action && (
              <button
                type="button"
                onClick={async () => {
                  remove(item.id);
                  try {
                    await item.action?.onClick();
                  } catch {
                    // El llamador muestra su propio error; el toast no debe romper la UI.
                  }
                }}
                className="whitespace-nowrap self-center rounded-md border border-black/10 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide transition hover:bg-black/5"
              >
                {item.action.label}
              </button>
            )}
            <button type="button" onClick={() => remove(item.id)} className="text-slate-300 transition hover:text-slate-500" aria-label="Cerrar aviso"><X className="h-3.5 w-3.5" /></button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

interface ConfirmOptions {
  title: string;
  description?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: 'danger' | 'default';
}

const ConfirmContext = createContext<((options: ConfirmOptions) => Promise<boolean>) | null>(null);

export function useConfirm() {
  const context = useContext(ConfirmContext);
  if (!context) throw new Error('useConfirm debe usarse dentro de ConfirmProvider');
  return context;
}

export function ConfirmProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<{ options: ConfirmOptions; resolve: (value: boolean) => void } | null>(null);

  const confirm = useCallback((options: ConfirmOptions) => new Promise<boolean>((resolve) => {
    setState({ options, resolve });
  }), []);

  const close = (value: boolean) => {
    setState((current) => {
      current?.resolve(value);
      return null;
    });
  };

  const danger = state?.options.tone !== 'default';

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      <Modal open={state !== null} onClose={() => close(false)} panelClassName="w-full max-w-sm rounded-2xl border border-slate-100 bg-white p-6 text-center shadow-2xl">
        {state && (
          <>
            <div className={`mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full ${danger ? 'bg-rose-50 text-rose-600' : 'bg-brand-50 text-brand-600'}`}>
              <AlertTriangle className="h-6 w-6" />
            </div>
            <h3 className="text-base font-semibold text-slate-900">{state.options.title}</h3>
            {state.options.description && <p className="mt-1.5 text-xs leading-relaxed text-slate-500">{state.options.description}</p>}
            <div className="mt-5 flex items-center justify-center gap-2">
              <button type="button" data-autofocus onClick={() => close(false)} className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50">{state.options.cancelLabel ?? 'Cancelar'}</button>
              <button type="button" onClick={() => close(true)} className={`rounded-xl px-4 py-2 text-xs font-semibold text-white transition ${danger ? 'bg-rose-600 hover:bg-rose-700' : 'bg-brand-600 hover:bg-brand-700'}`}>{state.options.confirmLabel ?? 'Confirmar'}</button>
            </div>
          </>
        )}
      </Modal>
    </ConfirmContext.Provider>
  );
}

export function useDialog(open: boolean, onClose: () => void) {
  const ref = useRef<HTMLDivElement>(null);
  const closeRef = useRef(onClose);
  closeRef.current = onClose;

  useEffect(() => {
    if (!open) return;
    const previousFocus = document.activeElement as HTMLElement | null;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    const focusFirst = () => {
      const node = ref.current;
      const target = node?.querySelector<HTMLElement>('[data-autofocus], input:not([type="hidden"]), select, textarea, button, [href], [tabindex]:not([tabindex="-1"])');
      target?.focus();
    };
    const timer = window.setTimeout(focusFirst, 40);

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        closeRef.current();
        return;
      }
      if (event.key !== 'Tab') return;
      const node = ref.current;
      if (!node) return;
      const focusable = Array.from(node.querySelectorAll<HTMLElement>('input:not([type="hidden"]), select, textarea, button, [href], [tabindex]:not([tabindex="-1"])')).filter((element) => !element.hasAttribute('disabled') && element.offsetParent !== null);
      if (focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', onKeyDown);
    return () => {
      window.clearTimeout(timer);
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = previousOverflow;
      previousFocus?.focus?.();
    };
  }, [open]);

  return ref;
}

export function Modal({ open, onClose, children, panelClassName, labelledBy }: { open: boolean; onClose: () => void; children: ReactNode; panelClassName?: string; labelledBy?: string }) {
  const ref = useDialog(open, onClose);
  if (!open) return null;
  return createPortal(
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs"
      onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}
    >
      <div ref={ref} role="dialog" aria-modal="true" aria-labelledby={labelledBy} className={panelClassName ?? 'w-full max-w-lg rounded-2xl border border-slate-100 bg-white shadow-2xl'}>
        {children}
      </div>
    </div>,
    document.body,
  );
}

export function LoadingButton({ pending, pendingLabel, children, className, disabled, ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { pending: boolean; pendingLabel?: string }) {
  return (
    <button type="button" {...props} disabled={pending || disabled} className={className}>
      {pending ? (
        <span className="inline-flex items-center gap-1.5">
          <Loader2 className="h-3.5 w-3.5 animate-spin" />
          {pendingLabel ?? 'Guardando…'}
        </span>
      ) : children}
    </button>
  );
}
