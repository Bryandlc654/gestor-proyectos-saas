import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Calendar, CalendarDays, CalendarRange, CalendarX, ChevronLeft, ChevronRight, ExternalLink, Filter, FolderKanban, LayoutList, Plus, Receipt, X } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate, formatMoney } from '../../lib/format';
import type { CalendarData, CalendarEvent } from '../../types';
import { Card, ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { LoadingButton, Modal, useDialog, useToast } from '../../components/feedback';

const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const WEEKDAYS_FULL = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

type ViewKey = 'month' | 'week' | 'agenda';

function ymd(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}
function todayString(): string {
  return ymd(new Date());
}
function monthLabel(month: string): string {
  const [year, value] = month.split('-').map(Number);
  return `${MONTHS[value - 1]} ${year}`;
}
function shiftMonth(month: string, delta: number): string {
  const [year, value] = month.split('-').map(Number);
  const date = new Date(year, value - 1 + delta, 1);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}
function addDays(date: string, days: number): string {
  const parsed = new Date(`${date}T00:00:00`);
  parsed.setDate(parsed.getDate() + days);
  return ymd(parsed);
}
function mondayOf(date: string): string {
  const parsed = new Date(`${date}T00:00:00`);
  const offset = (parsed.getDay() + 6) % 7;
  parsed.setDate(parsed.getDate() - offset);
  return ymd(parsed);
}
function weekDates(start: string): string[] {
  return Array.from({ length: 7 }, (_, index) => addDays(start, index));
}
function longDate(date: string): string {
  const parsed = new Date(`${date}T00:00:00`);
  return `${WEEKDAYS_FULL[(parsed.getDay() + 6) % 7]}, ${parsed.getDate()} de ${MONTHS[parsed.getMonth()].toLowerCase()}`;
}
function shortDate(date: string): string {
  return formatDate(date, { day: '2-digit', month: 'short' });
}
function eventLink(event: CalendarEvent): string {
  if (event.type === 'invoice') return event.href.startsWith('/app') ? event.href : `/app${event.href}`;
  if (event.projectId) return `/app/projects/${event.projectId}`;
  return event.href.startsWith('/app') ? event.href : `/app${event.href}`;
}
function typeLabel(type: string): string {
  return type === 'task' ? 'Tarea' : type === 'project' ? 'Proyecto' : 'Cobro';
}
function eventIcon(type: string) {
  return type === 'task' ? Calendar : type === 'project' ? FolderKanban : Receipt;
}

const CHIP: Record<string, string> = {
  task: 'bg-brand-50 text-brand-700 border-brand-200/70',
  project: 'bg-emerald-50 text-emerald-700 border-emerald-200/70',
  invoice: 'bg-amber-50 text-amber-800 border-amber-200/70',
};
const DOT: Record<string, string> = { task: 'bg-brand-500', project: 'bg-emerald-500', invoice: 'bg-amber-500' };
const ICON_CLASS: Record<string, string> = {
  task: 'bg-brand-50 text-brand-600',
  project: 'bg-emerald-50 text-emerald-600',
  invoice: 'bg-amber-50 text-amber-600',
};

function EventRow({ event, onOpen }: { event: CalendarEvent; onOpen: (event: CalendarEvent) => void }) {
  const Icon = eventIcon(event.type);
  return (
    <button type="button" onClick={() => onOpen(event)} className="flex w-full items-start gap-3 rounded-xl border border-slate-200/80 p-2.5 text-left transition hover:border-slate-300 hover:bg-slate-50">
      <span className={`mt-0.5 inline-flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg ${ICON_CLASS[event.type]}`}><Icon className="h-3.5 w-3.5" /></span>
      <span className="min-w-0 flex-1">
        <span className={`block truncate text-xs font-semibold ${event.done ? 'text-slate-400 line-through' : 'text-slate-800'}`}>{event.title}</span>
        <span className="block truncate text-[11px] text-slate-400">{event.subtitle}</span>
      </span>
      <span className={`flex-shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-semibold ${CHIP[event.type]}`}>{typeLabel(event.type)}</span>
    </button>
  );
}

function DayPanel({
  date,
  events,
  onClose,
  onCreate,
  onOpenEvent,
}: {
  date: string | null;
  events: CalendarEvent[];
  onClose: () => void;
  onCreate: (date: string) => void;
  onOpenEvent: (event: CalendarEvent) => void;
}) {
  const ref = useDialog(date !== null, onClose);
  if (date === null) return null;
  return createPortal(
    <div className="fixed inset-0 z-50 flex justify-end bg-slate-900/40 backdrop-blur-xs" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
      <div ref={ref} role="dialog" aria-modal="true" aria-label={`Eventos del ${longDate(date)}`} className="flex h-full w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-2xl">
        <div className="flex items-start justify-between gap-3 border-b border-slate-100 bg-slate-50/70 px-5 py-4">
          <div>
            <h2 className="text-base font-semibold capitalize text-slate-900">{longDate(date)}</h2>
            <p className="mt-0.5 text-[11px] text-slate-400">{events.length} evento{events.length === 1 ? '' : 's'}</p>
          </div>
          <div className="flex items-center gap-1">
            <button type="button" onClick={() => onCreate(date)} className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1.5 text-[11px] font-semibold text-white transition hover:bg-brand-700"><Plus className="h-3.5 w-3.5" /> Tarea</button>
            <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="Cerrar día"><X className="h-5 w-5" /></button>
          </div>
        </div>
        <div className="flex-1 space-y-2 overflow-y-auto p-4">
          {events.length === 0 && (
            <div className="rounded-2xl border border-dashed border-slate-200 p-8 text-center">
              <CalendarX className="mx-auto h-7 w-7 text-slate-300" />
              <p className="mt-2 text-xs font-semibold text-slate-700">Sin eventos para este día</p>
              <button type="button" onClick={() => onCreate(date)} className="mt-3 inline-flex items-center gap-1 rounded-lg bg-brand-600 px-3 py-1.5 text-[11px] font-semibold text-white"><Plus className="h-3 w-3" /> Crear tarea</button>
            </div>
          )}
          {events.map((event) => <EventRow key={`${event.type}-${event.id}`} event={event} onOpen={onOpenEvent} />)}
        </div>
      </div>
    </div>,
    document.body,
  );
}

function EventModal({
  event,
  onClose,
  onReschedule,
  rescheduling,
}: {
  event: CalendarEvent | null;
  onClose: () => void;
  onReschedule: (date: string) => void;
  rescheduling: boolean;
}) {
  const navigate = useNavigate();
  const [date, setDate] = useState(event?.date ?? todayString());
  useEffect(() => {
    if (event) setDate(event.date);
  }, [event]);
  if (event === null) return null;
  const Icon = eventIcon(event.type);
  return (
    <Modal open onClose={onClose} panelClassName="w-full max-w-sm overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
      <div className="flex items-start justify-between gap-3 border-b border-slate-100 bg-slate-50/70 px-5 py-4">
        <div className="flex items-center gap-2.5">
          <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${ICON_CLASS[event.type]}`}><Icon className="h-4 w-4" /></span>
          <div>
            <span className={`inline-flex rounded-full border px-2 py-0.5 text-[10px] font-semibold ${CHIP[event.type]}`}>{typeLabel(event.type)}</span>
            <p className="mt-1 text-[11px] text-slate-400">{formatDate(event.date)}</p>
          </div>
        </div>
        <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Cerrar"><X className="h-4 w-4" /></button>
      </div>
      <div className="space-y-4 p-5">
        <div>
          <h3 className={`text-sm font-semibold ${event.done ? 'text-slate-400 line-through' : 'text-slate-900'}`}>{event.title}</h3>
          <p className="mt-1 text-xs text-slate-500">{event.subtitle}</p>
          {event.amount !== undefined && <p className="mt-1 text-sm font-bold text-slate-900">{formatMoney(event.amount, event.currency ?? 'USD')}</p>}
        </div>
        {event.type === 'task' && (
          <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
            <label className="mb-1.5 block text-[11px] font-medium text-slate-600">Reprogramar tarea</label>
            <div className="flex items-center gap-2">
              <Input type="date" value={date} onChange={(inputEvent) => setDate(inputEvent.target.value)} className="flex-1 px-2.5 py-1.5 text-xs" />
              <LoadingButton type="button" pending={rescheduling} onClick={() => onReschedule(date)} className="rounded-lg bg-brand-600 px-3 py-1.5 text-[11px] font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Guardar</LoadingButton>
            </div>
          </div>
        )}
        <div className="flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
          <button type="button" onClick={onClose} className="rounded-xl px-3.5 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-100">Cerrar</button>
          <button type="button" onClick={() => { onClose(); navigate(eventLink(event)); }} className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-brand-700"><ExternalLink className="h-3.5 w-3.5" /> Abrir</button>
        </div>
      </div>
    </Modal>
  );
}

function OverdueModal({
  open,
  events,
  onClose,
  onOpenEvent,
}: {
  open: boolean;
  events: CalendarEvent[];
  onClose: () => void;
  onOpenEvent: (event: CalendarEvent) => void;
}) {
  if (!open) return null;
  return (
    <Modal open onClose={onClose} panelClassName="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
      <div className="flex items-center justify-between gap-3 border-b border-slate-100 bg-slate-50/70 px-5 py-4">
        <div className="flex items-center gap-2"><AlertTriangle className="h-4 w-4 text-rose-500" /><div><h3 className="text-sm font-semibold text-slate-900">Pendientes vencidos</h3><p className="text-[11px] text-slate-400">{events.length} eventos requieren atención</p></div></div>
        <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X className="h-4 w-4" /></button>
      </div>
      <div className="flex-1 space-y-2 overflow-y-auto p-4">
        {events.map((event) => <EventRow key={`${event.type}-${event.id}`} event={event} onOpen={onOpenEvent} />)}
      </div>
    </Modal>
  );
}

function CreateTaskModal({
  date,
  projects,
  defaultProjectId,
  onClose,
  onCreated,
}: {
  date: string | null;
  projects: Array<{ id: number; name: string; color: string }>;
  defaultProjectId?: string;
  onClose: () => void;
  onCreated: () => void;
}) {
  const toast = useToast();
  const create = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('tasks.php', { action: 'create', ...body }),
    onSuccess: () => { toast('Tarea creada correctamente.'); onCreated(); onClose(); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo crear la tarea.', 'error'),
  });
  if (date === null) return null;
  return (
    <Modal open onClose={onClose} panelClassName="w-full max-w-md overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
      <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/70 px-5 py-4">
        <div><h3 className="text-sm font-semibold text-slate-900">Nueva tarea</h3><p className="text-[11px] text-slate-400">Para el {formatDate(date)}</p></div>
        <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X className="h-4 w-4" /></button>
      </div>
      <form className="space-y-3 p-5" onSubmit={(event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const body: Record<string, string> = {};
        form.forEach((value, key) => { body[key] = String(value); });
        create.mutate(body);
      }}>
        <div>
          <label className="mb-1.5 block text-xs font-medium text-slate-700">Proyecto *</label>
          <Select name="project_id" required defaultValue={defaultProjectId ?? ''} className="w-full px-3 py-2 text-sm">
            <option value="">Selecciona un proyecto…</option>
            {projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
          </Select>
        </div>
        <div>
          <label className="mb-1.5 block text-xs font-medium text-slate-700">Título *</label>
          <Input name="title" required maxLength={255} placeholder="Ej: Revisar entrega con el cliente" className="px-3 py-2 text-sm" />
        </div>
        <div className="grid grid-cols-2 gap-2">
          <div>
            <label className="mb-1.5 block text-xs font-medium text-slate-700">Prioridad</label>
            <Select name="priority" defaultValue="media" className="w-full px-3 py-2 text-sm"><option value="baja">Baja</option><option value="media">Media</option><option value="alta">Alta</option><option value="urgente">Urgente</option></Select>
          </div>
          <div>
            <label className="mb-1.5 block text-xs font-medium text-slate-700">Fecha</label>
            <Input name="due_date" type="date" defaultValue={date} className="px-3 py-2 text-sm" />
          </div>
        </div>
        <div>
          <label className="mb-1.5 block text-xs font-medium text-slate-700">Descripción</label>
          <textarea name="description" rows={3} placeholder="Detalles opcionales…" className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15" />
        </div>
        {create.isError && <ErrorState message={create.error instanceof Error ? create.error.message : 'No se pudo crear la tarea.'} />}
        <div className="flex justify-end gap-2 border-t border-slate-100 pt-3">
          <button type="button" onClick={onClose} className="rounded-xl px-3.5 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
          <LoadingButton type="submit" pending={create.isPending} pendingLabel="Creando…" className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Crear tarea</LoadingButton>
        </div>
      </form>
    </Modal>
  );
}

export function CalendarPage() {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [month, setMonth] = useState(() => todayString().slice(0, 7));
  const [weekStart, setWeekStart] = useState(() => mondayOf(todayString()));
  const [view, setView] = useState<ViewKey>(() => (typeof window !== 'undefined' && window.innerWidth < 768 ? 'agenda' : 'month'));
  const [type, setType] = useState('all');
  const [projectId, setProjectId] = useState('');
  const [mine, setMine] = useState(false);
  const [showDone, setShowDone] = useState(false);
  const [showFilters, setShowFilters] = useState(false);
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const [selectedEvent, setSelectedEvent] = useState<CalendarEvent | null>(null);
  const [createDate, setCreateDate] = useState<string | null>(null);
  const [overdueOpen, setOverdueOpen] = useState(false);
  const [draggingTaskId, setDraggingTaskId] = useState<number | null>(null);
  const [dragOverDate, setDragOverDate] = useState<string | null>(null);

  const { data, isLoading, error } = useQuery({
    queryKey: ['calendar', view, month, weekStart, projectId, mine, showDone],
    queryFn: () => apiGet<CalendarData>('calendar.php', {
      view,
      month,
      start: view === 'week' ? weekStart : undefined,
      type: 'all',
      project_id: projectId,
      mine: mine ? 1 : undefined,
      show_done: showDone ? 1 : undefined,
    }),
  });

  const allEvents = data?.events ?? [];
  const filteredEvents = useMemo(() => allEvents.filter((event) => type === 'all' || event.type === type), [allEvents, type]);
  const eventsByDate = useMemo(() => {
    const map: Record<string, CalendarEvent[]> = {};
    filteredEvents.forEach((event) => { (map[event.date] ??= []).push(event); });
    return map;
  }, [filteredEvents]);
  const typeCounts = useMemo(() => allEvents.reduce<Record<string, number>>((counts, event) => {
    counts[event.type] = (counts[event.type] ?? 0) + 1;
    return counts;
  }, { task: 0, project: 0, invoice: 0 }), [allEvents]);
  const agenda = useMemo(() => Object.entries(eventsByDate).sort(([a], [b]) => a.localeCompare(b)), [eventsByDate]);
  const overdue = data?.overdue ?? [];
  const filteredOverdue = useMemo(() => overdue.filter((event) => type === 'all' || event.type === type), [overdue, type]);
  const overdueTaskCount = filteredOverdue.filter((event) => event.type === 'task').length;
  const overdueInvoiceCount = filteredOverdue.filter((event) => event.type === 'invoice').length;
  const overdueProjectCount = filteredOverdue.filter((event) => event.type === 'project').length;
  const filtersActive = projectId !== '' || mine || showDone || type !== 'all';

  const reschedule = useMutation({
    mutationFn: ({ taskId, dueDate }: { taskId: number; dueDate: string; fromDate: string }) => apiPost<{ success: boolean }>('tasks.php', { action: 'reschedule', task_id: taskId, due_date: dueDate }),
    onSuccess: (_result, variables) => {
      void queryClient.invalidateQueries({ queryKey: ['calendar'] });
      void queryClient.invalidateQueries({ queryKey: ['tasks'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
      toast('Tarea reprogramada.', 'success', {
        action: {
          label: 'Deshacer',
          onClick: () => reschedule.mutate({ taskId: variables.taskId, dueDate: variables.fromDate, fromDate: variables.dueDate }),
        },
      });
    },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo reprogramar la tarea.', 'error'),
  });

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['calendar'] });
    void queryClient.invalidateQueries({ queryKey: ['tasks'] });
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
  };

  const shift = (delta: number) => {
    if (view === 'week') {
      const next = addDays(weekStart, delta * 7);
      setWeekStart(next);
      setMonth(next.slice(0, 7));
    } else {
      setMonth(shiftMonth(month, delta));
    }
  };
  const goToday = () => {
    setMonth(todayString().slice(0, 7));
    setWeekStart(mondayOf(todayString()));
  };
  const onDropDate = (date: string) => {
    setDragOverDate(null);
    if (draggingTaskId === null) return;
    const event = allEvents.find((item) => item.type === 'task' && item.id === draggingTaskId);
    setDraggingTaskId(null);
    if (!event || event.date === date) return;
    reschedule.mutate({ taskId: event.id, dueDate: date, fromDate: event.date });
  };

  const headerLabel = view === 'week'
    ? `${shortDate(weekStart)} – ${shortDate(addDays(weekStart, 6))}`
    : monthLabel(month);
  const days = view === 'week' ? weekDates(weekStart) : (data?.dates ?? []);
  const stripAnchor = month === todayString().slice(0, 7) ? todayString() : `${month}-01`;
  const weekStrip = weekDates(mondayOf(stripAnchor));

  const eventChip = (event: CalendarEvent) => {
    const isDraggable = event.type === 'task' && !event.done;
    return (
      <button
        key={`${event.type}-${event.id}`}
        type="button"
        draggable={isDraggable}
        onDragStart={(dragEvent) => {
          if (!isDraggable) return;
          dragEvent.dataTransfer.effectAllowed = 'move';
          dragEvent.dataTransfer.setData('text/plain', String(event.id));
          setDraggingTaskId(event.id);
        }}
        onDragEnd={() => { setDraggingTaskId(null); setDragOverDate(null); }}
        onClick={(clickEvent) => { clickEvent.stopPropagation(); setSelectedEvent(event); }}
        className={`block w-full truncate rounded-md border px-1.5 py-1 text-left text-[10px] font-medium leading-tight transition hover:brightness-95 ${event.done ? 'border-slate-200 bg-slate-50 text-slate-400' : CHIP[event.type]}`}
        title={`${event.title} · ${event.subtitle}`}
      >
        <span className={`mr-1 inline-block h-1.5 w-1.5 rounded-full align-middle ${event.done ? 'bg-slate-300' : DOT[event.type]}`} /><span className="align-middle">{event.title}</span>
      </button>
    );
  };

  const dayCell = (date: string, inMonth: boolean) => {
    const dayEvents = eventsByDate[date] ?? [];
    const isToday = date === data?.today;
    const isDragTarget = dragOverDate === date && draggingTaskId !== null;
    return (
      <div
        key={date}
        onClick={() => setSelectedDate(date)}
        onDragOver={(event) => { if (draggingTaskId === null) return; event.preventDefault(); setDragOverDate(date); }}
        onDragLeave={() => setDragOverDate((current) => current === date ? null : current)}
        onDrop={(event) => { event.preventDefault(); event.stopPropagation(); onDropDate(date); }}
        className={`min-h-[64px] cursor-pointer border-b border-r border-slate-100 p-1.5 transition sm:min-h-[116px] ${inMonth ? 'bg-white hover:bg-slate-50/60' : 'bg-slate-50/70'} ${isDragTarget ? 'bg-brand-50 ring-2 ring-inset ring-brand-300' : ''}`}
      >
        <div className="mb-1 flex items-center justify-between">
          <span className={`inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1 text-[11px] font-semibold ${isToday ? 'bg-brand-600 text-white' : inMonth ? 'text-slate-600' : 'text-slate-400'}`}>{Number(date.slice(-2))}</span>
          <div className="flex items-center gap-1">
            {dayEvents.length > 3 && <span className="hidden text-[10px] font-medium text-slate-400 sm:inline">+{dayEvents.length - 3}</span>}
            <button type="button" onClick={(event) => { event.stopPropagation(); setCreateDate(date); }} className="hidden h-5 w-5 items-center justify-center rounded-md text-slate-300 transition hover:bg-white hover:text-brand-600 sm:flex" title="Nueva tarea"><Plus className="h-3 w-3" /></button>
          </div>
        </div>
        <div className="space-y-1">
          <div className="flex flex-wrap gap-1 sm:hidden">
            {dayEvents.slice(0, 4).map((event) => <span key={`${event.type}-${event.id}`} className={`h-1.5 w-1.5 rounded-full ${DOT[event.type]}`} />)}
          </div>
          <div className="hidden space-y-1 sm:block">{dayEvents.slice(0, 3).map(eventChip)}</div>
        </div>
      </div>
    );
  };

  return (
    <div className="space-y-5">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Calendario</h1>
          <p className="text-[11px] text-slate-400">{headerLabel} · {filteredEvents.length} evento{filteredEvents.length === 1 ? '' : 's'}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex items-center rounded-xl bg-slate-100 p-0.5">
            {([
              ['month', 'Mes', CalendarDays],
              ['week', 'Semana', CalendarRange],
              ['agenda', 'Agenda', LayoutList],
            ] as const).map(([key, label, Icon]) => (
              <button key={key} type="button" onClick={() => setView(key)} className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ${view === key ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}><Icon className="h-3.5 w-3.5" /><span className="hidden sm:inline">{label}</span></button>
            ))}
          </div>
          <button type="button" onClick={goToday} className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50"><Calendar className="h-3.5 w-3.5" /> Hoy</button>
        </div>
      </div>

      <Card className="space-y-3 p-3 sm:p-4">
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex items-center gap-1">
            <button type="button" onClick={() => shift(-1)} className="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" title={view === 'week' ? 'Semana anterior' : 'Mes anterior'}><ChevronLeft className="h-4 w-4" /></button>
            <span className="min-w-[120px] text-center text-sm font-semibold text-slate-900">{headerLabel}</span>
            <button type="button" onClick={() => shift(1)} className="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" title={view === 'week' ? 'Semana siguiente' : 'Mes siguiente'}><ChevronRight className="h-4 w-4" /></button>
          </div>
          <div className="ml-auto flex flex-wrap items-center gap-1.5">
            {[
              ['all', 'Todo', filteredEvents.length],
              ['tasks', 'Tareas', typeCounts.task],
              ['projects', 'Proyectos', typeCounts.project],
              ['invoices', 'Cobros', typeCounts.invoice],
            ].map(([key, label, count]) => (
              <button key={String(key)} type="button" onClick={() => setType(String(key))} className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold transition ${type === key ? 'border-brand-200 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'}`}>
                {label}<span className={`rounded-full px-1 text-[10px] font-bold ${type === key ? 'bg-white/80 text-brand-700' : 'bg-slate-100 text-slate-500'}`}>{count}</span>
              </button>
            ))}
            <button type="button" onClick={() => setMine((current) => !current)} className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold transition ${mine ? 'border-brand-200 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'}`}>Mis tareas</button>
            <button type="button" onClick={() => setShowDone((current) => !current)} className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold transition ${showDone ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'}`}>{showDone ? 'Ocultar completadas' : 'Completadas'}</button>
            <button type="button" onClick={() => setShowFilters((current) => !current)} className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold transition ${showFilters || projectId !== '' ? 'border-brand-200 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'}`}><Filter className="h-3 w-3" /><span className="hidden sm:inline">Filtros</span>{projectId !== '' && <span className="h-1.5 w-1.5 rounded-full bg-brand-600" />}</button>
            {filtersActive && <button type="button" onClick={() => { setProjectId(''); setMine(false); setShowDone(false); setType('all'); }} className="rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title="Limpiar filtros"><X className="h-3.5 w-3.5" /></button>}
          </div>
        </div>
        {showFilters && (
          <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
            <Select value={projectId} onChange={(event) => setProjectId(event.target.value)} className="max-w-[240px] px-3 py-1.5 text-xs"><option value="">Todos los proyectos</option>{data?.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}</Select>
            <span className="text-[11px] text-slate-400">Los cobros muestran solo facturas pendientes.</span>
          </div>
        )}
      </Card>

      {(overdueTaskCount > 0 || overdueInvoiceCount > 0 || overdueProjectCount > 0) && (
        <button type="button" onClick={() => setOverdueOpen(true)} className="flex w-full flex-wrap items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-left text-xs text-rose-800 transition hover:bg-rose-100">
          <AlertTriangle className="h-4 w-4 flex-shrink-0 text-rose-600" />
          <span className="font-semibold">Pendientes vencidos:</span>
          {overdueTaskCount > 0 && <span>{overdueTaskCount} tarea{overdueTaskCount === 1 ? '' : 's'}</span>}
          {overdueInvoiceCount > 0 && <span>· {overdueInvoiceCount} cobro{overdueInvoiceCount === 1 ? '' : 's'}</span>}
          {overdueProjectCount > 0 && <span>· {overdueProjectCount} proyecto{overdueProjectCount === 1 ? '' : 's'}</span>}
          <span className="ml-auto font-semibold underline underline-offset-2">Ver</span>
        </button>
      )}

      {isLoading ? (
        <Skeleton className="h-[620px] rounded-2xl" />
      ) : error ? (
        <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar el calendario.'} />
      ) : data ? (
        <>
          {view === 'month' && (
            <Card className="overflow-hidden">
              <div className="grid grid-cols-7 border-b border-slate-200/70 bg-slate-50/80">{WEEKDAYS.map((weekday) => <div key={weekday} className="px-2 py-2 text-center text-[9px] font-semibold uppercase tracking-wide text-slate-400 sm:py-2.5 sm:text-[10px]">{weekday}</div>)}</div>
              <div className="grid grid-cols-7">{days.map((date) => dayCell(date, date.startsWith(data.month)))}</div>
            </Card>
          )}

          {view === 'week' && (
            <Card className="overflow-x-auto">
              <div className="grid min-w-[760px] grid-cols-7">
                {days.map((date) => {
                  const dayEvents = eventsByDate[date] ?? [];
                  const isToday = date === data.today;
                  return (
                    <div key={date} onDragOver={(event) => { if (draggingTaskId === null) return; event.preventDefault(); setDragOverDate(date); }} onDragLeave={() => setDragOverDate((current) => current === date ? null : current)} onDrop={(event) => { event.preventDefault(); onDropDate(date); }} className={`min-h-[360px] border-r border-slate-100 last:border-r-0 ${dragOverDate === date && draggingTaskId !== null ? 'bg-brand-50' : ''}`}>
                      <button type="button" onClick={() => setSelectedDate(date)} className={`flex w-full flex-col items-center border-b border-slate-100 px-1 py-2 transition hover:bg-slate-50 ${isToday ? 'bg-brand-50/60' : ''}`}>
                        <span className="text-[9px] font-semibold uppercase text-slate-400">{WEEKDAYS[(new Date(`${date}T00:00:00`).getDay() + 6) % 7]}</span>
                        <span className={`mt-0.5 flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold ${isToday ? 'bg-brand-600 text-white' : 'text-slate-700'}`}>{Number(date.slice(-2))}</span>
                      </button>
                      <div className="space-y-1 p-1.5">
                        {dayEvents.length === 0 && <p className="py-6 text-center text-[10px] text-slate-300">Sin eventos</p>}
                        {dayEvents.map(eventChip)}
                        <button type="button" onClick={() => setCreateDate(date)} className="flex w-full items-center justify-center gap-1 rounded-md border border-dashed border-slate-200 py-1 text-[10px] font-medium text-slate-400 transition hover:border-brand-300 hover:text-brand-600"><Plus className="h-3 w-3" /> Tarea</button>
                      </div>
                    </div>
                  );
                })}
              </div>
            </Card>
          )}

          {view === 'agenda' && (
            <div className="space-y-3">
              <div className="grid grid-cols-7 gap-1 rounded-2xl border border-slate-200 bg-white p-1.5 md:hidden">
                {weekStrip.map((date) => {
                  const count = (eventsByDate[date] ?? []).length;
                  const active = date === data.today;
                  return (
                    <button key={date} type="button" onClick={() => setSelectedDate(date)} className={`flex flex-col items-center rounded-xl px-1 py-2 text-[10px] font-semibold transition ${active ? 'bg-brand-600 text-white' : 'text-slate-500 hover:bg-slate-50'}`}>
                      <span className="uppercase">{WEEKDAYS[(new Date(`${date}T00:00:00`).getDay() + 6) % 7]}</span>
                      <span className="mt-0.5 text-sm font-bold">{Number(date.slice(-2))}</span>
                      <span className={`mt-0.5 h-1.5 w-1.5 rounded-full ${count > 0 ? (active ? 'bg-white' : 'bg-brand-500') : 'bg-transparent'}`} />
                    </button>
                  );
                })}
              </div>

              <Card className="overflow-hidden">
                <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
                  <div><h2 className="text-sm font-semibold text-slate-800">Agenda de {monthLabel(month)}</h2><p className="mt-0.5 text-[11px] text-slate-400">Tareas, proyectos y cobros ordenados por fecha.</p></div>
                  <span className="text-[11px] text-slate-400">{filteredEvents.length} en total</span>
                </div>
                {agenda.length === 0 ? (
                  <div className="p-12 text-center"><div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><CalendarX className="h-6 w-6" /></div><h3 className="text-sm font-semibold text-slate-800">Sin eventos este mes</h3><p className="mt-1 text-xs text-slate-500">Cambia de mes o ajusta los filtros para ver otras fechas.</p></div>
                ) : (
                  <div className="divide-y divide-slate-100">
                    {agenda.map(([date, dayEvents]) => (
                      <div key={date} className="p-4 sm:px-5">
                        <div className="mb-2 flex items-center justify-between gap-2">
                          <div className="flex items-center gap-2"><span className="inline-flex h-7 min-w-7 items-center justify-center rounded-full bg-slate-100 text-[11px] font-bold text-slate-600">{Number(date.slice(-2))}</span><p className="text-xs font-semibold capitalize text-slate-700">{longDate(date)}</p></div>
                          <button type="button" onClick={() => setCreateDate(date)} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[10px] font-semibold text-slate-400 transition hover:bg-brand-50 hover:text-brand-600"><Plus className="h-3 w-3" /> Tarea</button>
                        </div>
                        <div className="space-y-2">{dayEvents.map((event) => <EventRow key={`${event.type}-${event.id}`} event={event} onOpen={setSelectedEvent} />)}</div>
                      </div>
                    ))}
                  </div>
                )}
              </Card>
            </div>
          )}
        </>
      ) : null}

      <DayPanel date={selectedDate} events={selectedDate ? (eventsByDate[selectedDate] ?? []) : []} onClose={() => setSelectedDate(null)} onCreate={(date) => { setSelectedDate(null); setCreateDate(date); }} onOpenEvent={(event) => { setSelectedDate(null); setSelectedEvent(event); }} />
      <EventModal event={selectedEvent} onClose={() => setSelectedEvent(null)} onReschedule={(date) => { if (!selectedEvent) return; reschedule.mutate({ taskId: selectedEvent.id, dueDate: date, fromDate: selectedEvent.date }); setSelectedEvent(null); }} rescheduling={reschedule.isPending} />
      <OverdueModal open={overdueOpen} events={filteredOverdue} onClose={() => setOverdueOpen(false)} onOpenEvent={(event) => { setOverdueOpen(false); setSelectedEvent(event); }} />
      <CreateTaskModal date={createDate} projects={data?.projects ?? []} defaultProjectId={projectId} onClose={() => setCreateDate(null)} onCreated={refresh} />
    </div>
  );
}
