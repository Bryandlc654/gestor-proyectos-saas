import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Archive, CheckSquare, Copy, Eye, EyeOff, FolderKanban, LayoutDashboard, LayoutTemplate, MessagesSquare, MoreHorizontal, NotebookPen, Paperclip, Plus, Receipt, Settings2, Share2, UserCog, UserPlus, Users } from 'lucide-react';
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useConfirm, useToast } from '../../components/feedback';
import { ErrorState, Skeleton } from '../../components/ui';
import { apiGet, apiPost } from '../../lib/api';
import type { ProjectDetail, ProjectInsightsData, ProjectTabsData } from '../../types';
import { TaskDetailModal } from '../tasks/TaskDetailModal';
import { ClientTaskPreview, DuplicateModal, EditProjectModal, NewTaskModal, TeamModal, TemplateModal } from './project/ProjectModals';
import { ShareProjectModal, ShareTeamModal } from './project/ShareModals';
import { Avatar, ProjectContext, type ProjectContextValue, type ProjectPermissions, type TabKey } from './project/shared';

const OverviewTab = lazy(() => import('./project/OverviewTab').then((m) => ({ default: m.OverviewTab })));
const TasksTab = lazy(() => import('./project/TasksTab').then((m) => ({ default: m.TasksTab })));
const ConversationTab = lazy(() => import('./project/ConversationTab').then((m) => ({ default: m.ConversationTab })));
const FilesTab = lazy(() => import('./project/FilesTab').then((m) => ({ default: m.FilesTab })));
const FinanceTab = lazy(() => import('./project/FinanceTab').then((m) => ({ default: m.FinanceTab })));
const MeetingsTab = lazy(() => import('./project/MeetingsTab').then((m) => ({ default: m.MeetingsTab })));

const TAB_KEYS: TabKey[] = ['resumen', 'tareas', 'conversacion', 'archivos', 'actas', 'finanzas'];
// Compatibilidad con enlaces antiguos (?tab=tasks, ?tab=comments...).
const LEGACY_TABS: Record<string, [TabKey, Record<string, string>?]> = {
  tasks: ['tareas'], gantt: ['tareas', { vista: 'cronograma' }], checklists: ['tareas', { vista: 'checklists' }],
  tickets: ['conversacion', { canal: 'tickets' }], comments: ['conversacion'], adjuntos: ['archivos'], activity: ['resumen'],
};

