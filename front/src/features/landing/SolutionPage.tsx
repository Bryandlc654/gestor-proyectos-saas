// Página de solución (/soluciones/<slug>): función o sector. Se prerenderiza como HTML estático (sin JS),
// por eso no usa animaciones de aparición que dependan de JavaScript.
import type { ReactNode } from 'react';
import { ArrowRight, ArrowUpRight, Check, ShieldCheck, X } from 'lucide-react';
import { COMPETITORS } from './compare-data';
import { AGENCY_SIGNUP_HREF, ERP_DEMO_EXTERNAL, ERP_DEMO_HREF } from './products';
import { SOLUTIONS, solutionHref, solutionProduct, type SolutionPage as Solution } from './solution-data';
import { SiteFooter } from './SiteFooter';
import { SiteHeader } from './SiteHeader';
import { AGENCY_PAGE_PATH } from './ui';
import './landing.css';

const asset = (file: string) => `${import.meta.env.BASE_URL}${file}`;

function Section({ children, className = '', id }: { children: ReactNode; className?: string; id?: string }) {
  return <section id={id} className={`mx-auto max-w-6xl px-4 sm:px-6 lg:px-8 ${className}`}>{children}</section>;
}

function Eyebrow({ children }: { children: ReactNode }) {
  return (
    <span className="vx-mono inline-flex items-center gap-2 text-[11px] font-medium uppercase tracking-[0.22em] text-brand-600">
      <span className="h-px w-6 bg-gradient-to-r from-transparent to-brand-500" />{children}
    </span>
  );
}

