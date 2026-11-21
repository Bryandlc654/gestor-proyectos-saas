// Home pública: Vértice ERP (producto principal). Vértice Agency vive en AgencyPage (/software-para-agencias).
import { useEffect, type ReactNode } from 'react';
import {
  ArrowRight, ArrowUpRight, BadgeCheck, BarChart3, Boxes, CalendarClock, CheckCircle2, ClipboardList, FileDiff, FileSpreadsheet, FileText, HandCoins, MessageSquare, Package,
  ReceiptText, ShieldCheck, ShoppingCart, Truck, UserCheck, Users, Wallet, } from 'lucide-react';
import { ERP_GROUPS, ERP_MODULE_NAMES } from './products';
import { ErpDemoLink, ExitIntent, StickyErpCta } from './LeadPrompts';
import { SOLUTION_ERP, solutionHref } from './solution-data';
import { asset, AGENCY_PAGE_PATH, Eyebrow, FaqSection, ProductBadge, Reveal, trackSpot, useReveal } from './ui';
import { SiteFooter } from './SiteFooter';
import { SiteHeader } from './SiteHeader';
import './landing.css';

/* ------------------------------------------------------------------ */
/* Hero: Vértice ERP es el producto principal                           */
/* ------------------------------------------------------------------ */
function Hero() {
  return (
    <section className="vx-noise relative isolate overflow-hidden pb-24 pt-32 sm:pt-40 lg:pb-40">
      <div className="vx-dots absolute inset-0 -z-10 [mask-image:radial-gradient(ellipse_at_top,#000_30%,transparent_75%)]" />
      <div className="vx-aurora -z-10 left-[-10%] top-[-15%] h-[520px] w-[520px] bg-brand-200" />
      <div className="vx-aurora -z-10 right-[-12%] top-[5%] h-[460px] w-[460px] bg-cyan-200" style={{ animationDelay: '-6s' }} />
      <div className="vx-aurora -z-10 bottom-[-25%] left-[30%] h-[420px] w-[620px] bg-brand-100" style={{ animationDelay: '-12s' }} />
      <div className="vx-floor -z-10" />

      <div className="relative mx-auto grid max-w-7xl items-center gap-14 px-4 sm:px-6 lg:grid-cols-[1.08fr_1fr] lg:px-8">
        <div>
          <Reveal>
            <div className="flex flex-wrap items-center gap-2.5">
              <ProductBadge product="erp" />
              <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-[11px] font-semibold text-emerald-700">
                <BadgeCheck className="h-3.5 w-3.5" /> Facturación electrónica SUNAT
              </span>
            </div>
          </Reveal>
          <Reveal delay={100}>
            <h1 className="mt-7">
              <span className="vx-mono block text-[11px] font-medium uppercase tracking-[0.22em] text-brand-600 sm:text-xs">Sistema ERP y facturación electrónica para empresas en Perú</span>
              <span className="vx-display mt-4 block text-[2.6rem] font-bold leading-[1.02] text-navy-950 sm:text-6xl lg:text-[4.3rem]">
                Factura, vende y controla tu stock <span className="vx-gradient-text">sin Excel.</span>
              </span>
            </h1>
          </Reveal>
          <Reveal delay={200}>
            <p className="mt-6 max-w-xl text-base leading-relaxed text-slate-500 sm:text-lg">
              Vértice ERP une facturación electrónica SUNAT, inventario, kardex, compras y cobranzas. Cada venta descuenta stock y queda en cuentas por cobrar: <strong className="font-semibold text-navy-950">tus números siempre cuadran.</strong>
            </p>
          </Reveal>
          <Reveal delay={300}>
            <div className="mt-9 flex flex-wrap items-center gap-3">
              <ErpDemoLink className="group relative inline-flex items-center gap-2 overflow-hidden rounded-full bg-navy-950 px-7 py-3.5 text-sm font-semibold text-white shadow-[0_14px_30px_-10px_rgb(7_42_91/.6)] transition hover:bg-navy-900 hover:shadow-[0_18px_40px_-10px_rgb(21_115_255/.6)]">
                Solicitar demo gratis
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-cyan-400 text-white transition group-hover:rotate-[-45deg]"><ArrowRight className="h-3.5 w-3.5" /></span>
              </ErpDemoLink>
              <a href="#erp" className="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white/70 px-6 py-3.5 text-sm font-semibold text-navy-950 transition hover:border-navy-950">
                Ver cómo funciona
              </a>
            </div>
            <ul className="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-500">
              {['Sin compromiso', 'Configuramos tu facturación', 'Te ayudamos a cargar tus productos'].map((t) => (
                <li key={t} className="flex items-center gap-1.5"><CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />{t}</li>
              ))}
            </ul>
          </Reveal>
          <Reveal delay={400}>
            <dl className="mt-10 grid max-w-md grid-cols-3 divide-x divide-slate-200 border-y border-slate-200 py-4">
              {[['SUNAT', 'vía Nubefact'], [String(ERP_MODULE_NAMES.length), 'módulos'], ['100%', 'web y en español']].map(([v, l]) => (
                <div key={l} className="px-4 first:pl-0">
                  <dt className="vx-display text-2xl font-bold text-navy-950">{v}</dt>
                  <dd className="vx-mono mt-0.5 text-[10px] uppercase tracking-widest text-slate-400">{l}</dd>
                </div>
              ))}
            </dl>
            <p className="mt-6 text-xs text-slate-400">¿Ya tienes cuenta? <a href="/app/login" className="font-semibold text-navy-950 hover:text-brand-600">Inicia sesión</a></p>
          </Reveal>
        </div>
        <Reveal delay={200} className="lg:pl-8"><InvoiceMock /></Reveal>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* ERP: problema → solución                                             */
/* ------------------------------------------------------------------ */
function ErpPains() {
  const pains = [
    {
      icon: <FileSpreadsheet className="h-5 w-5" />,
      t: 'El inventario no cuadra',
      d: 'El Excel dice una cosa y el almacén otra. Te enteras cuando ya vendiste lo que no tenías.',
      fix: 'Cada compra y cada venta mueven el stock y el kardex solos.',
    },
    {
      icon: <ReceiptText className="h-5 w-5" />,
      t: 'Facturas en un lado, ventas en otro',
      d: 'Emites en un portal, registras en una hoja y copias los mismos datos dos veces.',
      fix: 'La factura sale de la venta y llega a SUNAT vía Nubefact.',
    },
    {
      icon: <Wallet className="h-5 w-5" />,
      t: 'No sabes quién te debe',
      d: 'Las cobranzas viven en la memoria de alguien y los vencimientos se pasan.',
      fix: 'Cuentas por cobrar con saldos y vencimientos por cliente.',
    },
  ];
  return (
    <section className="relative py-24 sm:py-32">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <Reveal className="max-w-3xl">
          <Eyebrow>¿Te suena?</Eyebrow>
          <h2 className="vx-display mt-5 text-4xl font-bold leading-[1.05] text-navy-950 sm:text-5xl">
            Si tu empresa vive entre Excel y portales, <span className="text-slate-300">esto te pasa.</span>
          </h2>
        </Reveal>
        <div className="mt-14 grid gap-5 md:grid-cols-3">
          {pains.map((p, i) => (
            <Reveal key={p.t} delay={i * 120} className="h-full">
              <article className="flex h-full flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div className="flex-1 p-6 sm:p-7">
                  <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-rose-50 text-rose-500 ring-1 ring-rose-100">{p.icon}</span>
                  <h3 className="vx-display mt-5 text-xl font-bold text-navy-950">{p.t}</h3>
                  <p className="mt-2 text-sm leading-relaxed text-slate-500">{p.d}</p>
                </div>
                <div className="flex items-start gap-2.5 border-t border-emerald-100 bg-emerald-50/60 px-6 py-4 text-sm font-medium text-emerald-800 sm:px-7">
                  <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />
                  <span><span className="font-semibold">Con Vértice ERP:</span> {p.fix}</span>
                </div>
              </article>
            </Reveal>
          ))}
        </div>
        <Reveal className="mt-10 flex justify-center">
          <ErpDemoLink className="group inline-flex items-center gap-2 rounded-full bg-navy-950 px-7 py-3.5 text-sm font-semibold text-white transition hover:bg-brand-600">
            Quiero verlo con mis datos <ArrowRight className="h-4 w-4 transition group-hover:translate-x-0.5" />
          </ErpDemoLink>
        </Reveal>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* ERP: cómo empezar (reduce la fricción de pedir la demo)              */
/* ------------------------------------------------------------------ */
function ErpOnboarding() {
  const steps = [
    { icon: <MessageSquare className="h-5 w-5" />, t: 'Agenda tu demo', d: 'Nos cuentas cómo vendes, compras y facturas hoy. Te mostramos Vértice ERP con ese flujo.' },
    { icon: <ReceiptText className="h-5 w-5" />, t: 'Dejamos todo listo', d: 'Configuramos tu facturación electrónica y te ayudamos a cargar tus productos y clientes.' },
    { icon: <BadgeCheck className="h-5 w-5" />, t: 'Empiezas a facturar', d: 'Tu equipo vende, compra y cobra desde un solo lugar, sin instalar nada.' },
  ];
  return (
    <section id="empezar" className="relative scroll-mt-24 border-y border-slate-200 bg-white py-24 sm:py-32">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <Reveal className="flex flex-col items-start justify-between gap-5 sm:flex-row sm:items-end">
          <div>
            <Eyebrow>Cómo empezar con Vértice ERP</Eyebrow>
            <h2 className="vx-display mt-5 text-4xl font-bold leading-[1.05] text-navy-950 sm:text-5xl">No empiezas solo. <span className="vx-gradient-text">Te acompañamos.</span></h2>
          </div>
          <ul className="flex flex-wrap gap-2">
            {['Sin instalar nada', 'En español', 'Ayuda en la puesta en marcha'].map((t) => (
              <li key={t} className="vx-mono inline-flex items-center gap-1.5 rounded-full bg-slate-50 px-3 py-1 text-[11px] text-slate-500 ring-1 ring-slate-200">
                <ShieldCheck className="h-3 w-3 text-brand-600" />{t}
              </li>
            ))}
          </ul>
        </Reveal>
        <div className="relative mt-14 grid gap-10 md:grid-cols-3">
          <div className="absolute left-0 right-0 top-6 hidden h-px bg-gradient-to-r from-navy-900 via-brand-500 to-cyan-300 md:block" />
          {steps.map((s, i) => (
            <Reveal key={s.t} delay={i * 150}>
              <div className="relative">
                <span className="relative z-10 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-navy-950 to-brand-600 text-white shadow-lg shadow-brand-500/30">{s.icon}</span>
                <p className="vx-display vx-outline-text mt-8 text-7xl font-bold leading-none">0{i + 1}</p>
                <h3 className="vx-display mt-3 text-2xl font-bold text-navy-950">{s.t}</h3>
                <p className="mt-2 max-w-xs text-sm leading-relaxed text-slate-500">{s.d}</p>
              </div>
            </Reveal>
          ))}
        </div>

        <Reveal className="mt-16">
          <div id="erp-demo" className="relative scroll-mt-24 overflow-hidden rounded-[2rem] bg-gradient-to-br from-navy-950 via-navy-900 to-brand-700 px-7 py-10 text-white sm:px-12 sm:py-12">
            <div className="absolute -right-10 -top-16 h-64 w-64 rounded-full bg-cyan-400/25 blur-3xl" />
            <div className="relative flex flex-col items-start justify-between gap-6 md:flex-row md:items-center">
              <div>
                <ProductBadge product="erp" dark />
                <p className="vx-display mt-4 max-w-xl text-2xl font-bold leading-tight sm:text-3xl">Te mostramos Vértice ERP con los datos de tu empresa.</p>
                <p className="mt-2 max-w-lg text-sm text-white/70">Una demo sin compromiso: revisamos tus procesos y te decimos cómo quedaría tu operación en Vértice ERP.</p>
              </div>
              <ErpDemoLink className="group inline-flex shrink-0 items-center gap-2 rounded-full bg-white px-7 py-3.5 text-sm font-semibold text-navy-950 transition hover:bg-cyan-300">
                Solicitar demo gratis <ArrowUpRight className="h-4 w-4 transition group-hover:rotate-45" />
              </ErpDemoLink>
            </div>
          </div>
        </Reveal>
      </div>
    </section>
  );
}

/** Separador que abre el bloque de una app dentro de la landing. */
function ProductBand({ id, product, title, text }: { id: string; product: 'agency' | 'erp'; title: ReactNode; text: string }) {
  return (
    <div id={id} className="relative scroll-mt-24 px-4 pt-10 sm:px-6 lg:px-8">
      <Reveal className="mx-auto flex max-w-7xl flex-col items-start gap-5 border-t border-slate-200 pt-12 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <ProductBadge product={product} />
          <h2 className="vx-display mt-5 text-3xl font-bold leading-[1.05] text-navy-950 sm:text-4xl">{title}</h2>
        </div>
        <p className="max-w-sm text-sm leading-relaxed text-slate-500 sm:text-right">{text}</p>
      </Reveal>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Vértice ERP: módulos para empresas                                   */
/* ------------------------------------------------------------------ */
const ERP_ICONS: Record<string, ReactNode> = {
  clientes: <Users className="h-4 w-4" />,
  cotizaciones: <FileText className="h-4 w-4" />,
  facturacion: <ReceiptText className="h-4 w-4" />,
  notas: <FileDiff className="h-4 w-4" />,
  cxc: <Wallet className="h-4 w-4" />,
  productos: <Package className="h-4 w-4" />,
  inventario: <Boxes className="h-4 w-4" />,
  kardex: <ClipboardList className="h-4 w-4" />,
  compras: <ShoppingCart className="h-4 w-4" />,
  proveedores: <Truck className="h-4 w-4" />,
  cxp: <HandCoins className="h-4 w-4" />,
  colaboradores: <UserCheck className="h-4 w-4" />,
  asistencias: <CalendarClock className="h-4 w-4" />,
  reportes: <BarChart3 className="h-4 w-4" />,
};

function InvoiceMock() {
  const items = [
    { d: 'Laptop 14" i5 16GB', q: 2, p: '1,500.00' },
    { d: 'Mouse inalámbrico', q: 4, p: '45.00' },
  ];
  return (
    <div className="relative">
      <div className="absolute -inset-6 rounded-[2.5rem] bg-gradient-to-br from-brand-100 via-cyan-50 to-transparent blur-2xl" />
      <div className="relative rounded-3xl border border-slate-200 bg-white p-5 shadow-[0_40px_80px_-40px_rgb(7_42_91/.45)] sm:p-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="vx-mono text-[10px] uppercase tracking-[0.2em] text-slate-400">Factura electrónica</p>
            <p className="vx-display mt-1 text-xl font-bold text-navy-950">F001-000284</p>
          </div>
          <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200">
            <BadgeCheck className="h-3.5 w-3.5" /> Aceptada por SUNAT
          </span>
        </div>
        <div className="mt-4 grid grid-cols-2 gap-3 rounded-2xl bg-slate-50 p-3 text-[11px]">
          <div><p className="text-slate-400">Cliente</p><p className="font-semibold text-navy-950">Comercial Andina S.A.C.</p></div>
          <div><p className="text-slate-400">RUC</p><p className="vx-mono font-medium text-navy-950">20601234567</p></div>
        </div>
        <ul className="mt-4 divide-y divide-slate-100 text-[12px]">
          {items.map((it) => (
            <li key={it.d} className="flex items-center justify-between gap-3 py-2">
              <span className="truncate text-slate-700"><span className="vx-mono mr-2 text-slate-400">{it.q}×</span>{it.d}</span>
              <span className="vx-mono shrink-0 text-navy-950">S/ {it.p}</span>
            </li>
          ))}
        </ul>
        <div className="mt-2 space-y-1 border-t border-slate-200 pt-3 text-[12px]">
          <div className="flex justify-between text-slate-500"><span>Op. gravada</span><span className="vx-mono">S/ 2,694.92</span></div>
          <div className="flex justify-between text-slate-500"><span>IGV 18%</span><span className="vx-mono">S/ 485.08</span></div>
          <div className="flex justify-between text-sm font-bold text-navy-950"><span>Total</span><span className="vx-mono">S/ 3,180.00</span></div>
        </div>
        <div className="mt-4 flex flex-wrap items-center gap-1.5">
          {['XML', 'CDR', 'PDF'].map((f) => <span key={f} className="vx-mono rounded-md bg-navy-950 px-2 py-0.5 text-[10px] text-white">{f}</span>)}
          <span className="vx-mono ml-auto text-[10px] text-slate-400">vía Nubefact</span>
        </div>
      </div>

      {/* Kardex flotante */}
      <div className="relative mt-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl shadow-navy-900/10 sm:absolute sm:-bottom-28 sm:-left-12 sm:mt-0 sm:w-64">
        <div className="flex items-center justify-between">
          <p className="text-xs font-semibold text-navy-950">Kardex · Laptop 14"</p>
          <ClipboardList className="h-3.5 w-3.5 text-brand-600" />
        </div>
        <ul className="vx-mono mt-3 space-y-1.5 text-[10px]">
          <li className="flex justify-between rounded-md bg-emerald-50 px-2 py-1 text-emerald-700"><span>Compra OC-118</span><span>+10</span></li>
          <li className="flex justify-between rounded-md bg-rose-50 px-2 py-1 text-rose-600"><span>Venta F001-284</span><span>−2</span></li>
          <li className="flex justify-between rounded-md bg-slate-50 px-2 py-1 font-semibold text-navy-950"><span>Saldo</span><span>14 u.</span></li>
        </ul>
      </div>
    </div>
  );
}

/** Cuentas por cobrar y alerta de stock: lo que el dueño mira cada mañana. */
function ControlMock() {
  const rows = [
    { c: 'Comercial Andina S.A.C.', d: 'F001-000284', m: '3,180.00', s: 'Por vencer', tone: 'bg-amber-50 text-amber-700 ring-amber-200' },
    { c: 'Distribuidora Norte E.I.R.L.', d: 'F001-000279', m: '1,240.00', s: 'Vencida', tone: 'bg-rose-50 text-rose-600 ring-rose-200' },
    { c: 'Inversiones Sol S.A.', d: 'B001-001532', m: '420.00', s: 'Pagada', tone: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  ];
  return (
    <div className="relative">
      <div className="absolute -inset-6 rounded-[2.5rem] bg-gradient-to-br from-brand-100 via-cyan-50 to-transparent blur-2xl" />
      <div className="relative rounded-3xl border border-slate-200 bg-white p-5 shadow-[0_40px_80px_-40px_rgb(7_42_91/.45)] sm:p-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="vx-mono text-[10px] uppercase tracking-[0.2em] text-slate-400">Cuentas por cobrar</p>
            <p className="vx-display mt-1 text-2xl font-bold text-navy-950">S/ 4,420.00</p>
            <p className="text-[11px] text-slate-500">pendiente de cobro</p>
          </div>
          <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-100"><Wallet className="h-4 w-4" /></span>
        </div>
        <ul className="mt-5 divide-y divide-slate-100 text-[12px]">
          {rows.map((r) => (
            <li key={r.d} className="flex items-center justify-between gap-3 py-2.5">
              <span className="min-w-0">
                <span className="block truncate font-semibold text-navy-950">{r.c}</span>
                <span className="vx-mono text-[10px] text-slate-400">{r.d}</span>
              </span>
              <span className="flex shrink-0 items-center gap-2">
                <span className="vx-mono text-navy-950">S/ {r.m}</span>
                <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ${r.tone}`}>{r.s}</span>
              </span>
            </li>
          ))}
        </ul>
      </div>
      <div className="relative mt-4 flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl shadow-navy-900/10 sm:absolute sm:-bottom-16 sm:-right-6 sm:mt-0 sm:w-72">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600 ring-1 ring-amber-200"><Package className="h-4 w-4" /></span>
        <div>
          <p className="text-xs font-semibold text-navy-950">Stock bajo: Mouse inalámbrico</p>
          <p className="vx-mono text-[10px] text-slate-500">Quedan 3 u. · Generar compra</p>
        </div>
      </div>
    </div>
  );
}

function Erp() {
  const flow = ['Cotización', 'Factura', 'SUNAT', 'Cobro'];
  return (
    <section className="relative isolate overflow-hidden pb-24 pt-4 sm:pb-32">
      <div className="vx-aurora -z-10 right-[-10%] top-[10%] h-[460px] w-[460px] bg-cyan-100" />
      <div className="vx-aurora -z-10 left-[-12%] bottom-[5%] h-[380px] w-[380px] bg-brand-100" style={{ animationDelay: '-7s' }} />
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid items-center gap-16 pt-16 lg:grid-cols-[1fr_0.95fr]">
          <Reveal>
            <h3 className="vx-display text-4xl font-bold leading-[1.03] text-navy-950 sm:text-5xl lg:text-[3.4rem]">
              Vende, compra y factura.<br /><span className="vx-gradient-text">Todo cuadra.</span>
            </h3>
            <p className="mt-6 max-w-lg text-base leading-relaxed text-slate-500 sm:text-lg">
              Emite facturas, boletas y notas de crédito o débito electrónicas ante SUNAT a través de Nubefact. Cada venta descuenta el inventario, alimenta el kardex y queda en cuentas por cobrar.
            </p>
            <ol className="mt-8 flex flex-wrap items-center gap-2">
              {flow.map((s, i) => (
                <li key={s} className="flex items-center gap-2">
                  <span className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1.5 pl-1.5 pr-4 text-sm font-semibold text-navy-950 shadow-sm">
                    <span className="vx-mono flex h-6 w-6 items-center justify-center rounded-full bg-navy-950 text-[10px] text-white">{i + 1}</span>{s}
                  </span>
                  {i < flow.length - 1 && <ArrowRight className="h-3.5 w-3.5 text-slate-300" />}
                </li>
              ))}
            </ol>
            <div className="mt-9 flex flex-wrap items-center gap-3">
              <ErpDemoLink className="group inline-flex items-center gap-2 rounded-full bg-navy-950 px-7 py-3.5 text-sm font-semibold text-white shadow-[0_14px_30px_-10px_rgb(7_42_91/.6)] transition hover:bg-brand-600">
                Solicitar demo
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-cyan-400 transition group-hover:rotate-[-45deg]"><ArrowRight className="h-3.5 w-3.5" /></span>
              </ErpDemoLink>
            </div>
          </Reveal>
          <Reveal delay={150} className="lg:pl-10"><ControlMock /></Reveal>
        </div>

        {/* Módulos agrupados */}
        <div className="mt-28 grid gap-5 lg:grid-cols-3">
          {ERP_GROUPS.map((g, gi) => (
            <Reveal key={g.id} delay={gi * 120} className="h-full">
              <article onMouseMove={trackSpot} className="vx-spot flex h-full flex-col rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-lg sm:p-7">
                <div className="flex items-center justify-between">
                  <span className="vx-mono text-xs text-slate-400">0{gi + 1} / 0{ERP_GROUPS.length}</span>
                  <span className="vx-mono rounded-full bg-slate-50 px-2.5 py-0.5 text-[10px] text-slate-500 ring-1 ring-slate-200">{g.modules.length} módulos</span>
                </div>
                <h4 className="vx-display mt-6 text-2xl font-bold text-navy-950">{g.title}</h4>
                <p className="mt-1.5 text-sm text-slate-500">{g.text}</p>
                <ul className="mt-6 space-y-3 border-t border-slate-100 pt-5">
                  {g.modules.map((m) => (
                    <li key={m.key} className="flex gap-3">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-100">{ERP_ICONS[m.key]}</span>
                      <span>
                        <span className="block text-sm font-semibold text-navy-950">{m.name}</span>
                        <span className="block text-[13px] leading-snug text-slate-500">{m.text}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              </article>
            </Reveal>
          ))}
        </div>

        <Reveal className="mt-10">
          <ul className="flex flex-wrap justify-center gap-2.5">
            {SOLUTION_ERP.map((sol) => (
              <li key={sol.slug}>
                <a href={solutionHref(sol.slug)} className="group inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-navy-950 shadow-sm transition hover:border-brand-300 hover:text-brand-600">
                  {sol.name} <ArrowUpRight className="h-3.5 w-3.5 transition group-hover:rotate-45" />
                </a>
              </li>
            ))}
          </ul>
        </Reveal>

      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Clientes que ya usan Vértice ERP (prueba social justo tras el hero)  */
/* ------------------------------------------------------------------ */
const ERP_CLIENTS: { name: string; logo: string; w: number; h: number }[] = [
  { name: 'Consultoría Grupo JC S.A.C.', logo: 'clientes/consultoria-grupo-jc.webp', w: 267, h: 240 },
  { name: 'Magic Auto Spa (Felporca S.A.C.)', logo: 'clientes/magic-auto-spa.webp', w: 363, h: 240 },
  { name: 'G. Prisma Ingenieros S.A.C.', logo: 'clientes/g-prisma.webp', w: 433, h: 240 },
];

function ErpClients() {
  return (
    <section id="clientes" aria-labelledby="clientes-title" className="relative scroll-mt-24 border-y border-slate-200 bg-white py-16 sm:py-20">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <Reveal className="text-center">
          <p className="vx-mono text-[11px] font-medium uppercase tracking-[0.22em] text-brand-600">Clientes</p>
          <h2 id="clientes-title" className="vx-display mt-3 text-2xl font-bold text-navy-950 sm:text-3xl">Empresas que ya trabajan con Vértice ERP</h2>
        </Reveal>
        <ul className="mx-auto mt-10 grid max-w-4xl grid-cols-1 gap-4 sm:grid-cols-3">
          {ERP_CLIENTS.map((c, i) => (
            <li key={c.logo}>
              <Reveal delay={i * 100} className="flex h-32 items-center justify-center rounded-3xl border border-slate-200 bg-white p-5 shadow-[0_12px_30px_-20px_rgb(7_42_91/.35)] transition duration-300 hover:-translate-y-1 hover:border-brand-200 sm:h-36">
                <img src={asset(c.logo)} alt={`Logo de ${c.name}, cliente de Vértice ERP`} width={c.w} height={c.h} loading="lazy" className="max-h-full max-w-full object-contain" />
              </Reveal>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Contenido SEO: qué es y por qué Vértice ERP (keywords en texto real) */
/* ------------------------------------------------------------------ */
function ErpWhy() {
  const points = [
    { icon: <ReceiptText className="h-5 w-5" />, t: 'Facturación electrónica SUNAT', d: 'Facturas, boletas y notas de crédito o débito enviadas a SUNAT a través de Nubefact, con XML, CDR y PDF guardados en cada comprobante.' },
    { icon: <Boxes className="h-5 w-5" />, t: 'Inventario y kardex valorizado', d: 'Stock por producto actualizado con cada compra y cada venta, y un kardex con entradas, salidas y saldo valorizado.' },
    { icon: <Wallet className="h-5 w-5" />, t: 'Cuentas por cobrar y por pagar', d: 'Saldos, vencimientos y abonos por cliente y por proveedor, para saber cuánto entra y cuánto sale.' },
    { icon: <UserCheck className="h-5 w-5" />, t: 'Colaboradores y asistencias', d: 'Datos y accesos de tu equipo, con marcaciones de entrada y salida, tardanzas y faltas.' },
  ];
  return (
    <section id="que-es" className="relative scroll-mt-24 py-24 sm:py-32">
      <div className="mx-auto grid max-w-7xl gap-14 px-4 sm:px-6 lg:grid-cols-[0.9fr_1.1fr] lg:px-8">
        <Reveal>
          <Eyebrow>Sistema ERP en la nube</Eyebrow>
          <h2 className="vx-display mt-5 text-4xl font-bold leading-[1.05] text-navy-950 sm:text-5xl">
            Un sistema ERP para pymes en Perú, <span className="text-slate-300">sin instalar nada.</span>
          </h2>
          <p className="mt-6 text-base leading-relaxed text-slate-500">
            Vértice ERP es un software de gestión empresarial en la nube que reúne ventas, facturación electrónica, inventario, compras, cobranzas y personal en un solo lugar. Lo usas desde el navegador, en español, y cada módulo comparte la misma información: lo que vendes se descuenta del stock, se factura ante SUNAT y queda en tus cuentas por cobrar.
          </p>
          <p className="mt-4 text-base leading-relaxed text-slate-500">
            Está pensado para empresas que venden productos o servicios y hoy llevan su operación entre hojas de cálculo, un portal de facturación y cuadernos.
          </p>
        </Reveal>
        <div className="grid gap-4 sm:grid-cols-2">
          {points.map((p, i) => (
            <Reveal key={p.t} delay={i * 100} className="h-full">
              <article className="h-full rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-100">{p.icon}</span>
                <h3 className="vx-display mt-5 text-lg font-bold text-navy-950">{p.t}</h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-500">{p.d}</p>
              </article>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
export const LANDING_TITLE = 'Sistema ERP con facturación electrónica SUNAT | Vértice ERP';
export const LANDING_DESCRIPTION = 'Vértice ERP: sistema ERP en la nube para pymes en Perú. Facturación electrónica SUNAT, inventario, kardex, compras y cobranzas. Pide tu demo gratis.';

export const LANDING_FAQS: { q: string; a: string }[] = [
  { q: '¿Qué es Vértice ERP?', a: 'Vértice ERP es un sistema ERP en la nube para empresas en Perú. Reúne clientes, productos, inventario, kardex, compras, proveedores, cuentas por cobrar y por pagar, colaboradores, asistencias y facturación electrónica ante SUNAT en una sola plataforma web en español.' },
  { q: '¿Qué módulos incluye Vértice ERP?', a: 'Clientes, colaboradores, asistencias, productos, inventario, kardex, compras, proveedores, cuentas por pagar, cotizaciones, facturación electrónica, notas de crédito y débito, cuentas por cobrar y reportes.' },
  { q: '¿Vértice ERP emite comprobantes electrónicos válidos ante SUNAT?', a: 'Sí. Vértice ERP emite facturas, boletas y notas de crédito y débito electrónicas que se envían a SUNAT a través de Nubefact, y conserva el XML, la constancia de recepción (CDR) y el PDF de cada comprobante.' },
  { q: '¿Sirve como software de inventario?', a: 'Sí. Cada compra suma stock y cada venta lo descuenta automáticamente, y el kardex muestra las entradas, salidas y el saldo valorizado de cada producto.' },
  { q: '¿Puedo pasar mis productos y clientes desde Excel?', a: 'Sí. Durante la puesta en marcha te ayudamos a cargar tus productos y clientes para que empieces a facturar con tu propia información.' },
  { q: '¿Necesito instalar algo?', a: 'No. Vértice ERP es una plataforma web: entras desde el navegador, en cualquier computadora, sin instalar programas ni servidores.' },
  { q: '¿Cómo empiezo con Vértice ERP?', a: 'Solicita una demo: revisamos los procesos de tu empresa, configuramos tu facturación electrónica y te ayudamos a cargar tus productos y clientes.' },
  { q: '¿Cuánto cuesta Vértice ERP?', a: 'Depende del tamaño y las necesidades de tu empresa. Solicita una demo sin compromiso y te preparamos una propuesta a tu medida.' },
  { q: '¿Cómo se protege la información de mi empresa?', a: 'Los datos están aislados por cuenta, el acceso se controla con roles y permisos por módulo, y puedes activar la verificación en dos pasos (2FA) o iniciar sesión con Google.' },
];

/* Enlaces antiguos de la home (#agency, #red…) apuntaban a Vértice Agency, que ahora tiene su propia página. */
const AGENCY_ANCHORS = ['#agency', '#red', '#para-quien', '#comparar', '#modulos', '#aristas', '#apps'];

/* ------------------------------------------------------------------ */
function Cta() {
  const chips: { label: string; icon: ReactNode; pos: string; delay: string }[] = [
    { label: 'SUNAT', icon: <ReceiptText className="h-3.5 w-3.5" />, pos: 'left-[6%] top-[14%]', delay: '0s' },
    { label: 'Inventario', icon: <Boxes className="h-3.5 w-3.5" />, pos: 'right-[8%] top-[22%]', delay: '-2s' },
    { label: 'Kardex', icon: <ClipboardList className="h-3.5 w-3.5" />, pos: 'bottom-[14%] left-[12%]', delay: '-4s' },
    { label: 'Cobranzas', icon: <Wallet className="h-3.5 w-3.5" />, pos: 'bottom-[20%] right-[6%]', delay: '-1s' },
  ];
  return (
    <section className="relative px-4 py-24 sm:px-6 sm:py-32 lg:px-8">
      <Reveal className="mx-auto max-w-6xl">
        <div className="relative grid overflow-hidden rounded-[2.25rem] border border-slate-200 bg-white shadow-[0_50px_100px_-50px_rgb(7_42_91/.45)] lg:grid-cols-[1.05fr_0.95fr]">
          <div className="relative px-7 py-14 sm:px-12 sm:py-16 lg:py-20">
            <div className="vx-dots absolute inset-0 [mask-image:radial-gradient(ellipse_at_top_left,#000_10%,transparent_60%)]" />
            <div className="relative">
              <ProductBadge product="erp" />
              <h2 className="vx-display mt-6 text-[2.6rem] font-bold leading-[0.98] text-navy-950 sm:text-6xl">
                Deja el Excel.<br /><span className="vx-gradient-text">Factura con Vértice.</span>
              </h2>
              <p className="mt-5 max-w-sm text-slate-500">Agenda una demo sin compromiso y te mostramos Vértice ERP con los datos de tu empresa.</p>
              <div className="mt-9">
                <ErpDemoLink className="group relative inline-flex items-center gap-2 overflow-hidden rounded-full bg-gradient-to-r from-brand-600 to-cyan-500 px-7 py-3.5 text-sm font-semibold text-white shadow-[0_14px_30px_-10px_rgb(21_115_255/.7)] transition hover:shadow-[0_18px_40px_-8px_rgb(21_115_255/.8)]">
                  <span className="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/30 to-transparent transition duration-700 group-hover:translate-x-full" />
                  <span className="relative">Solicitar demo gratis</span>
                  <ArrowUpRight className="relative h-4 w-4 transition group-hover:rotate-45" />
                </ErpDemoLink>
              </div>
              <ul className="mt-8 flex flex-wrap gap-2">
                {['Datos aislados por cuenta', '2FA', 'Google login'].map((t) => (
                  <li key={t} className="vx-mono inline-flex items-center gap-1.5 rounded-full bg-slate-50 px-3 py-1 text-[11px] text-slate-500 ring-1 ring-slate-200">
                    <ShieldCheck className="h-3 w-3 text-brand-600" />{t}
                  </li>
                ))}
              </ul>
              <p className="mt-6 text-xs text-slate-400">¿Ya tienes cuenta? <a href="/app/login" className="font-semibold text-navy-950 hover:text-brand-600">Inicia sesión</a></p>
            </div>
          </div>

          <div className="relative min-h-[22rem] overflow-hidden bg-gradient-to-br from-navy-950 via-navy-900 to-brand-700 lg:min-h-full" aria-hidden="true">
            <div className="absolute inset-0 opacity-[0.18] [background-image:linear-gradient(rgb(255_255_255/.5)_1px,transparent_1px),linear-gradient(90deg,rgb(255_255_255/.5)_1px,transparent_1px)] [background-size:44px_44px] [mask-image:radial-gradient(ellipse_at_center,#000_20%,transparent_70%)]" />
            <div className="absolute left-1/2 top-1/2 h-72 w-72 -translate-x-1/2 -translate-y-1/2 rounded-full bg-cyan-400/30 blur-3xl" />
            <div className="absolute left-1/2 top-1/2 h-[19rem] w-[19rem] -translate-x-1/2 -translate-y-1/2 rounded-full border border-white/15" />
            <div className="vx-spin-rev absolute inset-0 m-auto h-[13rem] w-[13rem] rounded-full border border-dashed border-cyan-300/40" />
            <div className="vx-pulse-ring h-[11rem] w-[11rem] !border-cyan-300/40" />
            <div className="absolute inset-0 flex items-center justify-center">
              <img src={asset('vertice-mark-light.png')} alt="" width={225} height={183} loading="lazy" className="vx-float relative w-32 drop-shadow-[0_0_40px_rgb(21_189_244/.55)] sm:w-36" />
            </div>
            {chips.map((c) => (
              <span key={c.label} className={`vx-float absolute ${c.pos} flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-[11px] font-semibold text-white shadow-lg backdrop-blur-md`} style={{ animationDelay: c.delay }}>
                <span className="text-cyan-300">{c.icon}</span>{c.label}
              </span>
            ))}
          </div>
        </div>
      </Reveal>
    </section>
  );
}

export function LandingPage() {
  useReveal();
  useEffect(() => {
    document.title = LANDING_TITLE;
    if (AGENCY_ANCHORS.includes(window.location.hash)) window.location.replace(AGENCY_PAGE_PATH + window.location.hash);
  }, []);

  return (
    <div id="top" className="vx-root min-h-screen">
      <SiteHeader product="erp" />
      <main>
        <Hero />
        <ErpClients />
        <ErpPains />
        <ProductBand
          id="erp"
          product="erp"
          title={<>Todo tu negocio en <span className="vx-gradient-text">un solo sistema.</span></>}
          text="Clientes, productos, inventario, compras, cobranzas, colaboradores y facturación electrónica SUNAT en una sola plataforma."
        />
        <Erp />
        <ErpWhy />
        <ErpOnboarding />
        <FaqSection
          faqs={LANDING_FAQS}
          aside={(
            <>
              <p className="mt-6 max-w-sm text-sm leading-relaxed text-slate-500">¿No encuentras tu respuesta? Escríbenos y la resolvemos con tu caso.</p>
              <ErpDemoLink className="group mt-6 inline-flex items-center gap-2 rounded-full border border-slate-300 px-5 py-2.5 text-sm font-semibold text-navy-950 transition hover:border-navy-950">
                Hablar con nosotros <ArrowUpRight className="h-4 w-4 transition group-hover:rotate-45" />
              </ErpDemoLink>
            </>
          )}
        />
        <Cta />
      </main>
      <SiteFooter />
      <StickyErpCta />
      <ExitIntent />
    </div>
  );
}