const PROJECT_STATUS: Record<string, { label: string; className: string }> = {
  activo: { label: 'Activo', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200/70' },
  en_espera: { label: 'En espera', className: 'bg-amber-50 text-amber-700 ring-amber-200/70' },
  completado: { label: 'Completado', className: 'bg-slate-100 text-slate-600 ring-slate-200/70' },
};

type ModalKey = 'team' | 'newTask' | 'duplicate' | 'template' | 'edit' | 'share' | 'shareTeam' | null;

export function ProjectPage() {
  const { id } = useParams();
  const projectId = Number(id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [searchParams, setSearchParams] = useSearchParams();
  const [modal, setModal] = useState<ModalKey>(null);
  const [detailTaskId, setDetailTaskId] = useState<number | null>(null);
  const [preview, setPreview] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  const rawTab = searchParams.get('tab') ?? 'resumen';
  const tab: TabKey = (TAB_KEYS as string[]).includes(rawTab) ? (rawTab as TabKey) : (LEGACY_TABS[rawTab]?.[0] ?? 'resumen');

  const goTo = useCallback((next: TabKey, params: Record<string, string> = {}) => {
    const query = new URLSearchParams();
    if (next !== 'resumen') query.set('tab', next);
    Object.entries(params).forEach(([key, value]) => { if (value) query.set(key, value); });
    setSearchParams(query);
  }, [setSearchParams]);

  // Redirige pestañas antiguas a su nuevo lugar.
  useEffect(() => {
    const legacy = LEGACY_TABS[rawTab];
    if (legacy) goTo(legacy[0], legacy[1]);
  }, [rawTab, goTo]);

  const projectQuery = useQuery({ queryKey: ['project', id], queryFn: () => apiGet<ProjectDetail>('projects.php', { id }), enabled: Boolean(id) });
  const tabsQuery = useQuery({ queryKey: ['project-tabs', id], queryFn: () => apiGet<ProjectTabsData>('project-tabs.php', { id }), enabled: Boolean(id) });
  const insightsQuery = useQuery({ queryKey: ['project-insights', id], queryFn: () => apiGet<ProjectInsightsData>('project-insights.php', { id }), enabled: Boolean(id) });

  const refresh = useMemo(() => {
    // Las vistas globales (dashboard, tareas) solo se marcan como desactualizadas: se recargan al visitarlas.
    const markGlobal = () => {
      void queryClient.invalidateQueries({ queryKey: ['dashboard'], refetchType: 'none' });
      void queryClient.invalidateQueries({ queryKey: ['tasks'], refetchType: 'none' });
      // El sidebar marca los proyectos al 100%: se recarga para reflejar cambios de tareas.
      void queryClient.invalidateQueries({ queryKey: ['bootstrap'] });
    };
    const project = () => { void queryClient.invalidateQueries({ queryKey: ['project', id] }); markGlobal(); };
    const tabs = () => void queryClient.invalidateQueries({ queryKey: ['project-tabs', id] });
    const insights = () => void queryClient.invalidateQueries({ queryKey: ['project-insights', id] });
    return { project, tabs, insights, all: () => { project(); tabs(); insights(); void queryClient.invalidateQueries({ queryKey: ['projects'], refetchType: 'none' }); } };
  }, [queryClient, id]);

  // Cambio de estado optimista: la tarjeta se mueve al instante y se revierte si el servidor falla.
  const statusMutation = useMutation({
    mutationFn: ({ taskId, status }: { taskId: number; status: string; previous?: string; silent?: boolean }) =>
      apiPost<{ success: boolean }>('tasks.php', { action: 'update_status', project_id: projectId, task_id: taskId, status }),
    onMutate: async ({ taskId, status }) => {
      await queryClient.cancelQueries({ queryKey: ['project', id] });
      const snapshot = queryClient.getQueryData<ProjectDetail>(['project', id]);
      if (snapshot) {
        const label = snapshot.statuses.find((item) => item.key === status)?.label ?? status;
        const doneKeys = snapshot.statuses.filter((item) => item.kind === 'done').map((item) => item.key);
        const tasks = snapshot.tasks.map((task) => (task.id === taskId ? { ...task, status, statusLabel: label } : task));
        const done = tasks.filter((task) => doneKeys.includes(task.status)).length;
        queryClient.setQueryData<ProjectDetail>(['project', id], {
          ...snapshot,
          tasks,
          stats: { ...snapshot.stats, done, progress: tasks.length > 0 ? Math.round((done / tasks.length) * 100) : 0 },
        });
      }
      return { snapshot };
    },
    onError: (error, _vars, context) => {
      if (context?.snapshot) queryClient.setQueryData(['project', id], context.snapshot);
      toast(error instanceof Error ? error.message : 'No se pudo cambiar el estado.', 'error');
    },
    onSuccess: (_result, vars) => {
      if (vars.silent || !vars.previous || vars.previous === vars.status) return;
      toast('Estado actualizado.', 'success', {
        action: { label: 'Deshacer', onClick: () => statusMutation.mutate({ taskId: vars.taskId, status: vars.previous!, silent: true }) },
      });
    },
    onSettled: () => { refresh.project(); refresh.insights(); },
  });

  const projectStatus = useMutation({
    mutationFn: (status: string) => {
      const p = projectQuery.data!.project;
      return apiPost<{ message: string }>('projects.php', { action: 'update', project_id: projectId, name: p.name, description: p.description ?? '', status, deadline: p.dueDate ?? '' });
    },
    onSuccess: () => { refresh.all(); toast('Estado del proyecto actualizado.'); },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo actualizar el proyecto.', 'error'),
  });

  const archive = useMutation({
    mutationFn: () => apiPost<{ success: boolean }>('projects.php', { action: 'archive', project_id: projectId }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['projects'] });
      toast('Proyecto archivado.', 'info', {
        action: {
          label: 'Deshacer',
          onClick: async () => {
            try {
              await apiPost('trash.php', { action: 'restore', project_id: projectId });
              void queryClient.invalidateQueries({ queryKey: ['trash'] });
              void queryClient.invalidateQueries({ queryKey: ['projects'] });
              toast('Proyecto restaurado.');
              navigate(`/projects/${projectId}`);
            } catch (restoreError) {
              toast(restoreError instanceof Error ? restoreError.message : 'No se pudo restaurar el proyecto.', 'error');
            }
          },
        },
      });
      navigate('/projects');
    },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo archivar.', 'error'),
  });

  const data = projectQuery.data;
  const tabs = tabsQuery.data;
  const insights = insightsQuery.data;

  const context = useMemo<ProjectContextValue | null>(() => {
    if (!data) return null;
    const clientView = data.isClient || preview;
    const readOnly = preview;
    const allow = (value: boolean | undefined) => !readOnly && Boolean(value);
    const perms: ProjectPermissions = {
      manage: allow(data.project.canManage),
      editTasks: allow(data.project.canEditTasks),
      changeStatus: allow(data.canChangeStatus),
      deleteTasks: allow(data.canDeleteTasks),
      comment: allow(tabs?.permissions.canComment),
      moderate: allow(tabs?.permissions.canModerateComments),
      createTickets: allow(tabs?.permissions.canCreateTickets),
      changeTicketStatus: allow(tabs?.permissions.canChangeTicketStatus),
      deleteTickets: allow(tabs?.permissions.canDeleteTickets),
      uploadFiles: allow(tabs?.permissions.canUploadFiles),
      manageStructure: allow(tabs?.permissions.canManageStructure),
      manageChecklists: allow(tabs?.permissions.canManageChecklists),
      manageMilestones: allow(insights?.canManageMilestones),
    };
    return {
      projectId,
      data,
      tabs,
      insights,
      clientView,
      preview,
      perms,
      currentUserId: tabs?.currentUserId ?? null,
      doneKeys: data.statuses.filter((status) => status.kind === 'done').map((status) => status.key),
      openTask: setDetailTaskId,
      openNewTask: () => setModal('newTask'),
      setTaskStatus: (taskId, status) => {
        const previous = data.tasks.find((task) => task.id === taskId)?.status;
        if (previous === status) return;
        statusMutation.mutate({ taskId, status, previous });
      },
      goTo,
      refresh,
    };
  }, [data, tabs, insights, preview, projectId, goTo, refresh, statusMutation]);

  if (projectQuery.isLoading) return <div className="space-y-6"><Skeleton className="h-24" /><Skeleton className="h-64" /></div>;
  if (projectQuery.error || !data || !context) return <ErrorState message={projectQuery.error instanceof Error ? projectQuery.error.message : 'No se pudo cargar el proyecto.'} />;

  const { project, members } = data;
  const finances = insights?.finances ?? null;
  const showFinances = Boolean(finances) && !context.clientView;
  const openTickets = (tabs?.tickets ?? []).filter((ticket) => ticket.status === 'abierto' || ticket.status === 'en_revision').length;
  const visibleFiles = context.clientView && preview ? 0 : (tabs?.files.length ?? 0) + (tabs?.links.length ?? 0);

  const TABS: Array<{ key: TabKey; label: string; icon: typeof CheckSquare; count?: number; alert?: boolean }> = [
    { key: 'resumen', label: 'Resumen', icon: LayoutDashboard },
    { key: 'tareas', label: 'Tareas', icon: CheckSquare, count: data.tasks.length },
    { key: 'conversacion', label: 'Conversación', icon: MessagesSquare, count: openTickets > 0 ? openTickets : undefined, alert: openTickets > 0 },
    { key: 'archivos', label: 'Archivos', icon: Paperclip, count: visibleFiles },
    { key: 'actas', label: 'Actas', icon: NotebookPen },
    ...(showFinances ? [{ key: 'finanzas' as TabKey, label: 'Finanzas', icon: Receipt, alert: (finances?.totals.pending ?? 0) > 0 }] : []),
  ];
  const activeTab = tab === 'finanzas' && !showFinances ? 'resumen' : tab;
  const status = PROJECT_STATUS[project.status] ?? PROJECT_STATUS.activo;

  return (
    <ProjectContext.Provider value={context}>
      <div className="space-y-5">
        {preview && (
          <div className="flex flex-col gap-2 rounded-2xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-xs text-cyan-900 sm:flex-row sm:items-center sm:justify-between">
            <span className="flex items-center gap-2"><Eye className="h-4 w-4" /><span><strong>Vista previa como cliente.</strong> Así ve el proyecto un usuario con rol cliente: sin finanzas, checklists, actividad interna ni comentarios de las tareas. Todo está en solo lectura.</span></span>
            <button type="button" onClick={() => setPreview(false)} className="inline-flex items-center gap-1.5 self-start rounded-lg bg-white px-3 py-1.5 font-semibold text-cyan-800 ring-1 ring-cyan-200 hover:bg-cyan-100 sm:self-auto"><EyeOff className="h-3.5 w-3.5" />Salir de la vista previa</button>
          </div>
        )}

        <header className="space-y-4">
          <div className="flex flex-col justify-between gap-4 md:flex-row md:items-center">
            <div className="flex min-w-0 items-start gap-3.5">
              <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl text-white shadow-xs" style={{ backgroundColor: project.color }}><FolderKanban className="h-5 w-5" /></div>
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <h1 className="truncate text-base font-semibold tracking-tight text-slate-900 sm:text-lg">{project.name}</h1>
                  {context.perms.manage ? (
                    <label className={`relative inline-flex cursor-pointer items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ${status.className}`} title="Cambiar estado del proyecto">
                      {status.label} ▾
                      <select aria-label="Estado del proyecto" value={project.status} disabled={projectStatus.isPending} onChange={(event) => projectStatus.mutate(event.target.value)} className="absolute inset-0 cursor-pointer opacity-0">
                        {Object.entries(PROJECT_STATUS).map(([key, item]) => <option key={key} value={key}>{item.label}</option>)}
                      </select>
                    </label>
                  ) : <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ${status.className}`}>{status.label}</span>}
                  {project.shared && <span className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 ring-1 ring-indigo-200/60" title={project.sharedBy ? `Compartido por ${project.sharedBy}` : undefined}><Users className="h-3 w-3" />{project.sharedBy ? `Compartido por ${project.sharedBy}` : 'Compartido'}</span>}
                  {!project.shared && (project.sharedWithCount ?? 0) > 0 && (
                    <button type="button" disabled={!project.canManageShare || preview} onClick={() => setModal('share')} className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 ring-1 ring-indigo-200/60 enabled:hover:bg-indigo-100 disabled:cursor-default" title="Agencias con acceso a este proyecto">
                      <Share2 className="h-3 w-3" />Compartido con {project.sharedWithCount}
                    </button>
                  )}
                </div>
                <p className="mt-0.5 truncate text-xs text-slate-500">
                  {[project.clientName && `Cliente: ${project.clientName}`, project.category].filter(Boolean).join(' · ') || project.agencyName}
                </p>
              </div>
            </div>

            <div className="flex items-center gap-2 self-end md:self-center">
              <button type="button" onClick={() => setModal('team')} className="flex items-center rounded-xl px-1.5 py-1 transition hover:bg-slate-100" title={project.canManageMembers && !preview ? "Equipo del proyecto · añadir o quitar miembros" : "Equipo del proyecto"}>
                <span className="flex -space-x-2">{members.slice(0, 4).map((member) => <Avatar key={member.id} name={member.name} url={member.avatarUrl} />)}</span>
                {members.length > 4 && <span className="ml-1 text-[11px] font-semibold text-slate-500">+{members.length - 4}</span>}
                {members.length === 0 && <span className="flex items-center gap-1.5 text-xs font-semibold text-slate-600"><Users className="h-4 w-4" />Equipo</span>}
                {project.canManageMembers && !preview && <span className="ml-1 flex h-7 w-7 items-center justify-center rounded-full border border-dashed border-slate-300 text-slate-500" aria-hidden="true"><UserPlus className="h-3.5 w-3.5" /></span>}
              </button>
              {project.canManageShare && !preview && (
                <button type="button" onClick={() => setModal('share')} className="inline-flex items-center gap-1.5 rounded-xl border border-indigo-200/60 bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100" title="Compartir con agencias conectadas"><Share2 className="h-4 w-4" /><span className="hidden sm:inline">Compartir</span></button>
              )}
              {project.canManageShareMembers && !preview && (
                <button type="button" onClick={() => setModal('shareTeam')} className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200/70 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50" title="Elegir qué miembros de tu agencia participan"><UserCog className="h-4 w-4" /><span className="hidden sm:inline">Mi equipo</span></button>
              )}
              {context.perms.editTasks && (
                <button type="button" onClick={() => setModal('newTask')} className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-brand-700"><Plus className="h-4 w-4" /><span>Nueva tarea</span></button>
              )}
              {(data.project.canManage || preview) && (
                <ProjectMenu
                  preview={preview}
                  canManage={data.project.canManage && !preview}
                  onPreview={() => { setPreview((value) => !value); setDetailTaskId(null); }}
                  onEdit={() => setModal('edit')}
                  onDuplicate={() => setModal('duplicate')}
                  onTemplate={() => setModal('template')}
                  onArchive={async () => { if (await confirm({ title: 'Archivar proyecto', description: 'Podrás restaurarlo desde la Papelera.', confirmLabel: 'Archivar' })) archive.mutate(); }}
                />
              )}
            </div>
          </div>

          <nav className="flex items-center gap-1 overflow-x-auto border-b border-slate-200/70 pb-px sm:gap-2" aria-label="Secciones del proyecto">
            {TABS.map((item) => {
              const Icon = item.icon;
              const active = activeTab === item.key;
              return (
                <button key={item.key} type="button" onClick={() => goTo(item.key)} aria-current={active ? 'page' : undefined} className={`inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2 text-xs font-medium transition-all sm:px-4 sm:text-sm ${active ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900'}`}>
                  <Icon className="h-4 w-4" />
                  <span>{item.label}</span>
                  {item.count !== undefined && item.count > 0 && <span className={`rounded-full px-1.5 text-[10px] font-bold ${item.alert ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}`}>{item.count}</span>}
                  {item.count === undefined && item.alert && <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />}
                </button>
              );
            })}
          </nav>
        </header>

        {notice && <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{notice}</div>}

        <Suspense fallback={<Skeleton className="h-64" />}>
          {activeTab === 'resumen' && <OverviewTab />}
          {activeTab === 'tareas' && <TasksTab />}
          {activeTab === 'conversacion' && <ConversationTab />}
          {activeTab === 'archivos' && <FilesTab />}
          {activeTab === 'actas' && <MeetingsTab />}
          {activeTab === 'finanzas' && <FinanceTab />}
        </Suspense>

        {modal === 'team' && <TeamModal onClose={() => setModal(null)} />}
        {modal === 'share' && <ShareProjectModal onClose={() => setModal(null)} />}
        {modal === 'shareTeam' && <ShareTeamModal onClose={() => setModal(null)} />}
        {modal === 'newTask' && <NewTaskModal onClose={() => setModal(null)} />}
        {modal === 'duplicate' && <DuplicateModal onClose={() => setModal(null)} onDone={(newId) => { setModal(null); navigate(`/projects/${newId}`); }} />}
        {modal === 'template' && <TemplateModal onClose={() => setModal(null)} />}
        {modal === 'edit' && <EditProjectModal onClose={() => setModal(null)} onSaved={(message) => { setModal(null); setNotice(message); toast(message); }} />}

        {detailTaskId !== null && (preview ? (
          <ClientTaskPreview taskId={detailTaskId} onClose={() => setDetailTaskId(null)} />
        ) : (
          <TaskDetailModal
            taskId={detailTaskId}
            projectId={projectId}
            projectName={project.name}
            statuses={data.statuses}
            assignees={members.map((member) => ({ id: member.id, name: member.name }))}
            taskIds={data.tasks.map((task) => task.id)}
            onClose={() => setDetailTaskId(null)}
            onChanged={() => { refresh.project(); refresh.tabs(); refresh.insights(); }}
          />
        ))}
      </div>
    </ProjectContext.Provider>
  );
}

