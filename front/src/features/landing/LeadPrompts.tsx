// Elementos de conversión de la landing: enlace a la demo del ERP, barra fija con CTA y aviso al intentar salir.
// Todo lo que depende de window corre en efectos, así el prerender (SSR) no se rompe.
import { useEffect, useState, type ReactNode } from 'react';
import { ArrowUpRight, Briefcase, Store, X } from 'lucide-react';
import { AGENCY_SIGNUP_HREF, ERP_DEMO_EXTERNAL, ERP_DEMO_HREF } from './products';

/** Enlace a "Solicitar demo" de Vértice ERP (WhatsApp en pestaña nueva si está configurado). */
export function ErpDemoLink({ className, children }: { className: string; children: ReactNode }) {
  return ERP_DEMO_EXTERNAL
    ? <a href={ERP_DEMO_HREF} target="_blank" rel="noopener noreferrer" className={className}>{children}</a>
    : <a href={ERP_DEMO_HREF} className={className}>{children}</a>;
}

function sessionFlag(key: string, set = false): boolean {
  try {
    if (set) sessionStorage.setItem(key, '1');
    return sessionStorage.getItem(key) === '1';
  } catch {
    return false;
  }
}

/** Barra fija con la demo del ERP: aparece al pasar el hero y se oculta al llegar al cierre de la página. */
export function StickyErpCta() {
  const [show, setShow] = useState(false);
  const [closed, setClosed] = useState(false);

  useEffect(() => {
    if (sessionFlag('vx-sticky-closed')) { setClosed(true); return; }
    const onScroll = () => {
      const y = window.scrollY;
      const end = document.documentElement.scrollHeight - window.innerHeight - 900;
      setShow(y > 720 && y < end);
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  if (closed) return null;

  return (
    <aside
      aria-label="Solicitar demo de Vértice ERP"
      className={`fixed inset-x-3 bottom-3 z-40 transition duration-500 sm:inset-x-auto sm:bottom-5 sm:right-5 sm:w-[26rem] ${show ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-6 opacity-0'}`}
    >
      <div className="flex items-center gap-3 rounded-2xl border border-white/10 bg-navy-950 p-2.5 pl-3 text-white shadow-[0_20px_50px_-15px_rgb(7_42_91/.7)]">
        <span className="hidden h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-600 to-cyan-500 sm:flex"><Store className="h-4 w-4" /></span>
        <p className="min-w-0 flex-1 text-[13px] leading-snug">
          <span className="font-semibold">¿Facturas ante SUNAT?</span>{' '}
          <span className="text-white/70">Mira Vértice ERP con tus datos.</span>
        </p>
        <ErpDemoLink className="shrink-0 rounded-full bg-white px-4 py-2 text-xs font-semibold text-navy-950 transition hover:bg-cyan-300">Pedir demo</ErpDemoLink>
        <button
          type="button"
          aria-label="Cerrar"
          onClick={() => { sessionFlag('vx-sticky-closed', true); setClosed(true); }}
          className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-white/50 transition hover:bg-white/10 hover:text-white"
        >
          <X className="h-3.5 w-3.5" />
        </button>
      </div>
    </aside>
  );
}

/** Aviso al intentar salir (solo escritorio, una vez por sesión y tras unos segundos en la página). */
export function ExitIntent() {
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!window.matchMedia('(pointer: fine)').matches || sessionFlag('vx-exit-seen')) return;
    const start = Date.now();
    const onOut = (e: MouseEvent) => {
      if (e.relatedTarget !== null || e.clientY > 0 || Date.now() - start < 10000) return;
      sessionFlag('vx-exit-seen', true);
      setOpen(true);
      document.removeEventListener('mouseout', onOut);
    };
    document.addEventListener('mouseout', onOut);
    return () => document.removeEventListener('mouseout', onOut);
  }, []);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="vx-exit-title">
      <button type="button" aria-label="Cerrar" className="vx-fade-up absolute inset-0 bg-navy-950/50 backdrop-blur-sm" onClick={() => setOpen(false)} />
      <div className="vx-fade-up relative w-full max-w-lg overflow-hidden rounded-[2rem] bg-white shadow-2xl">
        <div className="relative bg-gradient-to-br from-navy-950 via-navy-900 to-brand-700 px-8 pb-8 pt-9 text-white">
          <div className="absolute -right-10 -top-12 h-48 w-48 rounded-full bg-cyan-400/30 blur-3xl" />
          <button type="button" aria-label="Cerrar" onClick={() => setOpen(false)} className="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full text-white/60 transition hover:bg-white/10 hover:text-white">
            <X className="h-4 w-4" />
          </button>
          <p className="vx-mono relative text-[11px] uppercase tracking-[0.22em] text-cyan-300">Antes de irte</p>
          <h2 id="vx-exit-title" className="vx-display relative mt-3 text-3xl font-bold leading-tight">¿Seguimos con el Excel un mes más?</h2>
          <p className="relative mt-3 text-sm leading-relaxed text-white/75">
            En una demo te mostramos Vértice ERP con los datos de tu empresa: facturación SUNAT, inventario y cobranzas en un solo lugar. Sin compromiso.
          </p>
          <ErpDemoLink className="group relative mt-6 inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 text-sm font-semibold text-navy-950 transition hover:bg-cyan-300">
            Solicitar demo gratis <ArrowUpRight className="h-4 w-4 transition group-hover:rotate-45" />
          </ErpDemoLink>
        </div>
        <a href={AGENCY_SIGNUP_HREF} className="group flex items-center gap-3 px-8 py-5 text-sm text-slate-500 transition hover:bg-slate-50">
          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-100"><Briefcase className="h-4 w-4" /></span>
          <span className="flex-1">¿Tienes una agencia? <span className="font-semibold text-navy-950 group-hover:text-brand-600">Crea tu cuenta de Vértice Agency gratis</span></span>
          <ArrowUpRight className="h-4 w-4 text-slate-300 transition group-hover:text-brand-600" />
        </a>
      </div>
    </div>
  );
}
