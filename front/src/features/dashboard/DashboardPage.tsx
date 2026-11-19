import { useQuery } from '@tanstack/react-query';
import { Activity, CheckCircle2, ChevronRight, Clock, FolderKanban, LifeBuoy, Share2, TrendingUp, Wallet } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import Chart from 'chart.js/auto';
import type { ChartConfiguration } from 'chart.js';
import { Card, ErrorState, Skeleton } from '../../components/ui';
import { apiGet } from '../../lib/api';
import { formatDate, formatTotals } from '../../lib/format';
import type { DashboardData } from '../../types';

function ChartCanvas({ id, config, className }: { id: string; config: ChartConfiguration; className?: string }) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  useEffect(() => {
    if (!canvasRef.current) return;
    const chart = new Chart(canvasRef.current, config);
    return () => chart.destroy();
  }, [config]);
  return <canvas id={id} ref={canvasRef} className={className} />;
}

const baseOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 11 }, color: '#64748b' },
    },
    tooltip: { backgroundColor: '#0f172a', padding: 10, cornerRadius: 8, titleFont: { size: 11 }, bodyFont: { size: 11 } },
  },
};

const STATUS_CLASSES: Record<string, string> = {
  activo: 'bg-emerald-50 text-emerald-700',
  en_espera: 'bg-amber-50 text-amber-700',
  completado: 'bg-slate-100 text-slate-500',
};

const STATUS_LABELS: Record<string, string> = { activo: 'Activo', en_espera: 'En espera', completado: 'Completado' };