export function SolutionPage({ solution }: { solution: Solution }) {
  const s = solution;
  const related = s.related.map((slug) => SOLUTIONS.find((o) => o.slug === slug)).filter((o): o is Solution => Boolean(o));
  const compare = s.compare.map((slug) => COMPETITORS.find((c) => c.slug === slug)).filter((c): c is (typeof COMPETITORS)[number] => Boolean(c));
  const erp = solutionProduct(s) === 'erp';
  const section = erp ? { label: 'Vértice ERP', href: '/' } : { label: 'Vértice Agency', href: AGENCY_PAGE_PATH };
  // CTA principal: registro gratis en Agency, demo en ERP (WhatsApp en pestaña nueva si está configurado).
  const cta = erp
    ? { href: ERP_DEMO_HREF, label: 'Solicitar demo', ext: ERP_DEMO_EXTERNAL ? { target: '_blank', rel: 'noopener noreferrer' } : {} }
    : { href: AGENCY_SIGNUP_HREF, label: 'Empezar gratis', ext: {} };

  return (
    <div id="top" className="vx-root min-h-screen">
      <SiteHeader product={erp ? 'erp' : 'agency'} />
      <main>
        {/* Hero */}
        <div className="vx-noise relative isolate overflow-hidden pb-16 pt-32 sm:pt-40">
          <div className="vx-dots absolute inset-0 -z-10 [mask-image:radial-gradient(ellipse_at_top,#000_30%,transparent_75%)]" />
          <div className="vx-aurora -z-10 left-[-10%] top-[-15%] h-[480px] w-[480px] bg-brand-200" />
          <div className="vx-aurora -z-10 right-[-12%] top-[5%] h-[420px] w-[420px] bg-cyan-200" />
          <Section className="text-center">
            <nav aria-label="Ruta" className="vx-mono text-[11px] uppercase tracking-[0.2em] text-slate-400">
              <a href="/" className="hover:text-brand-600">Vértice</a> <span className="mx-1.5">/</span> <a href={section.href} className="hover:text-brand-600">{section.label}</a> <span className="mx-1.5">/</span> <span className="text-brand-600">{s.name}</span>
            </nav>
            <h1 className="vx-display mx-auto mt-6 max-w-4xl text-[2.4rem] font-bold leading-[1.04] text-navy-950 sm:text-6xl lg:text-[4.2rem]">
              {s.h1Lead} <span className="vx-gradient-text">{s.h1Highlight}</span>
            </h1>
            <p className="mx-auto mt-6 max-w-2xl text-base leading-relaxed text-slate-500 sm:text-lg">{s.intro}</p>
            <div className="mt-9 flex flex-wrap items-center justify-center gap-3">
              <a href={cta.href} {...cta.ext} className="group inline-flex items-center gap-2 rounded-full bg-navy-950 px-7 py-3.5 text-sm font-semibold text-white shadow-[0_14px_30px_-10px_rgb(7_42_91/.6)] transition hover:bg-navy-900">
                {cta.label}
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-cyan-400"><ArrowRight className="h-3.5 w-3.5" /></span>
              </a>
              <a href="#funciones" className="inline-flex items-center rounded-full border border-slate-300 bg-white/70 px-6 py-3.5 text-sm font-semibold text-navy-950 hover:border-navy-950">Ver funciones</a>
            </div>
            <p className="vx-mono mt-6 text-[11px] text-slate-400">{erp ? 'Facturación electrónica SUNAT · 100% web · En español' : 'Gratis · Sin instalar nada · En español'}</p>
          </Section>
        </div>

        {/* Problemas */}
        <Section className="py-16 sm:py-20">
          <div className="max-w-3xl">
            <Eyebrow>El problema</Eyebrow>
            <h2 className="vx-display mt-5 text-3xl font-bold leading-[1.08] text-navy-950 sm:text-5xl">{s.painsTitle}</h2>
          </div>
          <div className="mt-10 grid gap-4 md:grid-cols-3">
            {s.pains.map((p) => (
              <article key={p.t} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-rose-50 text-rose-500 ring-1 ring-rose-100"><X className="h-4 w-4" /></span>
                <h3 className="vx-display mt-4 text-lg font-bold text-navy-950">{p.t}</h3>
                <p className="mt-1.5 text-sm leading-relaxed text-slate-500">{p.d}</p>
              </article>
            ))}
          </div>
        </Section>

        {/* Funciones */}
        <div className="relative isolate border-y border-slate-200 bg-white py-20 sm:py-24">
          <div className="vx-dots absolute inset-0 -z-10 [mask-image:radial-gradient(ellipse_at_top_right,#000_10%,transparent_60%)]" />
          <Section id="funciones" className="scroll-mt-28">
            <div className="mx-auto max-w-3xl text-center">
              <Eyebrow>La solución</Eyebrow>
              <h2 className="vx-display mt-5 text-3xl font-bold leading-[1.08] text-navy-950 sm:text-5xl">{s.featuresTitle}</h2>
            </div>
            <div className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {s.features.map((f) => (
                <article key={f.t} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-lg">
                  <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-100"><Check className="h-4 w-4" /></span>
                  <h3 className="vx-display mt-4 text-lg font-bold text-navy-950">{f.t}</h3>
                  <p className="mt-1.5 text-sm leading-relaxed text-slate-500">{f.d}</p>
                </article>
              ))}
            </div>
          </Section>
        </div>

        {/* Pasos */}
        <Section className="py-20 sm:py-24">
          <Eyebrow>Cómo funciona</Eyebrow>
          <h2 className="vx-display mt-5 text-3xl font-bold leading-[1.08] text-navy-950 sm:text-5xl">{s.stepsTitle}</h2>
          <div className="relative mt-12">
          <div className="absolute left-0 right-0 top-6 hidden h-px bg-gradient-to-r from-navy-900 via-brand-500 to-cyan-300 md:block" aria-hidden="true" />
          <ol className="relative grid gap-10 md:grid-cols-3">
            {s.steps.map((st, i) => (
              <li key={st.t} className="relative">
                <span className="vx-display relative z-10 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-600 to-cyan-500 text-lg font-bold text-white shadow-lg shadow-brand-500/30">{i + 1}</span>
                <h3 className="vx-display mt-6 text-2xl font-bold text-navy-950">{st.t}</h3>
                <p className="mt-2 max-w-xs text-sm leading-relaxed text-slate-500">{st.d}</p>
              </li>
            ))}
          </ol>
          </div>
        </Section>

        {/* FAQ */}
        <section className="border-y border-slate-200 bg-white py-20">
          <div className="mx-auto max-w-3xl px-4 sm:px-6">
            <h2 className="vx-display text-3xl font-bold text-navy-950 sm:text-4xl">Preguntas frecuentes</h2>
            <div className="mt-8 divide-y divide-slate-200 border-y border-slate-200">
              {s.faqs.map((f) => (
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

        {/* Enlazado interno + CTA */}
        <Section className="py-20">
          <div className="grid gap-4 md:grid-cols-2">
            <div className="rounded-3xl border border-slate-200 bg-white p-7 shadow-sm">
              <h2 className="vx-display text-xl font-bold text-navy-950">También te puede interesar</h2>
              <ul className="mt-5 space-y-2">
                {related.map((r) => (
                  <li key={r.slug}>
                    <a href={solutionHref(r.slug)} className="group flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3 text-sm font-semibold text-navy-950 ring-1 ring-slate-100 transition hover:bg-brand-50 hover:text-brand-700">
                      {r.kind === 'sector' ? `Vértice Agency para ${r.name.toLowerCase()}` : r.name}
                      <ArrowUpRight className="h-4 w-4 transition group-hover:rotate-45" />
                    </a>
                  </li>
                ))}
                <li>
                  <a href={erp ? '/' : AGENCY_PAGE_PATH} className="group flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3 text-sm font-semibold text-navy-950 ring-1 ring-slate-100 transition hover:bg-brand-50 hover:text-brand-700">
                    {erp ? 'Vértice ERP: todos los módulos' : 'Software de gestión de proyectos para agencias'}
                    <ArrowUpRight className="h-4 w-4 transition group-hover:rotate-45" />
                  </a>
                </li>
              </ul>
            </div>
            {erp ? (
            <div className="rounded-3xl border border-brand-200 bg-gradient-to-br from-white to-brand-50 p-7 shadow-sm">
              <h2 className="vx-display text-xl font-bold text-navy-950">¿Tu negocio trabaja por proyectos?</h2>
              <p className="mt-3 text-sm leading-relaxed text-slate-500">Si eres una agencia, un estudio o una consultora, Vértice Agency es el ERP pensado para ti: proyectos, CRM, cotizaciones y rentabilidad.</p>
              <ul className="mt-5 space-y-2">
                {[{ href: AGENCY_PAGE_PATH, label: 'Conoce Vértice Agency' }, { href: solutionHref('crm-para-agencias'), label: 'CRM para agencias' }].map((l) => (
                  <li key={l.href}>
                    <a href={l.href} className="group flex items-center justify-between rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-navy-950 ring-1 ring-brand-100 transition hover:text-brand-700">
                      {l.label}
                      <ArrowUpRight className="h-4 w-4 transition group-hover:rotate-45" />
                    </a>
                  </li>
                ))}
              </ul>
            </div>
            ) : (
            <div className="rounded-3xl border border-brand-200 bg-gradient-to-br from-white to-brand-50 p-7 shadow-sm">
              <h2 className="vx-display text-xl font-bold text-navy-950">¿Vienes de otra herramienta?</h2>
              <ul className="mt-5 space-y-2">
                {compare.map((c) => (
                  <li key={c.slug}>
                    <a href={`/comparar/${c.slug}`} className="group flex items-center justify-between rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-navy-950 ring-1 ring-brand-100 transition hover:text-brand-700">
                      Alternativa a {c.name}
                      <ArrowUpRight className="h-4 w-4 transition group-hover:rotate-45" />
                    </a>
                  </li>
                ))}
              </ul>
            </div>
            )}
          </div>

          <div className="relative mt-16 overflow-hidden rounded-[2.25rem] bg-gradient-to-br from-navy-950 via-navy-900 to-brand-700 px-7 py-14 text-center text-white shadow-[0_50px_100px_-50px_rgb(7_42_91/.6)] sm:px-12">
            <div className="absolute left-1/2 top-1/2 h-72 w-72 -translate-x-1/2 -translate-y-1/2 rounded-full bg-cyan-400/25 blur-3xl" />
            <img src={asset('vertice-mark-light.png')} alt="" width={225} height={183} loading="lazy" className="vx-float relative mx-auto w-16" />
            <h2 className="vx-display relative mt-6 text-3xl font-bold sm:text-5xl">{erp ? 'Conoce Vértice ERP' : 'Prueba Vértice Agency gratis'}</h2>
            <p className="relative mx-auto mt-3 max-w-md text-white/70">{erp ? 'Agenda una demo y te mostramos el ERP con los procesos de tu empresa.' : 'Configura tu agencia en minutos. Sin instalar nada.'}</p>
            <a href={cta.href} {...cta.ext} className="relative mt-8 inline-flex items-center gap-2 rounded-full bg-white px-7 py-3.5 text-sm font-semibold text-navy-950 transition hover:scale-[1.03]">
              {erp ? 'Solicitar demo' : 'Crear cuenta gratis'} <ArrowUpRight className="h-4 w-4" />
            </a>
            <p className="vx-mono relative mt-6 flex items-center justify-center gap-2 text-[11px] text-white/60"><ShieldCheck className="h-3.5 w-3.5" /> {erp ? 'Datos aislados por empresa' : 'Datos aislados por agencia'} · 2FA · Google login</p>
          </div>
        </Section>
      </main>

      <SiteFooter />
    </div>
  );
}
