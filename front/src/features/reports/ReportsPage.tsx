import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Chart from 'chart.js/auto';
import type { ChartConfiguration } from 'chart.js';
import { BarChart3, BookmarkPlus, CalendarPlus, Download, FileText, Mail, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Card, ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { ExportButton } from '../../components/ExportButton';
import { useConfirm, useToast, LoadingButton } from '../../components/feedback';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate, formatTotals } from '../../lib/format';
import type { ReportsData } from '../../types';

const FREQUENCY_OPTIONS = [
  { value: 'daily', label: 'Diaria (08:00)' },
  { value: 'weekly', label: 'Semanal (lunes 08:00)' },
  { value: 'monthly', label: 'Mensual (día 1, 08:00)' },
];

function isoDate(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function defaultRange() {
  const today = new Date();
  return { from: `${isoDate(today).slice(0, 8)}01`, to: isoDate(today) };
}

function deadlineRange() {
  const today = new Date();
  const next = new Date(today);
  next.setDate(next.getDate() + 30);
  return { from: isoDate(today), to: isoDate(next) };
}

function ChartCanvas({ id, config }: { id: string; config: ChartConfiguration }) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);

  useEffect(() => {
    if (!canvasRef.current) return;
    const chart = new Chart(canvasRef.current, config);
    return () => chart.destroy();
  }, [config]);

  return <canvas id={id} ref={canvasRef} />;
}

