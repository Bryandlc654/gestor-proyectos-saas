import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Building2, CalendarDays, CheckCircle2, Clock, FolderKanban, LayoutGrid, List, Plus, RotateCcw, Search, Share2, Users, Wallet, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Card, EmptyState, ErrorState, Input, PageHeader, Progress, Select, Skeleton } from '../../components/ui';
import { LoadingButton, Modal, useConfirm, useToast } from '../../components/feedback';
import { useUnsavedChanges } from '../../hooks/useUnsavedChanges';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate, formatMoney } from '../../lib/format';
import type { ProjectListItem } from '../../types';
import { useBootstrap } from '../auth/BootstrapProvider';

const STATUS_LABELS: Record<string, string> = {
  activo: 'Activo',
  en_espera: 'En espera',
  completado: 'Completado',
};

const CURRENCIES = ['USD', 'EUR', 'MXN', 'PEN', 'COP', 'CLP', 'ARS'];
const MEMBER_ROLES = [
  { value: 'project_manager', label: 'Project Manager' },
  { value: 'colaborador', label: 'Colaborador' },
  { value: 'cliente', label: 'Cliente' },
];

interface TeamMember {
  id: number;
  name: string;
  email: string;
  role: string;
}

interface ProjectsResponse {
  projects: ProjectListItem[];
  team: TeamMember[];
  categories: string[];
  clients: string[];
}

type ViewKey = 'grid' | 'table';
type SortKey = 'recent' | 'name' | 'due' | 'progress' | 'budget';

