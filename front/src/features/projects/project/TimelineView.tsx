import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarRange, Camera, Check, CornerDownRight, Diamond, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type FormEvent, type PointerEvent as ReactPointerEvent } from 'react';
import { LoadingButton, useConfirm, useToast } from '../../../components/feedback';
import { Card, Input, Select } from '../../../components/ui';
import { apiGet, apiPost } from '../../../lib/api';
import { formatDate } from '../../../lib/format';
import type { BaselinesData, ProjectInsightsData } from '../../../types';
import { errorMessage, todayIso, useProject } from './shared';

type GanttTask = ProjectInsightsData['gantt'][number];
type Zoom = 'day' | 'week';

const DAY = 86400000;
const ROW_HEIGHT = 48;
const MONTH_ROW = 26;
const SCALE_ROW = 32;
const LABEL_WIDTH = 270;
const MAX_DAYS = 365;
const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

function toTime(value: string): number {
  return new Date(`${value}T00:00:00`).getTime();
}

/** Fecha AAAA-MM-DD real. Una fecha como 0000-00-00 produce NaN y colgaba el cronograma. */
function isValidIso(value: string | null | undefined): value is string {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const time = toTime(value);
  return Number.isFinite(time) && isoFromTime(time) === value;
}

function isoFromTime(time: number): string {
  const date = new Date(time);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function addDays(value: string, days: number): string {
  const date = new Date(`${value}T00:00:00`);
  date.setDate(date.getDate() + days);
  return isoFromTime(date.getTime());
}

/** Días naturales entre dos fechas ISO (tolerante a cambios de horario). */
function diffDays(from: string, to: string): number {
  return Math.round((toTime(to) - toTime(from)) / DAY);
}

function isoWeek(time: number): number {
  const date = new Date(time);
  date.setHours(0, 0, 0, 0);
  date.setDate(date.getDate() + 3 - ((date.getDay() + 6) % 7));
  const firstThursday = new Date(date.getFullYear(), 0, 4);
  return 1 + Math.round(((date.getTime() - firstThursday.getTime()) / DAY - 3 + ((firstThursday.getDay() + 6) % 7)) / 7);
}

function shortDate(value: string): string {
  return `${value.slice(8, 10)}/${value.slice(5, 7)}`;
}

interface DragState { id: number; mode: 'move' | 'resize'; startX: number; dx: number; moved: boolean }

export function TimelineView({ visibleIds }: { visibleIds: Set<number> | null }) {
  const { data, insights, perms, openTask, openNewTask, projectId, refresh } = useProject();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [adding, setAdding] = useState(false);
  const [zoomChoice, setZoomChoice] = useState<Zoom | null>(null);
  const [baselineId, setBaselineId] = useState<number | null>(null);
  const [drag, setDrag] = useState<DragState | null>(null);
  const [pendingDates, setPendingDates] = useState<Record<number, { start: string; due: string }>>({});
  const suppressClick = useRef(false);
  const today = todayIso();

  const canEditDates = perms.editTasks && Boolean(insights?.canEditTasks ?? true);
  const canManage = perms.manage;
  const dependencies = insights?.dependencies ?? [];
  const milestones = useMemo(() => (insights?.milestones ?? []).filter((milestone) => isValidIso(milestone.date)), [insights?.milestones]);

  const tasks = useMemo<GanttTask[]>(() => (insights?.gantt ?? [])
    .filter((task) => !visibleIds || visibleIds.has(task.id))
    .map((task) => (pendingDates[task.id] ? { ...task, ...pendingDates[task.id] } : task))
    .filter((task) => isValidIso(task.start) && isValidIso(task.due))
    .sort((a, b) => (a.start === b.start ? a.id - b.id : a.start.localeCompare(b.start))), [insights?.gantt, visibleIds, pendingDates]);

  const baselinesQuery = useQuery({
    queryKey: ['project-baselines', projectId, baselineId],
    queryFn: () => apiGet<BaselinesData>('baselines.php', { project_id: projectId, baseline_id: baselineId ?? undefined }),
  });
  const baselines = baselinesQuery.data?.baselines ?? [];
  const selectedBaseline = baselines.find((item) => item.id === baselinesQuery.data?.selectedId) ?? null;
  const baselineTasks = baselinesQuery.data?.tasks ?? {};

  const range = useMemo(() => {
    const dates = [
      ...tasks.flatMap((task) => [task.start, task.due]),
      ...milestones.map((milestone) => milestone.date),
    ];
    const min = dates.length > 0 ? dates.reduce((a, b) => (a < b ? a : b)) : today;
    const max = dates.length > 0 ? dates.reduce((a, b) => (a > b ? a : b)) : addDays(today, 20);
    // Empieza un lunes con aire a la izquierda para que las semanas queden alineadas.
    let start = addDays(min, -3);
    start = addDays(start, -((new Date(`${start}T00:00:00`).getDay() + 6) % 7));
    let days = diffDays(start, addDays(max, 7)) + 1;
    if (days > MAX_DAYS) days = MAX_DAYS;
    return { start, days };
  }, [tasks, milestones, today]);

  const zoom: Zoom = zoomChoice ?? (range.days > 120 ? 'week' : 'day');
  const dayWidth = zoom === 'week' ? 12 : 32;
  const timelineWidth = range.days * dayWidth;
  const offsetDays = (value: string) => diffDays(range.start, value);
  const rowIndex = useMemo(() => new Map(tasks.map((task, index) => [task.id, index])), [tasks]);

  const months = useMemo(() => {
    const segments: Array<{ label: string; width: number; key: string }> = [];
    let cursor = range.start;
    const end = addDays(range.start, range.days - 1);
    // Tope de seguridad: nunca más segmentos que días (evita un bucle infinito con fechas corruptas).
    while (cursor <= end && segments.length <= range.days) {
      const date = new Date(`${cursor}T00:00:00`);
      const monthEnd = isoFromTime(new Date(date.getFullYear(), date.getMonth() + 1, 0).getTime());
      const segmentEnd = monthEnd < end ? monthEnd : end;
      segments.push({ key: cursor, label: `${MONTHS[date.getMonth()]} ${date.getFullYear()}`, width: (diffDays(cursor, segmentEnd) + 1) * dayWidth });
      cursor = addDays(segmentEnd, 1);
    }
    return segments;
  }, [range, dayWidth]);

  const scale = useMemo(() => {
    if (zoom === 'day') {
      return Array.from({ length: range.days }, (_, index) => {
        const iso = addDays(range.start, index);
        const weekday = (new Date(`${iso}T00:00:00`).getDay() + 6) % 7;
        return { key: iso, width: dayWidth, top: String(Number(iso.slice(8, 10))), bottom: WEEKDAYS[weekday], weekend: weekday >= 5, today: iso === today };
      });
    }
    const weeks = [];
    for (let index = 0; index < range.days; index += 7) {
      const iso = addDays(range.start, index);
      const length = Math.min(7, range.days - index);
      weeks.push({ key: iso, width: length * dayWidth, top: `Sem ${isoWeek(toTime(iso))}`, bottom: formatDate(iso, { day: 'numeric', month: 'short' }), weekend: false, today: false });
    }
    return weeks;
  }, [zoom, range, dayWidth, today]);

  const todayOffset = offsetDays(today);
  const todayInRange = todayOffset >= 0 && todayOffset < range.days;

  const invalidatePlanning = () => {
    refresh.insights();
    refresh.project();
    void queryClient.invalidateQueries({ queryKey: ['calendar'], refetchType: 'none' });
    void queryClient.invalidateQueries({ queryKey: ['tasks'], refetchType: 'none' });
  };

  const reschedule = useMutation({
    mutationFn: ({ taskId, start, due }: { taskId: number; start: string; due: string }) =>
      apiPost<{ success: boolean; message?: string }>('tasks.php', { action: 'reschedule', task_id: taskId, start_date: start, due_date: due }),
    onSuccess: () => toast('Fechas actualizadas.'),
    onError: (error) => toast(errorMessage(error, 'No se pudieron guardar las fechas.'), 'error'),
    onSettled: async (_result, _error, vars) => {
      await queryClient.invalidateQueries({ queryKey: ['project-insights', String(projectId)] });
      invalidatePlanning();
      setPendingDates((prev) => { const next = { ...prev }; delete next[vars.taskId]; return next; });
    },
  });

  // Arrastre: los movimientos se escuchan en window para no perder el puntero fuera de la barra.
  useEffect(() => {
    if (!drag) return;
    const move = (event: PointerEvent) => setDrag((current) => (current ? { ...current, dx: event.clientX - current.startX, moved: current.moved || Math.abs(event.clientX - current.startX) > 3 } : current));
    const finish = (event: PointerEvent) => {
      const current = drag;
      setDrag(null);
      const dx = event.clientX - current.startX;
      if (!current.moved && Math.abs(dx) <= 3) return;
      suppressClick.current = true;
      window.setTimeout(() => { suppressClick.current = false; }, 0);
      const task = tasks.find((item) => item.id === current.id);
      const delta = Math.round(dx / dayWidth);
      if (!task || delta === 0) return;
      let start = task.start;
      let due = task.due;
      if (current.mode === 'resize') {
        due = addDays(task.due, delta);
        if (due < task.start) due = task.start;
      } else {
        start = addDays(task.start, delta);
        due = task.isMilestone ? start : addDays(task.due, delta);
      }
      if (start === task.start && due === task.due) return;
      setPendingDates((prev) => ({ ...prev, [task.id]: { start, due } }));
      reschedule.mutate({ taskId: task.id, start, due });
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', finish);
    window.addEventListener('pointercancel', finish);
    return () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', finish);
      window.removeEventListener('pointercancel', finish);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [drag?.id, drag?.mode, drag?.startX, dayWidth, tasks]);

  const startDrag = (event: ReactPointerEvent, id: number, mode: DragState['mode']) => {
    if (!canEditDates || event.button !== 0) return;
    event.preventDefault();
    event.stopPropagation();
    setDrag({ id, mode, startX: event.clientX, dx: 0, moved: false });
  };

  const handleOpen = (id: number) => {
    if (suppressClick.current) return;
    openTask(id);
  };

  const milestoneCreate = useMutation({
    mutationFn: (body: { title: string; due_date: string }) => apiPost<{ message: string }>('milestones.php', { action: 'create', project_id: projectId, ...body }),
    onSuccess: (result) => { setAdding(false); refresh.insights(); toast(result.message); },
    onError: (error) => toast(errorMessage(error, 'No se pudo crear el hito.'), 'error'),
  });
  const milestoneUpdate = useMutation({
    mutationFn: (body: Record<string, string | number>) => apiPost<{ message: string }>('milestones.php', { project_id: projectId, ...body }),
    onSuccess: (result) => { refresh.insights(); toast(result.message); },
    onError: (error) => toast(errorMessage(error, 'No se pudo actualizar el hito.'), 'error'),
  });

  const saveBaseline = useMutation({
    mutationFn: (name: string) => apiPost<{ baselineId: number; message: string }>('baselines.php', { action: 'save', project_id: projectId, name }),
    onSuccess: (result) => { setBaselineId(result.baselineId); void queryClient.invalidateQueries({ queryKey: ['project-baselines', projectId] }); toast(result.message); },
    onError: (error) => toast(errorMessage(error, 'No se pudo guardar la línea base.'), 'error'),
  });
  const deleteBaseline = useMutation({
    mutationFn: (id: number) => apiPost<{ message: string }>('baselines.php', { action: 'delete', project_id: projectId, baseline_id: id }),
    onSuccess: (result) => { setBaselineId(null); void queryClient.invalidateQueries({ queryKey: ['project-baselines', projectId] }); toast(result.message); },
    onError: (error) => toast(errorMessage(error, 'No se pudo eliminar la línea base.'), 'error'),
  });

  const submitMilestone = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    milestoneCreate.mutate({ title: String(form.get('title') ?? ''), due_date: String(form.get('due_date') ?? '') });
  };

  const submitBaseline = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    saveBaseline.mutate(String(new FormData(form).get('name') ?? ''));
    form.reset();
  };

  const kindOf = (status: string) => data.statuses.find((item) => item.key === status)?.kind ?? 'open';
  const bodyHeight = tasks.length * ROW_HEIGHT;
  const gridBackground = { backgroundImage: 'linear-gradient(to right, rgba(226,232,240,0.55) 1px, transparent 1px)', backgroundSize: `${dayWidth}px 100%` };

  const geometry = (task: GanttTask) => {
    const startOffset = offsetDays(task.start);
    const dueOffset = offsetDays(task.due);
    const duration = Math.max(1, dueOffset - startOffset + 1);
    let left = startOffset * dayWidth + 2;
    let width = Math.max(8, duration * dayWidth - 4);
    if (drag?.id === task.id) {
      if (drag.mode === 'move') left += drag.dx;
      else width = Math.max(8, width + drag.dx);
    }
    return { left, width, startOffset, dueOffset };
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col justify-between gap-3 xl:flex-row xl:items-end">
        <div>
          <h2 className="text-base font-semibold text-slate-900">Cronograma</h2>
          <p className="mt-0.5 text-xs text-slate-500">Planificación por fechas, dependencias, hitos y camino crítico.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex items-center gap-1 rounded-lg bg-slate-100 p-0.5">
            {(['day', 'week'] as Zoom[]).map((option) => (
              <button key={option} type="button" onClick={() => setZoomChoice(option)} className={`rounded-md px-2.5 py-1 text-[11px] font-semibold ${zoom === option ? 'bg-white text-slate-800 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}>{option === 'day' ? 'Día' : 'Semana'}</button>
            ))}
          </div>

          {baselines.length > 0 && (
            <>
              <Select aria-label="Línea base" value={selectedBaseline?.id ?? ''} onChange={(event) => setBaselineId(Number(event.target.value) || null)} className="max-w-[200px] rounded-lg px-2.5 py-1.5 text-[11px]">
                {baselines.map((baseline) => <option key={baseline.id} value={baseline.id}>{baseline.name}</option>)}
              </Select>
              {canManage && selectedBaseline && (
                <button type="button" disabled={deleteBaseline.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar línea base', description: selectedBaseline.name, confirmLabel: 'Eliminar', tone: 'danger' })) deleteBaseline.mutate(selectedBaseline.id); }} className="rounded-lg border border-slate-200 p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar línea base"><Trash2 className="h-3.5 w-3.5" /></button>
              )}
            </>
          )}

          {canManage && (
            <form onSubmit={submitBaseline} className="inline-flex items-center gap-1.5">
              <Input name="name" maxLength={120} placeholder="Nombre de la línea base" className="w-40 rounded-lg px-2.5 py-1.5 text-[11px]" />
              <LoadingButton type="submit" pending={saveBaseline.isPending} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-50"><Camera className="h-3.5 w-3.5" />Guardar línea base</LoadingButton>
            </form>
          )}

          {perms.manageMilestones && !adding && <button type="button" onClick={() => setAdding(true)} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"><Plus className="h-3.5 w-3.5" />Nuevo hito</button>}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
        <span className="inline-flex items-center gap-1.5"><span className="h-2 w-3 rounded-sm bg-brand-500" />Tarea</span>
        <span className="inline-flex items-center gap-1.5"><span className="h-2 w-3 rounded-sm bg-emerald-500" />Completada</span>
        <span className="inline-flex items-center gap-1.5"><span className="h-2 w-3 rounded-sm bg-rose-500" />Camino crítico</span>
        <span className="inline-flex items-center gap-1.5"><span className="inline-block h-2.5 w-2.5 rotate-45 bg-amber-500" />Hito</span>
        {selectedBaseline && <span className="inline-flex items-center gap-1.5"><span className="h-1 w-3 rounded-full bg-slate-300" />Línea base: {selectedBaseline.name}</span>}
        <span className="text-slate-400">· El camino crítico se calcula con las dependencias y la duración de cada tarea.</span>
      </div>

      {adding && (
        <form onSubmit={submitMilestone} className="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200/80 bg-slate-50/60 px-4 py-3">
          <Diamond className="h-4 w-4 fill-brand-500 text-brand-500" />
          <Input name="title" required maxLength={150} autoFocus placeholder="Ej: Entrega del diseño al cliente" className="max-w-xs flex-1" />
          <Input name="due_date" type="date" required defaultValue={today} className="w-40" />
          <button type="submit" disabled={milestoneCreate.isPending} className="rounded-xl bg-brand-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-60">Crear hito</button>
          <button type="button" onClick={() => setAdding(false)} className="rounded-xl px-3 py-2 text-xs text-slate-500 hover:bg-slate-100">Cancelar</button>
        </form>
      )}

      {tasks.length === 0 && milestones.length === 0 ? (
        <Card className="p-12 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><CalendarRange className="h-6 w-6" /></div>
          <h3 className="text-sm font-semibold text-slate-800">{visibleIds ? 'Ninguna tarea con fechas coincide con los filtros' : 'No hay tareas con fechas'}</h3>
          <p className="mb-4 mt-1 text-xs text-slate-500">Añade fecha de inicio y vencimiento a las tareas para verlas en el cronograma.</p>
          {perms.editTasks && <button type="button" onClick={openNewTask} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-brand-700"><Plus className="h-3.5 w-3.5" />Nueva tarea</button>}
        </Card>
      ) : (
        <>
          <Card className="overflow-hidden">
            <div className="overflow-x-auto">
              <div className="flex" style={{ width: LABEL_WIDTH + timelineWidth }}>
                {/* Columna izquierda */}
                <div className="sticky left-0 z-20 flex-shrink-0 border-r border-slate-200 bg-white" style={{ width: LABEL_WIDTH }}>
                  <div className="flex items-end border-b border-slate-200 bg-slate-50/80 px-3 pb-2" style={{ height: MONTH_ROW + SCALE_ROW }}>
                    <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Tarea</span>
                  </div>
                  {milestones.length > 0 && <div className="flex h-10 items-center border-b border-slate-100 px-3 text-[11px] font-semibold text-slate-600">Hitos del proyecto</div>}
                  {tasks.map((task) => (
                    <div key={task.id} className="flex items-center gap-2 border-b border-slate-100 px-3 transition hover:bg-slate-50/60" style={{ height: ROW_HEIGHT }}>
                      {task.parentId && <CornerDownRight className="h-3 w-3 flex-shrink-0 text-slate-300" />}
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-1.5">
                          <button type="button" onClick={() => openTask(task.id)} className="truncate text-left text-[11px] font-semibold text-slate-800 transition hover:text-brand-600" title={task.title}>{task.title}</button>
                          {task.critical ? <span className="flex-shrink-0 rounded border border-rose-200 bg-rose-50 px-1 py-0.5 text-[9px] font-semibold text-rose-600">Crítica</span>
                            : (task.slack ?? 0) > 0 ? <span className="flex-shrink-0 text-[9px] text-slate-400" title="Holgura: días que puede retrasarse sin mover el final del proyecto">+{task.slack}d</span> : null}
                          {task.isMilestone && <Diamond className="h-2.5 w-2.5 flex-shrink-0 fill-amber-500 text-amber-500" />}
                        </div>
                        <p className="mt-0.5 truncate text-[10px] text-slate-400">{shortDate(task.start)} → {shortDate(task.due)}{task.assignee ? ` · ${task.assignee}` : ''}</p>
                      </div>
                    </div>
                  ))}
                </div>

                {/* Línea de tiempo */}
                <div className="relative flex-shrink-0" style={{ width: timelineWidth }}>
                  <div className="flex border-b border-slate-200 bg-slate-50/80" style={{ height: MONTH_ROW }}>
                    {months.map((segment) => <div key={segment.key} className="flex items-center overflow-hidden whitespace-nowrap border-r border-slate-200/70 px-2 text-[10px] font-semibold text-slate-500" style={{ width: segment.width }}>{segment.label}</div>)}
                  </div>
                  <div className="flex border-b border-slate-200 bg-white" style={{ height: SCALE_ROW }}>
                    {scale.map((cell) => (
                      <div key={cell.key} className={`flex flex-col items-center justify-center overflow-hidden border-r border-slate-100 ${cell.weekend ? 'bg-slate-50/70' : ''} ${cell.today ? 'bg-brand-50' : ''}`} style={{ width: cell.width }}>
                        <span className={`whitespace-nowrap text-[9px] font-semibold ${cell.today ? 'text-brand-700' : 'text-slate-500'}`}>{cell.top}</span>
                        <span className="whitespace-nowrap text-[8px] text-slate-400">{cell.bottom}</span>
                      </div>
                    ))}
                  </div>

                  {milestones.length > 0 && (
                    <div className="relative h-10 border-b border-slate-100" style={gridBackground}>
                      {milestones.map((milestone) => (
                        <div key={milestone.id} className="group absolute top-1/2 -translate-x-1/2 -translate-y-1/2" style={{ left: offsetDays(milestone.date) * dayWidth + dayWidth / 2 }} title={`${milestone.title} · ${formatDate(milestone.date)}`}>
                          <Diamond className={`h-4 w-4 ${milestone.done ? 'fill-emerald-500 text-emerald-500' : milestone.date < today ? 'fill-rose-500 text-rose-500' : 'fill-brand-500 text-brand-500'}`} />
                          <span className="pointer-events-none absolute left-1/2 top-full z-20 mt-1 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-navy-950 px-2 py-1 text-[10px] text-white group-hover:block">{milestone.title}</span>
                        </div>
                      ))}
                    </div>
                  )}

                  <div className="relative" style={{ height: bodyHeight }}>
                    {tasks.map((task) => {
                      const { left, width, dueOffset } = geometry(task);
                      const done = kindOf(task.status) === 'done';
                      const late = !done && task.due < today;
                      const barColor = task.critical ? 'bg-rose-500' : done ? 'bg-emerald-500' : 'bg-brand-500';
                      const baseline = baselineTasks[String(task.id)];
                      const dragging = drag?.id === task.id;
                      const title = task.isMilestone ? `${task.title} · ${formatDate(task.due)}` : `${task.title} · ${formatDate(task.start)} → ${formatDate(task.due)}${late ? ' (atrasada)' : ''}`;
                      return (
                        <div key={task.id} className="relative border-b border-slate-100 transition hover:bg-slate-50/40" style={{ height: ROW_HEIGHT, ...gridBackground }}>
                          {baseline && (
                            <div className="absolute h-1.5 rounded-full bg-slate-300/80" style={{ left: offsetDays(baseline.start) * dayWidth + 2, top: 6, width: Math.max(4, (diffDays(baseline.start, baseline.due) + 1) * dayWidth - 4) }} title={`Línea base: ${shortDate(baseline.start)} → ${shortDate(baseline.due)}`} />
                          )}
                          {task.isMilestone ? (
                            <div
                              role="button"
                              tabIndex={0}
                              onPointerDown={(event) => startDrag(event, task.id, 'move')}
                              onClick={() => handleOpen(task.id)}
                              onKeyDown={(event) => { if (event.key === 'Enter') openTask(task.id); }}
                              className={`absolute h-4 w-4 rotate-45 rounded-sm shadow-sm ${task.critical ? 'bg-rose-500' : 'bg-amber-500'} ${canEditDates ? 'cursor-grab' : 'cursor-pointer'} ${dragging ? 'ring-2 ring-white/70' : ''}`}
                              style={{ left: dueOffset * dayWidth + dayWidth / 2 - 8 + (dragging && drag.mode === 'move' ? drag.dx : 0), top: 16 }}
                              title={title}
                            />
                          ) : (
                            <div
                              role="button"
                              tabIndex={0}
                              onPointerDown={(event) => startDrag(event, task.id, 'move')}
                              onClick={() => handleOpen(task.id)}
                              onKeyDown={(event) => { if (event.key === 'Enter') openTask(task.id); }}
                              className={`group absolute select-none rounded-lg text-white shadow-sm ${barColor} ${late && !task.critical ? 'ring-2 ring-rose-300 ring-offset-1' : ''} ${dragging ? 'cursor-grabbing ring-2 ring-white/70' : canEditDates ? 'cursor-grab' : 'cursor-pointer'}`}
                              style={{ left, top: 18, width, height: 18, touchAction: 'none' }}
                              title={title}
                            >
                              {width > 54 && <span className="pointer-events-none absolute inset-y-0 left-2 right-3 flex items-center truncate text-[10px] font-semibold">{task.title}</span>}
                              {canEditDates && <span onPointerDown={(event) => startDrag(event, task.id, 'resize')} className="absolute inset-y-0 right-0 w-2 cursor-ew-resize rounded-r-lg bg-black/10 opacity-0 group-hover:opacity-100" title="Arrastra para cambiar el vencimiento" />}
                            </div>
                          )}
                        </div>
                      );
                    })}

                    {todayInRange && (
                      <div className="pointer-events-none absolute bottom-0 top-0 z-10 w-px bg-rose-400/80" style={{ left: Math.round(todayOffset * dayWidth + dayWidth / 2) }}>
                        <span className="absolute left-0 top-0 -translate-x-1/2 rounded bg-white px-1 text-[9px] font-semibold text-rose-500">Hoy</span>
                      </div>
                    )}

                    {/* Flechas de dependencias */}
                    <svg className="pointer-events-none absolute inset-0" width={timelineWidth} height={bodyHeight} aria-hidden="true">
                      <defs>
                        <marker id="gantt-arrow" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto"><path d="M0,0 L6,3 L0,6 Z" fill="#94a3b8" /></marker>
                        <marker id="gantt-arrow-critical" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto"><path d="M0,0 L6,3 L0,6 Z" fill="#f43f5e" /></marker>
                      </defs>
                      {dependencies.map((dependency) => {
                        const fromIndex = rowIndex.get(dependency.from);
                        const toIndex = rowIndex.get(dependency.to);
                        if (fromIndex === undefined || toIndex === undefined) return null;
                        const from = tasks[fromIndex];
                        const to = tasks[toIndex];
                        const x1 = (offsetDays(from.due) + 1) * dayWidth;
                        const x2 = offsetDays(to.start) * dayWidth + (to.isMilestone ? dayWidth / 2 : 0);
                        const y1 = fromIndex * ROW_HEIGHT + ROW_HEIGHT / 2;
                        const y2 = toIndex * ROW_HEIGHT + ROW_HEIGHT / 2;
                        const critical = Boolean(from.critical && to.critical);
                        const midY = y1 < y2 ? y1 + 10 : y1 - 10;
                        return (
                          <path
                            key={`${dependency.from}-${dependency.to}`}
                            d={`M ${x1} ${y1} H ${x1 + 8} V ${midY} H ${Math.max(x1 + 10, x2 - 8)} V ${y2} H ${x2}`}
                            fill="none"
                            stroke={critical ? '#f43f5e' : '#94a3b8'}
                            strokeWidth="1.2"
                            markerEnd={`url(#${critical ? 'gantt-arrow-critical' : 'gantt-arrow'})`}
                          />
                        );
                      })}
                    </svg>
                  </div>
                </div>
              </div>
            </div>
          </Card>
          {canEditDates && tasks.length > 0 && <p className="text-[10px] text-slate-400">Arrastra una barra para mover la tarea o su borde derecho para cambiar el vencimiento. Haz clic en el título para abrir la tarea.</p>}
        </>
      )}

      {milestones.length > 0 && (
        <Card className="overflow-hidden">
          <div className="border-b border-slate-100 px-5 py-3"><h3 className="text-sm font-semibold text-slate-900">Hitos del proyecto</h3></div>
          <div className="divide-y divide-slate-100">
            {milestones.map((milestone) => (
              <div key={milestone.id} className="group flex items-center gap-3 px-5 py-2.5">
                <button
                  type="button"
                  disabled={!perms.manageMilestones || milestoneUpdate.isPending}
                  onClick={() => milestoneUpdate.mutate({ action: 'toggle', milestone_id: milestone.id, is_done: milestone.done ? 0 : 1 })}
                  className={`flex h-5 w-5 items-center justify-center rounded-full border-2 transition ${milestone.done ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 text-transparent hover:border-emerald-500 hover:text-emerald-500'} disabled:cursor-default`}
                  title={milestone.done ? 'Marcar como pendiente' : 'Marcar como cumplido'}
                >
                  <Check className="h-3 w-3" />
                </button>
                <span className={`min-w-0 flex-1 truncate text-xs font-semibold ${milestone.done ? 'text-slate-400 line-through' : 'text-slate-800'}`}>{milestone.title}</span>
                <span className={`text-[11px] ${!milestone.done && milestone.date < today ? 'font-semibold text-rose-600' : 'text-slate-500'}`}>{formatDate(milestone.date)}</span>
                {perms.manageMilestones && <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar hito', description: milestone.title, confirmLabel: 'Eliminar', tone: 'danger' })) milestoneUpdate.mutate({ action: 'delete', milestone_id: milestone.id }); }} className="rounded p-1 text-slate-300 opacity-0 transition hover:text-rose-600 group-hover:opacity-100" title="Eliminar hito"><Trash2 className="h-3.5 w-3.5" /></button>}
              </div>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}
