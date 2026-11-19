import { useMutation } from '@tanstack/react-query';
import { LoadingButton, useToast } from '../../../components/feedback';
import { Badge, ErrorState, Input, Select } from '../../../components/ui';
import { apiPost } from '../../../lib/api';
import { errorMessage, FieldLabel, ModalActions, ModalShell, Avatar, useProject } from './shared';

export function TeamModal({ onClose }: { onClose: () => void }) {
  const { data } = useProject();
  return (
    <ModalShell title="Equipo del proyecto" subtitle={`${data.members.length} miembros asignados`} onClose={onClose}>
      {data.members.length === 0 ? <p className="p-8 text-center text-sm text-slate-400">Sin miembros asignados.</p> : data.members.map((member) => (
        <div key={member.id} className="flex items-center gap-3 px-6 py-3">
          <Avatar name={member.name} url={member.avatarUrl} size="h-9 w-9 text-[11px]" />
          <div className="min-w-0 flex-1"><p className="truncate text-xs font-semibold text-slate-800">{member.name}</p><p className="truncate text-[11px] text-slate-400">{member.email}</p></div>
          <Badge tone="slate" className="capitalize">{(member.projectRole ?? member.role).replace('_', ' ')}</Badge>
        </div>
      ))}
    </ModalShell>
  );
}

export function NewTaskModal({ onClose, defaultStatus }: { onClose: () => void; defaultStatus?: string }) {
  const { projectId, data, refresh } = useProject();
  const toast = useToast();
  const createTask = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('tasks.php', { action: 'create', project_id: projectId, ...body }),
    onSuccess: () => { refresh.project(); refresh.insights(); toast('Tarea creada.'); onClose(); },
  });
  return (
    <ModalShell title="Nueva tarea" subtitle="Añade una tarea con todos sus detalles" onClose={onClose}>
      <form className="space-y-3 p-6" onSubmit={(event) => {
        event.preventDefault();
        const body: Record<string, string> = {};
        new FormData(event.currentTarget).forEach((value, key) => { body[key] = String(value); });
        createTask.mutate(body);
      }}>
        <div><FieldLabel>Título *</FieldLabel><Input name="title" required maxLength={200} autoFocus className="px-3.5 py-2.5 text-sm" /></div>
        <div><FieldLabel>Descripción</FieldLabel><textarea name="description" rows={3} className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" /></div>
        <div className="grid grid-cols-2 gap-3">
          <div><FieldLabel>Estado</FieldLabel><Select name="status" defaultValue={defaultStatus ?? data.statuses[0]?.key} className="w-full px-3.5 py-2.5">{data.statuses.map((status) => <option key={status.key} value={status.key}>{status.label}</option>)}</Select></div>
          <div><FieldLabel>Prioridad</FieldLabel><Select name="priority" defaultValue="media" className="w-full px-3.5 py-2.5"><option value="baja">Baja</option><option value="media">Media</option><option value="alta">Alta</option><option value="urgente">Urgente</option></Select></div>
          <div><FieldLabel>Vencimiento</FieldLabel><Input name="due_date" type="date" className="px-3.5 py-2.5 text-sm" /></div>
          <div><FieldLabel>Responsable</FieldLabel><Select name="assigned_user_id" className="w-full px-3.5 py-2.5"><option value="">Sin asignar</option>{data.members.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}</Select></div>
        </div>
        {createTask.isError && <ErrorState message={errorMessage(createTask.error, 'No se pudo crear la tarea.')} />}
        <ModalActions onCancel={onClose}><LoadingButton type="submit" pending={createTask.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">Crear tarea</LoadingButton></ModalActions>
      </form>
    </ModalShell>
  );
}