export function ReportsPage() {
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const toast = useToast();
  const initial = defaultRange();
  const [section, setSection] = useState<'report' | 'schedules'>('report');
  const [report, setReport] = useState('projects');
  const [from, setFrom] = useState(initial.from);
  const [to, setTo] = useState(initial.to);
  const [projectId, setProjectId] = useState('');
  const [activeViewId, setActiveViewId] = useState<number | null>(null);
  const [viewName, setViewName] = useState('');

  const { data, isLoading, error } = useQuery({
    queryKey: ['reports', report, from, to, projectId, section],
    queryFn: () => apiGet<ReportsData>('reports.php', { report, from, to, project_id: projectId, section }),
  });

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['reports'] });

  const saveView = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('reports.php', { action: 'save_view', ...body }),
    onSuccess: () => { setViewName(''); refresh(); toast('Vista guardada.'); },
  });

  const deleteView = useMutation({
    mutationFn: (viewId: number) => apiPost<{ success: boolean }>('reports.php', { action: 'delete_view', view_id: viewId }),
    onSuccess: () => { refresh(); toast('Vista eliminada.'); },
  });

  const saveSchedule = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('reports.php', { action: 'save_schedule', ...body }),
    onSuccess: () => { refresh(); toast('Exportación programada.'); },
  });

  const toggleSchedule = useMutation({
    mutationFn: (scheduleId: number) => apiPost<{ success: boolean }>('reports.php', { action: 'toggle_schedule', schedule_id: scheduleId }),
    onSuccess: () => { refresh(); toast('Programación actualizada.'); },
  });

  const deleteSchedule = useMutation({
    mutationFn: (scheduleId: number) => apiPost<{ success: boolean }>('reports.php', { action: 'delete_schedule', schedule_id: scheduleId }),
    onSuccess: () => { refresh(); toast('Programación eliminada.'); },
  });

  if (isLoading) return <div className="space-y-4">{Array.from({ length: 6 }).map((_, index) => <Skeleton key={index} className="h-20" />)}</div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar el reporte.'} />;

  const dataset = data.dataset;
  const summary = dataset.summary;
  const money = (key: string) => formatTotals((summary[key] as Record<string, number> | undefined) ?? {});
  const count = (key: string) => Number(summary[key] ?? 0);
  const marginNegative = Object.values((summary.margin as Record<string, number> | undefined) ?? {}).reduce((acc, value) => acc + value, 0) < 0;

  const switchReport = (next: string) => {
    const range = next === 'deadlines' ? deadlineRange() : defaultRange();
    setReport(next);
    setActiveViewId(null);
    setFrom(range.from);
    setTo(range.to);
  };

  const applyView = (view: ReportsData['savedViews'][number]) => {
    setReport(view.reportType);
    setFrom(view.filters.from || initial.from);
    setTo(view.filters.to || initial.to);
    setProjectId(view.filters.project_id > 0 ? String(view.filters.project_id) : '');
    setActiveViewId(view.id);
  };

  const chart = dataset.chart;
  const chartConfig: ChartConfiguration | null = chart.labels.length === 0 || chart.series.length === 0
    ? null
    : chart.type === 'doughnut'
      ? {
        type: 'doughnut',
        data: {
          labels: chart.labels,
          datasets: [{ data: chart.series[0]?.data ?? [], backgroundColor: chart.series[0]?.colors ?? ['#0E66FF', '#22c55e', '#f59e0b'], borderWidth: 0 }],
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'bottom' } } },
      }
      : {
        type: 'bar',
        data: {
          labels: chart.labels,
          datasets: chart.series.map((serie) => ({ label: serie.label, data: serie.data, backgroundColor: serie.color ?? '#0E66FF', borderRadius: 4 })),
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: chart.series.length > 1, position: 'bottom' } },
          scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } },
        },
      };

  return (
    <div className="space-y-5">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Reportes</h1>
          <p className="hidden text-[11px] text-slate-400 sm:block">Proyectos, personas, clientes y vencimientos</p>
        </div>
        <button type="button" onClick={() => setSection(section === 'schedules' ? 'report' : 'schedules')} className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50">
          <Mail className="h-3.5 w-3.5" />
          <span className="hidden sm:inline">{section === 'schedules' ? 'Volver a reportes' : 'Exportaciones programadas'}</span>
          <span className="sm:hidden">{section === 'schedules' ? 'Volver' : 'Programadas'}</span>
        </button>
      </div>

      {section === 'schedules' ? (
        <>
          <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
              <h2 className="text-base font-semibold text-slate-900">Exportaciones programadas</h2>
              <p className="mt-0.5 text-xs text-slate-500">Envía por correo el reporte actual de forma diaria, semanal o mensual.</p>
            </div>
            <button type="button" onClick={() => setSection('report')} className="text-xs font-semibold text-brand-700 hover:text-brand-800">Volver a reportes</button>
          </div>

          <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <section className="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs lg:col-span-1">
              <h3 className="text-sm font-semibold text-slate-800">Nueva programación</h3>
              <p className="text-[11px] text-slate-500">
                Reporte: <strong>{data.typeLabels[report] ?? report}</strong><br />
                Periodo: {formatDate(data.filters.from)} – {formatDate(data.filters.to)}
              </p>
              <form key={`schedule-${data.schedules.length}`} className="space-y-3" onSubmit={(event) => {
                event.preventDefault();
                const form = new FormData(event.currentTarget);
                saveSchedule.mutate({
                  name: String(form.get('name') ?? ''),
                  frequency: String(form.get('frequency') ?? 'weekly'),
                  recipients: String(form.get('recipients') ?? ''),
                  report_type: report,
                  from,
                  to,
                  project_id: projectId,
                });
              }}>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Nombre</label>
                  <Input name="name" required maxLength={120} placeholder="Ej: Reporte semanal de proyectos" className="px-3 py-2" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Frecuencia</label>
                  <Select name="frequency" defaultValue="weekly" className="w-full px-3 py-2">
                    {FREQUENCY_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                  </Select>
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Correos destinatarios</label>
                  <Input name="recipients" required placeholder="uno@agencia.com, otro@agencia.com" className="px-3 py-2" />
                  <p className="mt-1 text-[10px] text-slate-400">Separa varios correos con comas.</p>
                </div>
                {saveSchedule.isError && <ErrorState message={saveSchedule.error instanceof Error ? saveSchedule.error.message : 'No se pudo programar el envío.'} />}
                <button type="submit" disabled={saveSchedule.isPending} className="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-brand-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">
                  <CalendarPlus className="h-3.5 w-3.5" />
                  Programar envío
                </button>
              </form>
              <p className="border-t border-slate-100 pt-2 text-[10px] leading-relaxed text-slate-400">
                El envío se procesa con <code className="font-mono">php database/reports.php</code>. Configúralo en el cron diario del servidor.
              </p>
            </section>

            <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs lg:col-span-2">
              <div className="border-b border-slate-100 px-5 py-4">
                <h3 className="text-sm font-semibold text-slate-800">Programaciones de la agencia</h3>
              </div>
              {data.schedules.length === 0 ? (
                <p className="p-10 text-center text-sm text-slate-400">Aún no hay exportaciones programadas.</p>
              ) : (
                <div className="divide-y divide-slate-100">
                  {data.schedules.map((schedule) => (
                    <div key={schedule.id} className="flex flex-wrap items-center justify-between gap-3 p-4 sm:px-5">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="truncate text-sm font-semibold text-slate-800">{schedule.name}</p>
                          <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${schedule.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{schedule.active ? 'Activa' : 'Pausada'}</span>
                        </div>
                        <p className="mt-0.5 text-[11px] text-slate-400">{data.typeLabels[schedule.reportType] ?? schedule.reportType} · {data.frequencyLabels[schedule.frequency] ?? schedule.frequency} · {schedule.recipients}</p>
                        <p className="mt-0.5 text-[10px] text-slate-400">
                          Próximo envío: {schedule.nextRunAt ? formatDate(schedule.nextRunAt, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'}
                          {schedule.lastRunAt ? ` · Último: ${formatDate(schedule.lastRunAt, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}` : ''}
                        </p>
                      </div>
                      <div className="flex items-center gap-1.5">
                        <button type="button" disabled={toggleSchedule.isPending} onClick={() => toggleSchedule.mutate(schedule.id)} className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50 disabled:opacity-60">
                          {schedule.active ? 'Pausar' : 'Activar'}
                        </button>
                        <button type="button" disabled={deleteSchedule.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar programación', description: schedule.name, confirmLabel: 'Eliminar', tone: 'danger' })) deleteSchedule.mutate(schedule.id); }} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar programación"><Trash2 className="h-3.5 w-3.5" /></button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </section>
          </div>
        </>
      ) : (
        <>
          <div className="flex flex-col justify-between gap-3 xl:flex-row xl:items-end">
            <div>
              <h2 className="text-base font-semibold text-slate-900">{dataset.title}</h2>
              <p className="mt-0.5 text-xs text-slate-500">Periodo: {formatDate(data.filters.from)} – {formatDate(data.filters.to)}</p>
            </div>
            <div className="flex flex-wrap items-center gap-1.5">
              <ExportButton url={data.exportUrl} format="csv" className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50"><Download className="h-3.5 w-3.5" /> CSV</ExportButton>
              <ExportButton url={data.exportPdfUrl} format="pdf" className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50"><FileText className="h-3.5 w-3.5" /> PDF</ExportButton>
            </div>
          </div>

          <form className="flex flex-col gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-2xs lg:flex-row lg:items-end" onSubmit={(event) => event.preventDefault()}>
            <div>
              <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Desde</label>
              <Input type="date" value={from} onChange={(event) => { setFrom(event.target.value); setActiveViewId(null); }} className="px-3 py-2" />
            </div>
            <div>
              <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Hasta</label>
              <Input type="date" value={to} onChange={(event) => { setTo(event.target.value); setActiveViewId(null); }} className="px-3 py-2" />
            </div>
            <div className="min-w-[180px]">
              <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">Proyecto</label>
              <Select value={projectId} onChange={(event) => { setProjectId(event.target.value); setActiveViewId(null); }} className="w-full px-3 py-2">
                <option value="">Todos los proyectos</option>
                {data.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
              </Select>
            </div>
            <button type="submit" className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700">Aplicar</button>
            <button type="button" onClick={() => { const range = report === 'deadlines' ? deadlineRange() : defaultRange(); setFrom(range.from); setTo(range.to); setProjectId(''); setActiveViewId(null); }} className="rounded-xl px-3 py-2 text-center text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">Limpiar</button>
          </form>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {report === 'projects' && (
              <>
                <Kpi label="Facturado" value={money('invoiced')} detail={`${count('projects')} proyectos · ${count('tasks')} tareas`} />
                <Kpi label="Cobrado" value={money('collected')} />
                <Kpi label="Por cobrar" value={money('pending')} />
                <Kpi label="Margen (cobrado − proveedores)" value={money('margin')} tone={marginNegative ? 'rose' : 'emerald'} />
              </>
            )}
            {report === 'people' && (
              <>
                <Kpi label="Personas" value={String(count('people'))} />
                <Kpi label="Tareas" value={String(count('tasks'))} />
                <Kpi label="Completadas" value={String(count('done'))} tone="emerald" />
                <Kpi label="Vencidas" value={String(count('overdue'))} tone="rose" />
              </>
            )}
            {report === 'clients' && (
              <>
                <Kpi label="Clientes" value={String(count('clients'))} detail={`${count('invoices')} facturas`} />
                <Kpi label="Facturado" value={money('invoiced')} />
                <Kpi label="Cobrado" value={money('collected')} tone="emerald" />
                <Kpi label="Pendiente" value={money('pending')} tone="amber" />
              </>
            )}
            {report === 'deadlines' && (
              <>
                <Kpi label="Vencidos" value={String(count('overdue'))} tone="rose" />
                <Kpi label="Próximos" value={String(count('upcoming'))} />
                <Kpi label="Tareas" value={String(count('tasks'))} />
                <Kpi label="Cobros" value={String(count('invoices'))} />
              </>
            )}
          </div>

          <div className="flex w-fit max-w-full items-center gap-1.5 overflow-x-auto rounded-xl bg-slate-100/80 p-1">
            {data.types.map((type) => (
              <button key={type.key} type="button" onClick={() => switchReport(type.key)} className={`whitespace-nowrap rounded-lg px-3.5 py-2 text-xs font-semibold transition ${report === type.key ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}>{type.label}</button>
            ))}
          </div>

          <div className="flex flex-wrap items-center gap-2">
            {data.savedViews.map((view) => (
              <div key={view.id} className={`inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white py-1 pl-2.5 pr-1 ${view.id === activeViewId ? 'ring-1 ring-brand-300' : ''}`}>
                <button type="button" onClick={() => applyView(view)} className={`text-[11px] font-semibold ${view.id === activeViewId ? 'text-brand-700' : 'text-slate-600 hover:text-brand-700'}`}>{view.name}</button>
                <button type="button" disabled={deleteView.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar vista guardada', description: view.name, confirmLabel: 'Eliminar', tone: 'danger' })) deleteView.mutate(view.id); }} className="rounded p-0.5 text-slate-300 transition hover:text-rose-600" title="Eliminar vista"><X className="h-3 w-3" /></button>
              </div>
            ))}
            <form className="inline-flex items-center gap-1" onSubmit={(event) => {
              event.preventDefault();
              saveView.mutate({ name: viewName, report_type: report, from, to, project_id: projectId });
            }}>
              <Input value={viewName} onChange={(event) => setViewName(event.target.value)} required maxLength={120} placeholder="Nombre de la vista" className="w-36 px-2.5 py-1.5 text-[11px]" />
              <LoadingButton type="submit" pending={saveView.isPending} className="inline-flex items-center gap-1 rounded-lg border border-brand-200 bg-brand-50 px-2.5 py-1.5 text-[11px] font-semibold text-brand-700 transition hover:bg-brand-100 disabled:opacity-60"><BookmarkPlus className="h-3 w-3" /> Guardar vista</LoadingButton>
            </form>
          </div>

          {chartConfig && (
            <Card className="p-5">
              <h3 className="mb-3 text-sm font-semibold text-slate-800">{data.typeLabels[report] ?? report} · visual</h3>
              <div className="relative h-64"><ChartCanvas id="reportChart" config={chartConfig} /></div>
            </Card>
          )}

          <Card className="overflow-hidden">
            <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
              <h3 className="text-sm font-semibold text-slate-800">{dataset.title}</h3>
              <span className="text-[11px] text-slate-400">{dataset.rows.length} fila{dataset.rows.length === 1 ? '' : 's'}</span>
            </div>
            {dataset.rows.length === 0 ? (
              <div className="p-12 text-center">
                <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><BarChart3 className="h-6 w-6" /></div>
                <h3 className="text-sm font-semibold text-slate-800">Sin datos en este periodo</h3>
                <p className="mt-1 text-xs text-slate-500">Ajusta las fechas o selecciona otro proyecto.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs text-slate-600">
                  <thead className="border-b border-slate-100 bg-slate-50/70 text-[11px] font-medium text-slate-500">
                    <tr>{dataset.headers.map((header) => <th key={header} className="whitespace-nowrap px-4 py-3">{header}</th>)}</tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {dataset.rows.map((row, rowIndex) => (
                      <tr key={rowIndex} className="transition hover:bg-slate-50/60">
                        {row.map((cell, cellIndex) => (
                          <td key={cellIndex} className={`whitespace-nowrap px-4 py-3 ${cellIndex === 0 ? 'font-semibold text-slate-800' : ''}`}>
                            {report === 'deadlines' && cellIndex === 6 ? (
                              <span className={`inline-flex rounded-full border px-2 py-0.5 text-[10px] font-semibold ${cell === 'Vencido' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-amber-200 bg-amber-50 text-amber-700'}`}>{cell === null || cell === '' ? '—' : String(cell)}</span>
                            ) : (
                              cell === null || cell === '' ? '—' : String(cell)
                            )}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </>
      )}

      {(saveView.isError || deleteView.isError || saveSchedule.isError || toggleSchedule.isError || deleteSchedule.isError) && (
        <ErrorState message={(() => {
          const mutationError = saveView.error ?? deleteView.error ?? saveSchedule.error ?? toggleSchedule.error ?? deleteSchedule.error;
          return mutationError instanceof Error ? mutationError.message : 'No se pudo completar la operación.';
        })()} />
      )}
    </div>
  );
}

function Kpi({ label, value, detail, tone }: { label: string; value: string; detail?: string; tone?: 'slate' | 'emerald' | 'rose' | 'amber' }) {
  const toneClass = tone === 'emerald' ? 'text-emerald-600' : tone === 'rose' ? 'text-rose-600' : tone === 'amber' ? 'text-amber-600' : 'text-slate-900';
  return (
    <Card className="p-4">
      <span className="text-xs font-medium text-slate-500">{label}</span>
      <p className={`mt-1 text-lg font-semibold ${toneClass}`}>{value}</p>
      {detail && <p className="text-[11px] text-slate-400">{detail}</p>}
    </Card>
  );
}
