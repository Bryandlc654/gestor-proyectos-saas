// Pie de página común de las páginas públicas. Enlaza ambos productos con anchor text descriptivo (SEO interno).
import { ArrowUp, Lock, ShieldCheck, Smartphone } from 'lucide-react';
import { COMPETITORS } from './compare-data';
import { SOLUTION_ERP, SOLUTION_FEATURES, SOLUTION_SECTORS, solutionHref } from './solution-data';
import { AGENCY_PAGE_PATH } from './ui';

const asset = (file: string) => `${import.meta.env.BASE_URL}${file}`;

export function SiteFooter() {
  const columns: { title: string; links: { label: string; href: string }[] }[] = [
    {
      title: 'Vértice ERP',
      links: [{ label: 'Sistema ERP', href: '/' }, ...SOLUTION_ERP.map((s) => ({ label: s.name, href: solutionHref(s.slug) }))],
    },
    {
      title: 'Vértice Agency',
      links: [{ label: 'Software para agencias', href: AGENCY_PAGE_PATH }, ...SOLUTION_FEATURES.map((s) => ({ label: s.name, href: solutionHref(s.slug) }))],
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
        { label: 'Iniciar sesión', href: '/app/login' },
        { label: 'Crear cuenta', href: '/app/register' },
        { label: 'Términos', href: '/app/legal/terms' },
        { label: 'Privacidad', href: '/app/legal/privacy' },
      ],
    },
  ];

  return (
    <footer className="relative border-t border-slate-200 bg-white">
      <div className="h-1 w-full bg-gradient-to-r from-navy-950 via-brand-500 to-cyan-400" />
      <div className="mx-auto max-w-7xl px-4 pt-16 sm:px-6 lg:px-8">
        <div className="grid gap-12 lg:grid-cols-[1fr_3.4fr]">
          <div>
            <a href="/" aria-label="Vértice, inicio" className="inline-block">
              <img src={asset('logo-clean.png')} alt="Vértice" width={894} height={183} loading="lazy" className="h-7 w-auto" />
            </a>
            <p className="mt-5 max-w-xs text-sm leading-relaxed text-slate-500">
              Software de gestión hecho en Perú: Vértice ERP para empresas y Vértice Agency para agencias.
            </p>
            <ul className="mt-6 flex flex-wrap gap-2">
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

          <nav aria-label="Pie de página" className="grid grid-cols-2 gap-8 sm:grid-cols-3 xl:grid-cols-5">
            {columns.map((col) => (
              <div key={col.title}>
                <p className="vx-mono text-[11px] font-medium uppercase tracking-[0.2em] text-brand-600">{col.title}</p>
                <ul className="mt-5 space-y-3">
                  {col.links.map((l) => (
                    <li key={l.href}>
                      <a href={l.href} className="text-sm text-slate-500 transition hover:text-navy-950">{l.label}</a>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </nav>
        </div>

        <div className="mt-16 flex flex-col-reverse items-start gap-4 border-t border-slate-200 py-6 sm:flex-row sm:items-center sm:justify-between">
          <p className="vx-mono text-[11px] text-slate-400">© {new Date().getFullYear()} Vértice</p>
          <a href="#top" className="group inline-flex items-center gap-2 text-xs font-semibold text-slate-500 hover:text-navy-950">
            Volver arriba
            <span className="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white transition group-hover:-translate-y-0.5 group-hover:border-brand-400 group-hover:text-brand-600"><ArrowUp className="h-3.5 w-3.5" /></span>
          </a>
        </div>
      </div>
    </footer>
  );
}
