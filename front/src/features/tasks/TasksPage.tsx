import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2, Calendar, Check, ChevronDown, ChevronUp, Columns3, ExternalLink, Filter, GripVertical, List, Plus, Search, Trash2, X } from 'lucide-react';
import { Fragment, useDeferredValue, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Card, ErrorState, Input, PageHeader, Select, Skeleton } from '../../components/ui';
import { ExportButton } from '../../components/ExportButton';
import { useDialog, useConfirm, useToast, LoadingButton } from '../../components/feedback';
import { useUnsavedChanges } from '../../hooks/useUnsavedChanges';
import { apiGet, apiPost, apiPostRoot } from '../../lib/api';
import { formatDate, initials, isOverdue } from '../../lib/format';
import type { Task, TasksResponse } from '../../types';
import { useBootstrap } from '../auth/BootstrapProvider';
import { TaskDetailModal } from './TaskDetailModal';

const PRIORITY_DOTS: Record<string, string> = {
  urgente: 'bg-rose-500',
  alta: 'bg-amber-500',
  media: 'bg-indigo-500',
  baja: 'bg-slate-300',
};

const KIND_DOTS: Record<string, string> = { open: 'bg-slate-400', in_progress: 'bg-amber-500', done: 'bg-emerald-500' };

export function TasksPage() {
  const queryClient = useQueryClient();
  const bootstrap = useBootstrap();
  const [view, setView] = useState<'kanban' | 'list'>('kanban');
  const [projectId, setProjectId] = useState('');
  const [priority, setPriority] = useState('todas');
  const [assigned, setAssigned] = useState('todos');
  const [search, setSearch] = useState('');
  const [quickFilter, setQuickFilter] = useState<'all' | 'mine' | 'overdue' | 'unassigned' | 'week' | 'done'>('all');
  const [showFilters, setShowFilters] = useState(false);
  const [selectionMode, setSelectionMode] = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [collapsedGroups, setCollapsedGroups] = useState<Record<string, boolean>>(() => {
    try { return JSON.parse(localStorage.getItem('vertice_task_groups_collapsed') ?? '{}') as Record<string, boolean>; } catch { return {}; }
  });
  const [showCreate, setShowCreate] = useState(false);
  const [createDirty, setCreateDirty] = useState(false);
  const [createDefaults, setCreateDefaults] = useState<{ status?: string; projectId?: string }>({});
  const [expandedColumns, setExpandedColumns] = useState<Record<string, boolean>>({});
  const [showAllDoneList, setShowAllDoneList] = useState(false);
  const [draggingTaskId, setDraggingTaskId] = useState<number | null>(null);
  const [dragOverStatus, setDragOverStatus] = useState<string | null>(null);
  const [dragOverTaskId, setDragOverTaskId] = useState<number | null>(null);
  const [detailTask, setDetailTask] = useState<{ id: number; projectId: number; projectName: string } | null>(null);
  const toast = useToast();
  const confirm = useConfirm();
  const searchRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    try { localStorage.setItem('vertice_task_groups_collapsed', JSON.stringify(collapsedGroups)); } catch { /* almacenamiento no disponible */ }
  }, [collapsedGroups]);

  const openCreate = (defaults: { status?: string; projectId?: string } = {}) => {
    setCreateDefaults(defaults);
    setCreateDirty(false);
    setShowCreate(true);
  };
  const closeCreate = async () => {
    if (createDirty && !(await confirm({ title: 'Descartar tarea', description: 'Se perderán los datos introducidos.', confirmLabel: 'Descartar', cancelLabel: 'Seguir editando', tone: 'danger' }))) return;
    setCreateDirty(false);
    setShowCreate(false);
    setCreateDefaults({});
  };
  const createModalRef = useDialog(showCreate, () => { void closeCreate(); });
  useUnsavedChanges(showCreate && createDirty, 'Tienes datos sin guardar de la nueva tarea.');
  const deferredSearch = useDeferredValue(search);

  useEffect(() => {
    setSelectedIds([]);
  }, [quickFilter, projectId, priority, assigned, deferredSearch]);

  const { data, isLoading, error } = useQuery({
    queryKey: ['tasks', projectId, priority, assigned, deferredSearch],
    queryFn: () => apiGet<TasksResponse>('tasks.php', {
      project_id: projectId,
      priority,
      assigned_to: assigned,
      search: deferredSearch,
    }),
  });

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['tasks'] });
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    void queryClient.invalidateQueries({ queryKey: ['project'] });
    void queryClient.invalidateQueries({ queryKey: ['project-tabs'] });
    void queryClient.invalidateQueries({ queryKey: ['project-insights'] });
  };

  const updateStatus = useMutation({
    mutationFn: ({ taskId, nextStatus, taskProjectId }: { taskId: number; nextStatus: string; taskProjectId: number; silent?: boolean }) =>
      apiPost<{ success: boolean }>('tasks.php', { action: 'update_status', project_id: taskProjectId, task_id: taskId, status: nextStatus }),
    onMutate: async ({ taskId, nextStatus }) => {
      await queryClient.cancelQueries({ queryKey: ['tasks'] });
      const previous = queryClient.getQueriesData<TasksResponse>({ queryKey: ['tasks'] });
      let previousStatus: string | undefined;
      previous.forEach(([, value]) => {
        const task = value?.tasks.find((item) => item.id === taskId);
        if (task && previousStatus === undefined) previousStatus = task.status;
      });
      queryClient.setQueriesData<TasksResponse>({ queryKey: ['tasks'] }, (old) => {
        if (!old) return old;
        const statusLabel = old.statuses.find((status) => status.key === nextStatus)?.label ?? nextStatus;
        const tasks = old.tasks.map((task) => task.id === taskId ? { ...task, status: nextStatus, statusLabel } : task);
        const statusCounts: Record<string, number> = {};
        tasks.forEach((task) => { statusCounts[task.status] = (statusCounts[task.status] ?? 0) + 1; });
        return { ...old, tasks, statusCounts };
      });
      return { previous, previousStatus };
    },
    onError: (mutationError, _variables, context) => {
      context?.previous.forEach(([key, value]) => queryClient.setQueryData(key, value));
      toast(mutationError instanceof Error ? mutationError.message : 'No se pudo cambiar el estado de la tarea.', 'error');
    },
    onSuccess: (_result, variables, context) => {
      if (variables.silent) return;
      const label = data?.statuses.find((status) => status.key === variables.nextStatus)?.label ?? variables.nextStatus;
      const canUndo = context?.previousStatus !== undefined && context.previousStatus !== variables.nextStatus;
      toast(`Tarea movida a ${label}`, 'success', canUndo ? {
        action: {
          label: 'Deshacer',
          onClick: () => updateStatus.mutate({ taskId: variables.taskId, nextStatus: context!.previousStatus!, taskProjectId: variables.taskProjectId, silent: true }),
        },
      } : undefined);
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ['tasks'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });

  const createTask = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('tasks.php', { action: 'create', ...body }),
    onSuccess: () => { setCreateDirty(false); setShowCreate(false); setCreateDefaults({}); refresh(); toast('Tarea creada correctamente.'); },
  });

  const bulkStatus = useMutation({
    mutationFn: (status: string) => apiPost<{ success: boolean }>('tasks.php', { action: 'bulk_status', task_ids: JSON.stringify(selectedIds), status }),
    onSuccess: () => {
      setSelectedIds([]);
      setSelectionMode(false);
      refresh();
      toast('Tareas actualizadas.');
    },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudieron actualizar las tareas.', 'error'),
  });

  const bulkAssign = useMutation({
    mutationFn: (assignedUserId: string) => apiPost<{ success: boolean }>('tasks.php', { action: 'bulk_assign', task_ids: JSON.stringify(selectedIds), assigned_user_id: assignedUserId }),
    onSuccess: () => {
      setSelectedIds([]);
      setSelectionMode(false);
      refresh();
      toast('Responsable actualizado.');
    },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo asignar el responsable.', 'error'),
  });

  const bulkDelete = useMutation({
    mutationFn: () => apiPost<{ success: boolean }>('tasks.php', { action: 'bulk_delete', task_ids: JSON.stringify(selectedIds) }),
    onSuccess: () => {
      setSelectedIds([]);
      setSelectionMode(false);
      refresh();
      toast('Tareas eliminadas.');
    },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudieron eliminar las tareas.', 'error'),
  });

  const reorderTask = useMutation({
    mutationFn: ({ taskId, projectId: taskProjectId, status, orderedIds }: { taskId: number; projectId: number; status: string; orderedIds: number[] }) =>
      apiPostRoot<{ success: boolean }>('/api/tasks.php', {
        action: 'reorder',
        project_id: taskProjectId,
        task_id: taskId,
        status,
        ordered_ids: JSON.stringify(orderedIds),
      }),
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo reordenar la tarea.', 'error'),
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: ['tasks'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });

  const allTasks = data?.tasks ?? [];
  const doneKeys = new Set((data?.statuses ?? []).filter((status) => status.kind === 'done').map((status) => status.key));
  const today = new Date().toISOString().slice(0, 10);
  const weekEnd = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
  const quickCounts = {
    all: allTasks.length,
    mine: allTasks.filter((task) => task.assignedUserId === bootstrap.user.id).length,
    overdue: allTasks.filter((task) => !!task.dueDate && task.dueDate < today && !doneKeys.has(task.status)).length,
    unassigned: allTasks.filter((task) => !task.assignee).length,
    week: allTasks.filter((task) => !!task.dueDate && task.dueDate >= today && task.dueDate <= weekEnd && !doneKeys.has(task.status)).length,
    done: allTasks.filter((task) => doneKeys.has(task.status)).length,
  };
  const quickFilteredTasks = allTasks.filter((task) => {
    if (quickFilter === 'mine') return task.assignedUserId === bootstrap.user.id;
    if (quickFilter === 'overdue') return !!task.dueDate && task.dueDate < today && !doneKeys.has(task.status);
    if (quickFilter === 'unassigned') return !task.assignee;
    if (quickFilter === 'week') return !!task.dueDate && task.dueDate >= today && task.dueDate <= weekEnd && !doneKeys.has(task.status);
    if (quickFilter === 'done') return doneKeys.has(task.status);
    return true;
  });
  const tasksByStatus = (() => {
    const map: Record<string, Task[]> = {};
    quickFilteredTasks.forEach((task) => { (map[task.status] ??= []).push(task); });
    return map;
  })();
  const hasFilters = search !== '' || projectId !== '' || priority !== 'todas' || assigned !== 'todos' || quickFilter !== 'all';
  const advancedFilterCount = [projectId !== '', priority !== 'todas', assigned !== 'todos'].filter(Boolean).length;
  const priorityRank: Record<string, number> = { urgente: 0, alta: 1, media: 2, baja: 3 };
  const byPriority = (a: Task, b: Task) =>
    (priorityRank[a.priority] ?? 9) - (priorityRank[b.priority] ?? 9)
    || (a.dueDate ?? '9999-12-31').localeCompare(b.dueDate ?? '9999-12-31')
    || b.id - a.id;
  const activeInList = quickFilteredTasks.filter((task) => !doneKeys.has(task.status)).sort(byPriority);
  const completedInList = quickFilteredTasks.filter((task) => doneKeys.has(task.status)).sort((a, b) => (b.dueDate ?? '').localeCompare(a.dueDate ?? '') || b.id - a.id);
  const listTasks = [...activeInList, ...(showAllDoneList ? completedInList : completedInList.slice(0, 6))];
  const hiddenCompletedInList = showAllDoneList ? 0 : Math.max(0, completedInList.length - 6);
  const agencyGroups = (() => {
    const agencies = new Map<number, { id: number; name: string; tasks: Task[]; projects: Map<number, { id: number; name: string; color: string; tasks: Task[] }> }>();
    listTasks.forEach((task) => {
      const agencyId = task.agencyId ?? 0;
      let agency = agencies.get(agencyId);
      if (!agency) {
        agency = { id: agencyId, name: task.agencyName ?? 'Mi agencia', tasks: [], projects: new Map() };
        agencies.set(agencyId, agency);
      }
      agency.tasks.push(task);
      let project = agency.projects.get(task.projectId);
      if (!project) {
        project = { id: task.projectId, name: task.projectName, color: task.projectColor, tasks: [] };
        agency.projects.set(task.projectId, project);
      }
      project.tasks.push(task);
    });
    return Array.from(agencies.values())
      .map((agency) => ({ ...agency, projects: Array.from(agency.projects.values()).sort((a, b) => a.name.localeCompare(b.name, 'es')) }))
      .sort((a, b) => a.name.localeCompare(b.name, 'es'));
  })();
  const multipleAgencies = agencyGroups.length > 1;
  const statusDot = (statusKey: string) => KIND_DOTS[(data?.statuses.find((status) => status.key === statusKey)?.kind) ?? 'open'];

  const toggleSelection = (taskId: number) => setSelectedIds((current) => current.includes(taskId) ? current.filter((id) => id !== taskId) : [...current, taskId]);
  const toggleGroup = (key: string) => setCollapsedGroups((current) => ({ ...current, [key]: !current[key] }));

  const moveTask = (draggedId: number, targetStatus: string, targetTask?: Task) => {
    const draggedTask = allTasks.find((task) => task.id === draggedId);
    if (!draggedTask || !draggedTask.editable) return;
    setDragOverStatus(null);
    setDragOverTaskId(null);
    setDraggingTaskId(null);

    if (targetTask && targetTask.id !== draggedId && targetTask.projectId === draggedTask.projectId) {
      const ordered = allTasks
        .filter((task) => task.projectId === draggedTask.projectId && task.status === targetStatus && task.id !== draggedId)
        .map((task) => task.id);
      const targetIndex = ordered.indexOf(targetTask.id);
      if (targetIndex >= 0) ordered.splice(targetIndex, 0, draggedId);
      else ordered.push(draggedId);
      reorderTask.mutate({ taskId: draggedId, projectId: draggedTask.projectId, status: targetStatus, orderedIds: ordered });
      return;
    }

    if (draggedTask.status !== targetStatus) {
      updateStatus.mutate({ taskId: draggedId, nextStatus: targetStatus, taskProjectId: draggedTask.projectId });
    }
  };

  useEffect(() => {
    const handler = (event: KeyboardEvent) => {
      const element = event.target as HTMLElement | null;
      if (element?.closest('input, textarea, select, [contenteditable="true"]')) return;
      if (showCreate || detailTask) return;
      if (event.key.toLowerCase() === 'n' && !event.metaKey && !event.ctrlKey && !event.altKey) {
        event.preventDefault();
        openCreate({});
      } else if (event.key === '/') {
        event.preventDefault();
        searchRef.current?.focus();
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [showCreate, detailTask]);

  return (
    <div className="space-y-5">
      <PageHeader
        title="Tareas"
        subtitle={`${quickFilteredTasks.length} de ${allTasks.length} tareas · Vista global de tus proyectos`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <div className="flex items-center rounded-xl bg-slate-100 p-0.5">
              <button type="button" onClick={() => setView('kanban')} className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ${view === 'kanban' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}><Columns3 className="h-3.5 w-3.5" /><span className="hidden sm:inline">Tablero</span></button>
              <button type="button" onClick={() => setView('list')} className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ${view === 'list' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}><List className="h-3.5 w-3.5" /><span className="hidden sm:inline">Lista</span></button>
            </div>
            <button type="button" onClick={() => { setSelectionMode((current) => !current); setSelectedIds([]); }} className={`inline-flex items-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-semibold transition ${selectionMode ? 'border-brand-200 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`} title="Seleccionar varias tareas"><Check className="h-3.5 w-3.5" /> <span className="hidden sm:inline">{selectionMode ? 'Seleccionando' : 'Seleccionar'}</span></button>
            <ExportButton url={`/export.php?type=tasks&format=csv${projectId ? `&project_id=${projectId}` : ''}`} format="csv" className="hidden items-center rounded-lg px-2 py-1.5 text-[11px] font-semibold text-slate-500 transition hover:text-slate-900 sm:inline-flex" title="Exportar tareas a CSV">CSV</ExportButton>
            <ExportButton url={`/export.php?type=tasks&format=pdf${projectId ? `&project_id=${projectId}` : ''}`} format="pdf" className="hidden items-center rounded-lg px-2 py-1.5 text-[11px] font-semibold text-slate-500 transition hover:text-slate-900 sm:inline-flex" title="Exportar tareas a PDF">PDF</ExportButton>
            <button type="button" onClick={() => openCreate({})} className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700" title="Nueva tarea (N)"><Plus className="h-4 w-4" /> Nueva tarea</button>
          </div>
        }
      />

      <Card className="border-slate-200/60 p-3 shadow-none sm:p-4">
        <div className="flex items-center gap-2">
          <div className="relative flex-1">
            <Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input id="tasks-search" ref={searchRef} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar tarea, proyecto o descripción..." className="border-slate-200/70 bg-slate-50/50 pl-10 pr-10" />
            <kbd className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-400">/</kbd>
          </div>
          <button type="button" onClick={() => setShowFilters((current) => !current)} className={`inline-flex flex-shrink-0 items-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-semibold transition ${showFilters || advancedFilterCount > 0 ? 'border-brand-200 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}>
            <Filter className="h-3.5 w-3.5" />
            <span className="hidden sm:inline">Filtros</span>
            {advancedFilterCount > 0 && <span className="flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[9px] font-bold text-white">{advancedFilterCount}</span>}
          </button>
          {hasFilters && <button type="button" onClick={() => { setSearch(''); setProjectId(''); setPriority('todas'); setAssigned('todos'); setQuickFilter('all'); }} className="flex-shrink-0 rounded-xl p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title="Limpiar filtros"><X className="h-4 w-4" /></button>}
        </div>
        {showFilters && (
          <div className="mt-3 grid grid-cols-1 gap-2 border-t border-slate-100 pt-3 sm:grid-cols-3">
            <Select value={projectId} onChange={(event) => setProjectId(event.target.value)} className="border-slate-200/70 bg-slate-50/50"><option value="">Todos los proyectos</option>{data?.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}</Select>
            <Select value={priority} onChange={(event) => setPriority(event.target.value)} className="border-slate-200/70 bg-slate-50/50"><option value="todas">Todas las prioridades</option><option value="urgente">Urgente</option><option value="alta">Alta</option><option value="media">Media</option><option value="baja">Baja</option></Select>
            <Select value={assigned} onChange={(event) => setAssigned(event.target.value)} className="border-slate-200/70 bg-slate-50/50"><option value="todos">Todos los miembros</option><option value="sin_asignar">Sin asignar</option>{data?.collaborators.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</Select>
          </div>
        )}
      </Card>

      <div className="flex flex-wrap items-center gap-1.5">
        {([
          ['all', 'Todas', quickCounts.all],
          ['mine', 'Mis tareas', quickCounts.mine],
          ['overdue', 'Vencidas', quickCounts.overdue],
          ['unassigned', 'Sin asignar', quickCounts.unassigned],
          ['week', 'Esta semana', quickCounts.week],
          ['done', 'Completadas', quickCounts.done],
        ] as const).map(([key, label, count]) => (
          <button
            key={key}
            type="button"
            onClick={() => setQuickFilter(key)}
            className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold transition ${quickFilter === key ? 'border-brand-200 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50 hover:text-slate-700'}`}
          >
            {label}
            <span className={`rounded-full px-1.5 text-[10px] font-bold tabular-nums ${quickFilter === key ? 'bg-white/80 text-brand-700' : 'bg-slate-100 text-slate-500'}`}>{count}</span>
          </button>
        ))}
        {view === 'kanban' && <span className="ml-auto hidden items-center gap-1.5 text-[10px] text-slate-400 sm:inline-flex"><GripVertical className="h-3 w-3" />Arrastra para mover o reordenar</span>}
      </div>

      {isLoading ? (
        <div className="grid gap-5 md:grid-cols-3">{Array.from({ length: 3 }).map((_, column) => <div key={column} className="space-y-3">{Array.from({ length: 3 }).map((_, card) => <Skeleton key={card} className="h-32 rounded-xl" />)}</div>)}</div>
      ) : error ? <ErrorState message={error instanceof Error ? error.message : 'No se pudieron cargar las tareas.'} /> : !data || data.tasks.length === 0 || quickFilteredTasks.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><List className="h-5 w-5" /></div>
          <h3 className="text-sm font-semibold text-slate-800">No hay tareas que mostrar</h3>
          <p className="mt-1 text-xs text-slate-500">{hasFilters ? 'Prueba a cambiar o limpiar los filtros.' : 'Aún no hay tareas registradas en tus proyectos.'}</p>
        </div>
      ) : view === 'kanban' ? (
        <div className="flex items-start gap-4 overflow-x-auto pb-4">
          {data.statuses.map((status) => {
            const columnTasks = tasksByStatus[status.key] ?? [];
            const expanded = expandedColumns[status.key] ?? false;
            const displayedTasks = expanded ? columnTasks : columnTasks.slice(0, 6);
            const hiddenTasks = columnTasks.length - displayedTasks.length;
            const scrollable = expanded && displayedTasks.length > 6;
            return (
              <div key={status.key} className="w-[300px] flex-shrink-0 space-y-2.5">
                <div className="flex items-center justify-between px-1">
                  <div className="flex items-center gap-2"><span className={`h-2 w-2 rounded-full ${KIND_DOTS[status.kind] ?? 'bg-slate-400'}`} /><h3 className="text-xs font-semibold text-slate-700">{status.label}</h3></div>
                  <div className="flex items-center gap-1">
                    <span className="rounded-full bg-slate-200/60 px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-slate-500">{columnTasks.length}</span>
                    <button type="button" onClick={() => openCreate({ status: status.key, projectId: projectId || undefined })} className="flex h-5 w-5 items-center justify-center rounded-md text-slate-400 transition hover:bg-white hover:text-brand-600" title={`Nueva tarea en ${status.label}`}><Plus className="h-3.5 w-3.5" /></button>
                  </div>
                </div>
                <div
                  className={`min-h-[280px] space-y-2.5 rounded-2xl p-2.5 transition-colors ${dragOverStatus === status.key ? 'bg-brand-50/60 ring-2 ring-brand-300' : 'bg-slate-100/40 ring-1 ring-inset ring-slate-200/40'} ${scrollable ? 'max-h-[calc(100vh-22rem)] overflow-y-auto' : ''}`}
                  onDragOver={(event) => {
                    if (draggingTaskId === null) return;
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                    if (dragOverStatus !== status.key) setDragOverStatus(status.key);
                  }}
                  onDragLeave={(event) => {
                    if (event.currentTarget.contains(event.relatedTarget as Node | null)) return;
                    setDragOverStatus((current) => current === status.key ? null : current);
                    setDragOverTaskId(null);
                  }}
                  onDrop={(event) => {
                    event.preventDefault();
                    const droppedId = Number(event.dataTransfer.getData('text/plain')) || draggingTaskId;
                    if (droppedId) moveTask(droppedId, status.key);
                    else {
                      setDragOverStatus(null);
                      setDragOverTaskId(null);
                      setDraggingTaskId(null);
                    }
                  }}
                >
                  {columnTasks.length === 0 ? (
                    <p className="py-8 text-center text-[11px] text-slate-400">Sin tareas</p>
                  ) : displayedTasks.map((task) => (
                    <article
                      key={task.id}
                      onClick={(event) => { if ((event.target as HTMLElement).closest('a,button,select,input,textarea')) return; setDetailTask({ id: task.id, projectId: task.projectId, projectName: task.projectName }); }}
                      onDragOver={(event) => {
                        if (draggingTaskId === null || draggingTaskId === task.id) return;
                        event.preventDefault();
                        event.stopPropagation();
                        event.dataTransfer.dropEffect = 'move';
                        setDragOverStatus(status.key);
                        setDragOverTaskId(task.id);
                      }}
                      onDragLeave={() => setDragOverTaskId((current) => current === task.id ? null : current)}
                      onDrop={(event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        const droppedId = Number(event.dataTransfer.getData('text/plain')) || draggingTaskId;
                        if (droppedId) moveTask(droppedId, status.key, task);
                      }}
                      className={`group cursor-pointer rounded-xl border bg-white p-3.5 transition ${dragOverTaskId === task.id ? 'border-brand-300 ring-2 ring-brand-300 ring-offset-1' : 'border-slate-200/60 hover:border-slate-300'} ${draggingTaskId === task.id ? 'opacity-40' : ''}`}
                    >
                      <div className="mb-2 flex items-center justify-between gap-2">
                        <div className="flex min-w-0 items-center gap-1">
                          {selectionMode && (
                            <input type="checkbox" checked={selectedIds.includes(task.id)} onChange={() => toggleSelection(task.id)} onClick={(event) => event.stopPropagation()} className="h-3.5 w-3.5 flex-shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500" aria-label={`Seleccionar ${task.title}`} />
                          )}
                          {task.editable && (
                            <span
                              draggable
                              onDragStart={(event) => {
                                event.dataTransfer.effectAllowed = 'move';
                                event.dataTransfer.setData('text/plain', String(task.id));
                                setDraggingTaskId(task.id);
                              }}
                              onDragEnd={() => { setDraggingTaskId(null); setDragOverStatus(null); setDragOverTaskId(null); }}
                              className="-ml-1 cursor-grab rounded p-0.5 text-slate-300 opacity-0 transition hover:text-slate-500 group-hover:opacity-100 active:cursor-grabbing"
                              title="Arrastrar para mover o reordenar"
                            >
                              <GripVertical className="h-3.5 w-3.5" />
                            </span>
                          )}
                          <Link to={`/projects/${task.projectId}`} draggable={false} className="inline-flex min-w-0 items-center gap-1.5 text-[10px] font-semibold text-slate-500 transition hover:text-brand-600" title={task.projectName}>
                            <span className="h-1.5 w-1.5 flex-shrink-0 rounded-full" style={{ backgroundColor: task.projectColor }} />
                            <span className="truncate">{task.projectName}</span>
                          </Link>
                        </div>
                        <span className="inline-flex flex-shrink-0 items-center gap-1 text-[10px] capitalize text-slate-400" title={`Prioridad ${task.priority}`}>
                          <span className={`h-1.5 w-1.5 rounded-full ${PRIORITY_DOTS[task.priority] ?? PRIORITY_DOTS.baja}`} />
                          {task.priority}
                        </span>
                      </div>
                      <div className="flex items-start gap-2">
                        <input
                          type="checkbox"
                          checked={doneKeys.has(task.status)}
                          disabled={!task.editable || updateStatus.isPending}
                          onChange={() => {
                            const next = doneKeys.has(task.status)
                              ? data.statuses.find((option) => option.kind === 'open')?.key
                              : data.statuses.find((option) => option.kind === 'done')?.key;
                            if (next) updateStatus.mutate({ taskId: task.id, nextStatus: next, taskProjectId: task.projectId });
                          }}
                          onClick={(event) => event.stopPropagation()}
                          className="mt-0.5 h-3.5 w-3.5 flex-shrink-0 cursor-pointer rounded border-slate-300 text-brand-600 focus:ring-brand-500 disabled:cursor-not-allowed disabled:opacity-50"
                          title={doneKeys.has(task.status) ? 'Marcar como pendiente' : 'Marcar como completada'}
                        />
                        <h4 className={`min-w-0 flex-1 text-xs font-semibold leading-snug transition group-hover:text-brand-600 sm:text-[13px] ${doneKeys.has(task.status) ? 'text-slate-400 line-through' : 'text-slate-900'}`}>
                          <button type="button" onClick={(event) => { event.stopPropagation(); setDetailTask({ id: task.id, projectId: task.projectId, projectName: task.projectName }); }} className="text-left">{task.title}</button>
                        </h4>
                      </div>
                      {task.parentTaskId && <p className="mt-1 text-[10px] font-medium text-slate-400">Subtarea</p>}
                      {task.description && <p className="mt-1.5 line-clamp-2 text-[11px] leading-relaxed text-slate-400">{task.description}</p>}
                      <div className="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-2.5">
                        <div className="flex min-w-0 items-center gap-2">
                          {task.assignee ? (
                            <span className="inline-flex max-w-[110px] items-center gap-1.5 truncate text-[10px] font-medium text-slate-600" title={task.assignee}>
                              <span className="flex h-4 w-4 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-[7px] font-bold text-brand-700">{initials(task.assignee)}</span>
                              <span className="truncate">{task.assignee}</span>
                            </span>
                          ) : <span className="text-[10px] text-slate-300">Sin asignar</span>}
                          {task.dueDate && <span className={`inline-flex items-center gap-1 whitespace-nowrap text-[10px] ${isOverdue(task.dueDate) ? 'font-semibold text-rose-600' : 'text-slate-400'}`}><Calendar className="h-3 w-3" />{formatDate(task.dueDate)}</span>}
                        </div>
                        <div className="flex flex-shrink-0 items-center gap-1">
                          {task.editable ? (
                            <div className="relative inline-flex items-center">
                              <span className={`pointer-events-none absolute left-2 h-1.5 w-1.5 rounded-full ${statusDot(task.status)}`} />
                              <Select value={task.status} disabled={updateStatus.isPending} onChange={(event) => updateStatus.mutate({ taskId: task.id, nextStatus: event.target.value, taskProjectId: task.projectId })} className="cursor-pointer rounded-lg border-transparent bg-slate-50 py-0.5 pl-4 pr-1.5 text-[10px] font-medium text-slate-600 hover:bg-slate-100">
                                {data.statuses.map((option) => <option key={option.key} value={option.key}>{option.label}</option>)}
                              </Select>
                            </div>
                          ) : <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold text-slate-500">{task.statusLabel}</span>}
                          <Link to={`/projects/${task.projectId}`} className="rounded p-1 text-slate-300 transition hover:text-brand-600" title="Ver en proyecto"><ExternalLink className="h-3.5 w-3.5" /></Link>
                        </div>
                      </div>
                    </article>
                  ))}
                  {hiddenTasks > 0 && (
                    <button type="button" onClick={() => setExpandedColumns((current) => ({ ...current, [status.key]: true }))} className="flex w-full items-center justify-center gap-1.5 rounded-xl border border-dashed border-slate-300 bg-white/60 py-2 text-[11px] font-semibold text-slate-500 transition hover:border-slate-400 hover:text-slate-700">
                      Ver {hiddenTasks} {status.kind === 'done' ? 'completadas' : 'tareas'} más <ChevronDown className="h-3.5 w-3.5" />
                    </button>
                  )}
                  {expanded && columnTasks.length > 6 && (
                    <button type="button" onClick={() => setExpandedColumns((current) => ({ ...current, [status.key]: false }))} className="flex w-full items-center justify-center gap-1.5 rounded-xl border border-dashed border-slate-300 bg-white/60 py-2 text-[11px] font-semibold text-slate-500 transition hover:border-slate-400 hover:text-slate-700">
                      Mostrar menos <ChevronUp className="h-3.5 w-3.5" />
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      ) : (
        <Card className="border-slate-200/60 shadow-none">
          <div className="overflow-x-auto lg:overflow-visible">
            <table className="w-full text-left text-xs text-slate-600">
              <thead className="border-b border-slate-100 text-[10px] uppercase tracking-wider text-slate-400">
                <tr><th className="px-5 py-3 font-semibold">Tarea</th><th className="px-3 py-3 font-semibold">Responsable</th><th className="px-3 py-3 font-semibold">Prioridad</th><th className="px-3 py-3 font-semibold">Vencimiento</th><th className="px-3 py-3 font-semibold">Estado</th><th className="px-5 py-3 text-right font-semibold"></th></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {agencyGroups.map((agency) => (
                  <Fragment key={`agency-${agency.id}`}>
                    {multipleAgencies && (() => {
                      const agencyKey = `agency-${agency.id}`;
                      const agencyCollapsed = collapsedGroups[agencyKey] ?? false;
                      return (
                        <tr className="bg-slate-100/80">
                          <td colSpan={6} className="px-5 py-2.5">
                            <button type="button" onClick={() => toggleGroup(agencyKey)} aria-expanded={!agencyCollapsed} className="flex w-full flex-wrap items-center gap-2 text-left">
                              <ChevronDown className={`h-3.5 w-3.5 text-slate-400 transition-transform ${agencyCollapsed ? '-rotate-90' : ''}`} />
                              <Building2 className="h-3.5 w-3.5 text-slate-400" />
                              <span className="text-[11px] font-bold uppercase tracking-wider text-slate-600">{agency.name}</span>
                              <span className="rounded-full bg-white px-1.5 py-0.5 text-[10px] font-bold tabular-nums text-slate-500 ring-1 ring-slate-200/70">{agency.tasks.length} tarea{agency.tasks.length === 1 ? '' : 's'}</span>
                            </button>
                          </td>
                        </tr>
                      );
                    })()}
                    {!collapsedGroups[`agency-${agency.id}`] && agency.projects.map((project) => {
                      const groupKey = `project-${agency.id}-${project.id}`;
                      const collapsed = collapsedGroups[groupKey] ?? false;
                      const done = project.tasks.filter((task) => doneKeys.has(task.status)).length;
                      const progress = project.tasks.length > 0 ? Math.round((done / project.tasks.length) * 100) : 0;
                      return (
                        <Fragment key={`project-${agency.id}-${project.id}`}>
                          <tr className="bg-slate-50/90">
                            <td colSpan={6} className="sticky top-14 z-[5] bg-slate-50/95 px-5 py-2 backdrop-blur">
                              <div className="flex flex-wrap items-center gap-2">
                                <button type="button" onClick={() => toggleGroup(groupKey)} aria-expanded={!collapsed} className="flex min-w-0 items-center gap-2 text-left">
                                  <ChevronDown className={`h-3.5 w-3.5 flex-shrink-0 text-slate-400 transition-transform ${collapsed ? '-rotate-90' : ''}`} />
                                  <span className="h-2 w-2 flex-shrink-0 rounded-full" style={{ backgroundColor: project.color }} />
                                  <span className="truncate text-[11px] font-semibold text-slate-700">{project.name}</span>
                                </button>
                                <span className="rounded-full bg-white px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-slate-500 ring-1 ring-slate-200/60">{project.tasks.length} tarea{project.tasks.length === 1 ? '' : 's'}</span>
                                <div className="hidden w-24 sm:block"><div className="flex items-center gap-1.5"><div className="h-1 flex-1 overflow-hidden rounded-full bg-slate-200"><div className="h-full rounded-full bg-brand-500" style={{ width: `${progress}%` }} /></div><span className="text-[9px] font-bold tabular-nums text-slate-400">{progress}%</span></div></div>
                                {!multipleAgencies && <span className="text-[10px] text-slate-400">· {agency.name}</span>}
                                <button type="button" onClick={() => openCreate({ projectId: String(project.id) })} className="ml-auto flex h-6 w-6 items-center justify-center rounded-md text-slate-400 transition hover:bg-white hover:text-brand-600" title={`Nueva tarea en ${project.name}`}><Plus className="h-3.5 w-3.5" /></button>
                              </div>
                            </td>
                          </tr>
                          {!collapsed && project.tasks.map((task) => (
                            <tr key={task.id} onClick={(event) => { if ((event.target as HTMLElement).closest('a,button,select,input,textarea')) return; setDetailTask({ id: task.id, projectId: task.projectId, projectName: task.projectName }); }} className="cursor-pointer transition hover:bg-slate-50/60">
                              <td className="px-5 py-3">
                                <div className="flex items-start gap-2">
                                  {selectionMode && <input type="checkbox" checked={selectedIds.includes(task.id)} onChange={() => toggleSelection(task.id)} onClick={(event) => event.stopPropagation()} className="mt-0.5 h-3.5 w-3.5 flex-shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500" aria-label={`Seleccionar ${task.title}`} />}
                                  <div className="min-w-0">
                                    <button type="button" onClick={(event) => { event.stopPropagation(); setDetailTask({ id: task.id, projectId: task.projectId, projectName: task.projectName }); }} className={`text-left font-semibold transition hover:text-brand-600 ${doneKeys.has(task.status) ? 'text-slate-400 line-through' : 'text-slate-900'}`}>{task.title}</button>
                                    {task.description && <div className="mt-0.5 max-w-sm truncate text-[11px] text-slate-400">{task.description}</div>}
                                  </div>
                                </div>
                              </td>
                              <td className="whitespace-nowrap px-3 py-3">
                                {task.assignee ? <span className="inline-flex items-center gap-1.5 text-[11px] text-slate-600"><span className="flex h-5 w-5 items-center justify-center rounded-full bg-brand-50 text-[8px] font-bold text-brand-700">{initials(task.assignee)}</span>{task.assignee}</span> : <span className="text-[11px] text-slate-300">Sin asignar</span>}
                              </td>
                              <td className="whitespace-nowrap px-3 py-3"><span className="inline-flex items-center gap-1.5 text-[11px] capitalize text-slate-500"><span className={`h-1.5 w-1.5 rounded-full ${PRIORITY_DOTS[task.priority] ?? PRIORITY_DOTS.baja}`} />{task.priority}</span></td>
                              <td className={`whitespace-nowrap px-3 py-3 text-[11px] ${isOverdue(task.dueDate) ? 'font-semibold text-rose-600' : 'text-slate-500'}`}>{task.dueDate ? formatDate(task.dueDate) : '—'}</td>
                              <td className="whitespace-nowrap px-3 py-3">
                                {task.editable ? (
                                  <div className="relative inline-flex items-center">
                                    <span className={`pointer-events-none absolute left-2.5 h-1.5 w-1.5 rounded-full ${statusDot(task.status)}`} />
                                    <Select value={task.status} disabled={updateStatus.isPending} onChange={(event) => updateStatus.mutate({ taskId: task.id, nextStatus: event.target.value, taskProjectId: task.projectId })} className="cursor-pointer rounded-lg border-slate-200/70 bg-slate-50 py-1 pl-5 pr-2 text-[11px]">{data.statuses.map((option) => <option key={option.key} value={option.key}>{option.label}</option>)}</Select>
                                  </div>
                                ) : <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500">{task.statusLabel}</span>}
                              </td>
                              <td className="whitespace-nowrap px-5 py-3 text-right"><Link to={`/projects/${task.projectId}`} className="inline-flex rounded p-1.5 text-slate-300 transition hover:text-brand-600" title="Ver en proyecto"><ExternalLink className="h-3.5 w-3.5" /></Link></td>
                            </tr>
                          ))}
                        </Fragment>
                      );
                    })}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
          {(hiddenCompletedInList > 0 || (showAllDoneList && completedInList.length > 6)) && (
            <div className="border-t border-slate-100 p-3 text-center">
              {hiddenCompletedInList > 0 ? (
                <button type="button" onClick={() => setShowAllDoneList(true)} className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-slate-500 transition hover:text-slate-800">
                  Ver {hiddenCompletedInList} completadas más <ChevronDown className="h-3.5 w-3.5" />
                </button>
              ) : (
                <button type="button" onClick={() => setShowAllDoneList(false)} className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-slate-500 transition hover:text-slate-800">
                  Mostrar menos <ChevronUp className="h-3.5 w-3.5" />
                </button>
              )}
            </div>
          )}
        </Card>
      )}

      {updateStatus.isError && <ErrorState message={updateStatus.error instanceof Error ? updateStatus.error.message : 'No se pudo actualizar la tarea.'} />}

      {selectionMode && (
        <div className="fixed bottom-20 left-1/2 z-40 w-[calc(100%-1.5rem)] max-w-3xl -translate-x-1/2 rounded-2xl border border-slate-200 bg-white p-3 shadow-2xl md:bottom-6">
          <div className="flex flex-wrap items-center gap-2">
            <span className="rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-bold text-brand-700">{selectedIds.length} seleccionada{selectedIds.length === 1 ? '' : 's'}</span>
            <button type="button" onClick={() => setSelectedIds(selectedIds.length === quickFilteredTasks.length ? [] : quickFilteredTasks.map((task) => task.id))} className="text-[11px] font-semibold text-slate-500 transition hover:text-slate-800">{selectedIds.length === quickFilteredTasks.length ? 'Quitar selección' : 'Seleccionar visibles'}</button>
            <div className="ml-auto flex flex-wrap items-center gap-2">
              <Select value="" disabled={selectedIds.length === 0 || bulkStatus.isPending} onChange={(event) => { if (event.target.value) bulkStatus.mutate(event.target.value); }} className="w-[130px] px-2 py-1.5 text-[11px]">
                <option value="">Cambiar estado…</option>
                {data?.statuses.map((status) => <option key={status.key} value={status.key}>{status.label}</option>)}
              </Select>
              <Select value="" disabled={selectedIds.length === 0 || bulkAssign.isPending} onChange={(event) => bulkAssign.mutate(event.target.value)} className="w-[140px] px-2 py-1.5 text-[11px]">
                <option value="">Asignar a…</option>
                <option value="0">Sin asignar</option>
                {data?.collaborators.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
              </Select>
              <button type="button" disabled={selectedIds.length === 0 || bulkDelete.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar tareas', description: `Se eliminarán ${selectedIds.length} tarea${selectedIds.length === 1 ? '' : 's'}.`, confirmLabel: 'Eliminar', tone: 'danger' })) bulkDelete.mutate(); }} className="inline-flex items-center gap-1 rounded-xl border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-[11px] font-semibold text-rose-600 transition hover:bg-rose-100 disabled:opacity-40"><Trash2 className="h-3.5 w-3.5" /> Eliminar</button>
              <button type="button" onClick={() => { setSelectionMode(false); setSelectedIds([]); }} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title="Salir de selección"><X className="h-4 w-4" /></button>
            </div>
          </div>
        </div>
      )}

      {detailTask && data && (
        <TaskDetailModal
          taskId={detailTask.id}
          projectId={detailTask.projectId}
          projectName={detailTask.projectName}
          statuses={data.statuses}
          assignees={data.collaborators}
          taskIds={(view === 'list' ? listTasks : quickFilteredTasks).map((task) => task.id)}
          onClose={() => setDetailTask(null)}
          onChanged={refresh}
        />
      )}

      {showCreate && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs" onClick={() => void closeCreate()}>
          <div ref={createModalRef} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" className="w-full max-w-lg overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
              <div>
                <h3 className="text-sm font-semibold text-slate-900">Nueva tarea</h3>
                <p className="text-[11px] text-slate-400">Añádela directamente a uno de tus proyectos</p>
              </div>
              <button type="button" onClick={() => void closeCreate()} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
            </div>
            <form className="space-y-4 p-6" onChange={() => setCreateDirty(true)} onSubmit={(event) => {
              event.preventDefault();
              const form = new FormData(event.currentTarget);
              const body: Record<string, string> = {};
              form.forEach((value, key) => { body[key] = String(value); });
              createTask.mutate(body);
            }}>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Proyecto *</label>
                <Select name="project_id" required defaultValue={createDefaults.projectId ?? projectId} className="w-full px-3.5 py-2.5">
                  <option value="">Selecciona un proyecto...</option>
                  {data?.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                </Select>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Título *</label>
                <Input name="title" required maxLength={200} placeholder="Ej: Revisar entrega del cliente" className="px-3.5 py-2.5 text-sm" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Descripción</label>
                <textarea name="description" rows={3} placeholder="Detalles opcionales..." className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15" />
              </div>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Prioridad</label>
                  <Select name="priority" defaultValue="media" className="w-full px-3.5 py-2.5"><option value="baja">Baja</option><option value="media">Media</option><option value="alta">Alta</option><option value="urgente">Urgente</option></Select>
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Estado</label>
                  <Select name="status" defaultValue={createDefaults.status ?? ''} className="w-full px-3.5 py-2.5">
                    <option value="">Estado predeterminado</option>
                    {data?.statuses.map((status) => <option key={status.key} value={status.key}>{status.label}</option>)}
                  </Select>
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Vencimiento</label>
                  <Input name="due_date" type="date" className="px-3.5 py-2.5 text-sm" />
                </div>
              </div>
              {createTask.isError && <ErrorState message={createTask.error instanceof Error ? createTask.error.message : 'No se pudo crear la tarea.'} />}
              <div className="flex justify-end gap-3 border-t border-slate-100 pt-3">
                <button type="button" onClick={() => void closeCreate()} className="rounded-xl px-4 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100">Cancelar</button>
                <LoadingButton type="submit" pending={createTask.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Crear tarea</LoadingButton>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
