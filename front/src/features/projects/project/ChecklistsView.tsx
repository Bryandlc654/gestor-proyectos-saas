import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CheckSquare, ListChecks, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { LoadingButton, useConfirm, useToast } from '../../../components/feedback';
import { ErrorState, Input, Select } from '../../../components/ui';
import { apiPostRoot } from '../../../lib/api';
import type { ProjectTabsData } from '../../../types';
import { errorMessage, FieldLabel, ModalActions, ModalShell, useProject } from './shared';

export function ChecklistsView() {
  const { projectId, data, tabs, perms, openTask, refresh } = useProject();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [creating, setCreating] = useState(false);
  const key = ['project-tabs', String(projectId)];
  const checklists = tabs?.checklists ?? [];

  const post = (body: Record<string, string | number>) => apiPostRoot<{ success: boolean; message?: string }>('/api/checklists.php', { project_id: projectId, ...body }).then((result) => {
    if (result && result.success === false) throw new Error(result.message ?? 'No se pudo completar la acción.');
    return result;
  });

  // Marcar un paso es instantáneo: se actualiza la caché y se revierte si falla.
  const toggleItem = useMutation({
    mutationFn: ({ itemId, completed }: { itemId: number; completed: boolean }) => post({ action: 'toggle_item', item_id: itemId, is_completed: completed ? 1 : 0 }),
    onMutate: async ({ itemId, completed }) => {
      await queryClient.cancelQueries({ queryKey: key });
      const snapshot = queryClient.getQueryData<ProjectTabsData>(key);
      if (snapshot) {
        queryClient.setQueryData<ProjectTabsData>(key, {
          ...snapshot,
          checklists: snapshot.checklists.map((list) => {
            if (!list.items.some((item) => item.id === itemId)) return list;
            const items = list.items.map((item) => (item.id === itemId ? { ...item, isCompleted: completed } : item));
            const done = items.filter((item) => item.isCompleted).length;
            return { ...list, items, completed: done, percentage: items.length ? Math.round((done / items.length) * 100) : 0 };
          }),
        });
      }
      return { snapshot };
    },
    onError: (error, _vars, context) => {
      if (context?.snapshot) queryClient.setQueryData(key, context.snapshot);
      toast(errorMessage(error, 'No se pudo actualizar el paso.'), 'error');
    },
    onSettled: () => { refresh.tabs(); refresh.project(); },
  });
  const addItem = useMutation({
    mutationFn: ({ checklistId, title }: { checklistId: number; title: string }) => post({ action: 'add_item', checklist_id: checklistId, title }),
    onSuccess: () => { refresh.tabs(); refresh.project(); },
    onError: (error) => toast(errorMessage(error, 'No se pudo añadir el paso.'), 'error'),
  });
  const deleteItem = useMutation({
    mutationFn: (itemId: number) => post({ action: 'delete_item', item_id: itemId }),
    onSuccess: () => { refresh.tabs(); refresh.project(); },
    onError: (error) => toast(errorMessage(error, 'No se pudo eliminar el paso.'), 'error'),
  });
  const deleteChecklist = useMutation({
    mutationFn: (checklistId: number) => post({ action: 'delete_checklist', checklist_id: checklistId }),
    onSuccess: () => { refresh.tabs(); refresh.project(); toast('Checklist eliminado.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo eliminar el checklist.'), 'error'),
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-xs text-slate-500">{checklists.length} lista{checklists.length === 1 ? '' : 's'} · los checklists vinculados también aparecen dentro de su tarea.</p>
        {perms.manageChecklists && <button type="button" onClick={() => setCreating(true)} className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-brand-700"><Plus className="h-3.5 w-3.5" />Nueva lista</button>}
      </div>

      {checklists.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><ListChecks className="h-6 w-6" /></div>
          <h3 className="text-sm font-semibold text-slate-800">No hay listas de verificación</h3>
          <p className="mt-1 text-xs text-slate-500">Crea un checklist para asegurar los requisitos de calidad.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {checklists.map((checklist) => (
            <div key={checklist.id} className="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <h3 className="truncate text-sm font-semibold text-slate-900">{checklist.title}</h3>
                  {checklist.taskId && checklist.taskTitle && <button type="button" onClick={() => openTask(checklist.taskId!)} className="mt-1 inline-flex max-w-full items-center gap-1 rounded-md bg-brand-50 px-2 py-0.5 text-[10px] font-semibold text-brand-700 hover:bg-brand-100"><CheckSquare className="h-3 w-3" /><span className="truncate">Tarea: {checklist.taskTitle}</span></button>}
                  <p className="mt-1 text-[11px] text-slate-400">{checklist.completed} de {checklist.total} pasos · {checklist.percentage}%</p>
                </div>
                {perms.manageChecklists && <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar checklist', description: checklist.title, confirmLabel: 'Eliminar', tone: 'danger' })) deleteChecklist.mutate(checklist.id); }} className="rounded p-1 text-slate-400 hover:text-rose-600" title="Eliminar checklist"><Trash2 className="h-4 w-4" /></button>}
              </div>
              <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-emerald-500 transition-all duration-300" style={{ width: `${checklist.percentage}%` }} /></div>
              <div className="space-y-1">
                {checklist.items.map((item) => (
                  <div key={item.id} className="group flex items-center justify-between rounded-lg px-1.5 py-1 hover:bg-slate-50">
                    <label className={`flex min-w-0 flex-1 items-center gap-2.5 ${perms.manageChecklists ? 'cursor-pointer' : ''}`}>
                      <input type="checkbox" checked={item.isCompleted} disabled={!perms.manageChecklists} onChange={(event) => toggleItem.mutate({ itemId: item.id, completed: event.target.checked })} className="h-4 w-4 rounded border-slate-300 text-brand-600" />
                      <span className={`truncate text-xs ${item.isCompleted ? 'text-slate-400 line-through' : 'text-slate-700'}`}>{item.title}</span>
                    </label>
                    {perms.manageChecklists && <button type="button" onClick={() => deleteItem.mutate(item.id)} className="rounded p-1 text-slate-300 opacity-0 transition hover:text-rose-600 group-hover:opacity-100" title="Eliminar paso"><X className="h-3.5 w-3.5" /></button>}
                  </div>
                ))}
              </div>
              {perms.manageChecklists && (
                <form className="flex items-center gap-2 border-t border-slate-100 pt-2" onSubmit={(event) => {
                  event.preventDefault();
                  const form = event.currentTarget;
                  const title = String(new FormData(form).get('title') ?? '').trim();
                  if (!title) return;
                  addItem.mutate({ checklistId: checklist.id, title });
                  form.reset();
                }}>
                  <Plus className="h-3.5 w-3.5 text-slate-400" />
                  <input name="title" placeholder="Añadir un paso y pulsar Enter" className="w-full bg-transparent py-1 text-xs text-slate-700 placeholder:text-slate-400 focus:outline-none" />
                </form>
              )}
            </div>
          ))}
        </div>
      )}

      {creating && <NewChecklistModal tasks={data.tasks} onClose={() => setCreating(false)} />}
    </div>
  );
}

function NewChecklistModal({ tasks, onClose }: { tasks: Array<{ id: number; title: string }>; onClose: () => void }) {
  const { projectId, refresh } = useProject();
  const toast = useToast();
  const [title, setTitle] = useState('');
  const [taskId, setTaskId] = useState('');
  const create = useMutation({
    mutationFn: () => apiPostRoot<{ success: boolean; message?: string }>('/api/checklists.php', { action: 'create_checklist', project_id: projectId, title, task_id: taskId || 0 }).then((result) => {
      if (result && result.success === false) throw new Error(result.message ?? 'No se pudo crear la lista.');
      return result;
    }),
    onSuccess: () => { refresh.tabs(); refresh.project(); toast('Checklist creado.'); onClose(); },
  });
  return (
    <ModalShell title="Nueva lista de verificación" subtitle="Checklist del proyecto" onClose={onClose}>
      <form className="space-y-4 p-6" onSubmit={(event) => { event.preventDefault(); create.mutate(); }}>
        <div><FieldLabel>Nombre de la lista *</FieldLabel><Input value={title} onChange={(event) => setTitle(event.target.value)} required autoFocus placeholder="Ej: Control de calidad" className="px-3.5 py-2.5 text-sm" /></div>
        <div><FieldLabel>Vincular a una tarea (opcional)</FieldLabel><Select value={taskId} onChange={(event) => setTaskId(event.target.value)} className="w-full px-3.5 py-2.5"><option value="">Sin tarea (lista del proyecto)</option>{tasks.map((task) => <option key={task.id} value={task.id}>{task.title}</option>)}</Select></div>
        {create.isError && <ErrorState message={errorMessage(create.error, 'No se pudo crear la lista.')} />}
        <ModalActions onCancel={onClose}><LoadingButton type="submit" pending={create.isPending} disabled={!title.trim()} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">Crear lista</LoadingButton></ModalActions>
      </form>
    </ModalShell>
  );
}
