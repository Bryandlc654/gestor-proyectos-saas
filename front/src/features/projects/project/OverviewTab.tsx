import { AlertTriangle, Clock, Diamond, LifeBuoy, ListChecks, Plus, Receipt } from 'lucide-react';
import { useState } from 'react';
import { Card, EmptyState, Progress } from '../../../components/ui';
import { formatDate, formatMoney } from '../../../lib/format';
import { Avatar, daysUntil, formatDateTime, KIND_DOT, ModalShell, relativeTime, todayIso, useProject } from './shared';

const ROLE_LABELS: Record<string, string> = { admin: 'Administrador', project_manager: 'Project Manager', colaborador: 'Colaborador', cliente: 'Cliente' };

export function OverviewTab() {
  const { data, tabs, insights, clientView, perms, doneKeys, openTask, openNewTask, goTo } = useProject();
  const [showActivity, setShowActivity] = useState(false);
  const { project, members, tasks, stats } = data;
  const finances = clientView ? null : insights?.finances ?? null;
  const currency = finances?.currency ?? project.currency;
  const today = todayIso();
  const openTasks = tasks.filter((task) => !doneKeys.includes(task.status));
  const overdue = openTasks.filter((task) => task.dueDate !== null && task.dueDate < today);
  const upcoming = [...openTasks].sort((a, b) => (a.dueDate ?? '9999-12-31').localeCompare(b.dueDate ?? '9999-12-31')).slice(0, 5);
  const statusGroups = data.statuses.map((status) => ({ ...status, count: tasks.filter((task) => task.status === status.key).length })).filter((group) => group.count > 0);
  const dueInDays = project.dueDate ? daysUntil(project.dueDate) : null;
  const milestones = (insights?.milestones ?? []).filter((milestone) => !milestone.done).slice(0, 4);
  const activity = clientView ? [] : tabs?.activity ?? [];
  const openTickets = (tabs?.tickets ?? []).filter((ticket) => ticket.status === 'abierto' || ticket.status === 'en_revision').length;

  return (
    <section className="space-y-5">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Card className="space-y-2 p-4 sm:p-5">
          <span className="text-xs font-medium text-slate-500">Progreso general</span>
          <p className="text-2xl font-semibold leading-none tracking-tight text-slate-900">{stats.progress}%</p>
          <Progress value={stats.progress} color={project.color} />
          <span className="text-[11px] text-slate-400">{stats.done} de {stats.total} tareas completadas</span>
        </Card>
        <button type="button" onClick={() => goTo('tareas', overdue.length > 0 ? { filtro: 'vencidas' } : {})} className="text-left">
          <Card className="h-full space-y-2 p-4 transition hover:border-slate-300 sm:p-5">
            <div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Tareas abiertas</span><span className="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-50 text-amber-500"><Clock className="h-3.5 w-3.5" /></span></div>
            <p className="text-2xl font-semibold leading-none tracking-tight text-slate-900">{openTasks.length}</p>
            {overdue.length > 0
              ? <span className="inline-flex w-fit items-center gap-1 rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-600"><AlertTriangle className="h-3 w-3" />{overdue.length} vencida{overdue.length === 1 ? '' : 's'}</span>
              : <span className="text-[11px] text-slate-400">Sin tareas vencidas</span>}
          </Card>
        </button>
        {clientView ? (
          <button type="button" onClick={() => goTo('conversacion', { canal: 'tickets' })} className="text-left">
            <Card className="h-full space-y-2 p-4 transition hover:border-slate-300 sm:p-5">
              <div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Tickets abiertos</span><span className="flex h-7 w-7 items-center justify-center rounded-lg bg-brand-50 text-brand-500"><LifeBuoy className="h-3.5 w-3.5" /></span></div>
              <p className="text-2xl font-semibold leading-none tracking-tight text-slate-900">{openTickets}</p>
              <span className="text-[11px] text-slate-400">{tabs?.tickets.length ?? 0} en total</span>
            </Card>
          </button>
        ) : (
          <button type="button" onClick={() => goTo('tareas', { vista: 'checklists' })} className="text-left">
            <Card className="h-full space-y-2 p-4 transition hover:border-slate-300 sm:p-5">
              <div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Checklists</span><span className="flex h-7 w-7 items-center justify-center rounded-lg bg-brand-50 text-brand-500"><ListChecks className="h-3.5 w-3.5" /></span></div>
              <p className="text-2xl font-semibold leading-none tracking-tight text-slate-900">{stats.checklistDone}<span className="text-sm font-medium text-slate-400">/{stats.checklistTotal}</span></p>
              <span className="text-[11px] text-slate-400">{stats.checklistTotal > 0 ? `${Math.round((stats.checklistDone / stats.checklistTotal) * 100)}% de pasos completados` : 'Sin checklists todavía'}</span>
            </Card>
          </button>
        )}
        <Card className="space-y-2 p-4 sm:p-5">
          <div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">{finances ? 'Por cobrar' : 'Entrega'}</span><span className="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-50 text-emerald-500"><Receipt className="h-3.5 w-3.5" /></span></div>
          {finances ? (
            <>
              <p className="text-xl font-semibold leading-none tracking-tight text-slate-900">{formatMoney(finances.totals.pending, currency)}</p>
              <span className="text-[11px] text-slate-400">{formatMoney(finances.totals.paid, currency)} cobrado de {formatMoney(project.budget ?? finances.budget, currency)}</span>
            </>
          ) : (
            <>
              <p className="text-xl font-semibold leading-none tracking-tight text-slate-900">{project.dueDate ? formatDate(project.dueDate) : '—'}</p>
              {dueInDays !== null && project.status !== 'completado' && <span className={`text-[11px] ${dueInDays < 0 ? 'text-rose-500' : 'text-slate-400'}`}>{dueInDays < 0 ? `${Math.abs(dueInDays)} días de retraso` : dueInDays === 0 ? 'Vence hoy' : `Faltan ${dueInDays} días`}</span>}
            </>
          )}
        </Card>
      </div>

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="space-y-5">
          <Card className="p-5">
            <h2 className="text-sm font-semibold text-slate-900">Descripción y fechas</h2>
            <p className="mt-2 whitespace-pre-line text-xs leading-relaxed text-slate-600">{project.description || 'Este proyecto todavía no tiene descripción.'}</p>
            <div className="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-3 text-[11px] sm:grid-cols-4">
              <Fact label="Cliente" value={project.clientName || '—'} />
              <Fact label="Inicio" value={project.startDate ? formatDate(project.startDate) : '—'} />
              <div>
                <span className="block text-[10px] uppercase tracking-wider text-slate-400">Entrega</span>
                <span className={`mt-0.5 block font-semibold ${dueInDays !== null && dueInDays < 0 && project.status !== 'completado' ? 'text-rose-600' : 'text-slate-700'}`}>{project.dueDate ? formatDate(project.dueDate) : '—'}</span>
                {dueInDays !== null && project.status !== 'completado' && <span className={`text-[10px] ${dueInDays < 0 ? 'text-rose-500' : 'text-slate-400'}`}>{dueInDays < 0 ? `${Math.abs(dueInDays)} días de retraso` : dueInDays === 0 ? 'vence hoy' : `faltan ${dueInDays} días`}</span>}
              </div>
              <Fact label="Agencia" value={project.agencyName} />
            </div>
          </Card>

          <Card className="p-5">
            <div className="flex items-center justify-between gap-2">
              <h2 className="text-sm font-semibold text-slate-900">Próximas tareas</h2>
              <div className="flex items-center gap-2">
                <button type="button" onClick={() => goTo('tareas')} className="text-[11px] font-semibold text-slate-500 hover:text-slate-800">Ver tablero</button>
                {perms.editTasks && <button type="button" onClick={openNewTask} className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1 text-[10px] font-semibold text-white hover:bg-brand-700"><Plus className="h-3 w-3" />Nueva</button>}
              </div>
            </div>
            <div className="mt-3 space-y-1.5">
              {upcoming.length === 0 && <p className="rounded-xl border border-dashed border-slate-200 px-3 py-6 text-center text-[11px] text-slate-400">{openTasks.length === 0 ? 'No hay tareas pendientes. ¡Todo al día!' : 'Sin tareas pendientes con fecha.'}</p>}
              {upcoming.map((task) => {
                const kind = data.statuses.find((status) => status.key === task.status)?.kind ?? 'open';
                const late = task.dueDate !== null && task.dueDate < today;
                return (
                  <button key={task.id} type="button" onClick={() => openTask(task.id)} className="flex w-full items-center gap-2.5 rounded-xl border border-slate-200/70 px-3 py-2 text-left transition hover:border-slate-300 hover:bg-slate-50/60">
                    <span className={`h-1.5 w-1.5 flex-shrink-0 rounded-full ${KIND_DOT[kind] ?? 'bg-slate-400'}`} />
                    <span className="min-w-0 flex-1"><span className="block truncate text-[11px] font-semibold text-slate-700">{task.title}</span><span className="block truncate text-[10px] text-slate-400">{task.assignee ?? 'Sin asignar'} · {task.statusLabel}</span></span>
                    <span className={`whitespace-nowrap text-[10px] ${late ? 'font-semibold text-rose-600' : 'text-slate-400'}`}>{task.dueDate ? formatDate(task.dueDate) : 'Sin fecha'}</span>
                  </button>
                );
              })}
            </div>
          </Card>

          <Card className="p-5">
            <div className="flex items-center justify-between"><h2 className="text-sm font-semibold text-slate-900">Estado de las tareas</h2><span className="text-[10px] text-slate-400">{tasks.length} en total</span></div>
            <div className="mt-3 flex h-2 overflow-hidden rounded-full bg-slate-100">
              {statusGroups.map((group) => <div key={group.key} className={KIND_DOT[group.kind] ?? 'bg-slate-400'} style={{ width: `${(group.count / Math.max(1, tasks.length)) * 100}%` }} title={`${group.label}: ${group.count}`} />)}
            </div>
            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
              {statusGroups.length === 0 && <span className="text-[11px] text-slate-400">Sin tareas registradas.</span>}
              {statusGroups.map((group) => <span key={group.key} className="inline-flex items-center gap-1.5 text-[11px] text-slate-500"><span className={`h-1.5 w-1.5 rounded-full ${KIND_DOT[group.kind] ?? 'bg-slate-400'}`} />{group.label}<span className="font-bold text-slate-700">{group.count}</span></span>)}
            </div>
          </Card>
        </div>

        <div className="space-y-5">
          <Card className="p-5">
            <div className="flex items-center justify-between gap-2"><h2 className="text-sm font-semibold text-slate-900">Próximos hitos</h2><button type="button" onClick={() => goTo('tareas', { vista: 'cronograma' })} className="text-[11px] font-semibold text-brand-600 hover:underline">Cronograma</button></div>
            <div className="mt-3 space-y-2">
              {milestones.length === 0 && <p className="text-[11px] text-slate-400">{perms.manageMilestones ? 'Aún no hay hitos. Créalos desde el cronograma.' : 'Sin hitos pendientes.'}</p>}
              {milestones.map((milestone) => {
                const late = milestone.date < today;
                return (
                  <div key={milestone.id} className="flex items-center gap-2.5">
                    <Diamond className={`h-3.5 w-3.5 flex-shrink-0 ${late ? 'fill-rose-500 text-rose-500' : 'fill-brand-500 text-brand-500'}`} />
                    <span className="min-w-0 flex-1 truncate text-[11px] font-semibold text-slate-700">{milestone.title}</span>
                    <span className={`whitespace-nowrap text-[10px] ${late ? 'font-semibold text-rose-600' : 'text-slate-400'}`}>{formatDate(milestone.date, { day: '2-digit', month: 'short' })}</span>
                  </div>
                );
              })}
            </div>
          </Card>

          <Card className="p-5">
            <h2 className="text-sm font-semibold text-slate-900">Equipo</h2>
            <div className="mt-3 space-y-2">
              {members.length === 0 && <p className="text-[11px] text-slate-400">Sin miembros asignados.</p>}
              {members.slice(0, 6).map((member) => (
                <div key={member.id} className="flex items-center gap-2">
                  <Avatar name={member.name} url={member.avatarUrl} />
                  <div className="min-w-0 flex-1"><p className="truncate text-[11px] font-semibold text-slate-700">{member.name}</p><p className="truncate text-[10px] text-slate-400">{ROLE_LABELS[member.projectRole ?? ''] ?? ROLE_LABELS[member.role] ?? member.role}</p></div>
                </div>
              ))}
              {members.length > 6 && <p className="text-[10px] text-slate-400">+{members.length - 6} más</p>}
            </div>
          </Card>

          {!clientView && (
            <Card className="p-5">
              <div className="flex items-center justify-between gap-2"><h2 className="text-sm font-semibold text-slate-900">Actividad reciente</h2>{activity.length > 6 && <button type="button" onClick={() => setShowActivity(true)} className="text-[11px] font-semibold text-slate-500 hover:text-slate-800">Ver todo</button>}</div>
              <div className="mt-3 space-y-2.5">
                {activity.length === 0 && <p className="text-[11px] text-slate-400">Aún no hay actividad registrada.</p>}
                {activity.slice(0, 6).map((item) => <ActivityItem key={item.id} item={item} />)}
              </div>
            </Card>
          )}
        </div>
      </div>

      {showActivity && (
        <ModalShell title="Actividad del proyecto" subtitle={`${activity.length} eventos recientes`} onClose={() => setShowActivity(false)}>
          {activity.length === 0 ? <EmptyState title="Sin actividad" /> : (
            <div className="divide-y divide-slate-100">
              {activity.map((item) => (
                <div key={item.id} className="px-6 py-3">
                  <p className="text-xs font-semibold text-slate-800">{item.label}</p>
                  {item.description && <p className="text-[11px] text-slate-500">{item.description}</p>}
                  <p className="text-[10px] text-slate-400">{item.user ?? 'Sistema'} · {formatDateTime(item.createdAt)}</p>
                </div>
              ))}
            </div>
          )}
        </ModalShell>
      )}
    </section>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return <div><span className="block text-[10px] uppercase tracking-wider text-slate-400">{label}</span><span className="mt-0.5 block truncate font-semibold text-slate-700">{value}</span></div>;
}

function ActivityItem({ item }: { item: { label: string; description: string | null; user: string | null; createdAt: string } }) {
  return (
    <div className="flex items-start gap-2">
      <span className="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-slate-300" />
      <div className="min-w-0 flex-1">
        <p className="truncate text-[11px] font-semibold text-slate-700">{item.label}</p>
        {item.description && <p className="truncate text-[10px] text-slate-500">{item.description}</p>}
        <p className="text-[10px] text-slate-400">{item.user ?? 'Sistema'} · {relativeTime(item.createdAt)}</p>
      </div>
    </div>
  );
}
