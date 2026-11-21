// Página pública de Vértice Agency (/software-para-agencias), separada de la home del ERP.
import { useEffect, useState, type ReactNode } from 'react';
import {
  ArrowRight, ArrowUpRight, BarChart3, Briefcase, Building, Building2, Check, CheckCircle2, Code2,
  Eye, FileText, FolderKanban, GanttChartSquare, LifeBuoy, Link2, Lock, Megaphone, MessageSquare, Palette, Pencil, Plus, ShieldCheck, Sparkles, UserRound, Users, X,
} from 'lucide-react';
import { COMPARE_ROWS, COMPETITORS, VERTICE_SUPPORT } from './compare-data';
import { AGENCY_SIGNUP_HREF } from './products';
import { SOLUTION_FEATURES, solutionHref } from './solution-data';
import { Eyebrow, FaqSection, ProductBadge, Reveal, trackSpot, useReveal } from './ui';
import { SiteFooter } from './SiteFooter';
import { SiteHeader } from './SiteHeader';
import './landing.css';

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
          <h2 className="vx-display mt-5 text-4xl font-bold leading-[1.05] text-navy-950 sm:text-5xl">Herramientas de gestión que piensan como una <span className="vx-highlight">agencia.</span></h2>
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
        <Reveal className="mt-12">
          <a href={AGENCY_SIGNUP_HREF} className="group inline-flex items-center gap-2 rounded-full bg-navy-950 px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-600">
            Crear mi agencia gratis <ArrowRight className="h-4 w-4 transition group-hover:translate-x-0.5" />
          </a>
        </Reveal>
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
/* Hero de Vértice Agency                                               */
/* ------------------------------------------------------------------ */
function AgencyHero() {
  const gantt = [
    { l: 'Brief', s: 0, w: 30, c: 'from-navy-700 to-navy-500' },
    { l: 'Diseño', s: 22, w: 38, c: 'from-brand-600 to-brand-400' },
    { l: 'Desarrollo', s: 48, w: 34, c: 'from-brand-400 to-cyan-400' },
    { l: 'Entrega', s: 78, w: 20, c: 'from-cyan-400 to-cyan-300' },
  ];
  return (
    <section className="vx-noise relative isolate overflow-hidden pb-24 pt-32 sm:pt-40">
      <div className="vx-dots absolute inset-0 -z-10 [mask-image:radial-gradient(ellipse_at_top,#000_30%,transparent_75%)]" />
      <div className="vx-aurora -z-10 left-[-10%] top-[-15%] h-[520px] w-[520px] bg-brand-200" />
      <div className="vx-aurora -z-10 right-[-12%] top-[5%] h-[460px] w-[460px] bg-cyan-200" style={{ animationDelay: '-6s' }} />

      <div className="relative mx-auto grid max-w-7xl items-center gap-14 px-4 sm:px-6 lg:grid-cols-[1.08fr_1fr] lg:px-8">
        <div>
          <Reveal>
            <nav aria-label="Ruta" className="vx-mono text-[11px] uppercase tracking-[0.18em] text-slate-400">
              <a href="/" className="hover:text-brand-600">Vértice</a> <span className="mx-1.5">/</span> <span className="text-brand-600">Vértice Agency</span>
            </nav>
            <div className="mt-6"><ProductBadge product="agency" /></div>
          </Reveal>
          <Reveal delay={100}>
            <h1 className="mt-7">
              <span className="vx-mono block text-[11px] font-medium uppercase tracking-[0.22em] text-brand-600 sm:text-xs">Software de gestión para agencias</span>
              <span className="vx-display mt-4 block text-[2.6rem] font-bold leading-[1.02] text-navy-950 sm:text-6xl lg:text-[4.1rem]">
                Proyectos, clientes y cobros de tu agencia <span className="vx-gradient-text">en un solo lugar.</span>
              </span>
            </h1>
          </Reveal>
          <Reveal delay={200}>
            <p className="mt-6 max-w-xl text-base leading-relaxed text-slate-500 sm:text-lg">
              Vértice Agency une gestión de proyectos con Kanban y Gantt, CRM, cotizaciones, facturación y rentabilidad por proyecto. Trabaja en red con otras agencias y da a tus clientes un portal con acceso limitado.
            </p>
          </Reveal>
          <Reveal delay={300}>
            <div className="mt-9 flex flex-wrap items-center gap-3">
              <a href={AGENCY_SIGNUP_HREF} className="group inline-flex items-center gap-2 rounded-full bg-navy-950 px-7 py-3.5 text-sm font-semibold text-white shadow-[0_14px_30px_-10px_rgb(7_42_91/.6)] transition hover:bg-navy-900">
                Crear cuenta gratis
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-cyan-400 transition group-hover:rotate-[-45deg]"><ArrowRight className="h-3.5 w-3.5" /></span>
              </a>
              <a href="#modulos" className="inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white/70 px-6 py-3.5 text-sm font-semibold text-navy-950 transition hover:border-navy-950">Ver funciones</a>
            </div>
            <ul className="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-500">
              {['Gratis para empezar', 'Listo en minutos', 'Sin instalar nada'].map((t) => (
                <li key={t} className="flex items-center gap-1.5"><CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />{t}</li>
              ))}
            </ul>
            <p className="mt-6 text-xs text-slate-400">¿Ya tienes cuenta? <a href="/app/login" className="font-semibold text-navy-950 hover:text-brand-600">Inicia sesión</a></p>
          </Reveal>
        </div>

        <Reveal delay={200}>
          <div className="relative">
            <div className="absolute -inset-6 rounded-[2.5rem] bg-gradient-to-br from-brand-100 via-cyan-50 to-transparent blur-2xl" />
            <div className="relative rounded-3xl border border-slate-200 bg-white p-5 shadow-[0_40px_80px_-40px_rgb(7_42_91/.45)] sm:p-6">
              <div className="flex items-start justify-between gap-4">
                <div>
                  <p className="vx-mono text-[10px] uppercase tracking-[0.2em] text-slate-400">Proyecto</p>
                  <p className="vx-display mt-1 text-xl font-bold text-navy-950">Rediseño web · Cliente Andino</p>
                </div>
                <span className="vx-mono rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-semibold text-brand-700 ring-1 ring-brand-100">68%</span>
              </div>
              <div className="mt-5 space-y-2.5">
                {gantt.map((g, i) => (
                  <div key={g.l} className="grid grid-cols-[76px_1fr] items-center gap-3">
                    <span className="vx-mono truncate text-[11px] text-slate-400">{g.l}</span>
                    <div className="relative h-6 rounded-md bg-slate-100">
                      <div className={`vx-bar absolute inset-y-0 rounded-md bg-gradient-to-r ${g.c}`} style={{ left: `${g.s}%`, width: `${g.w}%`, animationDelay: `${i * 0.25}s` }} />
                    </div>
                  </div>
                ))}
              </div>
              <div className="mt-5 grid grid-cols-3 gap-2 border-t border-slate-100 pt-4 text-center">
                {[['S/ 12,400', 'presupuesto'], ['S/ 8,060', 'facturado'], ['32%', 'margen']].map(([v, l]) => (
                  <div key={l}>
                    <p className="vx-display text-base font-bold text-navy-950">{v}</p>
                    <p className="vx-mono text-[10px] uppercase tracking-wider text-slate-400">{l}</p>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </Reveal>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
export const AGENCY_PAGE_TITLE = 'Software para agencias: proyectos, CRM y cobros | Vértice';
export const AGENCY_PAGE_DESCRIPTION = 'Vértice Agency: software de gestión para agencias con proyectos en Kanban y Gantt, CRM, cotizaciones, facturación y rentabilidad. Empieza gratis.';

export const AGENCY_FAQS: { q: string; a: string }[] = [
  { q: '¿Qué es Vértice Agency?', a: 'Vértice Agency es un software de gestión para agencias que trabajan por proyectos: reúne proyectos y tareas, CRM, cotizaciones, facturación, caja y rentabilidad por proyecto, y permite trabajar en red con otras agencias y dar acceso limitado a clientes.' },
  { q: '¿Qué debe tener un software de gestión de proyectos para agencias?', a: 'Además de tareas en Kanban y Gantt, una agencia necesita conectar la venta con la entrega y el cobro: CRM, cotizaciones, facturación con pagos parciales y rentabilidad por proyecto, más un acceso limitado para que los clientes sigan su avance. Vértice Agency reúne todo eso en una sola plataforma.' },
  { q: '¿Vértice Agency incluye diagrama de Gantt?', a: 'Sí. Incluye diagrama de Gantt online con dependencias, hitos y camino crítico, y las mismas tareas se pueden ver en Kanban o en lista.' },
  { q: '¿Puedo saber si un proyecto es rentable?', a: 'Sí. Vértice Agency cruza los ingresos facturados de cada proyecto con sus costos y egresos para mostrarte el margen real de cada proyecto.' },
  { q: '¿Cómo me conecto con otras agencias?', a: 'Cada agencia tiene un código de conexión. La otra agencia lo introduce y tú aceptas la solicitud. Luego compartes solo los proyectos que quieras, con acceso de Ver o Colaborar, y la agencia invitada elige qué miembros participan. El presupuesto y las facturas nunca se comparten.' },
  { q: '¿Mis clientes pueden ver sus proyectos?', a: 'Sí. Invitas a tu cliente con un enlace de un solo uso que caduca en 7 días. Con el rol cliente ve su dashboard, sus tareas y el calendario, y puede abrir tickets, pero no ve comentarios internos, checklists ni finanzas.' },
  { q: '¿Puedo crear cotizaciones y facturas?', a: 'Sí. Generas cotizaciones en PDF con tu marca, les haces seguimiento, las conviertes en proyectos y registras facturas con pagos parciales, cuentas por pagar y caja.' },
  { q: '¿Vértice Agency se integra con otras herramientas?', a: 'Sí. Ofrece una API con tokens por agencia y webhooks para enviar eventos a tus sistemas, además de exportación de datos.' },
  { q: '¿En qué se diferencia de ClickUp, Trello, Asana, Odoo o monday.com?', a: 'Vértice Agency está pensado para agencias: además de proyectos con Kanban y Gantt, incluye CRM, cotizaciones en PDF, facturación y rentabilidad, y permite compartir proyectos entre agencias conectadas y dar acceso limitado a clientes, sin exponer tus finanzas.' },
  { q: '¿Cuánto cuesta Vértice Agency?', a: 'Vértice Agency se crea gratis y se configura en minutos, sin instalar nada.' },
  { q: '¿Vértice Agency es lo mismo que Vértice ERP?', a: 'No. Son productos distintos. Vértice Agency es para negocios que trabajan por proyectos para clientes. Vértice ERP es para empresas que venden productos o servicios y necesitan inventario, compras y facturación electrónica SUNAT.' },
];

/* ------------------------------------------------------------------ */
function AgencyCta() {
  return (
    <section className="relative px-4 py-24 sm:px-6 sm:py-32 lg:px-8">
      <Reveal className="mx-auto max-w-6xl">
        <div className="relative overflow-hidden rounded-[2.25rem] bg-gradient-to-br from-navy-950 via-navy-900 to-brand-700 px-7 py-14 text-center text-white sm:px-12 sm:py-20">
          <div className="absolute inset-0 opacity-[0.14] [background-image:linear-gradient(rgb(255_255_255/.5)_1px,transparent_1px),linear-gradient(90deg,rgb(255_255_255/.5)_1px,transparent_1px)] [background-size:44px_44px] [mask-image:radial-gradient(ellipse_at_center,#000_20%,transparent_70%)]" />
          <div className="absolute left-1/2 top-0 h-72 w-72 -translate-x-1/2 rounded-full bg-cyan-400/25 blur-3xl" />
          <div className="relative mx-auto max-w-2xl">
            <ProductBadge product="agency" dark />
            <h2 className="vx-display mt-6 text-4xl font-bold leading-[1.02] sm:text-6xl">Tu agencia, <span className="bg-gradient-to-r from-cyan-300 to-brand-300 bg-clip-text text-transparent">en su vértice.</span></h2>
            <p className="mx-auto mt-5 max-w-md text-white/70">Crea tu agencia gratis, invita a tu equipo y gestiona tu primer proyecto hoy.</p>
            <a href={AGENCY_SIGNUP_HREF} className="group mt-9 inline-flex items-center gap-2 rounded-full bg-white px-7 py-3.5 text-sm font-semibold text-navy-950 transition hover:bg-cyan-300">
              Crear cuenta gratis <ArrowUpRight className="h-4 w-4 transition group-hover:rotate-45" />
            </a>
            <p className="mt-5 text-xs text-white/50">¿Ya tienes cuenta? <a href="/app/login" className="font-semibold text-white hover:text-cyan-300">Inicia sesión</a></p>
          </div>
        </div>
      </Reveal>
    </section>
  );
}

export function AgencyPage() {
  useReveal();
  useEffect(() => {
    document.title = AGENCY_PAGE_TITLE;
  }, []);

  return (
    <div id="top" className="vx-root min-h-screen">
      <SiteHeader product="agency" />
      <main>
        <AgencyHero />
        <AgencyLogos />
        <Bento />
        <Network />
        <Audience />
        <Steps />
        <Compare />
        <FaqSection faqs={AGENCY_FAQS} />
        <AgencyCta />
      </main>
      <SiteFooter />
    </div>
  );
}
