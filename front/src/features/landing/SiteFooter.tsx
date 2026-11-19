// Pie de página compartido por la landing y las comparativas.
import { ArrowUp, ArrowUpRight, Lock, ShieldCheck, Smartphone } from 'lucide-react';
import { COMPETITORS } from './compare-data';
import { SOLUTION_ERP, SOLUTION_FEATURES, SOLUTION_SECTORS, solutionHref } from './solution-data';

const asset = (file: string) => `${import.meta.env.BASE_URL}${file}`;

/** onHome: en la landing los enlaces son anclas locales; en otras páginas apuntan a "/#...". */
export function SiteFooter({ onHome = false }: { onHome?: boolean }) {
  const a = (hash: string) => (onHome ? `#${hash}` : `/#${hash}`);
  const columns: { title: string; links: { label: string; href: string }[] }[] = [
    {
      title: 'Producto',
      links: [
        { label: 'Vértice Agency', href: a('agency') },
        { label: 'Vértice ERP', href: a('erp') },
        { label: 'Red de agencias', href: a('red') },
        { label: 'Preguntas frecuentes', href: a('faq') },
      ],
    },
    {
      title: 'Vértice ERP',
      links: SOLUTION_ERP.map((s) => ({ label: s.name, href: solutionHref(s.slug) })),
    },
    {
      title: 'Agency',
      links: SOLUTION_FEATURES.map((s) => ({ label: s.name, href: solutionHref(s.slug) })),
    },
    {
      title: 'Agency para',
      links: SOLUTION_SECTORS.map((s) => ({ label: s.name, href: solutionHref(s.slug) })),
    },
    {
      title: 'Comparar',
      links: COMPETITORS.map((c) => ({ label: `Alternativa a ${c.name}`, href: `/comparar/${c.slug}` })),
    },
    {
      title: 'Cuenta',
      links: [
        { label: 'Crear cuenta gratis', href: '/app/register' },
        { label: 'Iniciar sesión', href: '/app/login' },
        { label: 'Términos', href: '/app/legal/terms' },
        { label: 'Privacidad', href: '/app/legal/privacy' },
      ],
    },
  ];

  return (
    <footer className="relative isolate overflow-hidden border-t border-slate-200 bg-white">
      <div className="vx-dots absolute inset-0 -z-10 [mask-image:radial-gradient(ellipse_at_top_left,#000_5%,transparent_55%)]" />
      <div className="absolute -right-32 top-10 -z-10 h-80 w-80 rounded-full bg-cyan-100/70 blur-3xl" />
      <div className="absolute -left-24 bottom-0 -z-10 h-72 w-72 rounded-full bg-brand-100/60 blur-3xl" />
      <div className="h-1 w-full bg-gradient-to-r from-navy-950 via-brand-500 to-cyan-400" />

      <div className="mx-auto max-w-7xl px-4 pt-16 sm:px-6 lg:px-8">
        <div className="grid gap-12 lg:grid-cols-[1fr_3fr]">
          {/* Marca */}
          <div>
            <a href="/" aria-label="Vértice, inicio" className="inline-block">
              <img src={asset('logo-clean.png')} alt="Vértice" width={894} height={183} loading="lazy" className="h-8 w-auto" />
            </a>
            <p className="vx-display mt-6 max-w-xs text-2xl font-bold leading-tight text-navy-950">
              Tu negocio, <span className="vx-gradient-text">en su vértice.</span>
            </p>
            <a href="/app/register" className="group mt-6 inline-flex items-center gap-2 rounded-full bg-navy-950 py-2.5 pl-5 pr-2.5 text-sm font-semibold text-white shadow-lg shadow-navy-900/20 transition hover:bg-navy-900">
              Empezar gratis
              <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-cyan-400 transition group-hover:rotate-45"><ArrowUpRight className="h-3.5 w-3.5" /></span>
            </a>
            <ul className="mt-8 flex flex-wrap gap-2">
              {[
                { icon: <Lock className="h-3 w-3" />, t: 'Datos aislados' },
                { icon: <ShieldCheck className="h-3 w-3" />, t: '2FA' },
                { icon: <Smartphone className="h-3 w-3" />, t: '100% web' },
              ].map((b) => (
                <li key={b.t} className="vx-mono inline-flex items-center gap-1.5 rounded-full bg-slate-50 px-3 py-1 text-[11px] text-slate-500 ring-1 ring-slate-200">
                  <span className="text-brand-600">{b.icon}</span>{b.t}
                </li>
              ))}
            </ul>
          </div>

          {/* Enlaces */}
          <nav aria-label="Pie de página" className="grid grid-cols-2 gap-8 sm:grid-cols-3 xl:grid-cols-6">
            {columns.map((col) => (
              <div key={col.title}>
                <p className="vx-mono text-[11px] font-medium uppercase tracking-[0.2em] text-brand-600">{col.title}</p>
                <ul className="mt-5 space-y-3">
                  {col.links.map((l) => (
                    <li key={l.href}>
                      <a href={l.href} className="group inline-flex items-center gap-1 text-sm text-slate-500 transition hover:text-navy-950">
                        <span className="h-px w-0 bg-brand-500 transition-all duration-300 group-hover:w-3" />
                        {l.label}
                      </a>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </nav>
        </div>

        {/* Barra inferior */}
        <div className="mt-16 flex flex-col-reverse items-start gap-4 border-t border-slate-200 py-6 sm:flex-row sm:items-center sm:justify-between">
          <p className="vx-mono text-[11px] text-slate-400">© {new Date().getFullYear()} Vértice · ERP para agencias y empresas</p>
          <a href="#top" className="group inline-flex items-center gap-2 text-xs font-semibold text-slate-500 hover:text-navy-950">
            Volver arriba
            <span className="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white transition group-hover:-translate-y-0.5 group-hover:border-brand-400 group-hover:text-brand-600"><ArrowUp className="h-3.5 w-3.5" /></span>
          </a>
        </div>
      </div>

      {/* Marca de agua */}
      <div className="pointer-events-none relative select-none" aria-hidden="true">
        <p className="vx-display bg-gradient-to-b from-slate-200 to-transparent bg-clip-text text-center text-[21vw] font-bold leading-[0.78] tracking-tight text-transparent">VÉRTICE</p>
        <div className="absolute inset-x-0 top-[18%] flex justify-center"><img src={asset('vertice-mark.png')} alt="" width={225} height={183} loading="lazy" className="vx-float w-[7vw] min-w-[2.5rem] opacity-90" /></div>
      </div>
    </footer>
  );
}
