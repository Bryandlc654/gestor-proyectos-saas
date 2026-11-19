import { useEffect, useRef, useState, type CSSProperties, type MouseEvent, type ReactNode } from 'react';
import {
  ArrowRight, ArrowUpRight, BarChart3, Briefcase, CheckCircle2, Code2, FileText, FolderKanban, GanttChartSquare,
  Building, Building2, Check, Handshake, LifeBuoy, Link2, Megaphone, Palette, Plus, ShieldCheck, Sparkles, UserRound, Users, Wallet, X, Lock, Eye, MessageSquare, Pencil,
  BadgeCheck, Boxes, CalendarClock, ClipboardList, FileDiff, HandCoins, Package, ReceiptText, ShoppingCart, Store, Truck, UserCheck,
} from 'lucide-react';
import { COMPARE_ROWS, COMPETITORS, VERTICE_SUPPORT } from './compare-data';
import { AGENCY_SIGNUP_HREF, ERP_DEMO_EXTERNAL, ERP_DEMO_HREF, ERP_GROUPS } from './products';
import { SOLUTION_ERP, SOLUTION_FEATURES, solutionHref } from './solution-data';
import { SiteFooter } from './SiteFooter';
import './landing.css';

const asset = (file: string) => `${import.meta.env.BASE_URL}${file}`;