export function DuplicateModal({ onClose, onDone }: { onClose: () => void; onDone: (projectId: number) => void }) {
  const { projectId, data } = useProject();
  const toast = useToast();
  const duplicate = useMutation({
    mutationFn: (name: string) => apiPost<{ projectId: number }>('projects.php', { action: 'duplicate', project_id: projectId, name }),
    onSuccess: (result) => { toast('Proyecto duplicado.'); onDone(result.projectId); },
  });
  return (
    <ModalShell title="Duplicar proyecto" subtitle="Crea una copia con tareas y checklists" onClose={onClose}>
      <form className="space-y-4 p-6" onSubmit={(event) => { event.preventDefault(); duplicate.mutate(String(new FormData(event.currentTarget).get('name') ?? '')); }}>
        <div><FieldLabel>Nombre de la copia</FieldLabel><Input name="name" placeholder={`${data.project.name} (copia)`} className="px-3.5 py-2.5 text-sm" /></div>
        {duplicate.isError && <ErrorState message={errorMessage(duplicate.error, 'No se pudo duplicar.')} />}
        <ModalActions onCancel={onClose}><LoadingButton type="submit" pending={duplicate.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">Duplicar</LoadingButton></ModalActions>
      </form>
    </ModalShell>
  );
}

export function TemplateModal({ onClose }: { onClose: () => void }) {
  const { projectId, data } = useProject();
  const toast = useToast();
  const saveTemplate = useMutation({
    mutationFn: (templateName: string) => apiPost<{ message: string }>('projects.php', { action: 'save_template', project_id: projectId, template_name: templateName }),
    onSuccess: (result) => { toast(result.message); onClose(); },
  });
  return (
    <ModalShell title="Guardar como plantilla" subtitle="Reutiliza tareas y checklists" onClose={onClose}>
      <form className="space-y-4 p-6" onSubmit={(event) => { event.preventDefault(); saveTemplate.mutate(String(new FormData(event.currentTarget).get('template_name') ?? '')); }}>
        <div><FieldLabel>Nombre de la plantilla *</FieldLabel><Input name="template_name" required defaultValue={data.project.name} className="px-3.5 py-2.5 text-sm" /></div>
        {saveTemplate.isError && <ErrorState message={errorMessage(saveTemplate.error, 'No se pudo guardar la plantilla.')} />}
        <ModalActions onCancel={onClose}><LoadingButton type="submit" pending={saveTemplate.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">Guardar plantilla</LoadingButton></ModalActions>
      </form>
    </ModalShell>
  );
}

export function EditProjectModal({ onClose, onSaved }: { onClose: () => void; onSaved: (message: string) => void }) {
  const { projectId, data, refresh } = useProject();
  const { project } = data;
  const updateProject = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ message: string }>('projects.php', { action: 'update', project_id: projectId, ...body }),
    onSuccess: (result) => { refresh.all(); onSaved(result.message); },
  });
  return (
    <ModalShell title="Configuración del proyecto" subtitle="Datos generales y presupuesto" onClose={onClose} wide>
      <form className="space-y-4 p-6" onSubmit={(event) => {
        event.preventDefault();
        const body: Record<string, string> = {};
        new FormData(event.currentTarget).forEach((value, key) => { body[key] = String(value); });
        updateProject.mutate(body);
      }}>
        <div><FieldLabel>Nombre *</FieldLabel><Input name="name" required defaultValue={project.name} className="px-3.5 py-2.5 text-sm" /></div>
        <div><FieldLabel>Cliente</FieldLabel><Input name="client_name" defaultValue={project.clientName ?? ''} maxLength={150} className="px-3.5 py-2.5 text-sm" /></div>
        <div><FieldLabel>Descripción</FieldLabel><textarea name="description" rows={3} defaultValue={project.description ?? ''} className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" /></div>
        <div className="grid grid-cols-2 gap-3">
          <div><FieldLabel>Estado</FieldLabel><Select name="status" defaultValue={project.status} className="w-full px-3.5 py-2.5"><option value="activo">Activo</option><option value="en_espera">En espera</option><option value="completado">Completado</option></Select></div>
          <div><FieldLabel>Categoría</FieldLabel><Input name="category" defaultValue={project.category ?? ''} maxLength={50} className="px-3.5 py-2.5 text-sm" /></div>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div><FieldLabel>Inicio</FieldLabel><Input name="start_date" type="date" defaultValue={project.startDate ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
          <div><FieldLabel>Entrega</FieldLabel><Input name="deadline" type="date" defaultValue={project.dueDate ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
          <div><FieldLabel>Color</FieldLabel><input name="color" type="color" defaultValue={project.color} className="h-[42px] w-full rounded-xl border border-slate-200 bg-white px-1 py-1" /></div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div><FieldLabel>Presupuesto</FieldLabel><Input name="budget" type="number" step="0.01" min="0" inputMode="decimal" defaultValue={project.budget ?? 0} className="px-3.5 py-2.5 text-sm" /></div>
          <div><FieldLabel>Moneda</FieldLabel><Select name="currency" defaultValue={project.currency} className="w-full px-3.5 py-2.5 font-mono">{['USD', 'EUR', 'MXN', 'PEN', 'COP', 'CLP', 'ARS'].map((code) => <option key={code} value={code}>{code}</option>)}</Select></div>
        </div>
        {updateProject.isError && <ErrorState message={errorMessage(updateProject.error, 'No se pudo guardar.')} />}
        <ModalActions onCancel={onClose}><LoadingButton type="submit" pending={updateProject.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">Guardar cambios</LoadingButton></ModalActions>
      </form>
    </ModalShell>
  );
}

/** Vista de tarea tal como la ve un cliente: solo descripción y estado, sin comentarios internos. */
export function ClientTaskPreview({ taskId, onClose }: { taskId: number; onClose: () => void }) {
  const { data } = useProject();
  const task = data.tasks.find((item) => item.id === taskId);
  if (!task) return null;
  return (
    <ModalShell title={task.title} subtitle="Vista previa como cliente" onClose={onClose}>
      <div className="space-y-4 p-6">
        <div className="flex flex-wrap gap-2 text-[11px]">
          <span className="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-600">{task.statusLabel}</span>
          {task.dueDate && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">Entrega: {task.dueDate}</span>}
          {task.assignee && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">Responsable: {task.assignee}</span>}
        </div>
        <p className="whitespace-pre-line text-sm leading-relaxed text-slate-600">{task.description || 'Sin descripción.'}</p>
        <p className="rounded-xl bg-slate-50 px-3 py-2 text-[11px] text-slate-500">El cliente no ve comentarios internos, checklists, adjuntos de otros ni enlaces de la tarea.</p>
      </div>
    </ModalShell>
  );
}