export function DashboardPage() {
  const { data, isLoading, error } = useQuery({ queryKey: ['dashboard'], queryFn: () => apiGet<DashboardData>('dashboard.php') });

  if (isLoading) {
    return (
      <div className="space-y-6">
        <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} className="h-28 rounded-2xl" />)}</div>
        <Skeleton className="h-48 rounded-2xl" />
        <div className="grid gap-6 lg:grid-cols-3">{Array.from({ length: 3 }).map((_, index) => <Skeleton key={index} className="h-72 rounded-2xl" />)}</div>
      </div>
    );
  }
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar el dashboard.'} />;

  const { stats } = data;
  const financeVisible = data.finance !== null && (
    Math.abs(Object.values(data.finance.budget).reduce((a, b) => a + b, 0)) > 0
    || Math.abs(Object.values(data.finance.collected).reduce((a, b) => a + b, 0)) > 0
    || data.finance.payablesCount > 0
  );
  const rawDate = formatDate(data.date, { weekday: 'long', day: 'numeric', month: 'long' });
  const dateLabel = rawDate.charAt(0).toUpperCase() + rawDate.slice(1);

  const statusConfig: ChartConfiguration<'doughnut'> = {
    type: 'doughnut',
    data: {
      labels: data.taskStatusChart.labels,
      datasets: [{ data: data.taskStatusChart.data, backgroundColor: data.taskStatusChart.colors, borderWidth: 0, hoverOffset: 4, spacing: 2 }],
    },
    options: { ...baseOptions, cutout: '74%', plugins: { ...baseOptions.plugins, legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', padding: 14, font: { size: 11 }, color: '#64748b' } } } },
  };
  const priorityConfig: ChartConfiguration<'bar'> = {
    type: 'bar',
    data: { labels: data.priorityChart.labels, datasets: [{ data: data.priorityChart.data, backgroundColor: data.priorityChart.colors, borderRadius: 6, barThickness: 18 }] },
    options: {
      ...baseOptions,
      plugins: { ...baseOptions.plugins, legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 }, color: '#94a3b8' }, grid: { color: '#f1f5f9' }, border: { display: false } },
        x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#94a3b8' }, border: { display: false } },
      },
    },
  };
  const categoryConfig: ChartConfiguration<'doughnut'> = {
    type: 'doughnut',
    data: {
      labels: data.categoryChart.labels,
      datasets: [{ data: data.categoryChart.data, backgroundColor: ['#0E66FF', '#15BDF4', '#8FBCFF', '#64748B', '#94A3B8', '#CBD5E1'], borderWidth: 0, spacing: 2 }],
    },
    options: { ...baseOptions, cutout: '74%', plugins: { ...baseOptions.plugins, legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', padding: 14, font: { size: 11 }, color: '#64748b' } } } },
  };
  const progressConfig: ChartConfiguration<'bar'> = {
    type: 'bar',
    data: {
      labels: data.projectProgress.map((project) => project.name.length > 18 ? `${project.name.slice(0, 16)}…` : project.name),
      datasets: [
        { label: 'Completadas', data: data.projectProgress.map((project) => project.completedTasks), backgroundColor: '#0E66FF', borderRadius: 6, stack: 'tasks' },
        { label: 'Pendientes', data: data.projectProgress.map((project) => project.pendingTasks), backgroundColor: '#E2E8F0', borderRadius: 6, stack: 'tasks' },
      ],
    },
    options: {
      ...baseOptions,
      indexAxis: 'y',
      plugins: { ...baseOptions.plugins, legend: { display: false } },
      scales: {
        x: { stacked: true, beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 }, color: '#94a3b8' }, grid: { color: '#f1f5f9' }, border: { display: false } },
        y: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 }, color: '#64748b' }, border: { display: false } },
      },
    },
  };

  const projectCount = data.projectProgress.length;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-400">{dateLabel}</p>
          <h2 className="mt-1 text-xl font-semibold tracking-tight text-slate-900">Resumen de la agencia</h2>
        </div>
        <Link to="/tasks" className="inline-flex w-fit items-center gap-1 text-xs font-semibold text-slate-500 transition hover:text-slate-900">
          Ver tareas <ChevronRight className="h-3.5 w-3.5" />
        </Link>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <Card className="border-slate-200/60 p-5 shadow-none">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Proyectos activos</p>
              <p className="mt-2 text-[30px] font-semibold leading-none tracking-tight tabular-nums text-slate-900">{stats.totalProjects}</p>
            </div>
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><FolderKanban className="h-4 w-4" /></span>
          </div>
          <p className="mt-3 text-[11px] text-slate-400">En seguimiento y compartidos</p>
        </Card>
        <Card className="border-slate-200/60 p-5 shadow-none">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Tareas completadas</p>
              <p className="mt-2 text-[30px] font-semibold leading-none tracking-tight tabular-nums text-slate-900">{stats.completionRate}%</p>
            </div>
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"><CheckCircle2 className="h-4 w-4" /></span>
          </div>
          <p className="mt-3 text-[11px] text-slate-400">{stats.completedTasks} de {stats.totalTasks} tareas</p>
        </Card>
        <Card className="border-slate-200/60 p-5 shadow-none">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">En curso</p>
              <p className="mt-2 text-[30px] font-semibold leading-none tracking-tight tabular-nums text-slate-900">{stats.inProgressTasks}</p>
            </div>
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-50 text-amber-600"><Activity className="h-4 w-4" /></span>
          </div>
          <p className="mt-3 text-[11px] text-slate-400">{stats.pendingTasks} pendientes en cola</p>
        </Card>
        <Card className="border-slate-200/60 p-5 shadow-none">
          <div className="flex items-start justify-between gap-3">
            <div>
              <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Tickets abiertos</p>
              <p className="mt-2 text-[30px] font-semibold leading-none tracking-tight tabular-nums text-slate-900">{stats.openTickets}</p>
            </div>
            <span className={`flex h-9 w-9 items-center justify-center rounded-xl ${stats.openTickets > 0 ? 'bg-rose-50 text-rose-500' : 'bg-slate-50 text-slate-400'}`}><LifeBuoy className="h-4 w-4" /></span>
          </div>
          <p className="mt-3 text-[11px] text-slate-400">{stats.resolvedTickets} resueltos</p>
        </Card>
      </div>

      {financeVisible && data.finance && (
        <Card className="grid grid-cols-2 divide-x divide-y divide-slate-100 overflow-hidden border-slate-200/60 shadow-none sm:grid-cols-4 sm:divide-y-0">
          <div className="p-5">
            <div className="flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-slate-400"><Wallet className="h-3.5 w-3.5" /> Presupuesto</div>
            <p className="mt-2 text-lg font-semibold tracking-tight tabular-nums text-slate-900">{formatTotals(data.finance.budget)}</p>
          </div>
          <Link to="/finance" className="group p-5 transition hover:bg-slate-50/60">
            <div className="flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-slate-400"><TrendingUp className="h-3.5 w-3.5" /> Cobrado</div>
            <p className="mt-2 text-lg font-semibold tracking-tight tabular-nums text-emerald-600">{formatTotals(data.finance.collected)}</p>
          </Link>
          <Link to="/finance" className="group p-5 transition hover:bg-slate-50/60">
            <div className="flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-slate-400"><Clock className="h-3.5 w-3.5" /> Pendiente de cobro</div>
            <p className="mt-2 text-lg font-semibold tracking-tight tabular-nums text-amber-600">{formatTotals(data.finance.pending)}</p>
          </Link>
          <Link to="/finance" className="group p-5 transition hover:bg-slate-50/60">
            <div className="flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-slate-400"><TrendingUp className="h-3.5 w-3.5 rotate-180" /> Por pagar</div>
            <p className="mt-2 text-lg font-semibold tracking-tight tabular-nums text-rose-500">{formatTotals(data.finance.payables)}</p>
            <p className="mt-0.5 text-[10px] text-slate-400">{data.finance.payablesCount} cuenta(s) pendiente(s)</p>
          </Link>
        </Card>
      )}

      <div className="grid gap-4 xl:grid-cols-3">
        {projectCount > 0 && (
          <Card className="overflow-hidden border-slate-200/60 shadow-none xl:col-span-2">
            <div className="flex items-center justify-between px-5 py-4">
              <div>
                <h3 className="text-sm font-semibold text-slate-900">Proyectos</h3>
                <p className="text-[11px] text-slate-400">{projectCount} proyectos con actividad</p>
              </div>
              <Link to="/projects" className="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-500 transition hover:text-slate-900">Ver todos <ChevronRight className="h-3.5 w-3.5" /></Link>
            </div>
            <div className="divide-y divide-slate-100">
              {data.projectProgress.map((project) => (
                <Link key={project.id} to={`/projects/${project.id}`} className="group flex items-center gap-3 px-5 py-3.5 transition hover:bg-slate-50/70">
                  <span className="h-2 w-2 flex-shrink-0 rounded-full" style={{ backgroundColor: project.color }} />
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <p className="truncate text-xs font-semibold text-slate-800 transition group-hover:text-brand-700">{project.name}</p>
                      <span className={`hidden flex-shrink-0 rounded-full px-1.5 py-0.5 text-[9px] font-semibold sm:inline ${STATUS_CLASSES[project.status] ?? 'bg-slate-100 text-slate-500'}`}>{STATUS_LABELS[project.status] ?? project.status}</span>
                      {project.sharedBy && <span className="inline-flex flex-shrink-0 items-center gap-1 rounded-full border border-indigo-200/60 bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700" title="Proyecto compartido por una agencia conectada"><Share2 className="h-2.5 w-2.5" /><span className="max-w-[8rem] truncate">Compartido por {project.sharedBy}</span></span>}
                    </div>
                    <p className="mt-0.5 truncate text-[10px] text-slate-400">{project.category}</p>
                  </div>
                  <div className="hidden w-32 flex-shrink-0 sm:block">
                    <div className="h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-brand-600" style={{ width: `${project.progress}%` }} /></div>
                  </div>
                  <span className="w-8 flex-shrink-0 text-right text-[11px] font-semibold tabular-nums text-slate-600">{project.progress}%</span>
                  <ChevronRight className="h-3.5 w-3.5 flex-shrink-0 text-slate-300 transition group-hover:text-slate-500" />
                </Link>
              ))}
            </div>
          </Card>
        )}

        <Card className="flex flex-col border-slate-200/60 p-5 shadow-none">
          <div className="mb-2 flex items-center justify-between">
            <h3 className="text-sm font-semibold text-slate-900">Categorías</h3>
            <span className="text-[11px] text-slate-400">{data.categoryChart.labels.length} categorías</span>
          </div>
          <div className="relative flex h-56 flex-1 items-center justify-center">
            {data.categoryChart.labels.length === 0 ? <span className="text-xs italic text-slate-400">No hay categorías registradas</span> : <ChartCanvas id="projectsCategoryChart" config={categoryConfig} />}
          </div>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="flex flex-col border-slate-200/60 p-5 shadow-none">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold text-slate-900">Estado de tareas</h3>
            <span className="text-[11px] text-slate-400">{stats.totalTasks} totales</span>
          </div>
          <div className="relative mt-3 flex h-52 items-center justify-center"><ChartCanvas id="tasksStatusChart" config={statusConfig} /></div>
          <div className="mt-3 grid grid-cols-3 gap-2 border-t border-slate-100 pt-4 text-center">
            {data.taskStatusChart.labelsFooter.map((item) => (
              <div key={item.label}><span className="block text-[10px] font-medium text-slate-400">{item.label}</span><span className="text-sm font-semibold tabular-nums text-slate-900">{item.value}</span></div>
            ))}
          </div>
        </Card>

        <Card className="flex flex-col border-slate-200/60 p-5 shadow-none">
          <div className="flex items-center justify-between">
            <h3 className="text-sm font-semibold text-slate-900">Tareas por prioridad</h3>
            <span className="text-[11px] text-slate-400">Distribución</span>
          </div>
          <div className="relative mt-3 flex h-52 items-center justify-center"><ChartCanvas id="tasksPriorityChart" config={priorityConfig} /></div>
          <div className="mt-3 flex items-center justify-around border-t border-slate-100 pt-4 text-center">
            {data.priorityChart.labels.map((label, index) => (
              <div key={label}><span className="block text-[10px] font-medium capitalize text-slate-400">{label}</span><span className="text-sm font-semibold tabular-nums text-slate-900">{data.priorityChart.data[index]}</span></div>
            ))}
          </div>
        </Card>
      </div>

      <Card className="border-slate-200/60 p-5 shadow-none">
        <div className="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
          <div>
            <h3 className="text-sm font-semibold text-slate-900">Progreso por proyecto</h3>
            <p className="text-[11px] text-slate-400">Tareas completadas frente a pendientes</p>
          </div>
          <div className="flex items-center gap-4 text-[11px]">
            <span className="flex items-center gap-1.5 text-slate-500"><span className="h-2 w-2 rounded-full bg-brand-600" /> Completadas</span>
            <span className="flex items-center gap-1.5 text-slate-500"><span className="h-2 w-2 rounded-full bg-slate-200" /> Pendientes</span>
          </div>
        </div>
        <div className="relative mt-4 h-64 w-full sm:h-72">
          {data.projectProgress.length === 0
            ? <div className="flex h-full items-center justify-center text-xs italic text-slate-400">No hay proyectos con tareas para graficar</div>
            : <ChartCanvas id="projectsComparisonChart" config={progressConfig} />}
        </div>
      </Card>
    </div>
  );
}