/** Activa las animaciones de aparición cuando los elementos entran en pantalla. */
function useReveal() {
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

function trackSpot(e: MouseEvent<HTMLElement>) {
  const r = e.currentTarget.getBoundingClientRect();
  e.currentTarget.style.setProperty('--mx', `${e.clientX - r.left}px`);
  e.currentTarget.style.setProperty('--my', `${e.clientY - r.top}px`);
}

function Reveal({ children, delay = 0, className = '' }: { children: ReactNode; delay?: number; className?: string }) {
  return <div className={`vx-reveal ${className}`} style={{ transitionDelay: `${delay}ms` }}>{children}</div>;
}

function Eyebrow({ children }: { children: ReactNode }) {
  return (
    <span className="vx-mono inline-flex items-center gap-2 text-[11px] font-medium uppercase tracking-[0.22em] text-brand-600">
      <span className="h-px w-6 bg-gradient-to-r from-transparent to-brand-500" />{children}
    </span>
  );
}

/** Enlace a "Solicitar demo" de Vértice ERP (WhatsApp en pestaña nueva si está configurado). */
function ErpDemoLink({ className, children }: { className: string; children: ReactNode }) {
  return ERP_DEMO_EXTERNAL
    ? <a href={ERP_DEMO_HREF} target="_blank" rel="noopener noreferrer" className={className}>{children}</a>
    : <a href={ERP_DEMO_HREF} className={className}>{children}</a>;
}

/** Etiqueta de producto: "Vértice Agency" o "Vértice ERP". */
function ProductBadge({ product, dark = false }: { product: 'agency' | 'erp'; dark?: boolean }) {
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

/* ------------------------------------------------------------------ */
/* Hero: el "motor" del vértice                                        */
/* ------------------------------------------------------------------ */
function VertexEngine() {
  const stageRef = useRef<HTMLDivElement>(null);

  const onMove = (e: MouseEvent<HTMLDivElement>) => {
    const el = stageRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const x = (e.clientX - r.left) / r.width - 0.5;
    const y = (e.clientY - r.top) / r.height - 0.5;
    el.style.transform = `rotateY(${x * 14}deg) rotateX(${-y * 14}deg)`;
  };
  const onLeave = () => { if (stageRef.current) stageRef.current.style.transform = ''; };

  const satellites: { label: string; icon: ReactNode; pos: CSSProperties; tone: string }[] = [
    { label: 'Proyectos', icon: <FolderKanban className="h-3.5 w-3.5" />, pos: { top: '2%', left: '50%' }, tone: 'text-navy-900' },
    { label: 'Inventario', icon: <Boxes className="h-3.5 w-3.5" />, pos: { top: '74%', left: '93%' }, tone: 'text-cyan-600' },
    { label: 'SUNAT', icon: <ReceiptText className="h-3.5 w-3.5" />, pos: { top: '74%', left: '7%' }, tone: 'text-brand-600' },
  ];

  return (
    <div className="relative mx-auto aspect-square w-full max-w-[520px]" style={{ perspective: '1000px' }} onMouseMove={onMove} onMouseLeave={onLeave}>
      <div ref={stageRef} className="relative h-full w-full transition-transform duration-300 ease-out" style={{ transformStyle: 'preserve-3d' }}>
        <div className="absolute inset-[14%] rounded-full bg-white shadow-[0_40px_80px_-30px_rgb(7_42_91/.35)]" />
        <div className="vx-pulse-ring h-[70%] w-[70%]" />
        <div className="vx-pulse-ring h-[70%] w-[70%]" style={{ animationDelay: '2s' }} />
        <div className="vx-orbit h-[96%] w-[96%]" />
        <div className="vx-orbit vx-orbit--dashed vx-spin-rev h-[78%] w-[78%]" />

        {/* Satélites que orbitan */}
        <div className="vx-spin absolute inset-[2%]">
          {satellites.map((s) => (
            <div key={s.label} className="absolute -translate-x-1/2 -translate-y-1/2" style={s.pos}>
              <div className="vx-counter-spin">
                <span className={`flex items-center gap-1.5 rounded-full border border-slate-200 bg-white/90 px-3 py-1.5 text-[11px] font-semibold shadow-lg shadow-navy-900/10 backdrop-blur ${s.tone}`}>
                  {s.icon}<span className="text-navy-950">{s.label}</span>
                </span>
              </div>
            </div>
          ))}
        </div>

        {/* Núcleo: isotipo */}
        <div className="absolute inset-0 flex items-center justify-center" style={{ transform: 'translateZ(60px)' }}>
          <div className="absolute h-[40%] w-[40%] rounded-full bg-gradient-to-br from-brand-200/70 via-cyan-100/60 to-transparent blur-2xl" />
          <img src={asset('vertice-mark.png')} alt="Isotipo de Vértice" width={225} height={183} fetchPriority="high" className="vx-float vx-mark-glow relative h-auto w-[40%] select-none" draggable={false} />
        </div>

        {/* Tarjetas flotantes */}
        <div className="absolute left-[-4%] top-[18%] hidden rounded-2xl border border-slate-200 bg-white/95 p-3 shadow-xl shadow-navy-900/10 backdrop-blur sm:block" style={{ transform: 'translateZ(90px)' }}>
          <p className="vx-mono text-[10px] uppercase tracking-widest text-slate-400">Avance</p>
          <p className="vx-display text-2xl font-bold text-navy-950">87<span className="text-brand-500">%</span></p>
          <div className="mt-1.5 h-1 w-24 overflow-hidden rounded-full bg-slate-100"><div className="vx-bar h-full w-[87%] rounded-full bg-gradient-to-r from-brand-500 to-cyan-400" /></div>
        </div>
        <div className="absolute bottom-[10%] right-[-2%] hidden items-center gap-2.5 rounded-2xl border border-slate-200 bg-white/95 p-3 shadow-xl shadow-navy-900/10 backdrop-blur sm:flex" style={{ transform: 'translateZ(120px)' }}>
          <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"><CheckCircle2 className="h-4 w-4" /></span>
          <div>
            <p className="text-xs font-semibold text-navy-950">F001-000284 aceptada</p>
            <p className="vx-mono text-[10px] text-slate-500">SUNAT · S/ 4,820.00</p>
          </div>
        </div>
      </div>
    </div>
  );
}

function Hero() {
  return (
    <section className="vx-noise relative isolate overflow-hidden pb-24 pt-32 sm:pt-40">
      <div className="vx-dots absolute inset-0 -z-10 [mask-image:radial-gradient(ellipse_at_top,#000_30%,transparent_75%)]" />
      <div className="vx-aurora -z-10 left-[-10%] top-[-15%] h-[520px] w-[520px] bg-brand-200" />
      <div className="vx-aurora -z-10 right-[-12%] top-[5%] h-[460px] w-[460px] bg-cyan-200" style={{ animationDelay: '-6s' }} />
      <div className="vx-aurora -z-10 bottom-[-25%] left-[30%] h-[420px] w-[620px] bg-brand-100" style={{ animationDelay: '-12s' }} />
      <div className="vx-floor -z-10" />

      <div className="relative mx-auto grid max-w-7xl items-center gap-14 px-4 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:px-8">
        <div>
          <Reveal>
            <a href="#erp" className="group inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white/80 py-1 pl-1 pr-3 text-xs text-slate-600 shadow-sm backdrop-blur hover:border-brand-300">
              <span className="rounded-full bg-gradient-to-r from-brand-600 to-cyan-500 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white">Nuevo</span>
              Vértice ERP con facturación electrónica SUNAT
              <ArrowRight className="h-3 w-3 transition group-hover:translate-x-0.5" />
            </a>
          </Reveal>
          <Reveal delay={100}>
            <h1 className="mt-7">
              <span className="vx-mono block text-[11px] font-medium uppercase tracking-[0.22em] text-brand-600 sm:text-xs">ERP para agencias y empresas</span>
              <span className="vx-display mt-4 block text-[2.7rem] font-bold leading-[1.02] text-navy-950 sm:text-6xl lg:text-[4.6rem]">
                Lleva tu negocio<br />a su <span className="vx-gradient-text">punto más alto.</span>
              </span>
            </h1>
          </Reveal>
          <Reveal delay={200}>
            <p className="mt-6 max-w-xl text-base leading-relaxed text-slate-500 sm:text-lg">
              Un solo vértice donde convergen tus{' '}
              <span className="vx-rotator font-semibold text-navy-950">
                <span className="vx-highlight">proyectos.</span><span className="vx-highlight">ventas.</span><span className="vx-highlight">inventarios.</span><span className="vx-highlight">finanzas.</span>
              </span>
            </p>
            <p className="mt-3 max-w-xl text-sm leading-relaxed text-slate-500 sm:text-base">
              Dos apps, una plataforma: <strong className="font-semibold text-navy-950">Vértice Agency</strong> para agencias que trabajan por proyectos y <strong className="font-semibold text-navy-950">Vértice ERP</strong> para empresas que venden, compran y facturan electrónicamente ante SUNAT.
            </p>
          </Reveal>
          <Reveal delay={300}>
            <div className="mt-9 flex flex-wrap items-center gap-3">
              <a href={AGENCY_SIGNUP_HREF} className="group relative inline-flex items-center gap-2 overflow-hidden rounded-full bg-navy-950 px-7 py-3.5 text-sm font-semibold text-white shadow-[0_14px_30px_-10px_rgb(7_42_91/.6)] transition hover:bg-navy-900 hover:shadow-[0_18px_40px_-10px_rgb(21_115_255/.6)]">
                Probar Vértice Agency
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-cyan-400 text-white transition group-hover:rotate-[-45deg]"><ArrowRight className="h-3.5 w-3.5" /></span>
              </a>
              <ErpDemoLink className="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white/70 px-6 py-3.5 text-sm font-semibold text-navy-950 transition hover:border-navy-950">
                Solicitar demo de ERP
              </ErpDemoLink>
            </div>
            <p className="mt-4 text-xs text-slate-400">¿Ya tienes cuenta? <a href="/app/login" className="font-semibold text-navy-950 hover:text-brand-600">Inicia sesión</a></p>
          </Reveal>
          <Reveal delay={400}>
            <dl className="mt-10 grid max-w-md grid-cols-3 divide-x divide-slate-200 border-y border-slate-200 py-4">
              {[['2', 'apps'], ['SUNAT', 'vía Nubefact'], ['Red', 'de agencias']].map(([v, l]) => (
                <div key={l} className="px-4 first:pl-0">
                  <dt className="vx-display text-2xl font-bold text-navy-950">{v}</dt>
                  <dd className="vx-mono mt-0.5 text-[10px] uppercase tracking-widest text-slate-400">{l}</dd>
                </div>
              ))}
            </dl>
          </Reveal>
        </div>
        <Reveal delay={200}><VertexEngine /></Reveal>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Agencias que ya usan Vértice (solo las que tienen logo)             */
/* ------------------------------------------------------------------ */
type AgencyLogo = { name: string; logo: string };

function AgencyLogos() {
  const [agencies, setAgencies] = useState<AgencyLogo[]>([]);
  useEffect(() => {
    let alive = true;
    fetch('/api/agency-logos.php', { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : { agencies: [] }))
      .then((d: { agencies?: AgencyLogo[] }) => { if (alive) setAgencies(Array.isArray(d.agencies) ? d.agencies : []); })
      .catch(() => {});
    return () => { alive = false; };
  }, []);

  if (agencies.length === 0) return null;

  const Logo = ({ a }: { a: AgencyLogo }) => (
    <div className="group/logo relative shrink-0" title={a.name}>
      <div className="absolute -inset-px rounded-[1.4rem] bg-gradient-to-br from-brand-400 via-cyan-300 to-navy-700 opacity-0 blur-sm transition duration-500 group-hover/logo:opacity-60" />
      <div className="relative flex h-24 w-44 items-center justify-center rounded-[1.4rem] border border-slate-200/80 bg-white p-4 shadow-[0_12px_30px_-18px_rgb(7_42_91/.35)] transition duration-500 group-hover/logo:-translate-y-1.5 sm:h-28 sm:w-52">
        <img src={a.logo} alt={a.name} loading="lazy" className="max-h-full max-w-full object-contain transition duration-500 group-hover/logo:scale-105" />
      </div>
      <p className="vx-mono mt-2 truncate text-center text-[10px] uppercase tracking-wider text-slate-400 opacity-0 transition group-hover/logo:opacity-100">{a.name}</p>
    </div>
  );

  const JoinCard = () => (
    <a href="/app/register" className="group/join flex h-24 w-44 shrink-0 flex-col items-center justify-center gap-1.5 rounded-[1.4rem] border-2 border-dashed border-slate-300 bg-white/50 text-slate-400 transition duration-300 hover:border-brand-400 hover:bg-brand-50/60 hover:text-brand-600 sm:h-28 sm:w-52">
      <span className="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 transition group-hover/join:rotate-90 group-hover/join:bg-brand-500 group-hover/join:text-white"><Plus className="h-4 w-4" /></span>
      <span className="text-xs font-semibold">Tu agencia aquí</span>
    </a>
  );

  // Con pocas agencias se muestran en fila; con más, en cinta infinita.
  const scroll = agencies.length >= 5;
  const count = agencies.length;

  return (
    <section aria-label="Agencias que usan Vértice" className="relative px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
      <div className="vx-fade-up mx-auto max-w-7xl">
        <div className="vx-noise relative overflow-hidden rounded-[2rem] border border-slate-200 bg-gradient-to-br from-white via-white to-brand-50/70 shadow-[0_30px_60px_-35px_rgb(7_42_91/.35)]">
          <div className="vx-dots absolute inset-0 [mask-image:radial-gradient(ellipse_at_right,#000_10%,transparent_65%)]" />
          <div className="absolute -right-24 -top-24 h-72 w-72 rounded-full bg-cyan-200/50 blur-3xl" />
          <div className="absolute -bottom-28 left-1/3 h-64 w-64 rounded-full bg-brand-200/40 blur-3xl" />

          <div className="relative grid items-center gap-10 p-7 sm:p-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] lg:gap-14 lg:p-12">
            <div>
              <span className="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-[11px] font-semibold text-emerald-700">
                <span className="relative flex h-2 w-2"><span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" /><span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" /></span>
                {count} agencia{count === 1 ? '' : 's'} en la red
              </span>
              <h2 className="vx-display mt-5 text-3xl font-bold leading-[1.05] text-navy-950 sm:text-4xl">
                Ya trabajan<br />con <span className="vx-gradient-text">Vértice Agency.</span>
              </h2>
              <div className="mt-6 flex items-center gap-3">
                <div className="flex -space-x-2">
                  {agencies.slice(0, 4).map((a) => (
                    <span key={a.logo} className="flex h-9 w-9 items-center justify-center overflow-hidden rounded-full border-2 border-white bg-white shadow-sm ring-1 ring-slate-200">
                      <img src={a.logo} alt="" loading="lazy" className="h-full w-full object-contain p-1" />
                    </span>
                  ))}
                </div>
                <a href="/app/register" className="group inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:text-navy-950">
                  Súmate <ArrowRight className="h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
                </a>
              </div>
            </div>

            {scroll ? (
              <div className="relative -mx-7 overflow-hidden py-2 sm:-mx-10 lg:mx-0">
                <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-16 bg-gradient-to-r from-white to-transparent" />
                <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-16 bg-gradient-to-l from-brand-50/90 to-transparent" />
                <div className="vx-marquee gap-4 pl-4">{[...agencies, ...agencies].map((a, i) => <Logo key={`${a.logo}-${i}`} a={a} />)}</div>
              </div>
            ) : (
              <div className="flex flex-wrap items-start justify-center gap-4 lg:justify-end">
                {agencies.map((a) => <Logo key={a.logo} a={a} />)}
                <JoinCard />
              </div>
            )}
          </div>
        </div>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
function Marquee() {
  const words = ['Proyectos', 'Gantt', 'CRM', 'Cotizaciones', 'Facturación SUNAT', 'Inventario', 'Kardex', 'Compras', 'Asistencias', 'Rentabilidad'];
  const row = [...words, ...words];
  return (
    <div className="relative -rotate-1 border-y border-slate-200 bg-white py-6 shadow-[0_10px_40px_-20px_rgb(7_42_91/.25)]">
      <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-24 bg-gradient-to-r from-white to-transparent" />
      <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-24 bg-gradient-to-l from-white to-transparent" />
      <div className="overflow-hidden">
        <div className="vx-marquee">
          {row.map((w, i) => (
            <span key={i} className="vx-display flex items-center gap-8 px-4 text-4xl font-bold sm:text-6xl">
              <span className={i % 2 ? 'vx-outline-text' : 'text-navy-950'}>{w}</span>
              <img src={asset('vertice-mark.png')} alt="" width={225} height={183} loading="lazy" className="h-6 w-auto sm:h-8" />
            </span>
          ))}
        </div>
      </div>
    </div>
  );
}


/* ------------------------------------------------------------------ */
/* Las dos apps de Vértice                                              */
/* ------------------------------------------------------------------ */
function Apps() {
  return (
    <section id="apps" className="relative py-24 sm:py-32">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <Reveal className="mx-auto max-w-2xl text-center">
          <Eyebrow>Dos apps, un vértice</Eyebrow>
          <h2 className="vx-display mt-5 text-4xl font-bold leading-[1.05] text-navy-950 sm:text-5xl">
            Elige el Vértice <span className="vx-highlight">hecho para ti.</span>
          </h2>
          <p className="mt-5 text-base leading-relaxed text-slate-500 sm:text-lg">
            Cada app está diseñada para una forma de trabajar distinta. La misma plataforma, la misma seguridad, el mismo equipo detrás.
          </p>
        </Reveal>

        <div className="mt-16 grid gap-5 lg:grid-cols-2">
          {/* Agency */}
          <Reveal className="h-full">
            <article onMouseMove={trackSpot} className="vx-spot group relative flex h-full flex-col overflow-hidden rounded-[2rem] border border-slate-200 bg-white p-7 shadow-[0_30px_60px_-35px_rgb(7_42_91/.35)] transition duration-500 hover:-translate-y-1 hover:border-brand-300 sm:p-10">
              <div className="absolute -right-20 -top-20 h-64 w-64 rounded-full bg-gradient-to-br from-brand-200 to-cyan-200 opacity-50 blur-3xl transition duration-700 group-hover:scale-125" />
              <div className="relative flex flex-1 flex-col">
                <ProductBadge product="agency" />
                <h3 className="vx-display mt-6 text-3xl font-bold leading-tight text-navy-950 sm:text-4xl">El ERP para <span className="vx-gradient-text">agencias.</span></h3>
                <p className="mt-4 max-w-md text-sm leading-relaxed text-slate-500 sm:text-base">
                  Para agencias, estudios, consultoras y software factories que venden su tiempo por proyectos: del primer contacto a la factura cobrada.
                </p>
                <ul className="mt-7 grid gap-2.5 sm:grid-cols-2">
                  {['Proyectos con Kanban y Gantt', 'CRM y cotizaciones en PDF', 'Facturación, caja y rentabilidad', 'Red de agencias y portal de clientes'].map((t) => (
                    <li key={t} className="flex items-start gap-2.5 text-sm text-slate-700">
                      <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 ring-1 ring-brand-100"><Check className="h-3 w-3" /></span>{t}
                    </li>
                  ))}
                </ul>
                <div className="mt-auto flex flex-wrap items-center gap-3 pt-9">
                  <a href={AGENCY_SIGNUP_HREF} className="group/btn inline-flex items-center gap-2 rounded-full bg-navy-950 px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-600">
                    Empezar gratis <ArrowRight className="h-4 w-4 transition group-hover/btn:translate-x-0.5" />
                  </a>
                  <a href="#agency" className="inline-flex items-center gap-1.5 rounded-full px-4 py-3 text-sm font-semibold text-navy-950 hover:text-brand-600">Ver módulos <ArrowUpRight className="h-3.5 w-3.5" /></a>
                </div>
              </div>
            </article>
          </Reveal>

          {/* ERP */}
          <Reveal delay={120} className="h-full">
            <article className="group relative flex h-full flex-col overflow-hidden rounded-[2rem] border border-navy-900 bg-gradient-to-br from-navy-950 via-navy-900 to-brand-700 p-7 text-white shadow-[0_40px_80px_-40px_rgb(7_42_91/.7)] transition duration-500 hover:-translate-y-1 sm:p-10">
              <div className="absolute inset-0 opacity-[0.12] [background-image:linear-gradient(rgb(255_255_255/.5)_1px,transparent_1px),linear-gradient(90deg,rgb(255_255_255/.5)_1px,transparent_1px)] [background-size:40px_40px] [mask-image:radial-gradient(ellipse_at_top_right,#000_10%,transparent_65%)]" />
              <div className="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-cyan-400/30 blur-3xl transition duration-700 group-hover:scale-125" />
              <div className="relative flex flex-1 flex-col">
                <ProductBadge product="erp" dark />
                <h3 className="vx-display mt-6 text-3xl font-bold leading-tight sm:text-4xl">El ERP para <span className="bg-gradient-to-r from-cyan-300 to-brand-300 bg-clip-text text-transparent">tu empresa.</span></h3>
                <p className="mt-4 max-w-md text-sm leading-relaxed text-white/70 sm:text-base">
                  Para empresas que venden productos o servicios y necesitan controlar inventario, compras, cobranzas y su facturación electrónica.
                </p>
                <ul className="mt-7 grid gap-2.5 sm:grid-cols-2">
                  {['Facturación electrónica SUNAT', 'Inventario, kardex y compras', 'Cuentas por cobrar y por pagar', 'Colaboradores y asistencias'].map((t) => (
                    <li key={t} className="flex items-start gap-2.5 text-sm text-white/85">
                      <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-cyan-400/15 text-cyan-300 ring-1 ring-cyan-300/30"><Check className="h-3 w-3" /></span>{t}
                    </li>
                  ))}
                </ul>
                <div className="mt-auto flex flex-wrap items-center gap-3 pt-9">
                  <ErpDemoLink className="group/btn inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 text-sm font-semibold text-navy-950 transition hover:bg-cyan-300">
                    Solicitar demo <ArrowRight className="h-4 w-4 transition group-hover/btn:translate-x-0.5" />
                  </ErpDemoLink>
                  <a href="#erp" className="inline-flex items-center gap-1.5 rounded-full px-4 py-3 text-sm font-semibold text-white/90 hover:text-cyan-300">Ver módulos <ArrowUpRight className="h-3.5 w-3.5" /></a>
                </div>
              </div>
            </article>
          </Reveal>
        </div>
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
          <Reveal delay={150} className="lg:pl-10"><InvoiceMock /></Reveal>
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

        {/* Demo */}
        <Reveal className="mt-16">
          <div id="erp-demo" className="relative scroll-mt-24 overflow-hidden rounded-[2rem] bg-gradient-to-br from-navy-950 via-navy-900 to-brand-700 px-7 py-10 text-white sm:px-12 sm:py-12">
            <div className="absolute -right-10 -top-16 h-64 w-64 rounded-full bg-cyan-400/25 blur-3xl" />
            <div className="relative flex flex-col items-start justify-between gap-6 md:flex-row md:items-center">
              <div>
                <ProductBadge product="erp" dark />
                <p className="vx-display mt-4 max-w-xl text-2xl font-bold leading-tight sm:text-3xl">Te mostramos Vértice ERP con los datos de tu empresa.</p>
                <p className="mt-2 max-w-lg text-sm text-white/70">Agenda una demo: revisamos tus procesos, configuramos tu facturación electrónica y migramos tus productos.</p>
              </div>
              <ErpDemoLink className="group inline-flex shrink-0 items-center gap-2 rounded-full bg-white px-7 py-3.5 text-sm font-semibold text-navy-950 transition hover:bg-cyan-300">
                Solicitar demo <ArrowUpRight className="h-4 w-4 transition group-hover:rotate-45" />
              </ErpDemoLink>
            </div>
          </div>
        </Reveal>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Red: conexión entre agencias y clientes con acceso limitado          */
/* ------------------------------------------------------------------ */
type AccessMode = 'view' | 'collaborate' | 'client';

const ACCESS_COLS: { id: AccessMode; label: string; sub: string; icon: ReactNode; tone: string }[] = [
  { id: 'view', label: 'Aliada', sub: 'Ver', icon: <Eye className="h-4 w-4" />, tone: 'bg-navy-900' },
  { id: 'collaborate', label: 'Aliada', sub: 'Colaborar', icon: <Users className="h-4 w-4" />, tone: 'bg-gradient-to-br from-brand-600 to-cyan-500' },
  { id: 'client', label: 'Cliente', sub: 'Limitado', icon: <UserRound className="h-4 w-4" />, tone: 'bg-cyan-500' },
];

const ACCESS_ROWS: { label: string; icon: ReactNode; values: Record<AccessMode, boolean> }[] = [
  { label: 'Avance y tareas', icon: <FolderKanban className="h-4 w-4" />, values: { view: true, collaborate: true, client: true } },
  { label: 'Comentarios y archivos', icon: <MessageSquare className="h-4 w-4" />, values: { view: true, collaborate: true, client: false } },
  { label: 'Crear y editar tareas', icon: <Pencil className="h-4 w-4" />, values: { view: false, collaborate: true, client: false } },
  { label: 'Tickets', icon: <LifeBuoy className="h-4 w-4" />, values: { view: false, collaborate: true, client: true } },
  { label: 'Finanzas y CRM', icon: <Lock className="h-4 w-4" />, values: { view: false, collaborate: false, client: false } },
];

function AgencyNode({ title, subtitle, tone, children }: { title: string; subtitle: string; tone: string; children?: ReactNode }) {
  return (
    <div className="relative z-10 w-[9.6rem] rounded-2xl border border-slate-200 bg-white p-3 sm:w-[11.5rem] sm:p-4 shadow-xl shadow-navy-900/10">
      <div className="flex items-center gap-2.5">
        <span className={`flex h-9 w-9 items-center justify-center rounded-xl text-white ${tone}`}><Building2 className="h-4 w-4" /></span>
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-navy-950">{title}</p>
          <p className="vx-mono text-[10px] uppercase tracking-wider text-slate-400">{subtitle}</p>
        </div>
      </div>
      {children}
    </div>
  );
}

function NetworkDiagram() {
  return (
    <div className="relative mx-auto h-[25rem] w-full max-w-[34rem]">
      <svg className="absolute inset-0 h-full w-full" viewBox="0 0 540 400" fill="none" aria-hidden="true" preserveAspectRatio="none">
        <defs>
          <linearGradient id="vx-link" x1="0" x2="1">
            <stop offset="0" stopColor="#072A5B" />
            <stop offset=".5" stopColor="#1573FF" />
            <stop offset="1" stopColor="#15BDF4" />
          </linearGradient>
        </defs>
        <path id="vx-path-agency" d="M110 90 C 220 20, 320 20, 430 90" stroke="url(#vx-link)" strokeWidth="2" className="vx-dash" />
        <path id="vx-path-client" d="M110 110 C 130 230, 200 300, 270 320" stroke="#CBD5E1" strokeWidth="2" strokeDasharray="4 6" />
        <circle r="5" fill="#15BDF4">
          <animateMotion dur="3.2s" repeatCount="indefinite" rotate="auto"><mpath href="#vx-path-agency" /></animateMotion>
        </circle>
        <circle r="4" fill="#1573FF">
          <animateMotion dur="3.2s" begin="1.6s" repeatCount="indefinite" keyPoints="1;0" keyTimes="0;1" calcMode="linear"><mpath href="#vx-path-agency" /></animateMotion>
        </circle>
        <circle r="3.5" fill="#94A3B8">
          <animateMotion dur="4s" repeatCount="indefinite"><mpath href="#vx-path-client" /></animateMotion>
        </circle>
      </svg>

      {/* Código de conexión */}
      <div className="absolute left-1/2 top-0 z-20 -translate-x-1/2">
        <div className="vx-float flex items-center gap-2 rounded-full border border-brand-200 bg-white px-3 py-1.5 shadow-lg shadow-brand-500/20">
          <Link2 className="h-3.5 w-3.5 text-brand-600" />
          <span className="vx-mono text-xs font-medium tracking-[0.18em] text-navy-950">K7Q2-XM49</span>
        </div>
      </div>

      <div className="absolute left-0 top-[14%]">
        <AgencyNode title="Tu agencia" subtitle="Dueña del proyecto" tone="bg-navy-950">
          <div className="mt-3 space-y-1.5">
            <div className="flex items-center justify-between rounded-lg bg-slate-50 px-2 py-1.5 text-[11px] text-slate-600"><span>Rediseño web</span><span className="vx-mono text-brand-600">68%</span></div>
            <div className="flex items-center gap-1.5 rounded-lg bg-slate-50 px-2 py-1.5 text-[11px] text-slate-500"><Lock className="h-3 w-3" /> Finanzas privadas</div>
          </div>
        </AgencyNode>
      </div>

      <div className="absolute right-0 top-[14%]">
        <AgencyNode title="Estudio aliado" subtitle="Agencia conectada" tone="bg-gradient-to-br from-brand-600 to-cyan-500">
          <div className="mt-3 flex items-center justify-between rounded-lg bg-brand-50 px-2 py-1.5 text-[11px] text-brand-700">
            <span>Compartido por Tu agencia</span>
          </div>
          <div className="mt-2 flex -space-x-1.5">
            {['bg-brand-500', 'bg-cyan-500', 'bg-navy-700'].map((c) => <span key={c} className={`h-5 w-5 rounded-full border-2 border-white ${c}`} />)}
            <span className="pl-3 text-[10px] text-slate-400">Elige quién participa</span>
          </div>
        </AgencyNode>
      </div>

      <div className="absolute bottom-0 left-[42%] -translate-x-1/2">
        <div className="relative z-10 w-[12.5rem] rounded-2xl border border-slate-200 bg-white p-4 shadow-xl shadow-navy-900/10">
          <div className="flex items-center gap-2.5">
            <span className="flex h-9 w-9 items-center justify-center rounded-full bg-cyan-50 text-cyan-700 ring-1 ring-cyan-200"><UserRound className="h-4 w-4" /></span>
            <div>
              <p className="text-sm font-semibold text-navy-950">Tu cliente</p>
              <p className="vx-mono text-[10px] uppercase tracking-wider text-slate-400">Acceso limitado</p>
            </div>
          </div>
          <div className="mt-3 flex flex-wrap gap-1">
            {['Dashboard', 'Sus tareas', 'Calendario'].map((t) => <span key={t} className="rounded-md bg-slate-50 px-1.5 py-0.5 text-[10px] text-slate-600 ring-1 ring-slate-200">{t}</span>)}
          </div>
        </div>
      </div>
    </div>
  );
}

function Network() {
  const [hover, setHover] = useState<AccessMode | null>(null);

  return (
    <section id="red" className="relative isolate overflow-hidden py-28 sm:py-36">
      <div className="vx-aurora -z-10 left-[55%] top-[10%] h-[420px] w-[420px] bg-brand-100" />
      <div className="vx-aurora -z-10 left-[-10%] bottom-[0%] h-[360px] w-[360px] bg-cyan-100" style={{ animationDelay: '-8s' }} />
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="grid items-center gap-14 lg:grid-cols-2">
          <Reveal>
            <span className="inline-flex items-center gap-2 rounded-full bg-navy-950 px-3 py-1 text-[11px] font-semibold text-white">
              <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-cyan-400" /> Lo que nos diferencia
            </span>
            <h2 className="vx-display mt-6 text-4xl font-bold leading-[1.03] text-navy-950 sm:text-5xl lg:text-[3.6rem]">
              Trabaja en red con otras agencias.<br /><span className="vx-gradient-text">Comparte sin exponer.</span>
            </h2>
            <p className="mt-6 max-w-md text-base leading-relaxed text-slate-500 sm:text-lg">
              Conecta agencias por código e invita a tus clientes a un <a href="/soluciones/portal-de-clientes" className="font-semibold text-navy-950 underline decoration-brand-300 underline-offset-4 hover:text-brand-600">portal de clientes</a> con acceso limitado. Cada quien ve solo lo suyo.
            </p>
            <ul className="mt-8 flex flex-wrap gap-2.5">
              {[
                { icon: <Link2 className="h-4 w-4" />, t: 'Conexión por código' },
                { icon: <Users className="h-4 w-4" />, t: 'Ver o Colaborar' },
                { icon: <ShieldCheck className="h-4 w-4" />, t: 'Clientes con acceso limitado' },
              ].map((f) => (
                <li key={f.t} className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1.5 pl-1.5 pr-4 text-sm font-semibold text-navy-950 shadow-sm">
                  <span className="flex h-7 w-7 items-center justify-center rounded-full bg-brand-50 text-brand-600 ring-1 ring-brand-100">{f.icon}</span>
                  <h3>{f.t}</h3>
                </li>
              ))}
            </ul>
          </Reveal>
          <Reveal delay={150}><NetworkDiagram /></Reveal>
        </div>

        {/* Matriz de permisos interactiva */}
        <Reveal delay={100} className="mt-20">
          <div className="overflow-hidden rounded-[2rem] border border-slate-200 bg-white p-3 shadow-[0_30px_60px_-30px_rgb(7_42_91/.3)] sm:p-5" onMouseLeave={() => setHover(null)}>
            <div className="grid grid-cols-[1fr_repeat(3,3.25rem)] items-end gap-1.5 px-2 pb-3 sm:grid-cols-[1fr_repeat(3,8.5rem)] sm:gap-3 sm:px-4">
              <p className="vx-mono text-[11px] uppercase tracking-[0.2em] text-brand-600">Quién ve qué</p>
              {ACCESS_COLS.map((c) => (
                <div key={c.id} onMouseEnter={() => setHover(c.id)} className={`flex flex-col items-center gap-1.5 rounded-2xl py-2 transition ${hover === c.id ? 'bg-slate-50' : ''}`}>
                  <span className={`flex h-10 w-10 items-center justify-center rounded-2xl text-white shadow-lg shadow-navy-900/15 ${c.tone}`}>{c.icon}</span>
                  <span className="text-center leading-tight">
                    <span className="block text-[11px] font-semibold text-navy-950 sm:text-xs">{c.label}</span>
                    <span className="vx-mono block text-[9px] uppercase tracking-wider text-slate-400 sm:text-[10px]">{c.sub}</span>
                  </span>
                </div>
              ))}
            </div>
            <ul className="space-y-1.5">
              {ACCESS_ROWS.map((row) => (
                <li key={row.label} className="grid grid-cols-[1fr_repeat(3,3.25rem)] items-center gap-1.5 rounded-2xl bg-slate-50/70 px-2 py-2.5 sm:grid-cols-[1fr_repeat(3,8.5rem)] sm:gap-3 sm:px-4">
                  <span className="flex min-w-0 items-center gap-2.5 text-sm font-medium text-navy-950">
                    <span className="hidden h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-white text-brand-600 ring-1 ring-slate-200 sm:flex">{row.icon}</span>
                    <span className="truncate">{row.label}</span>
                  </span>
                  {ACCESS_COLS.map((c) => {
                    const ok = row.values[c.id];
                    return (
                      <span key={c.id} onMouseEnter={() => setHover(c.id)} className="flex justify-center" aria-label={`${c.label} ${c.sub}: ${ok ? 'sí' : 'no'}`}>
                        <span className={`flex h-8 w-8 items-center justify-center rounded-full transition duration-300 ${ok ? 'bg-emerald-500 text-white shadow-md shadow-emerald-500/30' : 'bg-white text-slate-300 ring-1 ring-slate-200'} ${hover === c.id ? 'scale-110' : ''}`}>
                          {ok ? <Check className="h-4 w-4" /> : <X className="h-3.5 w-3.5" />}
                        </span>
                      </span>
                    );
                  })}
                </li>
              ))}
            </ul>
          </div>
        </Reveal>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Las tres aristas del isotipo = los tres pilares del producto         */
/* ------------------------------------------------------------------ */
function Edges() {
  const edges = [
    {
      n: '01', title: 'Operar', blob: 'from-navy-200 to-navy-400', accent: 'bg-navy-900', hover: 'hover:border-navy-300',
      items: ['Kanban y Gantt', 'Subtareas y dependencias', 'Plantillas de proyecto'],
    },
    {
      n: '02', title: 'Vender', blob: 'from-brand-200 to-brand-400', accent: 'bg-brand-500', hover: 'hover:border-brand-300',
      items: ['Pipeline comercial', 'Cotizaciones en PDF', 'De cotización a proyecto'],
    },
    {
      n: '03', title: 'Cobrar', blob: 'from-cyan-100 to-cyan-300', accent: 'bg-cyan-400', hover: 'hover:border-cyan-300',
      items: ['Facturas y abonos', 'Caja y egresos', 'Rentabilidad por proyecto'],
    },
  ];
  return (
    <section id="aristas" className="relative py-28 sm:py-36">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="max-w-3xl">
          <Reveal>
            <Eyebrow>Tres aristas, un vértice</Eyebrow>
            <h2 className="vx-display mt-5 text-4xl font-bold leading-[1.05] text-navy-950 sm:text-5xl lg:text-6xl">
              Proyectos, ventas y cobros de tu agencia <span className="text-slate-300">convergen aquí.</span>
            </h2>
            <p className="mt-6 max-w-2xl text-base leading-relaxed text-slate-500 sm:text-lg">
              Vértice Agency une la gestión de proyectos, el CRM y la facturación de tu agencia: del primer contacto con el cliente a la factura cobrada y la rentabilidad de cada proyecto.
            </p>
          </Reveal>
        </div>

        <div className="mt-16 grid gap-5 md:grid-cols-3">
          {edges.map((e, i) => (
            <div key={e.n} className={i === 1 ? 'md:translate-y-10' : i === 2 ? 'md:translate-y-20' : ''}>
              <Reveal delay={i * 140} className="h-full">
                <article className={`group vx-edge relative h-full overflow-hidden border border-slate-200 bg-white p-7 shadow-sm transition duration-500 hover:-translate-y-1 hover:shadow-xl ${e.hover}`}>
                  <div className={`absolute -right-16 -top-16 h-48 w-48 rounded-full bg-gradient-to-br ${e.blob} opacity-50 blur-2xl transition duration-700 group-hover:scale-150 group-hover:opacity-80`} />
                  <span className={`absolute left-0 top-0 h-1 w-16 ${e.accent} transition-all duration-500 group-hover:w-full`} />
                  <div className="relative">
                    <div className="flex items-center justify-between">
                      <span className="vx-mono text-xs text-slate-400">{e.n} / 03</span>
                      <span className={`h-2.5 w-2.5 rounded-full ${e.accent}`} />
                    </div>
                    <h3 className="vx-display mt-10 text-5xl font-bold text-navy-950">{e.title}</h3>
                    <ul className="mt-7 space-y-2.5 border-t border-slate-100 pt-5">
                      {e.items.map((it) => (
                        <li key={it} className="flex items-center gap-2.5 text-sm text-slate-700">
                          <span className={`h-1 w-3 rounded-full ${e.accent}`} />{it}
                        </li>
                      ))}
                    </ul>
                  </div>
                </article>
              </Reveal>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Bento con mini-interfaces                                           */
/* ------------------------------------------------------------------ */
function BentoCard({ className = '', children, delay = 0 }: { className?: string; children: ReactNode; delay?: number }) {
  return (
    <Reveal delay={delay} className={className}>
      <div onMouseMove={trackSpot} className="vx-spot h-full overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-lg">
        {children}
      </div>
    </Reveal>
  );
}

function CardHead({ icon, title, text }: { icon: ReactNode; title: string; text: string }) {
  return (
    <div>
      <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-100">{icon}</span>
      <h3 className="vx-display mt-4 text-xl font-bold text-navy-950">{title}</h3>
      <p className="mt-1.5 text-sm leading-relaxed text-slate-500">{text}</p>
    </div>
  );
}

function Bento() {
  const gantt = [
    { l: 'Brief', s: 0, w: 28, c: 'from-navy-700 to-navy-500' },
    { l: 'Diseño', s: 18, w: 40, c: 'from-brand-600 to-brand-400' },
    { l: 'Desarrollo', s: 42, w: 38, c: 'from-brand-400 to-cyan-400' },
    { l: 'Entrega', s: 76, w: 22, c: 'from-cyan-400 to-cyan-300' },
  ];
  const kanban = [
    { t: 'Por hacer', c: ['Moodboard', 'Copy landing'] },
    { t: 'En curso', c: ['Branding'] },
    { t: 'Listo', c: ['Kickoff', 'Contrato'] },
  ];
  const bars = [38, 56, 44, 70, 62, 84, 92];

  return (
    <section id="modulos" className="relative isolate py-24 sm:py-32">
      <div className="vx-dots absolute inset-0 -z-10 [mask-image:linear-gradient(to_bottom,transparent,#000_20%,#000_80%,transparent)]" />
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <Reveal className="mx-auto max-w-2xl text-center">
          <Eyebrow>Módulos de Vértice Agency</Eyebrow>
          <h2 className="vx-display mt-5 text-4xl font-bold leading-[1.05] text-navy-950 sm:text-5xl">Herramientas de gestión que piensan<br />como una <span className="vx-highlight">agencia.</span></h2>
        </Reveal>

        <div className="mt-16 grid auto-rows-[minmax(260px,auto)] gap-4 md:grid-cols-6">
          <BentoCard className="md:col-span-4">
            <CardHead icon={<GanttChartSquare className="h-4 w-4" />} title="Planificación con Gantt" text="Arrastra fechas, encadena dependencias y mira el camino crítico." />
            <div className="mt-6 space-y-2.5">
              {gantt.map((g, i) => (
                <div key={g.l} className="grid grid-cols-[84px_1fr] items-center gap-3">
                  <span className="vx-mono truncate text-[11px] text-slate-400">{g.l}</span>
                  <div className="relative h-6 rounded-md bg-slate-100">
                    <div className={`vx-bar absolute inset-y-0 rounded-md bg-gradient-to-r ${g.c}`} style={{ left: `${g.s}%`, width: `${g.w}%`, animationDelay: `${i * 0.25}s` }} />
                  </div>
                </div>
              ))}
            </div>
          </BentoCard>

          <BentoCard className="md:col-span-2" delay={100}>
            <CardHead icon={<BarChart3 className="h-4 w-4" />} title="Rentabilidad" text="Ingresos vs. costos por proyecto." />
            <div className="mt-6 flex h-28 items-end gap-2">
              {bars.map((h, i) => (
                <div key={i} className="vx-col flex-1 rounded-t-md bg-gradient-to-t from-brand-600 to-cyan-400" style={{ height: `${h}%`, animationDelay: `${i * 0.1}s`, opacity: 0.35 + i * 0.1 }} />
              ))}
            </div>
            <p className="vx-mono mt-3 text-[11px] text-emerald-600">▲ 24% margen este mes</p>
          </BentoCard>

          <BentoCard className="md:col-span-3" delay={100}>
            <CardHead icon={<FolderKanban className="h-4 w-4" />} title="Kanban con estados propios" text="Define los estados de tu flujo y arrastra tareas entre columnas." />
            <div className="mt-6 grid grid-cols-3 gap-2">
              {kanban.map((col) => (
                <div key={col.t} className="rounded-xl bg-slate-50 p-2 ring-1 ring-slate-100">
                  <p className="vx-mono mb-2 text-[10px] uppercase tracking-wider text-slate-400">{col.t}</p>
                  <div className="space-y-1.5">
                    {col.c.map((c) => <div key={c} className="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-[11px] text-slate-700 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-300">{c}</div>)}
                  </div>
                </div>
              ))}
            </div>
          </BentoCard>

          <BentoCard className="md:col-span-3" delay={200}>
            <CardHead icon={<Code2 className="h-4 w-4" />} title="API y webhooks" text="Tokens por agencia, eventos firmados y conexión con tu stack." />
            <pre className="vx-mono mt-5 overflow-hidden rounded-xl bg-navy-950 p-4 text-[11px] leading-relaxed text-white/75">
<span className="text-cyan-300">POST</span> /api/v1/tasks{'\n'}
<span className="text-white/35">Authorization: Bearer vtx_••••••</span>{'\n'}
{'{'} <span className="text-brand-300">"title"</span>: <span className="text-emerald-300">"Lanzamiento"</span>, <span className="text-brand-300">"status"</span>: <span className="text-emerald-300">"en_curso"</span> {'}'}{'\n'}
<span className="vx-caret text-emerald-300">201 Created</span>
            </pre>
          </BentoCard>

          {[
            { icon: <FileText className="h-4 w-4" />, t: 'Cotizaciones', d: 'PDF con tu marca y seguimiento automático.' },
            { icon: <Users className="h-4 w-4" />, t: 'Roles y permisos', d: 'Acceso por módulo, configurable por rol.' },
            { icon: <LifeBuoy className="h-4 w-4" />, t: 'Tickets', d: 'Soporte con respuestas e imágenes.' },
          ].map((c, i) => (
            <BentoCard key={c.t} className="md:col-span-2" delay={i * 100}>
              <CardHead icon={c.icon} title={c.t} text={c.d} />
            </BentoCard>
          ))}
        </div>

        <Reveal className="mt-10">
          <ul className="flex flex-wrap justify-center gap-2.5">
            {SOLUTION_FEATURES.map((s) => (
              <li key={s.slug}>
                <a href={solutionHref(s.slug)} className="group inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-navy-950 shadow-sm transition hover:border-brand-300 hover:text-brand-600">
                  {s.name} <ArrowUpRight className="h-3.5 w-3.5 transition group-hover:rotate-45" />
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
function Steps() {
  const steps = [
    { icon: <Briefcase className="h-5 w-5" />, t: 'Crea tu agencia', d: 'Tu marca, estados y roles en minutos.' },
    { icon: <Link2 className="h-5 w-5" />, t: 'Invita y conecta', d: 'Tu equipo, aliados y clientes.' },
    { icon: <Sparkles className="h-5 w-5" />, t: 'Opera en un solo lugar', d: 'Del primer contacto a la factura cobrada.' },
  ];
  return (
    <section className="relative border-y border-slate-200 bg-white py-24 sm:py-32">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <Reveal><Eyebrow>Empieza con Vértice Agency</Eyebrow></Reveal>
        <div className="relative mt-12 grid gap-10 md:grid-cols-3">
          <div className="absolute left-0 right-0 top-6 hidden h-px bg-gradient-to-r from-navy-900 via-brand-500 to-cyan-300 md:block" />
          {steps.map((s, i) => (
            <Reveal key={s.t} delay={i * 150}>
              <div className="relative">
                <span className="relative z-10 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-600 to-cyan-500 text-white shadow-lg shadow-brand-500/30">{s.icon}</span>
                <p className="vx-display vx-outline-text mt-8 text-7xl font-bold leading-none">0{i + 1}</p>
                <h3 className="vx-display mt-3 text-2xl font-bold text-navy-950">{s.t}</h3>
                <p className="mt-2 max-w-xs text-sm leading-relaxed text-slate-500">{s.d}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  );
}


/* ------------------------------------------------------------------ */
function Audience() {
  const profiles = [
    { icon: <Megaphone className="h-5 w-5" />, t: 'Agencias de marketing', href: solutionHref('agencias-de-marketing') },
    { icon: <Palette className="h-5 w-5" />, t: 'Estudios de diseño y creativos', href: solutionHref('estudios-de-diseno') },
    { icon: <Code2 className="h-5 w-5" />, t: 'Empresas de software', href: solutionHref('empresas-de-software') },
    { icon: <Building className="h-5 w-5" />, t: 'Consultoras y freelancers', href: solutionHref('consultoras') },
  ];
  return (
    <section id="para-quien" className="relative py-24 sm:py-32">
      <div className="mx-auto grid max-w-7xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-[0.9fr_1.1fr] lg:px-8">
        <Reveal>
          <Eyebrow>Para quién</Eyebrow>
          <h2 className="vx-display mt-5 text-4xl font-bold leading-[1.05] text-navy-950 sm:text-5xl">Software para agencias y equipos que <span className="vx-highlight">venden su tiempo.</span></h2>
          <p className="mt-6 max-w-md text-base leading-relaxed text-slate-500">Agencias de marketing digital, estudios de diseño, software factories, consultoras y freelancers que trabajan por proyectos para clientes.</p>
        </Reveal>
        <div className="grid gap-4 sm:grid-cols-2">
          {profiles.map((p, i) => (
            <Reveal key={p.t} delay={i * 100}>
              <a href={p.href} onMouseMove={trackSpot} className="vx-spot group flex h-full items-center gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:border-brand-300 hover:shadow-lg">
                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-navy-950 text-cyan-300 transition group-hover:bg-gradient-to-br group-hover:from-brand-600 group-hover:to-cyan-500 group-hover:text-white">{p.icon}</span>
                <h3 className="vx-display flex-1 text-lg font-bold text-navy-950">{p.t}</h3>
                <ArrowRight className="h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600" />
              </a>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Comparativas: enlaces internos a /comparar/<slug>                    */
/* ------------------------------------------------------------------ */
function Compare() {
  return (
    <section id="comparar" className="relative py-24 sm:py-32">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <Reveal className="mx-auto max-w-2xl text-center">
          <Eyebrow>Comparar</Eyebrow>
          <h2 className="vx-display mt-5 text-4xl font-bold leading-[1.05] text-navy-950 sm:text-5xl">¿Buscas una alternativa a <span className="vx-highlight">ClickUp, Trello o Asana?</span></h2>
        </Reveal>
        <div className="mt-14 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          {COMPETITORS.map((c, i) => {
            const wins = COMPARE_ROWS.filter((r) => VERTICE_SUPPORT[r.key] === 'yes' && c.support[r.key] !== 'yes').length;
            return (
              <Reveal key={c.slug} delay={i * 80} className="h-full">
                <a href={`/comparar/${c.slug}`} onMouseMove={trackSpot} className="vx-spot group flex h-full flex-col justify-between rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:border-brand-300 hover:shadow-xl">
                  <div>
                    <p className="vx-mono text-[10px] uppercase tracking-[0.2em] text-slate-400">Vértice Agency vs</p>
                    <h3 className="vx-display mt-2 text-2xl font-bold text-navy-950">{c.name}</h3>
                  </div>
                  <div className="mt-8 flex items-end justify-between">
                    <span>
                      <span className="vx-display block text-3xl font-bold text-brand-600">+{wins}</span>
                      <span className="vx-mono text-[10px] uppercase tracking-wider text-slate-400">ventajas</span>
                    </span>
                    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-navy-950 transition group-hover:rotate-[-45deg] group-hover:bg-gradient-to-br group-hover:from-brand-600 group-hover:to-cyan-500 group-hover:text-white"><ArrowRight className="h-4 w-4" /></span>
                  </div>
                </a>
              </Reveal>
            );
          })}
        </div>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
export const LANDING_TITLE = 'Vértice | ERP para agencias y empresas con facturación SUNAT';
export const LANDING_DESCRIPTION = 'Vértice Agency gestiona proyectos, CRM y cobros de tu agencia. Vértice ERP lleva inventario, compras y facturación electrónica SUNAT de tu empresa.';

export const LANDING_FAQS: { q: string; a: string }[] = [
  { q: '¿Qué es Vértice?', a: 'Vértice es una plataforma con dos apps: Vértice Agency, el ERP para agencias que reúne proyectos, tareas, CRM, cotizaciones, facturación, caja y rentabilidad; y Vértice ERP, para empresas que necesitan gestionar clientes, productos, inventario, compras, cobranzas y facturación electrónica ante SUNAT.' },
  { q: '¿Qué diferencia hay entre Vértice Agency y Vértice ERP?', a: 'Vértice Agency está pensado para negocios que trabajan por proyectos para clientes (agencias, estudios, consultoras, software factories). Vértice ERP está pensado para empresas que venden productos o servicios y necesitan inventario, kardex, compras, cuentas por cobrar y por pagar, colaboradores, asistencias y facturación electrónica.' },
  { q: '¿Qué módulos incluye Vértice ERP?', a: 'Clientes, colaboradores, asistencias, productos, inventario, kardex, compras, proveedores, cuentas por pagar, cotizaciones, facturación electrónica, notas de crédito y débito, cuentas por cobrar y reportes.' },
  { q: '¿Vértice ERP emite comprobantes electrónicos válidos ante SUNAT?', a: 'Sí. Vértice ERP emite facturas, boletas y notas de crédito y débito electrónicas que se envían a SUNAT a través de Nubefact, y conserva el XML, la constancia de recepción (CDR) y el PDF de cada comprobante.' },
  { q: '¿Cómo empiezo con Vértice ERP?', a: 'Solicita una demo: revisamos los procesos de tu empresa, configuramos tu facturación electrónica y te ayudamos a cargar tus productos y clientes.' },
  { q: '¿Qué debe tener un software de gestión de proyectos para agencias?', a: 'Además de tareas en Kanban y Gantt, una agencia necesita conectar la venta con la entrega y el cobro: CRM, cotizaciones, facturación con pagos parciales y rentabilidad por proyecto, más un acceso limitado para que los clientes sigan su avance. Vértice Agency reúne todo eso en una sola plataforma.' },
  { q: '¿Vértice Agency incluye diagrama de Gantt?', a: 'Sí. Incluye diagrama de Gantt online con dependencias, hitos y camino crítico, y las mismas tareas se pueden ver en Kanban o en lista.' },
  { q: '¿Puedo saber si un proyecto es rentable?', a: 'Sí. Vértice Agency cruza los ingresos facturados de cada proyecto con sus costos y egresos para mostrarte el margen real de cada proyecto.' },
  { q: '¿Cómo me conecto con otras agencias?', a: 'Cada agencia tiene un código de conexión. La otra agencia lo introduce y tú aceptas la solicitud. Luego compartes solo los proyectos que quieras, con acceso de Ver o Colaborar, y la agencia invitada elige qué miembros participan. El presupuesto y las facturas nunca se comparten.' },
  { q: '¿Mis clientes pueden ver sus proyectos?', a: 'Sí. Invitas a tu cliente con un enlace de un solo uso que caduca en 7 días. Con el rol cliente ve su dashboard, sus tareas y el calendario, y puede abrir tickets, pero no ve comentarios internos, checklists ni finanzas.' },
  { q: '¿Puedo crear cotizaciones y facturas?', a: 'Sí. Generas cotizaciones en PDF con tu marca, les haces seguimiento, las conviertes en proyectos y registras facturas con pagos parciales, cuentas por pagar y caja.' },
  { q: '¿Cómo se protege la información de mi negocio?', a: 'Los datos están aislados por cuenta, el acceso se controla con roles y permisos por módulo, y puedes activar la verificación en dos pasos (2FA) o iniciar sesión con Google.' },
  { q: '¿Vértice Agency se integra con otras herramientas?', a: 'Sí. Ofrece una API con tokens por agencia y webhooks para enviar eventos a tus sistemas, además de exportación de datos.' },
  { q: '¿En qué se diferencia Vértice Agency de ClickUp, Trello, Asana, Odoo o monday.com?', a: 'Vértice Agency está pensado para agencias: además de proyectos con Kanban y Gantt, incluye CRM, cotizaciones en PDF, facturación y rentabilidad, y permite compartir proyectos entre agencias conectadas y dar acceso limitado a clientes, sin exponer tus finanzas.' },
  { q: '¿Cuánto cuesta empezar?', a: 'Vértice Agency se crea gratis y se configura en minutos, sin instalar nada. Para Vértice ERP solicita una demo y te preparamos una propuesta según el tamaño de tu empresa.' },
  { q: '¿Vértice está en español?', a: 'Sí. Las dos apps son plataformas web en español: interfaz, cotizaciones, comprobantes y comunicación con tus clientes, sin instalar nada.' },
];

function Faq() {
  return (
    <section id="faq" className="relative border-t border-slate-200 bg-white py-24 sm:py-32">
      <div className="mx-auto grid max-w-7xl gap-12 px-4 sm:px-6 lg:grid-cols-[0.8fr_1.2fr] lg:px-8">
        <Reveal>
          <Eyebrow>Preguntas frecuentes</Eyebrow>
          <h2 className="vx-display mt-5 text-4xl font-bold leading-[1.05] text-navy-950 sm:text-5xl">Lo que suelen <span className="text-slate-300">preguntarnos.</span></h2>
        </Reveal>
        <div className="divide-y divide-slate-200 border-y border-slate-200">
          {LANDING_FAQS.map((f, i) => (
            <Reveal key={f.q} delay={i * 60}>
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

/* ------------------------------------------------------------------ */
function Cta() {
  const chips: { label: string; icon: ReactNode; pos: string; delay: string }[] = [
    { label: 'Proyectos', icon: <FolderKanban className="h-3.5 w-3.5" />, pos: 'left-[6%] top-[14%]', delay: '0s' },
    { label: 'CRM', icon: <Handshake className="h-3.5 w-3.5" />, pos: 'right-[8%] top-[22%]', delay: '-2s' },
    { label: 'Inventario', icon: <Boxes className="h-3.5 w-3.5" />, pos: 'bottom-[14%] left-[12%]', delay: '-4s' },
    { label: 'SUNAT', icon: <ReceiptText className="h-3.5 w-3.5" />, pos: 'bottom-[20%] right-[6%]', delay: '-1s' },
  ];
  return (
    <section className="relative px-4 py-24 sm:px-6 sm:py-32 lg:px-8">
      <Reveal className="mx-auto max-w-6xl">
        <div className="relative grid overflow-hidden rounded-[2.25rem] border border-slate-200 bg-white shadow-[0_50px_100px_-50px_rgb(7_42_91/.45)] lg:grid-cols-[1.05fr_0.95fr]">
          {/* Texto */}
          <div className="relative px-7 py-14 sm:px-12 sm:py-16 lg:py-20">
            <div className="vx-dots absolute inset-0 [mask-image:radial-gradient(ellipse_at_top_left,#000_10%,transparent_60%)]" />
            <div className="relative">
              <Eyebrow>Empieza hoy</Eyebrow>
              <h2 className="vx-display mt-6 text-[2.6rem] font-bold leading-[0.98] text-navy-950 sm:text-6xl">
                Tu negocio,<br /><span className="vx-gradient-text">en su vértice.</span>
              </h2>
              <p className="mt-5 max-w-sm text-slate-500">Elige tu app: Vértice Agency gratis en minutos, o una demo de Vértice ERP para tu empresa.</p>
              <div className="mt-9 flex flex-wrap items-center gap-3">
                <a href={AGENCY_SIGNUP_HREF} className="group relative inline-flex items-center gap-2 overflow-hidden rounded-full bg-gradient-to-r from-brand-600 to-cyan-500 px-7 py-3.5 text-sm font-semibold text-white shadow-[0_14px_30px_-10px_rgb(21_115_255/.7)] transition hover:shadow-[0_18px_40px_-8px_rgb(21_115_255/.8)]">
                  <span className="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/30 to-transparent transition duration-700 group-hover:translate-x-full" />
                  <span className="relative">Crear cuenta en Agency</span>
                  <ArrowUpRight className="relative h-4 w-4 transition group-hover:rotate-45" />
                </a>
                <ErpDemoLink className="inline-flex items-center gap-2 rounded-full border border-slate-300 px-6 py-3.5 text-sm font-semibold text-navy-950 transition hover:border-navy-950">Demo de ERP</ErpDemoLink>
              </div>
              <p className="mt-4 text-xs text-slate-400">¿Ya tienes cuenta? <a href="/app/login" className="font-semibold text-navy-950 hover:text-brand-600">Inicia sesión</a></p>
              <ul className="mt-10 flex flex-wrap gap-2">
                {['Datos aislados por cuenta', '2FA', 'Google login'].map((t) => (
                  <li key={t} className="vx-mono inline-flex items-center gap-1.5 rounded-full bg-slate-50 px-3 py-1 text-[11px] text-slate-500 ring-1 ring-slate-200">
                    <ShieldCheck className="h-3 w-3 text-brand-600" />{t}
                  </li>
                ))}
              </ul>
            </div>
          </div>

          {/* Visual */}
          <div className="relative min-h-[22rem] overflow-hidden bg-gradient-to-br from-navy-950 via-navy-900 to-brand-700 lg:min-h-full">
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

function Nav() {
  return (
    <header className="fixed inset-x-0 top-4 z-50 px-4">
      <div className="mx-auto flex h-14 max-w-5xl items-center justify-between rounded-full border border-slate-200/80 bg-white/75 pl-5 pr-2 shadow-lg shadow-navy-900/5 backdrop-blur-xl">
        <a href="/" aria-label="Vértice, inicio"><img src={asset('logo-clean.png')} alt="Vértice" width={894} height={183} className="h-6 w-auto" /></a>
        <nav aria-label="Principal" className="hidden items-center gap-7 text-[13px] text-slate-500 md:flex">
          <a href="#agency" className="hover:text-navy-950">Vértice Agency</a>
          <a href="#erp" className="hover:text-navy-950">Vértice ERP</a>
          <a href="#comparar" className="hover:text-navy-950">Comparar</a>
          <a href="#faq" className="hover:text-navy-950">Preguntas</a>
        </nav>
        <div className="flex items-center gap-1.5 text-[13px] font-semibold">
          <a href="/app/login" className="hidden rounded-full px-4 py-2 text-slate-600 hover:text-navy-950 sm:block">Iniciar sesión</a>
          <a href="/app/register" className="rounded-full bg-navy-950 px-4 py-2 text-white transition hover:bg-brand-600">Empezar</a>
        </div>
      </div>
    </header>
  );
}

export function LandingPage() {
  useReveal();
  useEffect(() => {
    document.title = LANDING_TITLE;
  }, []);

  return (
    <div id="top" className="vx-root min-h-screen">
      <Nav />
      <main>
        <Hero />
        <Marquee />
        <Apps />
        <ProductBand
          id="agency"
          product="agency"
          title={<>El ERP para <span className="vx-gradient-text">agencias.</span></>}
          text="Proyectos, CRM, cotizaciones, facturación y rentabilidad para agencias que trabajan por proyectos, en red con aliados y clientes."
        />
        <Network />
        <Edges />
        <Bento />
        <Audience />
        <AgencyLogos />
        <Steps />
        <Compare />
        <ProductBand
          id="erp"
          product="erp"
          title={<>El ERP para <span className="vx-gradient-text">tu empresa.</span></>}
          text="Clientes, productos, inventario, compras, cobranzas, colaboradores y facturación electrónica SUNAT en una sola plataforma."
        />
        <Erp />
        <Faq />
        <Cta />
      </main>
      <SiteFooter onHome />
    </div>
  );
}