function todayIso(): string {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function daysUntil(value: string): number {
  return Math.ceil((new Date(`${value}T23:59:59`).getTime() - Date.now()) / 86400000);
}

export function ProjectsPage() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const confirm = useConfirm();
  const toast = useToast();
  const bootstrap = useBootstrap();
  const canCreate = bootstrap.user.role === 'admin' || bootstrap.user.role === 'project_manager';
  const [searchParams, setSearchParams] = useSearchParams();
  const [search, setSearch] = useState(() => searchParams.get('q') ?? '');
  const [status, setStatus] = useState(() => searchParams.get('status') ?? 'todos');
  const [view, setView] = useState<ViewKey>(() => (searchParams.get('view') === 'table' ? 'table' : 'grid'));
  const [sort, setSort] = useState<SortKey>(() => {
    const value = searchParams.get('sort');
    return value === 'name' || value === 'due' || value === 'progress' || value === 'budget' ? value : 'recent';
  });
  const [sharedOnly, setSharedOnly] = useState(() => searchParams.get('shared') === '1');
  const [overdueOnly, setOverdueOnly] = useState(() => searchParams.get('overdue') === '1');
  const [createOpen, setCreateOpen] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [team, setTeam] = useState<Record<number, string>>({});
  const [formError, setFormError] = useState('');
  useUnsavedChanges(createOpen && dirty, 'Tienes datos sin guardar del nuevo proyecto.');

  const { data, isLoading, error } = useQuery({
    queryKey: ['projects'],
    queryFn: () => apiGet<ProjectsResponse>('projects.php'),
  });

  useEffect(() => {
    const next = new URLSearchParams();
    if (search !== '') next.set('q', search);
    if (status !== 'todos') next.set('status', status);
    if (view === 'table') next.set('view', 'table');
    if (sort !== 'recent') next.set('sort', sort);
    if (sharedOnly) next.set('shared', '1');
    if (overdueOnly) next.set('overdue', '1');
    setSearchParams(next, { replace: true });
  }, [overdueOnly, search, setSearchParams, sharedOnly, sort, status, view]);

  useEffect(() => {
    if (canCreate && searchParams.get('new') === '1') setCreateOpen(true);
  }, [searchParams, canCreate]);

  const closeCreate = async () => {
    if (dirty) {
      const discard = await confirm({ title: 'Descartar proyecto', description: 'Se perderán los datos que has escrito.', confirmLabel: 'Descartar', cancelLabel: 'Seguir editando', tone: 'danger' });
      if (!discard) return;
    }
    setCreateOpen(false);
    setDirty(false);
    setTeam({});
    setFormError('');
    if (searchParams.get('new') !== null) {
      const next = new URLSearchParams(searchParams);
      next.delete('new');
      setSearchParams(next, { replace: true });
    }
  };

  const openCreate = () => {
    setDirty(false);
    setTeam({});
    setFormError('');
    setCreateOpen(true);
  };

  const createProject = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean; projectId: number }>('projects.php', { action: 'create', ...body }),
    onSuccess: (result) => {
      setDirty(false);
      setCreateOpen(false);
      setTeam({});
      void queryClient.invalidateQueries({ queryKey: ['projects'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
      toast('Proyecto creado correctamente.');
      navigate(`/projects/${result.projectId}`);
    },
    onError: (mutationError) => setFormError(mutationError instanceof Error ? mutationError.message : 'No se pudo crear el proyecto.'),
  });

  const allProjects = data?.projects ?? [];
  const today = todayIso();
  const stats = useMemo(() => {
    const active = allProjects.filter((project) => project.status === 'activo').length;
    const waiting = allProjects.filter((project) => project.status === 'en_espera').length;
    const done = allProjects.filter((project) => project.status === 'completado').length;
    const overdue = allProjects.filter((project) => project.status !== 'completado' && project.dueDate && project.dueDate < today).length;
    const budgets: Record<string, number> = {};
    allProjects.forEach((project) => {
      if (project.budget !== null && project.budget > 0) budgets[project.currency] = (budgets[project.currency] ?? 0) + project.budget;
    });
    return { active, waiting, done, overdue, budgets };
  }, [allProjects, today]);

  const projects = useMemo(() => {
    const term = search.trim().toLowerCase();
    const filtered = allProjects.filter((project) => {
      const matchesSearch = term === ''
        || project.name.toLowerCase().includes(term)
        || (project.clientName ?? '').toLowerCase().includes(term)
        || (project.category ?? '').toLowerCase().includes(term);
      const matchesStatus = status === 'todos' || project.status === status;
      const matchesShared = !sharedOnly || project.shared;
      const matchesOverdue = !overdueOnly || (project.status !== 'completado' && project.dueDate !== null && project.dueDate !== undefined && project.dueDate < today);
      return matchesSearch && matchesStatus && matchesShared && matchesOverdue;
    });
    return filtered.sort((a, b) => {
      if (sort === 'name') return a.name.localeCompare(b.name);
      if (sort === 'due') {
        if (!a.dueDate && !b.dueDate) return 0;
        if (!a.dueDate) return 1;
        if (!b.dueDate) return -1;
        return a.dueDate.localeCompare(b.dueDate);
      }
      if (sort === 'progress') return b.progress - a.progress;
      if (sort === 'budget') return (b.budget ?? 0) - (a.budget ?? 0);
      return 0;
    });
  }, [allProjects, overdueOnly, search, sharedOnly, sort, status, today]);

  const filtersActive = search !== '' || status !== 'todos' || sharedOnly || overdueOnly;
  const resetFilters = () => {
    setSearch('');
    setStatus('todos');
    setSharedOnly(false);
    setOverdueOnly(false);
  };

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-9 w-48" />
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} className="h-28" />)}</div>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 6 }).map((_, index) => <Skeleton key={index} className="h-44" />)}</div>
      </div>
    );
  }

  if (error) return <ErrorState message={error instanceof Error ? error.message : 'No se pudieron cargar los proyectos.'} />;

  const statsCards = [
    { key: 'activo', label: 'Activos', value: stats.active, hint: 'en marcha', icon: FolderKanban, tone: 'bg-brand-50 text-brand-600' },
    { key: 'en_espera', label: 'En espera', value: stats.waiting, hint: 'pausados', icon: Clock, tone: 'bg-amber-50 text-amber-500' },
    { key: 'completado', label: 'Completados', value: stats.done, hint: 'finalizados', icon: CheckCircle2, tone: 'bg-emerald-50 text-emerald-500' },
    { key: 'overdue', label: 'Vencidos', value: stats.overdue, hint: 'fuera de fecha', icon: AlertTriangle, tone: 'bg-rose-50 text-rose-500' },
  ];
  const budgetText = Object.entries(stats.budgets)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 2)
    .map(([currency, amount]) => formatMoney(amount, currency))
    .join(' · ');

  const projectMeta = (project: ProjectListItem) => {
    const isOverdue = project.status !== 'completado' && project.dueDate !== null && project.dueDate !== undefined && project.dueDate < today;
    return { isOverdue, dueIn: project.dueDate ? daysUntil(project.dueDate) : null };
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Proyectos"
        subtitle={`${allProjects.length} proyectos visibles${budgetText ? ` · Presupuesto ${budgetText}` : ''}`}
        actions={canCreate ? <button type="button" onClick={openCreate} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700"><Plus className="h-4 w-4" /> Nuevo proyecto</button> : undefined}
      />

      <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        {statsCards.map((card) => {
          const Icon = card.icon;
          const active = card.key === 'overdue' ? overdueOnly : status === card.key && !overdueOnly;
          return (
            <button
              key={card.key}
              type="button"
              onClick={() => {
                if (card.key === 'overdue') {
                  setOverdueOnly(!overdueOnly);
                  setStatus('todos');
                } else {
                  setOverdueOnly(false);
                  setStatus(status === card.key ? 'todos' : card.key);
                }
              }}
              aria-pressed={active}
              className={`text-left transition ${active ? 'ring-2 ring-brand-500/40' : ''}`}
            >
              <Card className="h-full space-y-2 p-4 hover:border-slate-300 sm:p-5">
                <div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">{card.label}</span><span className={`flex h-8 w-8 items-center justify-center rounded-lg ${card.tone}`}><Icon className="h-4 w-4" /></span></div>
                <p className="text-2xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[26px]">{card.value}</p>
                <span className="text-[11px] font-medium text-slate-400">{card.hint}</span>
              </Card>
            </button>
          );
        })}
      </div>

      <Card className="p-4">
        <div className="flex w-full flex-col gap-3 lg:flex-row lg:items-center">
          <div className="relative w-full flex-1">
            <Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar proyecto, cliente o categoría..." className="pl-10 text-xs sm:text-sm" />
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Select value={status} onChange={(event) => { setStatus(event.target.value); setOverdueOnly(false); }}>
              <option value="todos">Todos los estados</option>
              <option value="activo">Activos</option>
              <option value="en_espera">En espera</option>
              <option value="completado">Completados</option>
            </Select>
            <Select value={sort} onChange={(event) => setSort(event.target.value as SortKey)}>
              <option value="recent">Más recientes</option>
              <option value="name">Nombre A–Z</option>
              <option value="due">Entrega próxima</option>
              <option value="progress">Mayor progreso</option>
              <option value="budget">Mayor presupuesto</option>
            </Select>
            <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-xs font-semibold text-slate-600">
              <input type="checkbox" checked={sharedOnly} onChange={(event) => setSharedOnly(event.target.checked)} className="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
              Compartidos
            </label>
            <div className="flex items-center rounded-xl bg-slate-100 p-0.5">
              <button type="button" onClick={() => setView('grid')} className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ${view === 'grid' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`} title="Vista de tarjetas"><LayoutGrid className="h-3.5 w-3.5" /><span className="hidden sm:inline">Tarjetas</span></button>
              <button type="button" onClick={() => setView('table')} className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ${view === 'table' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`} title="Vista de tabla"><List className="h-3.5 w-3.5" /><span className="hidden sm:inline">Tabla</span></button>
            </div>
            {(filtersActive || overdueOnly) && <button type="button" onClick={resetFilters} className="whitespace-nowrap rounded-xl px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-800"><RotateCcw className="mr-1 inline h-3.5 w-3.5" />Limpiar</button>}
          </div>
        </div>
      </Card>

      {projects.length === 0 ? (
        <Card>
          <EmptyState
            title={filtersActive ? 'Sin proyectos que coincidan' : 'Aún no hay proyectos'}
            description={filtersActive ? 'Prueba con otro estado, término o desactiva los filtros.' : 'Crea tu primer proyecto para organizar tareas, equipo y finanzas.'}
            action={filtersActive
              ? <button type="button" onClick={resetFilters} className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"><RotateCcw className="h-3.5 w-3.5" />Limpiar filtros</button>
              : canCreate ? <button type="button" onClick={openCreate} className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-700"><Plus className="h-3.5 w-3.5" />Nuevo proyecto</button> : undefined}
          />
        </Card>
      ) : view === 'grid' ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {projects.map((project) => {
            const { isOverdue, dueIn } = projectMeta(project);
            return (
              <Link key={project.id} to={`/projects/${project.id}`} className="group">
                <Card className="flex h-full flex-col p-5 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md">
                  <div className="flex items-start justify-between gap-2">
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl text-white" style={{ backgroundColor: project.color }}><FolderKanban className="h-5 w-5" /></span>
                    <div className="flex flex-col items-end gap-1">
                      <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ${project.status === 'activo' ? 'bg-emerald-50 text-emerald-700' : project.status === 'completado' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700'}`}>
                        {STATUS_LABELS[project.status] ?? project.status}
                      </span>
                      {isOverdue && <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-1.5 py-0.5 text-[9px] font-bold text-rose-600"><AlertTriangle className="h-2.5 w-2.5" />{dueIn !== null && dueIn < 0 ? `${Math.abs(dueIn)}d tarde` : 'Vence hoy'}</span>}
                    </div>
                  </div>
                  <h2 className="mt-3 truncate text-sm font-semibold text-slate-900 group-hover:text-brand-700">{project.name}</h2>
                  <p className="mt-0.5 truncate text-[11px] font-medium text-slate-500">{project.clientName ? `Cliente: ${project.clientName}` : 'Sin cliente'}</p>
                  <p className="mt-0.5 flex items-center gap-1 truncate text-[10px] text-slate-400">
                    <Building2 className="h-3 w-3 flex-shrink-0" /> <span className="truncate">{project.agencyName}</span>
                    {project.shared && <><Share2 className="ml-1 h-3 w-3 flex-shrink-0" /> Compartido</>}
                  </p>
                  <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[10px] text-slate-500">
                    <span>{project.category || 'General'}</span>
                    <span className="text-slate-300">·</span>
                    <span>{project.tasks} tarea{project.tasks === 1 ? '' : 's'}</span>
                    <span className="text-slate-300">·</span>
                    <span className="inline-flex items-center gap-1"><Users className="h-3 w-3" />{project.members}</span>
                    {project.overdueTasks > 0 && <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-1.5 py-0.5 font-semibold text-rose-600"><AlertTriangle className="h-2.5 w-2.5" />{project.overdueTasks} vencida{project.overdueTasks === 1 ? '' : 's'}</span>}
                  </div>
                  <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] text-slate-400">
                    {project.startDate && <span className="inline-flex items-center gap-1"><CalendarDays className="h-3 w-3" />Inicio {formatDate(project.startDate)}</span>}
                    {project.dueDate && <span className={`inline-flex items-center gap-1 ${isOverdue ? 'font-semibold text-rose-600' : ''}`}><CalendarDays className="h-3 w-3" />Entrega {formatDate(project.dueDate)}</span>}
                  </div>
                  <div className="mt-auto pt-4">
                    {project.budget !== null && project.budget > 0 && <div className="mb-2 flex items-center justify-between text-[10px]"><span className="inline-flex items-center gap-1 text-slate-400"><Wallet className="h-3 w-3" />Presupuesto</span><span className="font-semibold text-slate-700">{formatMoney(project.budget, project.currency)}</span></div>}
                    <div className="flex items-center gap-2">
                      <div className="flex-1"><Progress value={project.progress} color={project.color} /></div>
                      <span className="text-[10px] font-bold text-slate-500">{project.progress}%</span>
                    </div>
                  </div>
                </Card>
              </Link>
            );
          })}
        </div>
      ) : (
        <>
          <Card className="hidden overflow-hidden md:block">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs text-slate-600">
                <thead className="border-b border-slate-100 bg-slate-50/70 text-[11px] font-medium text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Proyecto</th>
                    <th className="px-4 py-3">Cliente</th>
                    <th className="px-4 py-3">Estado</th>
                    <th className="px-4 py-3">Progreso</th>
                    <th className="px-4 py-3 text-right">Tareas</th>
                    <th className="px-4 py-3 text-right">Equipo</th>
                    <th className="px-4 py-3">Entrega</th>
                    <th className="px-4 py-3 text-right">Presupuesto</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {projects.map((project) => {
                    const { isOverdue } = projectMeta(project);
                    return (
                      <tr key={project.id} className="transition hover:bg-slate-50/60">
                        <td className="min-w-[220px] px-4 py-3">
                          <Link to={`/projects/${project.id}`} className="inline-flex items-center gap-2 font-semibold text-slate-800 hover:text-brand-700">
                            <span className="h-2.5 w-2.5 flex-shrink-0 rounded-full" style={{ backgroundColor: project.color }} />
                            <span className="max-w-[220px] truncate">{project.name}</span>
                          </Link>
                          <div className="mt-0.5 text-[10px] text-slate-400">{project.category || 'General'} · {project.agencyName}{project.shared ? ' · Compartido' : ''}</div>
                        </td>
                        <td className="max-w-[180px] truncate px-4 py-3">{project.clientName || <span className="text-slate-300">—</span>}</td>
                        <td className="whitespace-nowrap px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${project.status === 'activo' ? 'bg-emerald-50 text-emerald-700' : project.status === 'completado' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700'}`}>{STATUS_LABELS[project.status] ?? project.status}</span></td>
                        <td className="whitespace-nowrap px-4 py-3"><div className="flex items-center gap-2"><div className="w-20"><Progress value={project.progress} color={project.color} /></div><span className="text-[10px] font-bold text-slate-500">{project.progress}%</span></div></td>
                        <td className="whitespace-nowrap px-4 py-3 text-right">{project.tasks}{project.overdueTasks > 0 && <div className="text-[9px] font-semibold text-rose-600">{project.overdueTasks} vencidas</div>}</td>
                        <td className="whitespace-nowrap px-4 py-3 text-right">{project.members}</td>
                        <td className="whitespace-nowrap px-4 py-3">{project.dueDate ? <span className={isOverdue ? 'font-semibold text-rose-600' : 'text-slate-500'}>{formatDate(project.dueDate)}</span> : <span className="text-slate-300">—</span>}</td>
                        <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-800">{project.budget !== null && project.budget > 0 ? formatMoney(project.budget, project.currency) : <span className="text-slate-300">—</span>}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Card>

          <div className="space-y-3 md:hidden">
            {projects.map((project) => {
              const { isOverdue } = projectMeta(project);
              return (
                <Link key={project.id} to={`/projects/${project.id}`}>
                  <Card className="space-y-2.5 p-4">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-900">{project.name}</p>
                        <p className="truncate text-[11px] text-slate-500">{project.clientName || 'Sin cliente'}{project.shared ? ` · ${project.agencyName}` : ''}</p>
                      </div>
                      <span className={`flex-shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold ${project.status === 'activo' ? 'bg-emerald-50 text-emerald-700' : project.status === 'completado' ? 'bg-slate-100 text-slate-600' : 'bg-amber-50 text-amber-700'}`}>{STATUS_LABELS[project.status] ?? project.status}</span>
                    </div>
                    <div className="flex items-center gap-2"><div className="flex-1"><Progress value={project.progress} color={project.color} /></div><span className="text-[10px] font-bold text-slate-500">{project.progress}%</span><span className="text-[10px] text-slate-400">{project.tasks} tareas</span></div>
                    <div className="flex items-center justify-between text-[10px] text-slate-400"><span>{project.dueDate ? `Entrega ${formatDate(project.dueDate)}` : 'Sin fecha de entrega'}</span>{isOverdue && <span className="font-semibold text-rose-600">Vencido</span>}</div>
                  </Card>
                </Link>
              );
            })}
          </div>
        </>
      )}

      {createOpen && canCreate && (
        <Modal open onClose={closeCreate} panelClassName="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-slate-100 bg-white shadow-2xl">
          <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
            <div className="flex items-center gap-2.5">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><FolderKanban className="h-4 w-4" /></div>
              <div>
                <h3 className="text-base font-semibold text-slate-800">Nuevo proyecto</h3>
                <p className="text-[11px] text-slate-400">Datos generales, fechas, presupuesto y equipo</p>
              </div>
            </div>
            <button type="button" onClick={closeCreate} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
          </div>

          <form
            className="space-y-4 p-6"
            onChange={() => setDirty(true)}
            onSubmit={(event) => {
              event.preventDefault();
              setFormError('');
              const form = new FormData(event.currentTarget);
              const body: Record<string, string> = {
                name: String(form.get('name') ?? '').trim(),
                client_name: String(form.get('client_name') ?? '').trim(),
                description: String(form.get('description') ?? '').trim(),
                category: String(form.get('category') ?? '').trim(),
                status: String(form.get('status') ?? 'activo'),
                color: String(form.get('color') ?? '#0E66FF'),
                start_date: String(form.get('start_date') ?? ''),
                deadline: String(form.get('deadline') ?? ''),
                budget: String(form.get('budget') ?? '0'),
                currency: String(form.get('currency') ?? 'USD'),
                team: JSON.stringify(Object.entries(team).map(([memberId, role]) => ({ id: Number(memberId), role }))),
              };
              if (body.name === '') {
                setFormError('El nombre del proyecto es obligatorio.');
                return;
              }
              if (body.start_date !== '' && body.deadline !== '' && body.start_date > body.deadline) {
                setFormError('La fecha de inicio no puede ser posterior a la fecha de entrega.');
                return;
              }
              if (Number(body.budget) < 0) {
                setFormError('El presupuesto no puede ser negativo.');
                return;
              }
              createProject.mutate(body);
            }}
          >
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Nombre del proyecto *</label>
                <Input name="name" required maxLength={150} placeholder="Ej: Rediseño web corporativo" className="px-3.5 py-2.5 text-sm" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Cliente</label>
                <Input name="client_name" list="project-clients" maxLength={150} placeholder="Elige un cliente o escribe uno nuevo" className="px-3.5 py-2.5 text-sm" />
                <datalist id="project-clients">{(data?.clients ?? []).map((client) => <option key={client} value={client} />)}</datalist>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Categoría</label>
                <Input name="category" list="project-categories" placeholder="Ej: Desarrollo Web" className="px-3.5 py-2.5 text-sm" />
                <datalist id="project-categories">{(data?.categories ?? []).map((category) => <option key={category} value={category} />)}</datalist>
              </div>
              <div className="sm:col-span-2">
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Descripción</label>
                <textarea name="description" rows={3} maxLength={5000} placeholder="Objetivo, alcance o notas iniciales…" className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Estado</label>
                <Select name="status" defaultValue="activo" className="w-full px-3.5 py-2.5 text-sm">
                  <option value="activo">Activo</option>
                  <option value="en_espera">En espera</option>
                  <option value="completado">Completado</option>
                </Select>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Color</label>
                <input name="color" type="color" defaultValue="#0E66FF" className="h-[42px] w-full cursor-pointer rounded-xl border border-slate-200 bg-white p-1" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Fecha de inicio</label>
                <Input name="start_date" type="date" defaultValue={todayIso()} className="px-3.5 py-2.5 text-sm" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Fecha de entrega</label>
                <Input name="deadline" type="date" className="px-3.5 py-2.5 text-sm" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Presupuesto</label>
                <Input name="budget" type="number" min="0" step="0.01" inputMode="decimal" defaultValue="0" className="px-3.5 py-2.5 text-sm font-semibold" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Moneda</label>
                <Select name="currency" defaultValue="USD" className="w-full px-3.5 py-2.5 font-mono text-sm">
                  {CURRENCIES.map((currency) => <option key={currency} value={currency}>{currency}</option>)}
                </Select>
              </div>
            </div>

            <div>
              <div className="mb-2 flex items-center justify-between">
                <label className="flex items-center gap-1.5 text-xs font-medium text-slate-700"><Users className="h-3.5 w-3.5" /> Equipo del proyecto</label>
                <span className="text-[10px] text-slate-400">Tú quedarás como Project Manager</span>
              </div>
              <div className="max-h-56 space-y-1.5 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50/50 p-2">
                {(data?.team ?? []).length === 0 && <p className="px-2 py-3 text-center text-[11px] text-slate-400">No hay usuarios disponibles.</p>}
                {(data?.team ?? []).map((member) => {
                  const selected = team[member.id] !== undefined;
                  return (
                    <div key={member.id} className={`flex flex-wrap items-center gap-2 rounded-lg px-2 py-1.5 transition ${selected ? 'bg-white shadow-2xs' : 'hover:bg-white/70'}`}>
                      <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-2">
                        <input
                          type="checkbox"
                          checked={selected}
                          onChange={() => setTeam((current) => {
                            const next = { ...current };
                            if (next[member.id] !== undefined) delete next[member.id];
                            else next[member.id] = member.role === 'cliente' ? 'cliente' : 'colaborador';
                            return next;
                          })}
                          className="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                        />
                        <span className="min-w-0">
                          <span className="block truncate text-[11px] font-semibold text-slate-700">{member.name}</span>
                          <span className="block truncate text-[10px] text-slate-400">{member.email}</span>
                        </span>
                      </label>
                      <Select value={team[member.id] ?? 'colaborador'} disabled={!selected} onChange={(event) => setTeam((current) => ({ ...current, [member.id]: event.target.value }))} className="w-[140px] px-2 py-1 text-[10px] disabled:opacity-40">
                        {MEMBER_ROLES.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}
                      </Select>
                    </div>
                  );
                })}
              </div>
            </div>

            {(formError || createProject.isError) && <ErrorState message={formError || (createProject.error instanceof Error ? createProject.error.message : 'No se pudo crear el proyecto.')} />}

            <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
              <button type="button" onClick={closeCreate} className="rounded-xl px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
              <LoadingButton type="submit" pending={createProject.isPending} pendingLabel="Creando…" className="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60">Crear proyecto</LoadingButton>
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}
