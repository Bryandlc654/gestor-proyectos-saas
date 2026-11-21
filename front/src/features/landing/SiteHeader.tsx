// Cabecera común de las páginas públicas. Cambia menú y CTA según el producto de la página.
import { ErpDemoLink } from './LeadPrompts';
import { AGENCY_PAGE_PATH } from './ui';

const asset = (file: string) => `${import.meta.env.BASE_URL}${file}`;

type Product = 'erp' | 'agency';

const MENU: Record<Product, { label: string; href: string; strong?: boolean }[]> = {
  erp: [
    { label: 'Vértice ERP', href: '/#erp', strong: true },
    { label: 'Facturación SUNAT', href: '/soluciones/facturacion-electronica-sunat' },
    { label: 'Inventario', href: '/soluciones/software-de-inventario' },
    { label: 'Preguntas', href: '/#faq' },
  ],
  agency: [
    { label: 'Vértice Agency', href: AGENCY_PAGE_PATH, strong: true },
    { label: 'Funciones', href: `${AGENCY_PAGE_PATH}#modulos` },
    { label: 'Comparar', href: `${AGENCY_PAGE_PATH}#comparar` },
    { label: 'Preguntas', href: `${AGENCY_PAGE_PATH}#faq` },
  ],
};

export function SiteHeader({ product = 'erp' }: { product?: Product }) {
  const other = product === 'erp'
    ? { label: 'Para agencias', href: AGENCY_PAGE_PATH }
    : { label: 'Vértice ERP', href: '/' };
  const cta = 'rounded-full bg-navy-950 px-4 py-2 text-white transition hover:bg-brand-600';

  return (
    <header className="fixed inset-x-0 top-4 z-50 px-4">
      <div className="mx-auto flex h-14 max-w-5xl items-center justify-between gap-4 rounded-full border border-slate-200/80 bg-white/80 pl-5 pr-2 shadow-lg shadow-navy-900/5 backdrop-blur-xl">
        <a href={product === 'erp' ? '/' : AGENCY_PAGE_PATH} aria-label="Vértice, inicio" className="shrink-0">
          <img src={asset('logo-clean.png')} alt="Vértice" width={894} height={183} className="h-6 w-auto" />
        </a>
        <nav aria-label="Principal" className="hidden items-center gap-6 text-[13px] text-slate-500 lg:flex">
          {MENU[product].map((m) => (
            <a key={m.href} href={m.href} className={m.strong ? 'font-semibold text-navy-950 hover:text-brand-600' : 'hover:text-navy-950'}>{m.label}</a>
          ))}
          <span className="h-4 w-px bg-slate-200" aria-hidden="true" />
          <a href={other.href} className="hover:text-navy-950">{other.label}</a>
        </nav>
        <div className="flex shrink-0 items-center gap-1.5 text-[13px] font-semibold">
          <a href="/app/login" className="hidden rounded-full px-4 py-2 text-slate-600 hover:text-navy-950 sm:block">Iniciar sesión</a>
          {product === 'erp'
            ? <ErpDemoLink className={cta}>Solicitar demo</ErpDemoLink>
            : <a href="/app/register" className={cta}>Crear cuenta gratis</a>}
        </div>
      </div>
    </header>
  );
}
