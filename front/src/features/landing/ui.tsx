// Piezas compartidas por las páginas públicas (home de Vértice ERP y página de Vértice Agency).
import { useEffect, type MouseEvent, type ReactNode } from 'react';
import {
  Briefcase, Plus, Store, } from 'lucide-react';

export const asset = (file: string) => `${import.meta.env.BASE_URL}${file}`;

/** Activa las animaciones de aparición cuando los elementos entran en pantalla. */
export function useReveal() {
  useEffect(() => {
    const nodes = document.querySelectorAll<HTMLElement>('.vx-reveal');
    if (!('IntersectionObserver' in window)) {
      nodes.forEach((n) => n.classList.add('is-in'));
      return;
    }
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => {
        if (e.isIntersecting) {
          e.target.classList.add('is-in');
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    nodes.forEach((n) => io.observe(n));
    return () => io.disconnect();
  }, []);
}

export function trackSpot(e: MouseEvent<HTMLElement>) {
  const r = e.currentTarget.getBoundingClientRect();
  e.currentTarget.style.setProperty('--mx', `${e.clientX - r.left}px`);
  e.currentTarget.style.setProperty('--my', `${e.clientY - r.top}px`);
}

export function Reveal({ children, delay = 0, className = '' }: { children: ReactNode; delay?: number; className?: string }) {
  return <div className={`vx-reveal ${className}`} style={{ transitionDelay: `${delay}ms` }}>{children}</div>;
}

export function Eyebrow({ children }: { children: ReactNode }) {
  return (
    <span className="vx-mono inline-flex items-center gap-2 text-[11px] font-medium uppercase tracking-[0.22em] text-brand-600">
      <span className="h-px w-6 bg-gradient-to-r from-transparent to-brand-500" />{children}
    </span>
  );
}

/** Etiqueta de producto: "Vértice Agency" o "Vértice ERP". */
export function ProductBadge({ product, dark = false }: { product: 'agency' | 'erp'; dark?: boolean }) {
  const label = product === 'agency' ? 'Agency' : 'ERP';
  return (
    <span className={`inline-flex self-start items-center gap-2 rounded-full py-1 pl-1 pr-3 text-[11px] font-semibold ${dark ? 'bg-white/10 text-white ring-1 ring-white/15' : 'bg-white text-navy-950 ring-1 ring-slate-200 shadow-sm'}`}>
      <span className={`flex h-5 w-5 items-center justify-center rounded-full text-white ${product === 'agency' ? 'bg-gradient-to-br from-brand-600 to-cyan-500' : 'bg-gradient-to-br from-navy-900 to-brand-600'}`}>
        {product === 'agency' ? <Briefcase className="h-3 w-3" /> : <Store className="h-3 w-3" />}
      </span>
      Vértice <span className={dark ? 'text-cyan-300' : 'text-brand-600'}>{label}</span>
    </span>
  );
}

/** Lista de preguntas frecuentes con acordeón nativo (<details>), legible sin JavaScript. */
export function FaqSection({ faqs, aside, id = 'faq' }: { faqs: { q: string; a: string }[]; aside?: ReactNode; id?: string }) {
  return (
    <section id={id} className="relative scroll-mt-24 border-t border-slate-200 bg-white py-24 sm:py-32">
      <div className="mx-auto grid max-w-7xl gap-12 px-4 sm:px-6 lg:grid-cols-[0.8fr_1.2fr] lg:px-8">
        <Reveal>
          <div className="lg:sticky lg:top-28">
            <Eyebrow>Preguntas frecuentes</Eyebrow>
            <h2 className="vx-display mt-5 text-4xl font-bold leading-[1.05] text-navy-950 sm:text-5xl">Lo que suelen <span className="text-slate-300">preguntarnos.</span></h2>
            {aside}
          </div>
        </Reveal>
        <div className="divide-y divide-slate-200 border-y border-slate-200">
          {faqs.map((f, i) => (
            <Reveal key={f.q} delay={Math.min(i, 6) * 60}>
              <details className="group py-5">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-6 text-left [&::-webkit-details-marker]:hidden">
                  <h3 className="vx-display text-lg font-bold text-navy-950 sm:text-xl">{f.q}</h3>
                  <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-navy-950 transition duration-300 group-open:rotate-45 group-open:border-brand-500 group-open:bg-brand-500 group-open:text-white"><Plus className="h-4 w-4" /></span>
                </summary>
                <p className="mt-3 max-w-2xl text-sm leading-relaxed text-slate-500 sm:text-base">{f.a}</p>
              </details>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  );
}

/** Página pública de Vértice Agency (servida por software-para-agencias.php). */
export const AGENCY_PAGE_PATH = '/software-para-agencias';
