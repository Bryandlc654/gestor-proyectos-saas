// Página comparativa "Alternativa a <X> para agencias". Se prerenderiza como HTML estático (sin JS).
import type { ReactNode } from 'react';
import { ArrowRight, ArrowUpRight, Check, Minus, ShieldCheck, Sparkles, X } from 'lucide-react';
import { COMPARE_ROWS, COMPARE_UPDATED, COMPETITORS, VERTICE_SUPPORT, type Competitor, type Support } from './compare-data';
import { SiteFooter } from './SiteFooter';
import { SiteHeader } from './SiteHeader';
import './landing.css';

const asset = (file: string) => `${import.meta.env.BASE_URL}${file}`;

function Mark({ value, note, strong = false }: { value: Support; note?: string; strong?: boolean }) {
  const icon = value === 'yes' ? <Check className="h-4 w-4" /> : value === 'partial' ? <Minus className="h-4 w-4" /> : <X className="h-3.5 w-3.5" />;
  const tone = value === 'yes'
    ? strong ? 'bg-gradient-to-br from-brand-600 to-cyan-500 text-white shadow-md shadow-brand-500/30' : 'bg-emerald-50 text-emerald-600 ring-1 ring-emerald-200'
    : value === 'partial' ? 'bg-amber-50 text-amber-600 ring-1 ring-amber-200' : 'bg-white text-slate-300 ring-1 ring-slate-200';
  const label = value === 'yes' ? 'Sí' : value === 'partial' ? 'Parcial' : 'No';
  return (
    <span className="flex flex-col items-center gap-1 text-center">
      <span className={`flex h-8 w-8 items-center justify-center rounded-full ${tone}`} aria-label={label}>{icon}</span>
      {note && <span className="vx-mono max-w-[9rem] text-[10px] leading-tight text-slate-400">{note}</span>}
    </span>
  );
}

