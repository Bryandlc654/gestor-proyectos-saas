import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LayoutTemplate } from 'lucide-react';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card, ErrorState, Input, Skeleton } from '../../components/ui';
import { useConfirm, useToast } from '../../components/feedback';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate } from '../../lib/format';
import type { TemplatesData } from '../../types';

export function TemplatesPage() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const confirm = useConfirm();
  const toast = useToast();
  const [names, setNames] = useState<Record<number, string>>({});
  const [deadlines, setDeadlines] = useState<Record<number, string>>({});

  const { data, isLoading, error } = useQuery({ queryKey: ['templates'], queryFn: () => apiGet<TemplatesData>('templates.php') });

  const createProject = useMutation({
    mutationFn: ({ templateId, name, deadline }: { templateId: number; name: string; deadline: string }) =>
      apiPost<{ projectId: number }>('templates.php', { action: 'create_project', template_id: templateId, name, deadline }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['templates'] });
      toast('Proyecto creado desde la plantilla.');
      navigate(`/projects/${result.projectId}`);
    },
  });

  const deleteTemplate = useMutation({
    mutationFn: (templateId: number) => apiPost<{ success: boolean }>('templates.php', { action: 'delete', template_id: templateId }),
    onSuccess: () => { void queryClient.invalidateQueries({ queryKey: ['templates'] }); toast('Plantilla eliminada.'); },
  });

  if (isLoading) return <div className="space-y-4">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} className="h-40" />)}</div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudieron cargar las plantillas.'} />;

  return (
    <div className="space-y-5">
      <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Plantillas</h1>

      <p className="text-sm text-slate-500">
        Guarda un proyecto como plantilla desde su página (menú de acciones) y reutiliza sus tareas y checklists.
        Las tareas se crean en estado pendiente y sin responsable.
      </p>

      {data.templates.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-10 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><LayoutTemplate className="h-6 w-6" /></div>
          <h2 className="text-sm font-semibold text-slate-800">Todavía no hay plantillas</h2>
          <p className="mt-1 text-xs text-slate-500">Abre un proyecto y usa “Guardar plantilla”.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {data.templates.map((template) => (
            <Card key={template.id} className="space-y-3 p-5">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <h2 className="truncate text-sm font-semibold text-slate-900">{template.name}</h2>
                  <p className="text-[11px] text-slate-400">
                    {template.category || 'General'} · creada {formatDate(template.createdAt)}
                    {template.createdBy ? ` · ${template.createdBy}` : ''}
                  </p>
                </div>
                <span className="h-2.5 w-2.5 flex-shrink-0 rounded-full" style={{ backgroundColor: template.color }} />
              </div>

              {template.description && <p className="line-clamp-2 text-xs text-slate-500">{template.description}</p>}

              <form className="space-y-2 border-t border-slate-100 pt-2" onSubmit={(event) => {
                event.preventDefault();
                createProject.mutate({ templateId: template.id, name: names[template.id] ?? '', deadline: deadlines[template.id] ?? '' });
              }}>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_auto]">
                  <Input value={names[template.id] ?? ''} onChange={(event) => setNames({ ...names, [template.id]: event.target.value })} required placeholder="Nombre del nuevo proyecto" className="px-3 py-2" />
                  <Input type="date" value={deadlines[template.id] ?? ''} onChange={(event) => setDeadlines({ ...deadlines, [template.id]: event.target.value })} className="px-3 py-2" />
                </div>
                <div className="flex items-center justify-between gap-2">
                  <button type="submit" disabled={createProject.isPending || !(names[template.id] ?? '').trim()} className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Crear proyecto</button>
                  <button type="button" disabled={deleteTemplate.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar plantilla', description: template.name, confirmLabel: 'Eliminar', tone: 'danger' })) deleteTemplate.mutate(template.id); }} className="text-[11px] font-medium text-rose-600 transition hover:text-rose-700 disabled:opacity-60">Eliminar</button>
                </div>
              </form>
            </Card>
          ))}
        </div>
      )}

      {(createProject.isError || deleteTemplate.isError) && (
        <ErrorState message={(() => {
          const mutationError = createProject.error ?? deleteTemplate.error;
          return mutationError instanceof Error ? mutationError.message : 'No se pudo completar la acción.';
        })()} />
      )}
    </div>
  );
}
