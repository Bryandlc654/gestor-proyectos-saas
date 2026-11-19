import { useMutation } from '@tanstack/react-query';
import { CalendarRange, Check, CheckSquare, Columns3, GanttChartSquare, List, ListChecks, MessageSquare, Plus, Search, Trash2, X } from 'lucide-react';
import { lazy, Suspense, useEffect, useMemo, useRef, useState, type DragEvent, type FormEvent, type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useConfirm, useToast } from '../../../components/feedback';
import { Select, Skeleton } from '../../../components/ui';
import { apiPost, apiPostRoot } from '../../../lib/api';
import { formatDate, isOverdue } from '../../../lib/format';
import { ensureOk, errorMessage, KIND_DOT, PriorityBadge, SegmentedControl, todayIso, useProject, type ProjectTask, type TaskView } from './shared';

const TimelineView = lazy(() => import('./TimelineView').then((m) => ({ default: m.TimelineView })));
const ChecklistsView = lazy(() => import('./ChecklistsView').then((m) => ({ default: m.ChecklistsView })));

type QuickFilter = 'todas' | 'mias' | 'vencidas' | 'sin_asignar';
const QUICK_FILTERS: Array<{ value: QuickFilter; label: string }> = [
  { value: 'todas', label: 'Todas' },
  { value: 'mias', label: 'Mis tareas' },
  { value: 'vencidas', label: 'Vencidas' },
  { value: 'sin_asignar', label: 'Sin asignar' },
];

