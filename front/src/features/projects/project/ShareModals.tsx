import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Building2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { LoadingButton, useConfirm, useToast } from '../../../components/feedback';
import { Badge, ErrorState, Select, Skeleton } from '../../../components/ui';
import { apiGet, apiPost } from '../../../lib/api';
import { initials } from '../../../lib/format';
import type { ShareAccess, SharePanelData } from '../../../types';
import { Avatar, errorMessage, ModalActions, ModalShell, useProject } from './shared';

const ACCESS_OPTIONS: Array<{ value: ShareAccess; label: string }> = [
  { value: 'view', label: 'Ver (solo lectura)' },
  { value: 'collaborate', label: 'Colaborar (tareas, notas y archivos)' },
];

function useSharePanel(projectId: number) {
  return useQuery({
    queryKey: ['project-shares', projectId],
    queryFn: () => apiGet<SharePanelData>('shares.php', { project_id: projectId }),
  });
}

/** Agencia dueña: comparte el proyecto con agencias conectadas y elige el nivel de acceso. */
export function ShareProjectModal({ onClose }: { onClose: () => void }) {
  const { projectId, refresh } = useProject();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const panel = useSharePanel(projectId);
  const [access, setAccess] = useState<Record<number, ShareAccess>>({});

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['project-shares', projectId] });
    void queryClient.invalidateQueries({ queryKey: ['projects'], refetchType: 'none' });
    refresh.project();
    refresh.insights();
  };

  const share = useMutation({
    mutationFn: ({ agencyId, level }: { agencyId: number; level: ShareAccess }) =>
      apiPost<{ message: string }>('shares.php', { action: 'share', project_id: projectId, guest_agency_id: agencyId, access: level }),
    onSuccess: (result) => { invalidate(); toast(result.message); },
    onError: (error) => toast(errorMessage(error, 'No se pudo compartir el proyecto.'), 'error'),
  });

  const unshare = useMutation({
    mutationFn: (shareId: number) => apiPost<{ message: string }>('shares.php', { action: 'unshare', project_id: projectId, share_id: shareId }),
    onSuccess: (result) => { invalidate(); toast(result.message); },
    onError: (error) => toast(errorMessage(error, 'No se pudo dejar de compartir.'), 'error'),
  });

  const agencies = panel.data?.agencies ?? [];
  const pendingAgency = share.isPending ? share.variables?.agencyId : null;

  return (
    <ModalShell title="Compartir proyecto" subtitle="Con agencias conectadas" onClose={onClose}>
      <div className="space-y-4 p-6">
        <p className="text-xs text-slate-500">
          Elige una agencia conectada y su nivel de acceso. La agencia invitada decide después qué miembros de su equipo participan.
          El presupuesto y las facturas nunca se comparten.
        </p>

        {panel.isLoading && <Skeleton className="h-24" />}
        {panel.error && <ErrorState message={errorMessage(panel.error, 'No se pudieron cargar las agencias.')} />}

        {panel.data && agencies.length === 0 && (
          <div className="rounded-xl border border-dashed border-slate-200 p-5 text-center text-xs text-slate-500">
            Aún no tienes agencias conectadas.{' '}
            <Link to="/connections" onClick={onClose} className="font-semibold text-brand-600 hover:text-brand-700">Conecta una agencia</Link>{' '}
            para poder compartir proyectos.
          </div>
        )}

        {agencies.length > 0 && (
          <div className="space-y-2.5">
            {agencies.map((agency) => {
              const selected = access[agency.agencyId] ?? agency.access ?? 'view';
              const isShared = agency.shareId !== null;
              const unchanged = isShared && selected === agency.access;
              return (
                <div key={agency.agencyId} className="space-y-2 rounded-xl border border-slate-200/80 p-3.5">
                  <div className="flex items-center justify-between gap-2">
                    <div className="flex min-w-0 items-center gap-2">
                      <span className="flex h-7 w-7 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 text-[10px] font-medium text-slate-700">
                        {agency.agencyLogo ? <img src={agency.agencyLogo} alt="" className="h-full w-full object-cover" /> : initials(agency.agencyName)}
                      </span>
                      <span className="truncate text-xs font-semibold text-slate-800">{agency.agencyName}</span>
                    </div>
                    {isShared ? <Badge tone="emerald">Compartido</Badge> : <span className="text-[10px] font-medium text-slate-400">No compartido</span>}
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Select
                      aria-label={`Acceso de ${agency.agencyName}`}
                      value={selected}
                      onChange={(event) => setAccess((prev) => ({ ...prev, [agency.agencyId]: event.target.value as ShareAccess }))}
                      className="px-2.5 py-1.5 text-xs"
                    >
                      {ACCESS_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </Select>
                    <LoadingButton
                      type="button"
                      pending={pendingAgency === agency.agencyId}
                      disabled={unchanged || share.isPending}
                      onClick={() => share.mutate({ agencyId: agency.agencyId, level: selected })}
                      className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-50"
                    >
                      {isShared ? 'Cambiar acceso' : 'Compartir'}
                    </LoadingButton>
                    {isShared && (
                      <button
                        type="button"
                        disabled={unshare.isPending}
                        onClick={async () => {
                          if (await confirm({ title: 'Dejar de compartir', description: `${agency.agencyName} perderá el acceso a este proyecto.`, confirmLabel: 'Dejar de compartir', tone: 'danger' })) {
                            unshare.mutate(agency.shareId!);
                          }
                        }}
                        className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                      >
                        Dejar de compartir
                      </button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </ModalShell>
  );
}

/** Agencia invitada: elige qué miembros de su equipo participan en el proyecto compartido. */
export function ShareTeamModal({ onClose }: { onClose: () => void }) {
  const { projectId, refresh } = useProject();
  const queryClient = useQueryClient();
  const toast = useToast();
  const panel = useSharePanel(projectId);
  const [selected, setSelected] = useState<Set<number>>(new Set());

  useEffect(() => {
    if (panel.data?.memberIds) setSelected(new Set(panel.data.memberIds));
  }, [panel.data?.memberIds]);

  const save = useMutation({
    mutationFn: () => apiPost<{ message: string }>('shares.php', {
      action: 'set_members',
      project_id: projectId,
      share_id: panel.data!.share!.id,
      user_ids: Array.from(selected).join(','),
    }),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['project-shares', projectId] });
      refresh.all();
      toast(result.message);
      onClose();
    },
  });

  const share = panel.data?.share;
  const members = panel.data?.members ?? [];
  const toggle = (id: number) => setSelected((prev) => {
    const next = new Set(prev);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });

  return (
    <ModalShell title="Equipo en este proyecto" subtitle="Miembros de tu agencia con acceso" onClose={onClose}>
      <form className="space-y-4 p-6" onSubmit={(event) => { event.preventDefault(); save.mutate(); }}>
        {panel.isLoading && <Skeleton className="h-24" />}
        {panel.error && <ErrorState message={errorMessage(panel.error, 'No se pudo cargar el equipo.')} />}

        {share && (
          <p className="flex items-start gap-2 text-xs text-slate-500">
            <Building2 className="mt-0.5 h-3.5 w-3.5 flex-shrink-0 text-slate-400" />
            <span>
              Proyecto compartido por <strong className="text-slate-700">{share.ownerAgencyName}</strong> ({share.access === 'collaborate' ? 'pueden colaborar' : 'solo lectura'}).
              Elige qué miembros de <strong className="text-slate-700">{panel.data?.agencyName}</strong> tienen acceso.
            </span>
          </p>
        )}

        {share && members.length === 0 && <p className="text-xs italic text-slate-400">No hay usuarios en tu agencia todavía.</p>}

        {members.length > 0 && (
          <div className="max-h-64 space-y-1 overflow-y-auto rounded-xl border border-slate-200/80 p-2">
            {members.map((member) => (
              <label key={member.id} className="flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 hover:bg-slate-50">
                <input type="checkbox" checked={selected.has(member.id)} onChange={() => toggle(member.id)} className="h-4 w-4 rounded border-slate-300 text-brand-600" />
                <Avatar name={member.name} url={member.avatarUrl} />
                <span className="min-w-0">
                  <span className="block truncate text-xs font-medium text-slate-800">{member.name}</span>
                  <span className="block truncate text-[10px] text-slate-400">{member.email} · <span className="capitalize">{member.role.replace('_', ' ')}</span></span>
                </span>
              </label>
            ))}
          </div>
        )}

        {save.isError && <ErrorState message={errorMessage(save.error, 'No se pudo guardar el equipo.')} />}
        <ModalActions onCancel={onClose}>
          <LoadingButton type="submit" pending={save.isPending} disabled={!share} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">Guardar equipo</LoadingButton>
        </ModalActions>
      </form>
    </ModalShell>
  );
}
