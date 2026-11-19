import { useQuery } from '@tanstack/react-query';
import { Activity, Calendar, ChevronLeft, ChevronRight, ExternalLink, Image as ImageIcon, Link2, ListChecks, ListTree, MessageSquare, Paperclip, Pencil, PlayCircle, Plus, Trash2, Upload, User, X } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { apiPostRoot, apiUploadRoot } from '../../lib/api';
import { COMMENT_IMAGE_ACCEPT, commentImageError } from '../../lib/upload';
import type { TaskStatus } from '../../types';
import { ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { LoadingButton, Modal, useConfirm, useToast } from '../../components/feedback';
import { useUnsavedChanges } from '../../hooks/useUnsavedChanges';

interface MiniTask {
  id: number;
  title: string;
  status: string;
  status_label: string;
  done: boolean;
  assigned_to: string;
  due_date: string;
  priority: string;
}

interface TaskComment {
  id: number;
  author_name: string;
  initials: string;
  raw_content: string;
  image_url: string | null;
  created_at: string;
  can_manage: boolean;
}

interface ChecklistItem {
  id: number;
  title: string;
  is_completed: number;
}

interface Checklist {
  id: number;
  title: string;
  items: ChecklistItem[];
}

interface TaskFile {
  id: number;
  original_name: string;
  file_size: string;
  created_at: string;
  download_url: string;
}

interface TaskLink {
  id: number;
  title: string;
  url: string;
  created_at: string;
}

interface TaskDetailData {
  success: boolean;
  task: {
    id: number;
    title: string;
    raw_title: string;
    description: string;
    raw_description: string;
    priority: string;
    status: string;
    assigned_to: string;
    raw_assigned_to: string;
    due_date: string;
    raw_due_date: string;
    start_date: string;
    raw_start_date: string;
    is_milestone: boolean;
  };
  parent: { id: number; title: string } | null;
  subtasks: MiniTask[];
  blocked_by: MiniTask[];
  blocks: MiniTask[];
  dependency_options: Array<{ id: number; title: string; status: string }>;
  is_blocked: boolean;
  permissions: {
    can_edit: boolean;
    can_delete: boolean;
    can_change_status: boolean;
    can_manage_checklists: boolean;
    can_manage_files: boolean;
    can_manage_links: boolean;
    can_manage_subtasks: boolean;
    can_manage_dependencies: boolean;
    is_cliente: boolean;
  };
  comments: TaskComment[];
  checklists: Checklist[];
  files: TaskFile[];
  links: TaskLink[];
}

const PRIORITY_STYLES: Record<string, string> = {
  urgente: 'border-rose-200 bg-rose-50 text-rose-700',
  alta: 'border-amber-200 bg-amber-50 text-amber-700',
  media: 'border-indigo-200 bg-indigo-50 text-indigo-700',
  baja: 'border-slate-200 bg-slate-50 text-slate-600',
};

const PRIORITIES = ['baja', 'media', 'alta', 'urgente'];
const TABS = [
  { key: 'comments', label: 'Comentarios', icon: MessageSquare },
  { key: 'checklists', label: 'Checklists', icon: ListChecks },
  { key: 'attachments', label: 'Adjuntos', icon: Paperclip },
] as const;

type TabKey = typeof TABS[number]['key'];

function rootUrl(url: string): string {
  return url.startsWith('http') || url.startsWith('/') ? url : `/${url}`;
}

/** Los endpoints clásicos responden 200 con {success:false}; se trata como error. */
function assertSuccess(result: unknown): void {
  if (result && typeof result === 'object' && (result as { success?: unknown }).success === false) {
    throw new Error(String((result as { message?: unknown }).message ?? 'No se pudo completar la acción.'));
  }
}

function taskDraftKey(taskId: number): string {
  return `vertice_task_draft_${taskId}`;
}

function readTaskDraft(taskId: number): Record<string, string> | null {
  try {
    const raw = localStorage.getItem(taskDraftKey(taskId));
    return raw ? JSON.parse(raw) as Record<string, string> : null;
  } catch {
    return null;
  }
}

function clearTaskDraft(taskId: number): void {
  try { localStorage.removeItem(taskDraftKey(taskId)); } catch { /* almacenamiento no disponible */ }
}

export function TaskDetailModal({
  taskId,
  projectId,
  projectName,
  statuses,
  assignees,
  taskIds = [],
  onClose,
  onChanged,
}: {
  taskId: number | null;
  projectId: number;
  projectName?: string;
  statuses: TaskStatus[];
  assignees: Array<{ id: number; name: string }>;
  taskIds?: number[];
  onClose: () => void;
  onChanged?: () => void;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const [currentTaskId, setCurrentTaskId] = useState<number | null>(taskId);
  const [editing, setEditing] = useState(false);
  const [editDirty, setEditDirty] = useState(false);
  const [tab, setTab] = useState<TabKey>('comments');
  const [busy, setBusy] = useState<string | null>(null);
  const [commentText, setCommentText] = useState('');
  const [commentImage, setCommentImage] = useState<File | null>(null);
  const [commentPreview, setCommentPreview] = useState<string | null>(null);
  const [subtaskOpen, setSubtaskOpen] = useState(false);
  const [dependencyOpen, setDependencyOpen] = useState(false);
  const [dependencyId, setDependencyId] = useState('');
  const [newChecklist, setNewChecklist] = useState('');
  const [checklistDrafts, setChecklistDrafts] = useState<Record<number, string>>({});
  const [linkFormOpen, setLinkFormOpen] = useState(false);
  const [linkTitle, setLinkTitle] = useState('');
  const [linkUrl, setLinkUrl] = useState('');

  useEffect(() => {
    setCurrentTaskId(taskId);
    setEditing(false);
    setEditDirty(false);
    setTab('comments');
    setCommentText('');
    setCommentImage(null);
    setCommentPreview(null);
    setSubtaskOpen(false);
    setDependencyOpen(false);
    setLinkFormOpen(false);
  }, [taskId]);

  const detailQuery = useQuery({
    queryKey: ['task-detail', currentTaskId],
    queryFn: () => apiPostRoot<TaskDetailData>('/api/tasks.php', {
      action: 'get_detail',
      project_id: projectId,
      task_id: currentTaskId ?? 0,
    }),
    enabled: taskId !== null && currentTaskId !== null,
    staleTime: 10_000,
  });

  const detail = detailQuery.data;
  const task = detail?.task;
  const permissions = detail?.permissions;
  const draft = task ? readTaskDraft(task.id) : null;
  const taskIndex = currentTaskId !== null ? taskIds.indexOf(currentTaskId) : -1;
  const previousTaskId = taskIndex > 0 ? taskIds[taskIndex - 1] : null;
  const nextTaskId = taskIndex >= 0 && taskIndex < taskIds.length - 1 ? taskIds[taskIndex + 1] : null;

  useUnsavedChanges(editing && editDirty, 'Tienes cambios sin guardar en esta tarea.');

  const requestClose = async () => {
    if (editing && editDirty) {
      const discard = await confirm({ title: 'Descartar cambios', description: 'Se perderán los cambios de la tarea que no has guardado.', confirmLabel: 'Descartar', cancelLabel: 'Seguir editando', tone: 'danger' });
      if (!discard) return;
    }
    setEditDirty(false);
    if (task) clearTaskDraft(task.id);
    onClose();
  };

  const toggleEditing = async () => {
    if (editing && editDirty) {
      const discard = await confirm({ title: 'Descartar cambios', description: 'Se perderán los cambios de la tarea que no has guardado.', confirmLabel: 'Descartar', cancelLabel: 'Seguir editando', tone: 'danger' });
      if (!discard) return;
    }
    if (editing && task) clearTaskDraft(task.id);
    setEditDirty(false);
    setEditing((current) => !current);
  };

  const run = async (key: string, action: () => Promise<unknown>, successMessage?: string): Promise<boolean> => {
    setBusy(key);
    try {
      assertSuccess(await action());
      await detailQuery.refetch();
      onChanged?.();
      if (successMessage) toast(successMessage);
      return true;
    } catch (actionError) {
      toast(actionError instanceof Error ? actionError.message : 'No se pudo completar la acción.', 'error');
      return false;
    } finally {
      setBusy(null);
    }
  };

  const openTask = async (nextTaskId: number) => {
    if (editing && editDirty) {
      const discard = await confirm({ title: 'Descartar cambios', description: 'Se perderán los cambios de la tarea que no has guardado.', confirmLabel: 'Descartar', cancelLabel: 'Seguir editando', tone: 'danger' });
      if (!discard) return;
    }
    setCurrentTaskId(nextTaskId);
    setEditing(false);
    setEditDirty(false);
    if (task) clearTaskDraft(task.id);
    setTab('comments');
  };

  const submitEdit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!task) return;
    const form = new FormData(event.currentTarget);
    const body: Record<string, string | number> = { action: 'update', project_id: projectId, task_id: task.id };
    form.forEach((value, key) => { body[key] = String(value); });
    body.is_milestone = form.get('is_milestone') === 'on' ? 1 : 0;
    void run('edit', () => apiPostRoot('/api/tasks.php', body), 'Tarea actualizada.').then((ok) => {
      if (ok) {
        clearTaskDraft(task.id);
        setEditing(false);
        setEditDirty(false);
      }
    });
  };

  const createSubtask = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!task) return;
    const form = event.currentTarget;
    const body: Record<string, string | number> = { action: 'create_subtask', project_id: projectId, parent_task_id: task.id };
    new FormData(form).forEach((value, key) => { body[key] = String(value); });
    void run('subtask', () => apiPostRoot('/api/tasks.php', body), 'Subtarea creada.').then((ok) => {
      if (ok) {
        setSubtaskOpen(false);
        form.reset();
      }
    });
  };

  const addDependency = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!task || dependencyId === '') return;
    void run('dependency', () => apiPostRoot('/api/tasks.php', {
      action: 'add_dependency',
      project_id: projectId,
      task_id: task.id,
      depends_on_task_id: dependencyId,
    }), 'Dependencia añadida.').then((ok) => {
      if (ok) {
        setDependencyId('');
        setDependencyOpen(false);
      }
    });
  };

  const removeDependency = (dependsOnTaskId: number) => {
    if (!task) return;
    void run(`dependency-${dependsOnTaskId}`, () => apiPostRoot('/api/tasks.php', {
      action: 'remove_dependency',
      project_id: projectId,
      task_id: task.id,
      depends_on_task_id: dependsOnTaskId,
    }), 'Dependencia eliminada.');
  };

  const addComment = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!task || (commentText.trim() === '' && !commentImage)) return;
    const form = new FormData();
    form.set('action', 'create');
    form.set('project_id', String(projectId));
    form.set('task_id', String(task.id));
    form.set('content', commentText.trim());
    if (commentImage) form.set('image', commentImage);
    void run('comment', () => apiUploadRoot('/api/comments.php', form), 'Comentario publicado.').then((ok) => {
      if (ok) { setCommentText(''); clearCommentImage(); }
    });
  };

  const clearCommentImage = () => {
    if (commentPreview) URL.revokeObjectURL(commentPreview);
    setCommentImage(null);
    setCommentPreview(null);
  };

  const pickCommentImage = (file: File | undefined) => {
    if (!file) return;
    const error = commentImageError(file);
    if (error) { toast(error, 'error'); return; }
    if (commentPreview) URL.revokeObjectURL(commentPreview);
    setCommentImage(file);
    setCommentPreview(URL.createObjectURL(file));
  };

  const deleteComment = async (commentId: number) => {
    if (!task) return;
    if (!(await confirm({ title: 'Eliminar comentario', description: 'El comentario se eliminará de la tarea.', confirmLabel: 'Eliminar', tone: 'danger' }))) return;
    await run(`comment-delete-${commentId}`, () => apiPostRoot('/api/comments.php', {
      action: 'delete',
      project_id: projectId,
      comment_id: commentId,
    }), 'Comentario eliminado.');
  };

  const addChecklist = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!task || newChecklist.trim() === '') return;
    void run('checklist', () => apiPostRoot('/api/checklists.php', {
      action: 'create_checklist',
      project_id: projectId,
      task_id: task.id,
      title: newChecklist.trim(),
    }), 'Checklist creado.').then((ok) => { if (ok) setNewChecklist(''); });
  };

  const addChecklistItem = (event: FormEvent<HTMLFormElement>, checklistId: number) => {
    event.preventDefault();
    const title = (checklistDrafts[checklistId] ?? '').trim();
    if (title === '') return;
    void run(`checklist-item-${checklistId}`, () => apiPostRoot('/api/checklists.php', {
      action: 'add_item',
      project_id: projectId,
      checklist_id: checklistId,
      title,
    }), 'Paso añadido.').then((ok) => {
      if (ok) setChecklistDrafts((current) => ({ ...current, [checklistId]: '' }));
    });
  };

  const toggleChecklistItem = (itemId: number, completed: boolean) => {
    void run(`checklist-toggle-${itemId}`, () => apiPostRoot('/api/checklists.php', {
      action: 'toggle_item',
      project_id: projectId,
      item_id: itemId,
      is_completed: completed ? 1 : 0,
    }));
  };

  const deleteChecklistItem = (itemId: number) => {
    void run(`checklist-item-delete-${itemId}`, () => apiPostRoot('/api/checklists.php', {
      action: 'delete_item',
      project_id: projectId,
      item_id: itemId,
    }), 'Paso eliminado.');
  };

  const deleteChecklist = async (checklistId: number, title: string) => {
    if (!(await confirm({ title: 'Eliminar checklist', description: title, confirmLabel: 'Eliminar', tone: 'danger' }))) return;
    await run(`checklist-delete-${checklistId}`, () => apiPostRoot('/api/checklists.php', {
      action: 'delete_checklist',
      project_id: projectId,
      checklist_id: checklistId,
    }), 'Checklist eliminado.');
  };

  const uploadFile = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!task) return;
    const input = event.currentTarget.querySelector<HTMLInputElement>('input[type="file"]');
    if (!input?.files?.[0]) return;
    const data = new FormData();
    data.set('action', 'upload');
    data.set('project_id', String(projectId));
    data.set('task_id', String(task.id));
    data.set('file', input.files[0]);
    void run('file-upload', () => apiUploadRoot('/api/files.php', data), 'Archivo subido.').then((ok) => {
      if (ok) input.value = '';
    });
  };

  const deleteFile = async (fileId: number, name: string) => {
    if (!(await confirm({ title: 'Eliminar archivo', description: name, confirmLabel: 'Eliminar', tone: 'danger' }))) return;
    await run(`file-delete-${fileId}`, () => apiPostRoot('/api/files.php', {
      action: 'delete',
      project_id: projectId,
      file_id: fileId,
    }), 'Archivo eliminado.');
  };

  const addLink = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!task || linkTitle.trim() === '' || linkUrl.trim() === '') return;
    void run('link', () => apiPostRoot('/api/links.php', {
      action: 'create',
      project_id: projectId,
      task_id: task.id,
      title: linkTitle.trim(),
      url: linkUrl.trim(),
    }), 'Enlace añadido.').then((ok) => {
      if (ok) {
        setLinkTitle('');
        setLinkUrl('');
        setLinkFormOpen(false);
      }
    });
  };

  const deleteLink = (linkId: number) => {
    void run(`link-delete-${linkId}`, () => apiPostRoot('/api/links.php', {
      action: 'delete',
      project_id: projectId,
      link_id: linkId,
    }), 'Enlace eliminado.');
  };

  const deleteTask = async () => {
    if (!task) return;
    if (!(await confirm({ title: 'Eliminar tarea', description: task.raw_title, confirmLabel: 'Eliminar', tone: 'danger' }))) return;
    setBusy('delete-task');
    try {
      await apiPostRoot('/api/tasks.php', { action: 'delete', project_id: projectId, task_id: task.id });
      onChanged?.();
      onClose();
      toast('Tarea eliminada.');
    } catch (deleteError) {
      toast(deleteError instanceof Error ? deleteError.message : 'No se pudo eliminar la tarea.', 'error');
    } finally {
      setBusy(null);
    }
  };

  return (
    <Modal open={taskId !== null} onClose={requestClose} panelClassName="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-2xl">
      <div className="flex items-center justify-between gap-3 border-b border-slate-100 bg-slate-50/70 px-5 py-3 sm:px-6">
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          {task && <span className={`rounded-full border px-2.5 py-0.5 text-[11px] font-semibold capitalize ${PRIORITY_STYLES[task.priority] ?? PRIORITY_STYLES.baja}`}>{task.priority}</span>}
          {task && <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${statuses.find((status) => status.key === task.status)?.kind === 'done' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700'}`}>{statuses.find((status) => status.key === task.status)?.label ?? task.status}</span>}
          {detail?.is_blocked && <span className="rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700">Bloqueada</span>}
        </div>
        <div className="flex flex-shrink-0 items-center gap-1.5">
          {taskIds.length > 0 && (
            <div className="mr-1 flex items-center rounded-lg border border-slate-200 bg-white">
              <button type="button" disabled={previousTaskId === null} onClick={() => { if (previousTaskId !== null) void openTask(previousTaskId); }} className="rounded-l-lg p-1.5 text-slate-400 transition hover:bg-slate-50 hover:text-slate-700 disabled:opacity-30" title="Tarea anterior"><ChevronLeft className="h-4 w-4" /></button>
              <span className="border-x border-slate-100 px-1 text-[9px] font-semibold tabular-nums text-slate-400">{taskIndex >= 0 ? `${taskIndex + 1}/${taskIds.length}` : '—'}</span>
              <button type="button" disabled={nextTaskId === null} onClick={() => { if (nextTaskId !== null) void openTask(nextTaskId); }} className="rounded-r-lg p-1.5 text-slate-400 transition hover:bg-slate-50 hover:text-slate-700 disabled:opacity-30" title="Tarea siguiente"><ChevronRight className="h-4 w-4" /></button>
            </div>
          )}
          {permissions?.can_edit && (
            <button type="button" onClick={() => void toggleEditing()} className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:bg-slate-50">
              <Pencil className="h-3.5 w-3.5 text-brand-600" />
              <span className="hidden sm:inline">{editing ? 'Cerrar edición' : 'Editar tarea'}</span>
            </button>
          )}
          {permissions?.can_delete && task && (
            <button type="button" onClick={deleteTask} disabled={busy === 'delete-task'} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 disabled:opacity-60" title="Eliminar tarea"><Trash2 className="h-4 w-4" /></button>
          )}
          <button type="button" onClick={() => void requestClose()} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="Cerrar detalle"><X className="h-5 w-5" /></button>
        </div>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto bg-white p-5 sm:p-6">
        {detailQuery.isLoading && (
          <div className="space-y-4">
            <Skeleton className="h-8 w-2/3" />
            <Skeleton className="h-24" />
            <div className="grid gap-3 sm:grid-cols-4">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} className="h-16" />)}</div>
            <Skeleton className="h-40" />
          </div>
        )}
        {detailQuery.isError && <ErrorState message={detailQuery.error instanceof Error ? detailQuery.error.message : 'No se pudo cargar la tarea.'} />}

        {detail && task && (
          <div className="space-y-5">
            {detail.parent && (
              <p className="flex items-center gap-1 text-[11px] text-slate-500">
                <ListTree className="h-3.5 w-3.5" /> Subtarea de{' '}
                <button type="button" onClick={() => openTask(detail.parent!.id)} className="font-semibold text-brand-700 hover:underline">{detail.parent.title}</button>
              </p>
            )}

            {editing ? (
              <form key={`edit-${task.id}`} onSubmit={submitEdit} onChange={(event) => {
                setEditDirty(true);
                try {
                  const values = Object.fromEntries(new FormData(event.currentTarget).entries());
                  localStorage.setItem(taskDraftKey(task.id), JSON.stringify(values));
                } catch { /* almacenamiento no disponible */ }
              }} className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 sm:p-5">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Título *</label>
                  <Input name="title" required maxLength={255} defaultValue={draft?.title ?? task.raw_title} className="px-3.5 py-2.5 text-sm" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Descripción</label>
                  <textarea name="description" rows={4} defaultValue={draft?.description ?? task.raw_description} className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm leading-relaxed focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15" />
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  <div>
                    <label className="mb-1.5 block text-xs font-medium text-slate-700">Prioridad</label>
                    <Select name="priority" defaultValue={draft?.priority ?? task.priority} className="w-full px-3 py-2 text-xs">{PRIORITIES.map((priority) => <option key={priority} value={priority}>{priority}</option>)}</Select>
                  </div>
                  <div>
                    <label className="mb-1.5 block text-xs font-medium text-slate-700">Estado</label>
                    <Select name="status" defaultValue={draft?.status ?? task.status} disabled={!permissions?.can_change_status} className="w-full px-3 py-2 text-xs">
                      {statuses.map((status) => <option key={status.key} value={status.key}>{status.label}</option>)}
                      {!statuses.some((status) => status.key === task.status) && <option value={task.status}>{task.status}</option>}
                    </Select>
                  </div>
                  <div>
                    <label className="mb-1.5 block text-xs font-medium text-slate-700">Responsable</label>
                    <Select name="assigned_user_id" defaultValue={draft?.assigned_user_id ?? task.raw_assigned_to} className="w-full px-3 py-2 text-xs">
                      <option value="">Sin asignar</option>
                      {assignees.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                      {task.raw_assigned_to !== '' && !assignees.some((person) => String(person.id) === task.raw_assigned_to) && <option value={task.raw_assigned_to}>{task.assigned_to}</option>}
                    </Select>
                  </div>
                  <div>
                    <label className="mb-1.5 block text-xs font-medium text-slate-700">Fecha de inicio</label>
                    <Input name="start_date" type="date" defaultValue={draft?.start_date ?? task.raw_start_date} className="px-3 py-2 text-xs" />
                  </div>
                  <div>
                    <label className="mb-1.5 block text-xs font-medium text-slate-700">Fecha límite</label>
                    <Input name="due_date" type="date" defaultValue={draft?.due_date ?? task.raw_due_date} className="px-3 py-2 text-xs" />
                  </div>
                  <label className="flex items-end gap-2 pb-2 text-xs font-medium text-slate-600">
                    <input type="checkbox" name="is_milestone" defaultChecked={draft ? draft.is_milestone === 'on' : task.is_milestone} className="h-3.5 w-3.5 rounded border-slate-300 text-amber-600 focus:ring-amber-500" />
                    Es un hito
                  </label>
                </div>
                <div className="flex items-center justify-end gap-2 border-t border-slate-200 pt-3">
                  <button type="button" onClick={() => void toggleEditing()} className="rounded-xl px-4 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
                  <LoadingButton type="submit" pending={busy === 'edit'} pendingLabel="Guardando…" className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-brand-700 disabled:opacity-60">Guardar cambios</LoadingButton>
                </div>
              </form>
            ) : (
              <div className="space-y-4">
                <div>
                  <h2 className="text-xl font-semibold leading-tight text-slate-900 sm:text-2xl">{task.raw_title}</h2>
                  <div className="mt-2 rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                    <p className="mb-1 text-[11px] font-medium text-slate-500">Descripción</p>
                    <p className="whitespace-pre-line text-xs leading-relaxed text-slate-600 sm:text-sm">{task.raw_description || 'Sin descripción detallada.'}</p>
                  </div>
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  {[
                    { label: 'Responsable', value: task.assigned_to, icon: User, tone: 'bg-brand-50 text-brand-700' },
                    { label: 'Fecha inicio', value: task.start_date, icon: PlayCircle, tone: 'bg-sky-50 text-sky-700' },
                    { label: 'Fecha límite', value: task.due_date, icon: Calendar, tone: 'bg-indigo-50 text-indigo-700' },
                    { label: 'Estado actual', value: statuses.find((status) => status.key === task.status)?.label ?? task.status, icon: Activity, tone: 'bg-amber-50 text-amber-700' },
                  ].map((meta) => (
                    <div key={meta.label} className="flex items-center gap-3 rounded-2xl border border-slate-100 bg-slate-50/60 p-3.5">
                      <span className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl ${meta.tone}`}><meta.icon className="h-4 w-4" /></span>
                      <div className="min-w-0">
                        <span className="block text-[11px] font-medium leading-tight text-slate-500">{meta.label}</span>
                        <span className="mt-0.5 block truncate text-xs font-semibold text-slate-800">{meta.value}</span>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {!permissions?.is_cliente && (
              <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <section className="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-2xs">
                  <div className="mb-3 flex items-center justify-between gap-2">
                    <h3 className="flex items-center gap-1.5 text-xs font-semibold text-slate-800"><ListTree className="h-3.5 w-3.5 text-brand-600" /> Subtareas <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">{detail.subtasks.length}</span></h3>
                    {permissions?.can_manage_subtasks && !detail.parent && (
                      <button type="button" onClick={() => setSubtaskOpen((current) => !current)} className="inline-flex items-center gap-1 rounded-lg border border-brand-200 bg-brand-50 px-2 py-1 text-[10px] font-semibold text-brand-700 transition hover:bg-brand-100"><Plus className="h-3 w-3" /> Añadir</button>
                    )}
                  </div>
                  <div className="space-y-2">
                    {detail.subtasks.length === 0 && <p className="text-[11px] text-slate-400">Sin subtareas todavía.</p>}
                    {detail.subtasks.map((subtask) => (
                      <button key={subtask.id} type="button" onClick={() => openTask(subtask.id)} className="flex w-full items-center justify-between gap-3 rounded-xl border border-slate-100 bg-slate-50/60 px-3 py-2 text-left transition hover:border-slate-200 hover:bg-slate-50">
                        <span className="min-w-0">
                          <span className={`block truncate text-[11px] font-semibold ${subtask.done ? 'text-slate-400 line-through' : 'text-slate-700'}`}>{subtask.title}</span>
                          <span className="mt-0.5 block text-[10px] text-slate-400">{subtask.assigned_to || 'Sin asignar'}{subtask.due_date ? ` · ${subtask.due_date}` : ''}</span>
                        </span>
                        <span className={`flex-shrink-0 rounded-full px-1.5 py-0.5 text-[9px] font-semibold ${subtask.done ? 'bg-emerald-50 text-emerald-700' : 'bg-white text-slate-500 ring-1 ring-slate-200'}`}>{subtask.status_label}</span>
                      </button>
                    ))}
                  </div>
                  {subtaskOpen && permissions?.can_manage_subtasks && (
                    <form onSubmit={createSubtask} className="mt-3 space-y-2 border-t border-slate-100 pt-3">
                      <Input name="title" required maxLength={255} placeholder="Título de la subtarea" className="px-3 py-2 text-xs" />
                      <div className="grid grid-cols-2 gap-2">
                        <Select name="assigned_user_id" defaultValue="" className="px-2.5 py-2 text-[11px]"><option value="">Sin asignar</option>{assignees.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</Select>
                        <Input name="due_date" type="date" className="px-2.5 py-2 text-[11px]" />
                        <Select name="priority" defaultValue="media" className="px-2.5 py-2 text-[11px]">{PRIORITIES.map((priority) => <option key={priority} value={priority}>{priority}</option>)}</Select>
                        <LoadingButton type="submit" pending={busy === 'subtask'} className="rounded-lg bg-brand-600 px-3 py-2 text-[11px] font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Crear subtarea</LoadingButton>
                      </div>
                    </form>
                  )}
                </section>

                <section className="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-2xs">
                  <h3 className="mb-3 flex items-center gap-1.5 text-xs font-semibold text-slate-800"><Link2 className="h-3.5 w-3.5 text-brand-600" /> Dependencias</h3>
                  {detail.is_blocked && <div className="mb-2 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-2 text-[10px] font-medium text-rose-700">Esta tarea está bloqueada por dependencias sin completar.</div>}
                  <p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Bloqueada por</p>
                  <div className="space-y-1.5">
                    {detail.blocked_by.length === 0 && <p className="text-[11px] text-slate-400">Sin dependencias.</p>}
                    {detail.blocked_by.map((dependency) => (
                      <div key={dependency.id} className="flex items-center justify-between gap-2 rounded-lg border border-slate-100 bg-slate-50/60 px-2.5 py-1.5">
                        <button type="button" onClick={() => openTask(dependency.id)} className={`min-w-0 truncate text-left text-[11px] font-medium ${dependency.done ? 'text-slate-400 line-through' : 'text-slate-700'} hover:text-brand-600`}>{dependency.title}</button>
                        {permissions?.can_manage_dependencies && <button type="button" onClick={() => removeDependency(dependency.id)} disabled={busy === `dependency-${dependency.id}`} className="rounded p-0.5 text-slate-300 transition hover:text-rose-600 disabled:opacity-50" title="Quitar dependencia"><X className="h-3 w-3" /></button>}
                      </div>
                    ))}
                  </div>
                  {permissions?.can_manage_dependencies && detail.dependency_options.length > 0 && (
                    <div className="mt-2">
                      {dependencyOpen ? (
                        <form onSubmit={addDependency} className="flex gap-1.5">
                          <Select value={dependencyId} onChange={(event) => setDependencyId(event.target.value)} className="min-w-0 flex-1 px-2 py-1.5 text-[11px]"><option value="">Selecciona una tarea…</option>{detail.dependency_options.map((option) => <option key={option.id} value={option.id}>{option.title}</option>)}</Select>
                          <button type="submit" disabled={busy === 'dependency'} className="rounded-lg bg-brand-600 px-2.5 py-1.5 text-[10px] font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Vincular</button>
                        </form>
                      ) : (
                        <button type="button" onClick={() => setDependencyOpen(true)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-[10px] font-semibold text-slate-600 transition hover:bg-slate-50"><Plus className="h-3 w-3" /> Añadir dependencia</button>
                      )}
                    </div>
                  )}
                  {detail.blocks.length > 0 && (
                    <>
                      <p className="mb-1 mt-3 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Bloquea a</p>
                      <div className="space-y-1.5">
                        {detail.blocks.map((dependent) => (
                          <button key={dependent.id} type="button" onClick={() => openTask(dependent.id)} className="block w-full truncate rounded-lg border border-slate-100 bg-slate-50/60 px-2.5 py-1.5 text-left text-[11px] font-medium text-slate-700 transition hover:text-brand-600">{dependent.title}</button>
                        ))}
                      </div>
                    </>
                  )}
                </section>
              </div>
            )}

            {!permissions?.is_cliente && (
              <div className="space-y-4 border-t border-slate-100 pt-4">
                <div className="flex items-center gap-5 border-b border-slate-100 text-xs font-medium">
                  {TABS.map((item) => {
                    const Icon = item.icon;
                    const active = tab === item.key;
                    const count = item.key === 'comments' ? detail.comments.length : item.key === 'checklists' ? detail.checklists.length : detail.files.length + detail.links.length;
                    return (
                      <button key={item.key} type="button" onClick={() => setTab(item.key)} className={`flex items-center gap-1.5 border-b-2 pb-2.5 transition ${active ? 'border-slate-900 font-semibold text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'}`}>
                        <Icon className="h-3.5 w-3.5" /> {item.label} <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-bold ${active ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-500'}`}>{count}</span>
                      </button>
                    );
                  })}
                </div>

                {tab === 'comments' && (
                  <div className="space-y-3">
                    <form onSubmit={addComment} className="space-y-2 rounded-2xl border border-slate-200/70 bg-slate-50/70 p-3.5">
                      <textarea value={commentText} onChange={(event) => setCommentText(event.target.value)} onPaste={(event) => { const pasted = Array.from(event.clipboardData.files).find((file) => file.type.startsWith('image/')); if (pasted) { event.preventDefault(); pickCommentImage(pasted); } }} rows={2} placeholder="Escribe un comentario en esta tarea…" className="w-full rounded-xl border border-slate-200 bg-white p-2.5 text-xs leading-relaxed focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15" />
                      {commentPreview && (
                        <div className="relative inline-block">
                          <img src={commentPreview} alt="Vista previa" className="max-h-32 rounded-lg border border-slate-200" />
                          <button type="button" onClick={clearCommentImage} className="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-rose-600 text-white shadow" aria-label="Quitar imagen"><X className="h-3 w-3" /></button>
                        </div>
                      )}
                      <div className="flex items-center justify-between gap-2">
                        <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-semibold text-slate-500 transition hover:bg-white hover:text-slate-800">
                          <ImageIcon className="h-3.5 w-3.5" />Imagen
                          <input type="file" accept={COMMENT_IMAGE_ACCEPT} className="hidden" onChange={(event) => { pickCommentImage(event.target.files?.[0]); event.target.value = ''; }} />
                        </label>
                        <LoadingButton type="submit" pending={busy === 'comment'} disabled={commentText.trim() === '' && !commentImage} className="rounded-xl bg-brand-600 px-3.5 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-50">Enviar comentario</LoadingButton>
                      </div>
                    </form>
                    {detail.comments.length === 0 && <p className="py-4 text-center text-[11px] text-slate-400">Sin comentarios todavía.</p>}
                    {detail.comments.map((comment) => (
                      <div key={comment.id} className="flex items-start gap-3 rounded-2xl border border-slate-100 bg-slate-50/60 p-3">
                        <span className="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-brand-50 text-[9px] font-bold text-brand-700">{comment.initials}</span>
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center justify-between gap-2">
                            <p className="text-[11px] font-semibold text-slate-700">{comment.author_name}</p>
                            <div className="flex items-center gap-1">
                              <span className="text-[10px] text-slate-400">{comment.created_at}</span>
                              {comment.can_manage && <button type="button" onClick={() => deleteComment(comment.id)} disabled={busy === `comment-delete-${comment.id}`} className="rounded p-0.5 text-slate-300 transition hover:text-rose-600 disabled:opacity-50" title="Eliminar comentario"><Trash2 className="h-3 w-3" /></button>}
                            </div>
                          </div>
                          <p className="mt-1 whitespace-pre-line text-[11px] leading-relaxed text-slate-600">{comment.raw_content}</p>
                          {comment.image_url && <a href={rootUrl(comment.image_url)} target="_blank" rel="noreferrer"><img src={rootUrl(comment.image_url)} alt="Adjunto del comentario" className="mt-2 max-h-48 rounded-lg border border-slate-200 object-cover" /></a>}
                        </div>
                      </div>
                    ))}
                  </div>
                )}

                {tab === 'checklists' && (
                  <div className="space-y-3">
                    {permissions?.can_manage_checklists && (
                      <form onSubmit={addChecklist} className="flex items-center gap-2">
                        <Input value={newChecklist} onChange={(event) => setNewChecklist(event.target.value)} placeholder="+ Crear nueva lista de pasos…" className="flex-1 px-3 py-2 text-xs" />
                        <LoadingButton type="submit" pending={busy === 'checklist'} className="rounded-xl bg-brand-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Crear lista</LoadingButton>
                      </form>
                    )}
                    {detail.checklists.length === 0 && <p className="py-4 text-center text-[11px] text-slate-400">Sin checklists todavía.</p>}
                    {detail.checklists.map((checklist) => {
                      const done = checklist.items.filter((item) => item.is_completed === 1).length;
                      return (
                        <section key={checklist.id} className="rounded-2xl border border-slate-200/80 bg-white p-3.5">
                          <div className="mb-2 flex items-center justify-between gap-2">
                            <div className="flex min-w-0 items-center gap-2">
                              <h4 className="truncate text-xs font-semibold text-slate-800">{checklist.title}</h4>
                              <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">{done}/{checklist.items.length}</span>
                            </div>
                            {permissions?.can_manage_checklists && <button type="button" onClick={() => deleteChecklist(checklist.id, checklist.title)} disabled={busy === `checklist-delete-${checklist.id}`} className="rounded p-0.5 text-slate-300 transition hover:text-rose-600 disabled:opacity-50" title="Eliminar checklist"><Trash2 className="h-3.5 w-3.5" /></button>}
                          </div>
                          <div className="space-y-1">
                            {checklist.items.map((item) => (
                              <div key={item.id} className="group flex items-center gap-2 rounded-lg px-1.5 py-1 hover:bg-slate-50">
                                <input type="checkbox" checked={item.is_completed === 1} disabled={!permissions?.can_manage_checklists || busy === `checklist-toggle-${item.id}`} onChange={(event) => toggleChecklistItem(item.id, event.target.checked)} className="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 disabled:opacity-60" />
                                <span className={`min-w-0 flex-1 text-[11px] ${item.is_completed === 1 ? 'text-slate-400 line-through' : 'text-slate-600'}`}>{item.title}</span>
                                {permissions?.can_manage_checklists && <button type="button" onClick={() => deleteChecklistItem(item.id)} disabled={busy === `checklist-item-delete-${item.id}`} className="rounded p-0.5 text-slate-300 opacity-0 transition hover:text-rose-600 group-hover:opacity-100 disabled:opacity-50" title="Eliminar paso"><X className="h-3 w-3" /></button>}
                              </div>
                            ))}
                          </div>
                          {permissions?.can_manage_checklists && (
                            <form onSubmit={(event) => addChecklistItem(event, checklist.id)} className="mt-2 flex items-center gap-1.5">
                              <Input value={checklistDrafts[checklist.id] ?? ''} onChange={(event) => setChecklistDrafts((current) => ({ ...current, [checklist.id]: event.target.value }))} placeholder="Añadir paso…" className="flex-1 px-2.5 py-1.5 text-[11px]" />
                              <button type="submit" disabled={busy === `checklist-item-${checklist.id}`} className="rounded-lg bg-slate-900 px-2.5 py-1.5 text-[10px] font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60">Añadir</button>
                            </form>
                          )}
                        </section>
                      );
                    })}
                  </div>
                )}

                {tab === 'attachments' && (
                  <div className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2">
                      <span className="text-xs font-semibold text-slate-700">Archivos y enlaces de esta tarea</span>
                      <div className="flex items-center gap-1.5">
                        {permissions?.can_manage_files && (
                          <form onSubmit={uploadFile} className="flex items-center gap-1.5">
                            <input type="file" className="max-w-[150px] text-[10px] text-slate-500 file:mr-1 file:rounded-md file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-[10px] file:font-semibold file:text-slate-700" />
                            <LoadingButton type="submit" pending={busy === 'file-upload'} className="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2 py-1.5 text-[10px] font-semibold text-slate-600 transition hover:bg-slate-200 disabled:opacity-60"><Upload className="h-3 w-3" /> Subir</LoadingButton>
                          </form>
                        )}
                        {permissions?.can_manage_links && <button type="button" onClick={() => setLinkFormOpen((current) => !current)} className="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2 py-1.5 text-[10px] font-semibold text-slate-600 transition hover:bg-slate-200"><Link2 className="h-3 w-3" /> Enlace</button>}
                      </div>
                    </div>

                    {linkFormOpen && permissions?.can_manage_links && (
                      <form onSubmit={addLink} className="grid grid-cols-1 gap-2 rounded-xl border border-slate-200 bg-slate-50/80 p-3 sm:grid-cols-2">
                        <Input value={linkTitle} onChange={(event) => setLinkTitle(event.target.value)} required placeholder="Título del enlace" className="px-2.5 py-1.5 text-xs" />
                        <Input value={linkUrl} onChange={(event) => setLinkUrl(event.target.value)} required type="url" placeholder="https://…" className="px-2.5 py-1.5 text-xs" />
                        <div className="flex justify-end sm:col-span-2"><LoadingButton type="submit" pending={busy === 'link'} className="rounded-lg bg-brand-600 px-3 py-1.5 text-[11px] font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Guardar enlace</LoadingButton></div>
                      </form>
                    )}

                    <div className="space-y-3">
                      <div>
                        <p className="mb-1.5 text-xs font-medium text-slate-500">Archivos</p>
                        <div className="space-y-1.5">
                          {detail.files.length === 0 && <p className="text-[11px] text-slate-400">Sin archivos.</p>}
                          {detail.files.map((file) => (
                            <div key={file.id} className="flex items-center justify-between gap-2 rounded-xl border border-slate-100 bg-slate-50/60 px-3 py-2">
                              <div className="min-w-0">
                                <p className="truncate text-[11px] font-semibold text-slate-700">{file.original_name}</p>
                                <p className="text-[10px] text-slate-400">{file.file_size} · {file.created_at}</p>
                              </div>
                              <div className="flex flex-shrink-0 items-center gap-1">
                                <a href={rootUrl(file.download_url)} className="rounded p-1 text-slate-400 transition hover:text-brand-600" title="Descargar"><ExternalLink className="h-3.5 w-3.5" /></a>
                                {permissions?.can_manage_files && <button type="button" onClick={() => deleteFile(file.id, file.original_name)} disabled={busy === `file-delete-${file.id}`} className="rounded p-1 text-slate-300 transition hover:text-rose-600 disabled:opacity-50" title="Eliminar archivo"><Trash2 className="h-3.5 w-3.5" /></button>}
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>

                      <div>
                        <p className="mb-1.5 text-xs font-medium text-slate-500">Enlaces</p>
                        <div className="space-y-1.5">
                          {detail.links.length === 0 && <p className="text-[11px] text-slate-400">Sin enlaces.</p>}
                          {detail.links.map((link) => (
                            <div key={link.id} className="flex items-center justify-between gap-2 rounded-xl border border-slate-100 bg-slate-50/60 px-3 py-2">
                              <a href={link.url} target="_blank" rel="noreferrer" className="min-w-0 truncate text-[11px] font-semibold text-brand-700 hover:underline">{link.title}</a>
                              {permissions?.can_manage_links && <button type="button" onClick={() => deleteLink(link.id)} disabled={busy === `link-delete-${link.id}`} className="rounded p-1 text-slate-300 transition hover:text-rose-600 disabled:opacity-50" title="Eliminar enlace"><Trash2 className="h-3.5 w-3.5" /></button>}
                            </div>
                          ))}
                        </div>
                      </div>
                    </div>
                  </div>
                )}
              </div>
            )}

            {projectName && <p className="text-[10px] text-slate-400">Proyecto: {projectName}</p>}
          </div>
        )}
      </div>
    </Modal>
  );
}
