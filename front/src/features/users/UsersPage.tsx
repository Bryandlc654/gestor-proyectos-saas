import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Briefcase, FolderCheck, Pencil, Search, Send, Shield, ShieldCheck, Trash2, UserCheck, UserPlus, Users, X } from 'lucide-react';
import { useState } from 'react';
import { Card, ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { useConfirm, useDialog, useToast, LoadingButton } from '../../components/feedback';
import { useUnsavedChanges } from '../../hooks/useUnsavedChanges';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate } from '../../lib/format';
import type { InvitationResult, UsersData } from '../../types';

const ROLE_OPTIONS = [
  { value: 'cliente', label: 'Cliente (Solo proyectos asignados y tickets)' },
  { value: 'colaborador', label: 'Colaborador (Ejecuta tareas asignadas)' },
  { value: 'project_manager', label: 'Project Manager (Gestiona proyectos)' },
  { value: 'admin', label: 'Administrador (Control total del sistema)' },
];

export function UsersPage() {
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const toast = useToast();
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('todos');
  const [page, setPage] = useState(1);

  const [inviteOpen, setInviteOpen] = useState(false);
  const [inviteLink, setInviteLink] = useState('');
  const [copied, setCopied] = useState(false);

  const [newUserOpen, setNewUserOpen] = useState(false);
  const [newUserDirty, setNewUserDirty] = useState(false);
  useUnsavedChanges(newUserOpen && newUserDirty, 'Tienes datos sin guardar del nuevo usuario.');
  const closeNewUser = async () => {
    if (newUserDirty && !(await confirm({ title: 'Descartar usuario', description: 'Se perderán los datos introducidos.', confirmLabel: 'Descartar', cancelLabel: 'Seguir editando', tone: 'danger' }))) return;
    setNewUserDirty(false);
    setNewUserOpen(false);
  };
  const [newUserRole, setNewUserRole] = useState('cliente');
  const [newUserProjects, setNewUserProjects] = useState<number[]>([]);

  const [assignUser, setAssignUser] = useState<UsersData['users'][number] | null>(null);
  const [assignIds, setAssignIds] = useState<number[]>([]);
  const assignRef = useDialog(assignUser !== null, () => setAssignUser(null));

  const { data, isLoading, error } = useQuery({
    queryKey: ['users', search, role, page],
    queryFn: () => apiGet<UsersData>('users.php', { search, role, page }),
  });

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['users'] });

  const createUser = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('users.php', { action: 'create_user', ...body }),
    onSuccess: () => {
      setNewUserDirty(false);
      setNewUserOpen(false);
      setNewUserProjects([]);
      setNewUserRole('cliente');
      refresh();
      toast('Usuario creado correctamente.');
    },
  });

  const updateRole = useMutation({
    mutationFn: ({ userId, newRole }: { userId: number; newRole: string }) => apiPost<{ success: boolean }>('users.php', { action: 'update_role', target_user_id: userId, new_role: newRole }),
    onSuccess: () => { refresh(); toast('Rol actualizado.'); },
  });

  const deleteUser = useMutation({
    mutationFn: (userId: number) => apiPost<{ success: boolean }>('users.php', { action: 'delete_user', target_user_id: userId }),
    onSuccess: () => { refresh(); toast('Usuario eliminado.'); },
  });

  const assignProjects = useMutation({
    mutationFn: ({ userId, projectIds }: { userId: number; projectIds: number[] }) => apiPost<{ success: boolean }>('users.php', { action: 'assign_projects', target_user_id: userId, project_ids: JSON.stringify(projectIds) }),
    onSuccess: () => { setAssignUser(null); refresh(); toast('Proyectos vinculados.'); },
  });

  const createInvitation = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<InvitationResult>('users.php', { action: 'create_invitation', ...body }),
    onSuccess: (result) => {
      setInviteLink(`${window.location.origin}${result.invitePath}`);
      setCopied(false);
      refresh();
      toast('Invitación creada.');
    },
  });

  const revokeInvitation = useMutation({
    mutationFn: (invitationId: number) => apiPost<{ success: boolean }>('users.php', { action: 'revoke_invitation', invitation_id: invitationId }),
    onSuccess: () => { refresh(); toast('Invitación revocada.'); },
  });

  if (isLoading) return <div className="space-y-4">{Array.from({ length: 6 }).map((_, index) => <Skeleton key={index} className="h-20" />)}</div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudieron cargar los usuarios.'} />;

  const roleMeta = (key: string) => data.roles.find((item) => item.key === key);
  const filtered = search !== '' || role !== 'todos';
  const pagination = data.pagination;

  const toggleProject = (list: number[], projectId: number): number[] => list.includes(projectId) ? list.filter((id) => id !== projectId) : [...list, projectId];

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Usuarios y roles</h1>
        <div className="flex items-center gap-3">
          <button type="button" onClick={() => setInviteOpen((value) => !value)} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 sm:text-sm">
            <Send className="h-4 w-4" />
            <span>Invitar{data.invitations.length ? ` (${data.invitations.length})` : ''}</span>
          </button>
          <button type="button" onClick={() => { setNewUserDirty(false); setNewUserOpen((value) => !value); }} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 sm:text-sm">
            <UserPlus className="h-4 w-4" />
            <span>Nuevo Usuario</span>
          </button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <Card className="space-y-2 p-5">
          <div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Total Usuarios</span><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-50 text-slate-400"><Users className="h-4 w-4" /></span></div>
          <p className="text-2xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[28px]">{data.stats.total}</p>
          <span className="text-xs font-medium text-slate-400">cuentas activas</span>
        </Card>
        <Card className="space-y-2 p-5">
          <div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Administradores</span><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-50 text-slate-400"><Shield className="h-4 w-4" /></span></div>
          <p className="text-2xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[28px]">{data.stats.admins}</p>
          <span className="text-xs font-medium text-slate-400">control absoluto</span>
        </Card>
        <Card className="space-y-2 p-5">
          <div className="flex items-center justify-between"><span className="text-xs font-medium text-blue-700">Colaboradores & PM</span><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-50 text-slate-400"><UserCheck className="h-4 w-4" /></span></div>
          <p className="text-2xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[28px]">{data.stats.collaborators + data.stats.managers}</p>
          <span className="text-xs font-medium text-slate-400">{data.stats.managers} managers / {data.stats.collaborators} operativos</span>
        </Card>
        <Card className="space-y-2 p-5">
          <div className="flex items-center justify-between"><span className="text-xs font-medium text-amber-700">Clientes</span><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-50 text-slate-400"><Briefcase className="h-4 w-4" /></span></div>
          <p className="text-2xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[28px]">{data.stats.clients}</p>
          <span className="text-xs font-medium text-slate-400">acceso restringido a sus proyectos</span>
        </Card>
      </div>

      {inviteOpen && (
        <div className="space-y-5 rounded-3xl border border-brand-200/80 bg-white p-6 shadow-sm sm:p-8">
          <div className="flex items-center justify-between border-b border-slate-100 pb-3">
            <div className="flex items-center gap-2.5">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-700"><Send className="h-4 w-4" /></div>
              <div>
                <h3 className="text-base font-semibold text-slate-900">Invitar por enlace</h3>
                <p className="text-xs text-slate-400">La persona crea su propia contraseña. El enlace caduca en 7 días y sirve una sola vez.</p>
              </div>
            </div>
            <button type="button" onClick={() => setInviteOpen(false)} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X className="h-5 w-5" /></button>
          </div>

          <form className="grid grid-cols-1 items-end gap-4 sm:grid-cols-[1fr_1fr_auto]" onSubmit={(event) => {
            event.preventDefault();
            const form = new FormData(event.currentTarget);
            createInvitation.mutate({ email: String(form.get('email') ?? ''), role: String(form.get('role') ?? 'cliente') });
          }}>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Correo Electrónico *</label>
              <Input name="email" type="email" required placeholder="persona@empresa.com" className="px-3.5 py-2.5" />
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Rol *</label>
              <Select name="role" defaultValue="cliente" className="w-full px-3.5 py-2.5">
                {ROLE_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
              </Select>
            </div>
            <LoadingButton type="submit" pending={createInvitation.isPending} className="rounded-xl bg-brand-600 px-5 py-2.5 text-xs font-semibold text-white shadow-2xs transition hover:bg-brand-700 disabled:opacity-60">Generar enlace</LoadingButton>
          </form>

          {createInvitation.isError && <ErrorState message={createInvitation.error instanceof Error ? createInvitation.error.message : 'No se pudo crear la invitación.'} />}

          {inviteLink && (
            <div className="space-y-2 rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4">
              <p className="text-xs font-medium text-emerald-800">Enlace listo. Cópialo y envíaselo: por seguridad no se volverá a mostrar.</p>
              <div className="flex gap-2">
                <input type="text" readOnly value={inviteLink} className="min-w-0 flex-1 rounded-xl border border-emerald-200 bg-white px-3 py-2 text-xs text-slate-700" />
                <button type="button" onClick={async () => {
                  try { await navigator.clipboard.writeText(inviteLink); } catch { /* portapapeles no disponible */ }
                  setCopied(true);
                  window.setTimeout(() => setCopied(false), 2000);
                }} className="rounded-xl border border-emerald-200 bg-white px-3.5 py-2 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-50">{copied ? 'Copiado' : 'Copiar'}</button>
              </div>
            </div>
          )}

          {data.invitations.length > 0 && (
            <div className="space-y-2">
              <h4 className="text-xs font-semibold text-slate-500">Invitaciones pendientes</h4>
              <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200/80">
                {data.invitations.map((invitation) => {
                  const meta = roleMeta(invitation.role);
                  return (
                    <li key={invitation.id} className="flex items-center justify-between gap-3 px-4 py-2.5 text-xs">
                      <div className="min-w-0">
                        <span className="truncate font-medium text-slate-800">{invitation.email}</span>
                        <span className={`ml-2 rounded-md px-2 py-0.5 ${meta?.className ?? 'bg-slate-100 text-slate-600'}`}>{invitation.roleLabel}</span>
                      </div>
                      <div className="flex flex-shrink-0 items-center gap-3">
                        <span className="text-slate-400">caduca {formatDate(invitation.expiresAt)}</span>
                        <button type="button" disabled={revokeInvitation.isPending} onClick={async () => { if (await confirm({ title: 'Revocar invitación', description: 'El enlace dejará de funcionar.', confirmLabel: 'Revocar', tone: 'danger' })) revokeInvitation.mutate(invitation.id); }} className="font-medium text-rose-600 hover:underline">Revocar</button>
                      </div>
                    </li>
                  );
                })}
              </ul>
            </div>
          )}
        </div>
      )}

      {newUserOpen && (
        <div className="space-y-5 rounded-3xl border border-brand-200/80 bg-white p-6 shadow-sm sm:p-8">
          <div className="flex items-center justify-between border-b border-slate-100 pb-3">
            <div className="flex items-center gap-2.5">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-700"><UserPlus className="h-4 w-4" /></div>
              <div>
                <h3 className="text-base font-semibold text-slate-900">Registrar Nuevo Usuario / Cliente</h3>
                <p className="text-xs text-slate-400">Configura sus credenciales, rol y proyectos permitidos</p>
              </div>
            </div>
            <button type="button" onClick={() => void closeNewUser()} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><X className="h-5 w-5" /></button>
          </div>

          <form className="space-y-4" onChange={() => setNewUserDirty(true)} onSubmit={(event) => {
            event.preventDefault();
            const form = new FormData(event.currentTarget);
            createUser.mutate({
              name: String(form.get('name') ?? ''),
              email: String(form.get('email') ?? ''),
              password: String(form.get('password') ?? ''),
              role: newUserRole,
              project_ids: JSON.stringify(newUserRole === 'admin' ? [] : newUserProjects),
            });
          }}>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Nombre Completo *</label>
                <Input name="name" required placeholder="Ej: Carlos Méndez" className="px-3.5 py-2.5" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Correo Electrónico *</label>
                <Input name="email" type="email" required placeholder="usuario@empresa.com" className="px-3.5 py-2.5" />
              </div>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Contraseña Inicial (mín. 8 caracteres) *</label>
                <Input name="password" type="password" minLength={8} required placeholder="••••••••" className="px-3.5 py-2.5" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Nivel de Acceso (Rol) *</label>
                <Select value={newUserRole} onChange={(event) => setNewUserRole(event.target.value)} className="w-full px-3.5 py-2.5">
                  {ROLE_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </Select>
              </div>
            </div>

            <div className={`space-y-2 rounded-2xl border border-brand-100 bg-brand-50/50 p-4 ${newUserRole === 'admin' ? 'pointer-events-none opacity-50' : ''}`}>
              <label className="flex items-center gap-1.5 text-xs font-bold text-slate-900">
                <FolderCheck className="h-3.5 w-3.5 text-brand-600" />
                <span>Proyectos Asignados a este usuario:</span>
              </label>
              <p className="text-[11px] text-brand-700">Para clientes, solo podrán visualizar las tareas y tickets de los proyectos marcados aquí.</p>
              <div className="grid max-h-48 grid-cols-1 gap-2 overflow-y-auto pt-2 sm:grid-cols-2 md:grid-cols-3">
                {data.projects.map((project) => (
                  <label key={project.id} className="flex cursor-pointer items-center gap-2 rounded-xl border border-brand-100 bg-white p-2 text-xs transition hover:border-brand-200">
                    <input type="checkbox" checked={newUserProjects.includes(project.id)} onChange={() => setNewUserProjects((current) => toggleProject(current, project.id))} className="rounded text-brand-600 focus:ring-brand-500" />
                    <span className="h-2.5 w-2.5 flex-shrink-0 rounded-full" style={{ backgroundColor: project.color }} />
                    <span className="truncate font-medium text-slate-800">{project.name}</span>
                  </label>
                ))}
              </div>
            </div>

            {createUser.isError && <ErrorState message={createUser.error instanceof Error ? createUser.error.message : 'No se pudo crear el usuario.'} />}

            <div className="flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
              <button type="button" onClick={() => void closeNewUser()} className="rounded-xl px-4 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
              <LoadingButton type="submit" pending={createUser.isPending} className="rounded-xl bg-brand-600 px-5 py-2 text-xs font-semibold text-white shadow-2xs transition hover:bg-brand-700 disabled:opacity-60">Registrar Usuario</LoadingButton>
            </div>
          </form>
        </div>
      )}

      <Card className="flex flex-col items-center justify-between gap-3 p-4 md:flex-row">
        <form className="flex w-full flex-col items-center gap-3 md:flex-row" onSubmit={(event) => event.preventDefault()}>
          <div className="relative w-full flex-1">
            <Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Buscar por nombre o correo electrónico..." className="pl-10 text-xs sm:text-sm" />
          </div>
          <div className="flex w-full items-center gap-2 md:w-auto">
            <Select value={role} onChange={(event) => { setRole(event.target.value); setPage(1); }}>
              <option value="todos">Todos los roles</option>
              <option value="admin">Administradores</option>
              <option value="project_manager">Project Managers</option>
              <option value="colaborador">Colaboradores</option>
              <option value="cliente">Clientes</option>
            </Select>
            {filtered && <button type="button" onClick={() => { setSearch(''); setRole('todos'); setPage(1); }} className="whitespace-nowrap rounded-xl px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">Limpiar</button>}
          </div>
        </form>
      </Card>

      <div className="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-2xs">
        <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4">
          <div className="flex items-center gap-2">
            <h3 className="text-sm font-semibold text-slate-900">Directorio de Cuentas</h3>
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{pagination.total}</span>
          </div>
        </div>

        <div className="hidden overflow-x-auto md:block">
          <table className="w-full text-left text-xs text-slate-600">
            <thead className="border-b border-slate-100 bg-slate-50/70 text-[11px] font-medium text-slate-500">
              <tr>
                <th className="px-6 py-3.5">Usuario</th>
                <th className="px-4 py-3.5">Nivel de Acceso (Rol)</th>
                <th className="px-4 py-3.5">Proyectos Vinculados</th>
                <th className="px-4 py-3.5">Registro</th>
                <th className="px-6 py-3.5 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.users.map((user) => {
                const isSelf = user.id === data.currentUserId;
                const meta = roleMeta(user.role);
                return (
                  <tr key={user.id} className="transition hover:bg-slate-50/60">
                    <td className="px-6 py-3.5">
                      <div className="flex items-center gap-3">
                        <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">{user.name.slice(0, 2).toUpperCase()}</div>
                        <div>
                          <div className="flex items-center gap-1.5 font-bold text-slate-900">
                            <span>{user.name}</span>
                            {isSelf && <span className="rounded bg-indigo-100 px-1.5 py-0.5 text-[11px] font-medium text-brand-700">Tú</span>}
                          </div>
                          <div className="text-[11px] text-slate-400">{user.email}</div>
                        </div>
                      </div>
                    </td>

                    <td className="whitespace-nowrap px-4 py-3.5">
                      {isSelf ? (
                        <span className={`inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-[11px] font-medium ${meta?.className ?? 'border-slate-200 bg-slate-50 text-slate-600'}`}>
                          <span className={`h-1.5 w-1.5 rounded-full ${meta?.dot ?? 'bg-slate-400'}`} />
                          {user.roleLabel}
                        </span>
                      ) : (
                        <Select value={user.role} disabled={updateRole.isPending} onChange={(event) => updateRole.mutate({ userId: user.id, newRole: event.target.value })} className="cursor-pointer rounded-xl border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-800">
                          <option value="admin">Administrador</option>
                          <option value="project_manager">Project Manager</option>
                          <option value="colaborador">Colaborador</option>
                          <option value="cliente">Cliente</option>
                        </Select>
                      )}
                    </td>

                    <td className="px-4 py-3.5">
                      {user.role === 'admin' ? (
                        <span className="flex items-center gap-1 text-xs font-semibold text-brand-700"><ShieldCheck className="h-3.5 w-3.5" /> Todos los proyectos (Acceso total)</span>
                      ) : (
                        <div className="flex max-w-md flex-wrap items-center gap-1">
                          {user.projectNames.length === 0 ? (
                            <span className="text-[11px] italic text-slate-400">Sin proyectos asignados</span>
                          ) : (
                            <>
                              {user.projectNames.slice(0, 3).map((name) => (
                                <span key={name} className="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">
                                  <span className="h-1.5 w-1.5 rounded-full bg-brand-500" />
                                  <span className="max-w-[130px] truncate">{name}</span>
                                </span>
                              ))}
                              {user.projectNames.length > 3 && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-400">+{user.projectNames.length - 3} más</span>}
                            </>
                          )}
                          <button type="button" onClick={() => { setAssignUser(user); setAssignIds(user.projectIds); }} className="ml-1 flex items-center gap-0.5 text-[11px] font-semibold text-brand-600 hover:text-brand-700 hover:underline"><Pencil className="h-3 w-3" /><span>Editar</span></button>
                        </div>
                      )}
                    </td>

                    <td className="whitespace-nowrap px-4 py-3.5 text-xs text-slate-400">{formatDate(user.createdAt)}</td>

                    <td className="whitespace-nowrap px-6 py-3.5 text-right">
                      <div className="inline-flex items-center justify-end gap-1.5">
                        {isSelf ? (
                          <span className="text-[11px] italic text-slate-400">Sesión actual</span>
                        ) : (
                          <button type="button" disabled={deleteUser.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar usuario', description: `Se eliminará permanentemente a ${user.name}. Esta acción no se puede deshacer.`, confirmLabel: 'Eliminar', tone: 'danger' })) deleteUser.mutate(user.id); }} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar usuario"><Trash2 className="h-4 w-4" /></button>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        <div className="divide-y divide-slate-100 md:hidden">
          {data.users.map((user) => {
            const isSelf = user.id === data.currentUserId;
            return (
              <div key={user.id} className="space-y-3 p-4">
                <div className="flex items-start gap-3">
                  <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">{user.name.slice(0, 2).toUpperCase()}</div>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5 font-bold text-slate-900"><span className="truncate">{user.name}</span>{isSelf && <span className="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-medium text-brand-700">Tú</span>}</div>
                    <p className="truncate text-[11px] text-slate-400">{user.email}</p>
                  </div>
                  {isSelf ? (
                    <span className="flex-shrink-0 rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-semibold text-slate-600">{user.roleLabel}</span>
                  ) : (
                    <Select value={user.role} disabled={updateRole.isPending} onChange={(event) => updateRole.mutate({ userId: user.id, newRole: event.target.value })} className="w-[120px] flex-shrink-0 px-2 py-1 text-[10px] font-semibold">
                      <option value="admin">Admin</option>
                      <option value="project_manager">PM</option>
                      <option value="colaborador">Colaborador</option>
                      <option value="cliente">Cliente</option>
                    </Select>
                  )}
                </div>
                <div className="flex flex-wrap items-center gap-1">
                  {user.role === 'admin' ? (
                    <span className="flex items-center gap-1 text-[10px] font-semibold text-brand-700"><ShieldCheck className="h-3 w-3" /> Todos los proyectos</span>
                  ) : (
                    <>
                      {user.projectNames.slice(0, 3).map((name) => <span key={name} className="max-w-[140px] truncate rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">{name}</span>)}
                      {user.projectNames.length > 3 && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-400">+{user.projectNames.length - 3}</span>}
                      {user.projectNames.length === 0 && <span className="text-[10px] italic text-slate-400">Sin proyectos asignados</span>}
                    </>
                  )}
                </div>
                <div className="flex items-center justify-between gap-2 border-t border-slate-100 pt-2">
                  <span className="text-[10px] text-slate-400">Registro {formatDate(user.createdAt)}</span>
                  <div className="flex items-center gap-1.5">
                    {user.role !== 'admin' && <button type="button" onClick={() => { setAssignUser(user); setAssignIds(user.projectIds); }} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-[10px] font-semibold text-brand-700"><Pencil className="h-3 w-3" /> Proyectos</button>}
                    {!isSelf && <button type="button" disabled={deleteUser.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar usuario', description: `Se eliminará permanentemente a ${user.name}.`, confirmLabel: 'Eliminar', tone: 'danger' })) deleteUser.mutate(user.id); }} className="rounded-lg border border-rose-200 p-1.5 text-rose-500 disabled:opacity-50" title="Eliminar usuario"><Trash2 className="h-3.5 w-3.5" /></button>}
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      </div>

      <PaginationControls pagination={pagination} onChange={setPage} />

      {(updateRole.isError || deleteUser.isError || assignProjects.isError || revokeInvitation.isError) && (
        <ErrorState message={(() => {
          const mutationError = updateRole.error ?? deleteUser.error ?? assignProjects.error ?? revokeInvitation.error;
          return mutationError instanceof Error ? mutationError.message : 'No se pudo completar la acción.';
        })()} />
      )}

      {assignUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs" onClick={() => setAssignUser(null)}>
          <div ref={assignRef} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" className="w-full max-w-lg overflow-hidden rounded-3xl border border-slate-100 bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-100 bg-brand-50/50 px-6 py-4">
              <div className="flex items-center gap-2.5">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-100 text-brand-700"><FolderCheck className="h-4 w-4" /></div>
                <div>
                  <h3 className="text-sm font-semibold text-slate-900">Vincular Proyectos</h3>
                  <p className="text-[11px] text-slate-400">Usuario: {assignUser.name}</p>
                </div>
              </div>
              <button type="button" onClick={() => setAssignUser(null)} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
            </div>

            <div className="space-y-4 p-6">
              <p className="text-xs text-slate-500">Selecciona los proyectos a los que tendrá acceso este usuario:</p>
              <div className="grid max-h-64 grid-cols-1 gap-2 overflow-y-auto p-1 sm:grid-cols-2">
                {data.projects.map((project) => (
                  <label key={project.id} className="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 bg-slate-50/50 p-2.5 text-xs transition hover:border-brand-200 hover:bg-white">
                    <input type="checkbox" checked={assignIds.includes(project.id)} onChange={() => setAssignIds((current) => toggleProject(current, project.id))} className="rounded text-brand-600 focus:ring-brand-500" />
                    <span className="h-2.5 w-2.5 flex-shrink-0 rounded-full" style={{ backgroundColor: project.color }} />
                    <span className="truncate font-medium text-slate-800">{project.name}</span>
                  </label>
                ))}
              </div>

              {assignProjects.isError && <ErrorState message={assignProjects.error instanceof Error ? assignProjects.error.message : 'No se pudieron vincular los proyectos.'} />}

              <div className="flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
                <button type="button" onClick={() => setAssignUser(null)} className="rounded-xl px-3.5 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
                <button type="button" disabled={assignProjects.isPending} onClick={() => assignProjects.mutate({ userId: assignUser.id, projectIds: assignIds })} className="rounded-xl bg-brand-600 px-4 py-1.5 text-xs font-semibold text-white shadow-2xs transition hover:bg-brand-700 disabled:opacity-60">Guardar Cambios</button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function PaginationControls({
  pagination,
  onChange,
}: {
  pagination: UsersData['pagination'];
  onChange: (page: number) => void;
}) {
  if (pagination.pages <= 1) return null;

  const pages = Array.from(new Set([1, pagination.page - 1, pagination.page, pagination.page + 1, pagination.pages]))
    .filter((value) => value >= 1 && value <= pagination.pages)
    .sort((a, b) => a - b);

  return (
    <nav className="flex flex-col items-center justify-between gap-3 sm:flex-row" aria-label="Paginación">
      <p className="text-xs text-slate-500">Mostrando <strong className="text-slate-800">{(pagination.page - 1) * pagination.perPage + 1}–{Math.min(pagination.page * pagination.perPage, pagination.total)}</strong> de <strong className="text-slate-800">{pagination.total}</strong></p>
      <div className="flex items-center gap-1">
        {pagination.page > 1 && <button type="button" onClick={() => onChange(pagination.page - 1)} className="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100" aria-label="Página anterior">←</button>}
        {pages.map((value, index) => (
          <span key={value} className="flex items-center gap-1">
            {index > 0 && value - pages[index - 1] > 1 && <span className="px-1.5 text-xs text-slate-400">…</span>}
            {value === pagination.page
              ? <span className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white" aria-current="page">{value}</span>
              : <button type="button" onClick={() => onChange(value)} className="rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100">{value}</button>}
          </span>
        ))}
        {pagination.page < pagination.pages && <button type="button" onClick={() => onChange(pagination.page + 1)} className="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100" aria-label="Página siguiente">→</button>}
      </div>
    </nav>
  );
}
