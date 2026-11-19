// Cabecera de las páginas públicas estáticas (comparativas y soluciones). La landing tiene su propia Nav.
const asset = (file: string) => `${import.meta.env.BASE_URL}${file}`;

export function SiteHeader() {
  return (
    <header className="fixed inset-x-0 top-4 z-50 px-4">
      <div className="mx-auto flex h-14 max-w-5xl items-center justify-between rounded-full border border-slate-200/80 bg-white/80 pl-5 pr-2 shadow-lg shadow-navy-900/5 backdrop-blur-xl">
        <a href="/" aria-label="Vértice, inicio"><img src={asset('logo-clean.png')} alt="Vértice" width={894} height={183} className="h-6 w-auto" /></a>
        <nav aria-label="Principal" className="hidden items-center gap-7 text-[13px] text-slate-500 md:flex">
          <a href="/#agency" className="hover:text-navy-950">Vértice Agency</a>
          <a href="/#erp" className="hover:text-navy-950">Vértice ERP</a>
          <a href="/#comparar" className="hover:text-navy-950">Comparar</a>
          <a href="/#faq" className="hover:text-navy-950">Preguntas</a>
        </nav>
        <div className="flex items-center gap-1.5 text-[13px] font-semibold">
          <a href="/app/login" className="hidden rounded-full px-4 py-2 text-slate-600 hover:text-navy-950 sm:block">Iniciar sesión</a>
          <a href="/app/register" className="rounded-full bg-navy-950 px-4 py-2 text-white transition hover:bg-brand-600">Empezar</a>
        </div>
      </div>
    </header>
  );
}