export function TasksTab() {
  const { data, clientView, currentUserId, doneKeys } = useProject();
  const [searchParams, setSearchParams] = useSearchParams();
  const [search, setSearch] = useState('');
  const [assignee, setAssignee] = useState('');
  const [priority, setPriority] = useState('');

  const views: Array<{ value: TaskView; label: string; icon: ReactNode }> = [
    { value: 'tablero', label: 'Tablero', icon: <Columns3 className="h-3.5 w-3.5" /> },
    { value: 'lista', label: 'Lista', icon: <List className="h-3.5 w-3.5" /> },
    { value: 'cronograma', label: 'Cronograma', icon: <GanttChartSquare className="h-3.5 w-3.5" /> },
    ...(!clientView ? [{ value: 'checklists' as TaskView, label: 'Checklists', icon: <ListChecks className="h-3.5 w-3.5" /> }] : []),
  ];
  const rawView = searchParams.get('vista') as TaskView | null;
  const view: TaskView = views.some((item) => item.value === rawView) ? rawView! : 'tablero';
  const rawFilter = searchParams.get('filtro') as QuickFilter | null;
  const quick: QuickFilter = QUICK_FILTERS.some((item) => item.value === rawFilter) ? rawFilter! : 'todas';

  const setParam = (key: string, value: string, fallback: string) => {
    const next = new URLSearchParams(searchParams);
    if (value === fallback) next.delete(key); else next.set(key, value);
    setSearchParams(next, { replace: true });
  };

  const today = todayIso();
  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();
    return data.tasks.filter((task) => {
      if (term && !`${task.title} ${task.description ?? ''} ${task.assignee ?? ''}`.toLowerCase().includes(term)) return false;
      if (assignee && String(task.assignedUserId ?? '') !== assignee) return false;
      if (priority && task.priority !== priority) return false;
      if (quick === 'mias' && task.assignedUserId !== currentUserId) return false;
      if (quick === 'sin_asignar' && task.assignedUserId !== null) return false;
      if (quick === 'vencidas' && !(task.dueDate !== null && task.dueDate < today && !doneKeys.includes(task.status))) return false;
      return true;
    });
  }, [data.tasks, search, assignee, priority, quick, currentUserId, today, doneKeys]);

  const filtersActive = search !== '' || assignee !== '' || priority !== '' || quick !== 'todas';
  const showFilters = view === 'tablero' || view === 'lista' || view === 'cronograma';

  return (
    <section className="space-y-4">
      <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
        <SegmentedControl value={view} options={views} onChange={(value) => setParam('vista', value, 'tablero')} />
        {showFilters && (
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
              <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar tarea..." aria-label="Buscar tarea" className="w-44 rounded-xl border border-slate-200 bg-white py-1.5 pl-8 pr-3 text-xs text-slate-700 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15" />
            </div>
            <div className="flex items-center gap-1 rounded-xl bg-slate-100 p-0.5">
              {QUICK_FILTERS.filter((item) => !(clientView && item.value === 'mias')).map((item) => (
                <button key={item.value} type="button" onClick={() => setParam('filtro', item.value, 'todas')} className={`rounded-lg px-2.5 py-1 text-xs font-semibold transition ${quick === item.value ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}>{item.label}</button>
              ))}
            </div>
            <Select value={assignee} onChange={(event) => setAssignee(event.target.value)} aria-label="Filtrar por responsable" className="py-1.5">
              <option value="">Responsable</option>
              {data.members.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}
            </Select>
            <Select value={priority} onChange={(event) => setPriority(event.target.value)} aria-label="Filtrar por prioridad" className="py-1.5">
              <option value="">Prioridad</option>
              <option value="urgente">Urgente</option><option value="alta">Alta</option><option value="media">Media</option><option value="baja">Baja</option>
            </Select>
            {filtersActive && <button type="button" onClick={() => { setSearch(''); setAssignee(''); setPriority(''); setParam('filtro', 'todas', 'todas'); }} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-slate-500 hover:bg-slate-100"><X className="h-3.5 w-3.5" />Limpiar</button>}
          </div>
        )}
      </div>

      {showFilters && filtersActive && <p className="text-xs text-slate-500">Mostrando {filtered.length} de {data.tasks.length} tareas</p>}

      {view === 'tablero' && <BoardView tasks={filtered} />}
      {view === 'lista' && <ListView tasks={filtered} />}
      <Suspense fallback={<Skeleton className="h-64" />}>
        {view === 'cronograma' && <TimelineView visibleIds={filtersActive ? new Set(filtered.map((task) => task.id)) : null} />}
        {view === 'checklists' && <ChecklistsView />}
      </Suspense>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Creación rápida: título + Enter                                       */
/* ------------------------------------------------------------------ */
function useQuickCreate() {
  const { projectId, refresh } = useProject();
  const toast = useToast();
  return useMutation({
    mutationFn: ({ title, status }: { title: string; status?: string }) => apiPost<{ success: boolean }>('tasks.php', { action: 'create', project_id: projectId, title, status }),
    onSuccess: () => { refresh.project(); refresh.insights(); },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo crear la tarea.', 'error'),
  });
}

function QuickAdd({ status, placeholder = 'Añadir tarea y pulsar Enter', autoFocus = false, onDone }: { status?: string; placeholder?: string; autoFocus?: boolean; onDone?: () => void }) {
  const create = useQuickCreate();
  const [title, setTitle] = useState('');
  const submit = (event: FormEvent) => {
    event.preventDefault();
    const value = title.trim();
    if (!value) return;
    create.mutate({ title: value, status }, { onSuccess: () => setTitle('') });
  };
  return (
    <form onSubmit={submit} className="flex items-center gap-2">
      <Plus className="h-3.5 w-3.5 flex-shrink-0 text-slate-400" />
      <input
        value={title}
        onChange={(event) => setTitle(event.target.value)}
        onKeyDown={(event) => { if (event.key === 'Escape') { setTitle(''); onDone?.(); } }}
        onBlur={() => { if (!title.trim()) onDone?.(); }}
        autoFocus={autoFocus}
        disabled={create.isPending}
        maxLength={255}
        placeholder={placeholder}
        aria-label="Título de la nueva tarea"
        className="w-full bg-transparent py-1 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none disabled:opacity-60"
      />
    </form>
  );
}

function useDeleteTask() {
  const { refresh } = useProject();
  const toast = useToast();
  const confirm = useConfirm();
  const mutation = useMutation({
    mutationFn: (taskId: number) => apiPost<{ success: boolean }>('tasks.php', { action: 'bulk_delete', task_ids: JSON.stringify([taskId]) }),
    onSuccess: () => { refresh.project(); refresh.insights(); toast('Tarea eliminada.'); },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo eliminar.', 'error'),
  });
  return async (task: ProjectTask) => {
    if (await confirm({ title: 'Eliminar tarea', description: task.title, confirmLabel: 'Eliminar', tone: 'danger' })) mutation.mutate(task.id);
  };
}

/* ------------------------------------------------------------------ */
/* Tablero con arrastrar y soltar                                       */
/* ------------------------------------------------------------------ */
function BoardView({ tasks }: { tasks: ProjectTask[] }) {
  const { projectId, data, perms, clientView, doneKeys, openTask, setTaskStatus, refresh } = useProject();
  const toast = useToast();
  const deleteTask = useDeleteTask();
  const [dragging, setDragging] = useState<number | null>(null);
  const [over, setOver] = useState<string | null>(null);
  const [overTask, setOverTask] = useState<number | null>(null);
  const [adding, setAdding] = useState<string | null>(null);
  const canDrag = perms.changeStatus;

  // Orden manual de cada columna (sort_order), igual que el tablero clásico.
  const byOrder = (a: ProjectTask, b: ProjectTask) => (a.sortOrder ?? 0) - (b.sortOrder ?? 0) || a.id - b.id;

  const reorder = useMutation({
    mutationFn: ({ taskId, status, orderedIds }: { taskId: number; status: string; orderedIds: number[] }) =>
      ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/tasks.php', { action: 'reorder', project_id: projectId, task_id: taskId, status, ordered_ids: JSON.stringify(orderedIds) })),
    onError: (error) => toast(errorMessage(error, 'No se pudo reordenar la tarea.'), 'error'),
    onSettled: () => { refresh.project(); refresh.insights(); },
  });

  const onDrop = (event: DragEvent, status: string, targetTask?: ProjectTask) => {
    event.preventDefault();
    event.stopPropagation();
    const taskId = Number(event.dataTransfer.getData('text/plain')) || dragging;
    setDragging(null);
    setOver(null);
    setOverTask(null);
    if (!taskId) return;
    const dragged = data.tasks.find((task) => task.id === taskId);
    if (!dragged) return;
    if (targetTask && targetTask.id !== taskId) {
      const ordered = data.tasks.filter((task) => task.status === status && task.id !== taskId).sort(byOrder).map((task) => task.id);
      const index = ordered.indexOf(targetTask.id);
      if (index >= 0) ordered.splice(index, 0, taskId); else ordered.push(taskId);
      reorder.mutate({ taskId, status, orderedIds: ordered });
      return;
    }
    if (dragged.status !== status) setTaskStatus(taskId, status);
  };

  return (
    <div className="flex items-start gap-4 overflow-x-auto pb-4">
      {data.statuses.map((status) => {
        const column = tasks.filter((task) => task.status === status.key).sort(byOrder);
        return (
          <div key={status.key} className="w-[290px] flex-shrink-0 space-y-2.5">
            <div className="flex items-center justify-between px-1">
              <div className="flex items-center gap-2"><span className={`h-2.5 w-2.5 rounded-full ${KIND_DOT[status.kind] ?? 'bg-slate-400'}`} /><h3 className="text-xs font-semibold text-slate-800 sm:text-sm">{status.label}</h3><span className="text-xs font-medium tabular-nums text-slate-400">{column.length}</span></div>
              {perms.editTasks && <button type="button" onClick={() => setAdding(status.key)} className="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title={`Añadir tarea en ${status.label}`}><Plus className="h-4 w-4" /></button>}
            </div>
            <div
              onDragOver={(event) => { if (dragging === null) return; event.preventDefault(); event.dataTransfer.dropEffect = 'move'; if (over !== status.key) setOver(status.key); }}
              onDragLeave={(event) => { if (!event.currentTarget.contains(event.relatedTarget as Node)) setOver((current) => (current === status.key ? null : current)); }}
              onDrop={(event) => onDrop(event, status.key)}
              className={`min-h-[150px] space-y-2 rounded-2xl p-2 transition-colors ${over === status.key ? 'bg-brand-50/70 ring-2 ring-brand-300' : 'bg-slate-100/50 ring-1 ring-inset ring-slate-200/50'}`}
            >
              {column.length === 0 && adding !== status.key && <p className="py-8 text-center text-[11px] text-slate-400">{canDrag ? 'Arrastra tareas aquí' : 'Sin tareas'}</p>}
              {column.map((task) => {
                const done = doneKeys.includes(task.status);
                return (
                  <article
                    key={task.id}
                    draggable={canDrag}
                    onDragStart={(event) => { event.dataTransfer.setData('text/plain', String(task.id)); event.dataTransfer.effectAllowed = 'move'; setDragging(task.id); }}
                    onDragEnd={() => { setDragging(null); setOver(null); setOverTask(null); }}
                    onDragOver={(event) => { if (dragging === null || dragging === task.id) return; event.preventDefault(); event.stopPropagation(); event.dataTransfer.dropEffect = 'move'; if (over !== status.key) setOver(status.key); if (overTask !== task.id) setOverTask(task.id); }}
                    onDrop={(event) => onDrop(event, status.key, task)}
                    onClick={() => openTask(task.id)}
                    className={`group cursor-pointer rounded-xl border bg-white p-3.5 shadow-2xs transition hover:border-slate-300 hover:shadow-sm ${overTask === task.id ? 'border-brand-400 ring-2 ring-brand-200' : 'border-slate-200/80'} ${dragging === task.id ? 'opacity-40' : ''} ${canDrag ? 'active:cursor-grabbing' : ''}`}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <h4 className={`text-xs font-semibold leading-snug sm:text-[13px] ${done ? 'text-slate-400 line-through' : 'text-slate-900 group-hover:text-brand-700'}`}>{task.title}</h4>
                      {perms.deleteTasks && <button type="button" onClick={(event) => { event.stopPropagation(); void deleteTask(task); }} className="-mr-1 -mt-1 rounded p-1 text-slate-300 opacity-0 transition hover:text-rose-600 group-hover:opacity-100" title="Eliminar tarea"><Trash2 className="h-3.5 w-3.5" /></button>}
                    </div>
                    {task.description && <p className="mt-1 line-clamp-2 text-[11px] leading-relaxed text-slate-500">{task.description}</p>}
                    <div className="mt-2.5 flex items-center justify-between gap-2">
                      <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                        <PriorityBadge priority={task.priority} />
                        {task.dueDate && <span className={`inline-flex items-center gap-1 whitespace-nowrap text-[10px] ${!done && isOverdue(task.dueDate) ? 'font-semibold text-rose-600' : 'text-slate-400'}`}><CalendarRange className="h-3 w-3" />{formatDate(task.dueDate, { day: '2-digit', month: 'short' })}</span>}
                      </div>
                      <div className="flex flex-shrink-0 items-center gap-2 text-[10px] text-slate-400">
                        {!clientView && task.commentsCount > 0 && <span className="inline-flex items-center gap-0.5"><MessageSquare className="h-3 w-3" />{task.commentsCount}</span>}
                        {task.assignee && <span className="flex h-5 w-5 items-center justify-center rounded-full bg-brand-50 text-[9px] font-bold text-brand-700" title={task.assignee}>{task.assignee.split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase()}</span>}
                      </div>
                    </div>
                  </article>
                );
              })}
              {perms.editTasks && (adding === status.key ? (
                <div className="rounded-xl border border-brand-300 bg-white px-3 py-2 shadow-sm"><QuickAdd status={status.key} autoFocus onDone={() => setAdding(null)} /></div>
              ) : (
                <button type="button" onClick={() => setAdding(status.key)} className="flex w-full items-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-medium text-slate-400 transition hover:bg-white hover:text-slate-700"><Plus className="h-3.5 w-3.5" />Añadir tarea</button>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Lista con selección y acciones en bloque                             */
/* ------------------------------------------------------------------ */
function ListView({ tasks }: { tasks: ProjectTask[] }) {
  const { data, perms, clientView, doneKeys, openTask, setTaskStatus, refresh } = useProject();
  const toast = useToast();
  const confirm = useConfirm();
  const deleteTask = useDeleteTask();
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const headerRef = useRef<HTMLInputElement>(null);
  const selectable = perms.editTasks;
  const visibleSelected = tasks.filter((task) => selected.has(task.id));
  const allSelected = tasks.length > 0 && visibleSelected.length === tasks.length;
  useEffect(() => {
    if (headerRef.current) headerRef.current.indeterminate = visibleSelected.length > 0 && !allSelected;
  }, [visibleSelected.length, allSelected]);

  const bulk = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ message: string }>('tasks.php', { ...body, task_ids: JSON.stringify(visibleSelected.map((task) => task.id)) }),
    onSuccess: (result) => { setSelected(new Set()); refresh.project(); refresh.insights(); toast(result.message ?? 'Tareas actualizadas.'); },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo aplicar la acción.', 'error'),
  });

  const toggle = (taskId: number) => setSelected((current) => {
    const next = new Set(current);
    if (next.has(taskId)) next.delete(taskId); else next.add(taskId);
    return next;
  });

  const openStatus = data.statuses.find((status) => status.kind === 'open')?.key;

  return (
    <div className="space-y-3">
      {visibleSelected.length > 0 && (
        <div className="sticky top-2 z-20 flex flex-wrap items-center gap-2 rounded-2xl bg-navy-950 px-4 py-2.5 text-xs text-white shadow-xl">
          <span className="font-semibold">{visibleSelected.length} seleccionada{visibleSelected.length === 1 ? '' : 's'}</span>
          <span className="mx-1 h-4 w-px bg-white/20" />
          <select aria-label="Cambiar estado" value="" disabled={bulk.isPending} onChange={(event) => event.target.value && bulk.mutate({ action: 'bulk_status', status: event.target.value })} className="rounded-lg border-0 bg-white/10 px-2 py-1 text-xs text-white focus:ring-2 focus:ring-cyan-400 [&>option]:text-slate-800">
            <option value="">Cambiar estado…</option>
            {data.statuses.map((status) => <option key={status.key} value={status.key}>{status.label}</option>)}
          </select>
          <select aria-label="Asignar responsable" value="" disabled={bulk.isPending} onChange={(event) => event.target.value && bulk.mutate({ action: 'bulk_assign', assigned_user_id: event.target.value === 'none' ? '0' : event.target.value })} className="rounded-lg border-0 bg-white/10 px-2 py-1 text-xs text-white focus:ring-2 focus:ring-cyan-400 [&>option]:text-slate-800">
            <option value="">Asignar a…</option>
            <option value="none">Sin asignar</option>
            {data.members.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}
          </select>
          {perms.deleteTasks && <button type="button" disabled={bulk.isPending} onClick={async () => { if (await confirm({ title: `Eliminar ${visibleSelected.length} tareas`, description: 'Esta acción no se puede deshacer.', confirmLabel: 'Eliminar', tone: 'danger' })) bulk.mutate({ action: 'bulk_delete' }); }} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-semibold text-rose-300 hover:bg-white/10"><Trash2 className="h-3.5 w-3.5" />Eliminar</button>}
          <button type="button" onClick={() => setSelected(new Set())} className="ml-auto rounded-lg px-2 py-1 text-white/70 hover:bg-white/10 hover:text-white">Cancelar</button>
        </div>
      )}

      <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
        <div className="flex items-center gap-3 border-b border-slate-100 bg-slate-50/60 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400 sm:px-5">
          {selectable && <input ref={headerRef} type="checkbox" checked={allSelected} onChange={() => setSelected(allSelected ? new Set() : new Set(tasks.map((task) => task.id)))} aria-label="Seleccionar todas" className="h-4 w-4 rounded border-slate-300 text-brand-600" />}
          <span className="flex-1">Tarea</span>
          <span className="hidden w-24 sm:block">Prioridad</span>
          <span className="w-32">Estado</span>
        </div>
        {perms.editTasks && <div className="border-b border-slate-100 px-4 py-2 sm:px-5"><QuickAdd /></div>}
        {tasks.length === 0 && <p className="px-5 py-10 text-center text-xs text-slate-400">No hay tareas que coincidan.</p>}
        <div className="divide-y divide-slate-100">
          {tasks.map((task) => {
            const done = doneKeys.includes(task.status);
            return (
              <div key={task.id} onClick={() => openTask(task.id)} className={`group flex cursor-pointer items-center gap-3 px-4 py-2.5 transition hover:bg-slate-50/70 sm:px-5 ${selected.has(task.id) ? 'bg-brand-50/50' : ''}`}>
                {selectable && <input type="checkbox" checked={selected.has(task.id)} onClick={(event) => event.stopPropagation()} onChange={() => toggle(task.id)} aria-label={`Seleccionar ${task.title}`} className="h-4 w-4 rounded border-slate-300 text-brand-600" />}
                <button
                  type="button"
                  disabled={!perms.changeStatus}
                  onClick={(event) => { event.stopPropagation(); setTaskStatus(task.id, done ? (openStatus ?? task.status) : (doneKeys[0] ?? task.status)); }}
                  className={`flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full border-2 transition ${done ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 text-transparent hover:border-emerald-500 hover:text-emerald-500'} disabled:cursor-default`}
                  title={done ? 'Marcar como pendiente' : 'Marcar como completada'}
                >
                  <Check className="h-3 w-3" />
                </button>
                <div className="min-w-0 flex-1">
                  <p className={`truncate text-xs font-semibold sm:text-[13px] ${done ? 'text-slate-400 line-through' : 'text-slate-900 group-hover:text-brand-700'}`}>{task.title}</p>
                  <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] text-slate-400">
                    <span className={!done && isOverdue(task.dueDate) ? 'font-semibold text-rose-600' : ''}>{formatDate(task.dueDate)}</span>
                    {task.assignee && <><span>·</span><span className="text-slate-600">{task.assignee}</span></>}
                    {!clientView && task.commentsCount > 0 && <><span>·</span><span>{task.commentsCount} comentarios</span></>}
                  </p>
                </div>
                <span className="hidden w-24 sm:block"><PriorityBadge priority={task.priority} /></span>
                <div className="flex w-32 items-center gap-1" onClick={(event) => event.stopPropagation()}>
                  {perms.changeStatus ? (
                    <Select value={task.status} onChange={(event) => setTaskStatus(task.id, event.target.value)} aria-label="Estado" className="w-full px-2 py-1 text-[11px] font-semibold">{data.statuses.map((status) => <option key={status.key} value={status.key}>{status.label}</option>)}</Select>
                  ) : <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">{task.statusLabel}</span>}
                  {perms.deleteTasks && <button type="button" onClick={() => void deleteTask(task)} className="rounded p-1 text-slate-300 opacity-0 transition hover:text-rose-600 group-hover:opacity-100" title="Eliminar tarea"><Trash2 className="h-3.5 w-3.5" /></button>}
                </div>
              </div>
            );
          })}
        </div>
      </div>
      {tasks.length === 0 && data.tasks.length === 0 && !perms.editTasks && <p className="text-center text-xs text-slate-400"><CheckSquare className="mr-1 inline h-3.5 w-3.5" />Este proyecto aún no tiene tareas.</p>}
    </div>
  );
}