function ProjectMenu({ preview, canManage, onPreview, onEdit, onDuplicate, onTemplate, onArchive }: {
  preview: boolean;
  canManage: boolean;
  onPreview: () => void;
  onEdit: () => void;
  onDuplicate: () => void;
  onTemplate: () => void;
  onArchive: () => void;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const close = (event: MouseEvent | KeyboardEvent) => {
      if (event instanceof KeyboardEvent ? event.key === 'Escape' : !ref.current?.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', close);
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', close); };
  }, [open]);

  const item = (label: string, Icon: typeof Eye, action: () => void, tone = 'text-slate-700 hover:bg-slate-50') => (
    <button type="button" role="menuitem" onClick={() => { setOpen(false); action(); }} className={`flex w-full items-center gap-2.5 px-3 py-2 text-left text-xs font-medium ${tone}`}><Icon className="h-4 w-4" />{label}</button>
  );

  return (
    <div ref={ref} className="relative">
      <button type="button" onClick={() => setOpen((value) => !value)} aria-haspopup="menu" aria-expanded={open} className="rounded-xl border border-slate-200/70 p-2 text-slate-500 transition hover:bg-slate-50 hover:text-slate-800" title="Más acciones"><MoreHorizontal className="h-4 w-4" /></button>
      {open && (
        <div role="menu" className="absolute right-0 top-full z-30 mt-1.5 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl">
          {item(preview ? 'Salir de la vista de cliente' : 'Ver como cliente', preview ? EyeOff : Eye, onPreview)}
          {canManage && (
            <>
              <div className="my-1 border-t border-slate-100" />
              {item('Configuración', Settings2, onEdit)}
              {item('Duplicar proyecto', Copy, onDuplicate)}
              {item('Guardar como plantilla', LayoutTemplate, onTemplate)}
              <div className="my-1 border-t border-slate-100" />
              {item('Archivar proyecto', Archive, onArchive, 'text-amber-700 hover:bg-amber-50')}
            </>
          )}
        </div>
      )}
    </div>
  );
}
