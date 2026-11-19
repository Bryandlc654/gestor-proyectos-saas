import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Archive } from 'lucide-react';
import { ErrorState, Skeleton } from '../../components/ui';
import { useConfirm, useToast } from '../../components/feedback';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate } from '../../lib/format';
import type { TrashData } from '../../types';

export function TrashPage() {
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const toast = useToast();
  const { data, isLoading, error } = useQuery({ queryKey: ['trash'], queryFn: () => apiGet<TrashData>('trash.php') });

  const restore = useMutation({
    mutationFn: (projectId: number) => apiPost<{ success: boolean }>('trash.php', { action: 'restore', project_id: projectId }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['trash'] });
      void queryClient.invalidateQueries({ queryKey: ['projects'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
      toast('Proyecto restaurado.');
    },
  });

  const destroy = useMutation({
    mutationFn: (projectId: number) => apiPost<{ success: boolean }>('trash.php', { action: 'delete_forever', project_id: projectId }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['trash'] });
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
      toast('Proyecto eliminado definitivamente.');
    },
  });

  if (isLoading) return <div className="space-y-4">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} className="h-16" />)}</div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar la papelera.'} />;

  return (
    <div className="space-y-6">
      <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Papelera</h1>

      <p className="text-sm text-slate-500">
        Los proyectos archivados no aparecen en dashboards, tareas ni agencias invitadas. Puedes restaurarlos o,
        si eres administrador, eliminarlos definitivamente.
      </p>

      {data.projects.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-10 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><Archive className="h-6 w-6" /></div>
          <h2 className="text-sm font-semibold text-slate-800">La papelera está vacía</h2>
          <p className="mt-1 text-xs text-slate-500">Cuando archives un proyecto aparecerá aquí.</p>
        </div>
      ) : (
        <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
          {data.projects.map((project) => (
            <li key={project.id} className="flex flex-col justify-between gap-3 px-4 py-3.5 sm:flex-row sm:items-center">
              <div className="flex min-w-0 items-center gap-3">
                <span className="h-2.5 w-2.5 flex-shrink-0 rounded-full" style={{ backgroundColor: project.color }} />
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-slate-900">{project.name}</p>
                  <p className="text-xs text-slate-400">
                    Archivado el {formatDate(project.archivedAt, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
                    {project.archivedBy ? ` · por ${project.archivedBy}` : ''}
                  </p>
                </div>
              </div>
              <div className="flex flex-shrink-0 items-center gap-2">
                <button type="button" disabled={restore.isPending} onClick={() => restore.mutate(project.id)} className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Restaurar</button>
                {data.isAdmin && (
                  <button
                    type="button"
                    disabled={destroy.isPending}
                    onClick={async () => {
                      if (await confirm({ title: 'Eliminar definitivamente', description: 'Se eliminará el proyecto y todos sus archivos. Esta acción no se puede deshacer.', confirmLabel: 'Eliminar definitivamente', tone: 'danger' })) destroy.mutate(project.id);
                    }}
                    className="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-50 disabled:opacity-60"
                  >
                    Eliminar definitivamente
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}

      {(restore.isError || destroy.isError) && (
        <ErrorState message={(() => {
          const mutationError = restore.error ?? destroy.error;
          return mutationError instanceof Error ? mutationError.message : 'No se pudo completar la acción.';
        })()} />
      )}
    </div>
  );
}