function Section({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <section className={`mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 ${className}`}>{children}</section>;
}

export function ComparePage({ competitor }: { competitor: Competitor }) {
  const c = competitor;
  const others = COMPETITORS.filter((o) => o.slug !== c.slug);
  const wins = COMPARE_ROWS.filter((r) => VERTICE_SUPPORT[r.key] === 'yes' && c.support[r.key] !== 'yes').length;

  return (
    <div id="top" className="vx-root min-h-screen">
      <SiteHeader />
      <main>
        {/* Hero */}
        <div className="vx-noise relative isolate overflow-hidden pb-16 pt-32 sm:pt-40">
          <div className="vx-dots absolute inset-0 -z-10 [mask-image:radial-gradient(ellipse_at_top,#000_30%,transparent_75%)]" />
          <div className="vx-aurora -z-10 left-[-10%] top-[-15%] h-[480px] w-[480px] bg-brand-200" />
          <div className="vx-aurora -z-10 right-[-12%] top-[5%] h-[420px] w-[420px] bg-cyan-200" />
          <Section className="text-center">
            <nav aria-label="Ruta" className="vx-mono text-[11px] uppercase tracking-[0.2em] text-slate-400">
              <a href="/" className="hover:text-brand-600">Vértice</a> <span className="mx-1.5">/</span> <a href="/#comparar" className="hover:text-brand-600">Comparar</a> <span className="mx-1.5">/</span> <span className="text-brand-600">{c.name}</span>
            </nav>
            <h1 className="vx-display mx-auto mt-6 max-w-4xl text-[2.6rem] font-bold leading-[1.02] text-navy-950 sm:text-6xl lg:text-7xl">
              La alternativa a <span className="vx-gradient-text">{c.name}</span><br className="hidden sm:block" /> para agencias.
            </h1>
            <p className="mx-auto mt-6 max-w-xl text-base text-slate-500 sm:text-lg">
              Proyectos, CRM, cotizaciones y facturación en un solo lugar, en red con agencias aliadas y clientes.
            </p>
            <div className="mt-9 flex flex-wrap items-center justify-center gap-3">
              <a href="/app/register" className="group inline-flex items-center gap-2 rounded-full bg-navy-950 px-7 py-3.5 text-sm font-semibold text-white shadow-[0_14px_30px_-10px_rgb(7_42_91/.6)] transition hover:bg-navy-900">
                Probar Vértice Agency gratis
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-cyan-400"><ArrowRight className="h-3.5 w-3.5" /></span>
              </a>
              <a href="#comparativa" className="inline-flex items-center rounded-full border border-slate-300 bg-white/70 px-6 py-3.5 text-sm font-semibold text-navy-950 hover:border-navy-950">Ver comparativa</a>
            </div>
          </Section>
        </div>

        {/* Versus */}
        <Section className="pb-6">
          <div className="grid items-stretch gap-4 sm:grid-cols-[1fr_auto_1fr]">
            <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
              <p className="vx-mono text-[11px] uppercase tracking-[0.2em] text-slate-400">{c.name}</p>
              <p className="vx-display mt-3 text-2xl font-bold text-navy-950">{c.tagline}</p>
            </div>
            <div className="flex items-center justify-center">
              <span className="vx-display flex h-14 w-14 items-center justify-center rounded-full bg-navy-950 text-sm font-bold text-white shadow-lg">vs</span>
            </div>
            <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-950 via-navy-900 to-brand-700 p-6 text-white shadow-[0_30px_60px_-30px_rgb(7_42_91/.6)]">
              <img src={asset('vertice-mark-light.png')} alt="" width={225} height={183} className="absolute -right-6 -top-6 w-28 opacity-20" />
              <p className="vx-mono text-[11px] uppercase tracking-[0.2em] text-cyan-300">Vértice</p>
              <p className="vx-display relative mt-3 text-2xl font-bold">Gestión completa para agencias, en red.</p>
              <p className="vx-mono relative mt-4 inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-[11px] text-cyan-200"><Sparkles className="h-3 w-3" /> {wins} ventajas incluidas</p>
            </div>
          </div>
        </Section>

        {/* Tabla comparativa */}
        <Section className="py-16 sm:py-20">
          <div id="comparativa" className="scroll-mt-28">
            <h2 className="vx-display text-center text-3xl font-bold text-navy-950 sm:text-5xl">Vértice vs {c.name}</h2>
            <div className="mt-10 overflow-hidden rounded-[2rem] border border-slate-200 bg-white p-3 shadow-[0_30px_60px_-30px_rgb(7_42_91/.3)] sm:p-5">
              <table className="w-full border-separate border-spacing-y-1.5">
                <caption className="sr-only">Comparativa de funciones entre Vértice y {c.name}</caption>
                <thead>
                  <tr>
                    <th scope="col" className="px-3 pb-2 text-left"><span className="vx-mono text-[11px] font-normal uppercase tracking-[0.2em] text-brand-600">Función</span></th>
                    <th scope="col" className="w-24 pb-2 sm:w-40"><span className="vx-display text-sm font-bold text-navy-950 sm:text-base">Vértice</span></th>
                    <th scope="col" className="w-24 pb-2 sm:w-40"><span className="vx-display text-sm font-bold text-slate-500 sm:text-base">{c.name}</span></th>
                  </tr>
                </thead>
                <tbody>
                  {COMPARE_ROWS.map((r) => (
                    <tr key={r.key} className="bg-slate-50/70">
                      <th scope="row" className="rounded-l-2xl px-3 py-3 text-left text-sm font-medium text-navy-950 sm:px-4">{r.label}</th>
                      <td className="py-3"><Mark value={VERTICE_SUPPORT[r.key]} strong /></td>
                      <td className="rounded-r-2xl py-3"><Mark value={c.support[r.key]} note={c.notes[r.key]} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="vx-mono mt-4 text-center text-[10px] leading-relaxed text-slate-400">
              Según la información pública de cada plataforma a {COMPARE_UPDATED}; puede variar según el plan. {c.name} es una marca de su respectivo titular.
            </p>
          </div>
        </Section>

        {/* Cuándo elegir */}
        <Section className="pb-20">
          <div className="grid gap-4 md:grid-cols-2">
            <div className="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
              <h2 className="vx-display text-xl font-bold text-navy-950">Elige {c.name} si…</h2>
              <ul className="mt-5 space-y-3">
                {c.chooseThem.map((t) => <li key={t} className="flex gap-3 text-sm text-slate-600"><span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-slate-300" />{t}</li>)}
              </ul>
            </div>
            <div className="rounded-3xl border border-brand-200 bg-gradient-to-br from-white to-brand-50 p-7 shadow-sm">
              <h2 className="vx-display text-xl font-bold text-navy-950">Elige <span className="vx-gradient-text">Vértice</span> si…</h2>
              <ul className="mt-5 space-y-3">
                {c.chooseUs.map((t) => <li key={t} className="flex gap-3 text-sm text-navy-950"><span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-500 text-white"><Check className="h-3 w-3" /></span>{t}</li>)}
              </ul>
            </div>
          </div>
        </Section>

        {/* FAQ */}
        <section className="border-y border-slate-200 bg-white py-20">
          <div className="mx-auto max-w-3xl px-4 sm:px-6">
            <h2 className="vx-display text-3xl font-bold text-navy-950 sm:text-4xl">Preguntas frecuentes</h2>
            <div className="mt-8 divide-y divide-slate-200 border-y border-slate-200">
              {c.faqs.map((f) => (
                <details key={f.q} className="group py-5">
                  <summary className="flex cursor-pointer list-none items-center justify-between gap-6 [&::-webkit-details-marker]:hidden">
                    <h3 className="vx-display text-lg font-bold text-navy-950">{f.q}</h3>
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 text-lg text-navy-950 transition group-open:rotate-45 group-open:border-brand-500 group-open:bg-brand-500 group-open:text-white">+</span>
                  </summary>
                  <p className="mt-3 text-sm leading-relaxed text-slate-500 sm:text-base">{f.a}</p>
                </details>
              ))}
            </div>
          </div>
        </section>

        {/* Otras comparativas + CTA */}
        <Section className="py-20">
          <p className="vx-mono text-center text-[11px] uppercase tracking-[0.22em] text-slate-400">Otras comparativas</p>
          <div className="mt-5 flex flex-wrap justify-center gap-2.5">
            {others.map((o) => (
              <a key={o.slug} href={`/comparar/${o.slug}`} className="group inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-navy-950 shadow-sm transition hover:border-brand-300 hover:text-brand-600">
                Vértice vs {o.name} <ArrowUpRight className="h-3.5 w-3.5 transition group-hover:rotate-45" />
              </a>
            ))}
          </div>

          <div className="relative mt-16 overflow-hidden rounded-[2.25rem] bg-gradient-to-br from-navy-950 via-navy-900 to-brand-700 px-7 py-14 text-center text-white shadow-[0_50px_100px_-50px_rgb(7_42_91/.6)] sm:px-12">
            <div className="absolute left-1/2 top-1/2 h-72 w-72 -translate-x-1/2 -translate-y-1/2 rounded-full bg-cyan-400/25 blur-3xl" />
            <img src={asset('vertice-mark-light.png')} alt="" width={225} height={183} className="vx-float relative mx-auto w-16" />
            <h2 className="vx-display relative mt-6 text-3xl font-bold sm:text-5xl">¿Listo para cambiarte?</h2>
            <p className="relative mx-auto mt-3 max-w-md text-white/70">Crea tu cuenta gratis. Sin instalar nada.</p>
            <a href="/app/register" className="relative mt-8 inline-flex items-center gap-2 rounded-full bg-white px-7 py-3.5 text-sm font-semibold text-navy-950 transition hover:scale-[1.03]">
              Crear cuenta gratis <ArrowUpRight className="h-4 w-4" />
            </a>
            <p className="vx-mono relative mt-6 flex items-center justify-center gap-2 text-[11px] text-white/60"><ShieldCheck className="h-3.5 w-3.5" /> Datos aislados por agencia · 2FA · Google login</p>
          </div>
        </Section>
      </main>

      <SiteFooter />
    </div>
  );
}
