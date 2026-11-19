import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AlertCircle,
  ChevronDown,
  ChevronUp,
  Columns3,
  Crown,
  History,
  Image as ImageIcon,
  Layers,
  Plus,
  RotateCcw,
  Save,
  Shield,
  ShieldCheck,
  Trash2,
  Upload,
  UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { ExportButton } from '../../components/ExportButton';
import { useConfirm, useToast, LoadingButton } from '../../components/feedback';
import { apiGet, apiPost, apiUploadRoot } from '../../lib/api';
import { formatDate } from '../../lib/format';
import type { SettingsData } from '../../types';

const TABS = [
  { key: 'perfil', label: 'Mi perfil', icon: UserRound, admin: false },
  { key: 'seguridad', label: 'Seguridad', icon: ShieldCheck, admin: false },
  { key: 'actividad', label: 'Actividad', icon: History, admin: true },
  { key: 'tareas', label: 'Tareas', icon: Columns3, admin: true },
  { key: 'permisos', label: 'Permisos', icon: Shield, admin: true },
  { key: 'marca', label: 'Marca', icon: ImageIcon, admin: true },
] as const;

type TabKey = (typeof TABS)[number]['key'];

const KIND_LABELS: Record<string, string> = { open: 'Pendiente', in_progress: 'En curso', done: 'Completada' };

const ROLE_DESCRIPTIONS: Record<string, string> = {
  project_manager: 'Gestiona proyectos, equipo, CRM y finanzas de los proyectos asignados.',
  colaborador: 'Ejecuta tareas y participa en los proyectos asignados.',
  cliente: 'Consulta el avance y los tickets de sus proyectos.',
};

export function SettingsPage() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const confirm = useConfirm();
  const toast = useToast();

  const [notice, setNotice] = useState<string | null>(null);
  const [twoFactorSetup, setTwoFactorSetup] = useState<{ secret: string; uri: string } | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [profileFormKey, setProfileFormKey] = useState(0);
  const [passwordKey, setPasswordKey] = useState(0);
  const [activityAction, setActivityAction] = useState('');
  const [activityProject, setActivityProject] = useState('');
  const [activityPage, setActivityPage] = useState(1);
  const [brandingPreview, setBrandingPreview] = useState<{ url: string; name: string } | null>(null);

  const { data, isLoading, error } = useQuery({
    queryKey: ['settings', activityAction, activityProject, activityPage],
    queryFn: () => apiGet<SettingsData>('settings.php', {
      activity_action: activityAction,
      activity_project: activityProject,
      activity_page: activityPage,
    }),
  });

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['settings'] });
  const refreshBranding = () => {
    void queryClient.invalidateQueries({ queryKey: ['settings'] });
    void queryClient.invalidateQueries({ queryKey: ['bootstrap'] });
  };

  const saveProfile = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ message: string }>('settings.php', { action: 'update_profile', ...body }),
    onSuccess: (result) => { setNotice(result.message); toast(result.message); refresh(); },
  });
  const changePassword = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ message: string }>('settings.php', { action: 'change_password', ...body }),
    onSuccess: (result) => { setNotice(result.message); toast(result.message); setPasswordKey((value) => value + 1); },
  });
  const uploadAvatar = useMutation({
    mutationFn: (form: FormData) => apiUploadRoot<{ message: string }>('/api/app/settings.php', form),
    onSuccess: (result) => { setNotice(result.message); toast(result.message); setProfileFormKey((value) => value + 1); refreshBranding(); },
  });
  const selectAvatar = useMutation({
    mutationFn: (avatarKey: string) => apiPost<{ message: string }>('settings.php', { action: 'select_avatar', avatar_key: avatarKey }),
    onSuccess: (result) => { setNotice(result.message); toast(result.message); refreshBranding(); },
  });
  const removeAvatar = useMutation({
    mutationFn: () => apiPost<{ message: string }>('settings.php', { action: 'remove_avatar' }),
    onSuccess: (result) => { setNotice(result.message); toast(result.message); refreshBranding(); },
  });
  const start2fa = useMutation({
    mutationFn: () => apiPost<{ secret: string; uri: string }>('settings.php', { action: 'start_2fa' }),
    onSuccess: (result) => { setTwoFactorSetup(result); setRecoveryCodes([]); },
  });
  const confirm2fa = useMutation({
    mutationFn: (code: string) => apiPost<{ recoveryCodes: string[]; message: string }>('settings.php', { action: 'confirm_2fa', code }),
    onSuccess: (result) => {
      setRecoveryCodes(result.recoveryCodes ?? []);
      setTwoFactorSetup(null);
      setNotice(result.message);
      toast(result.message);
      void queryClient.invalidateQueries({ queryKey: ['settings'] });
    },
  });
  const disable2fa = useMutation({
    mutationFn: (password: string) => apiPost<{ message: string }>('settings.php', { action: 'disable_2fa', password }),
    onSuccess: (result) => { setNotice(result.message); toast(result.message); setRecoveryCodes([]); void queryClient.invalidateQueries({ queryKey: ['settings'] }); },
  });
  const kanbanAction = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ message?: string }>('settings.php', body),
    onSuccess: (result) => { if (result.message) setNotice(result.message); refresh(); },
  });
  const savePermissions = useMutation({
    mutationFn: (permissions: string) => apiPost<{ message: string }>('settings.php', { action: 'save_permissions', permissions }),
    onSuccess: (result) => { setNotice(result.message); toast(result.message); refresh(); },
  });
  const saveBranding = useMutation({
    mutationFn: (form: FormData) => apiUploadRoot<{ message: string }>('/api/app/settings.php', form),
    onSuccess: (result) => { setNotice(result.message); toast(result.message); setBrandingPreview(null); refreshBranding(); },
  });
  const removeLogo = useMutation({
    mutationFn: () => apiPost<{ message: string }>('settings.php', { action: 'remove_logo' }),
    onSuccess: (result) => { setNotice(result.message); toast(result.message); refreshBranding(); },
  });

  if (isLoading) return <div className="space-y-4">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} className="h-28" />)}</div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar la configuración.'} />;

  const allowedTabs = TABS.filter((item) => !item.admin || data.isAdmin);
  const requested = (searchParams.get('tab') ?? '') as TabKey;
  const tab: TabKey = allowedTabs.some((item) => item.key === requested)
    ? requested
    : (data.isAdmin ? 'marca' : 'perfil');

  const switchTab = (next: TabKey) => {
    setNotice(null);
    setSearchParams({ tab: next });
  };

  const actionError = [
    saveProfile.error,
    changePassword.error,
    uploadAvatar.error,
    selectAvatar.error,
    removeAvatar.error,
    start2fa.error,
    confirm2fa.error,
    disable2fa.error,
    kanbanAction.error,
    savePermissions.error,
    saveBranding.error,
    removeLogo.error,
  ].find(Boolean);

  const activityQuery = new URLSearchParams();
  activityQuery.set('tab', 'actividad');
  if (activityAction) activityQuery.set('action', activityAction);
  if (activityProject !== '' && activityProject !== '0') activityQuery.set('project_id', activityProject);
  const activityExport = `/export.php?type=audit&format=csv&${activityQuery.toString()}`;
  const activityExportPdf = `/export.php?type=audit&format=pdf&${activityQuery.toString()}`;

  const isPresetAvatar = data.avatars.some((avatar) => avatar.file === data.profile.avatarUrl);

  return (
    <div className="space-y-5">
      <header className="rounded-none border-0">
        <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Configuración</h1>
      </header>

      <div className="flex max-w-4xl items-center gap-1 overflow-x-auto border-b border-slate-200/70">
        {allowedTabs.map((item) => {
          const Icon = item.icon;
          const active = tab === item.key;
          return (
            <button
              key={item.key}
              type="button"
              onClick={() => switchTab(item.key)}
              className={`inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2 text-xs font-medium transition-all sm:px-4 sm:text-sm ${active ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-900'}`}
            >
              <Icon className="h-4 w-4" />
              <span>{item.label}</span>
            </button>
          );
        })}
      </div>

      {notice && <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{notice}</div>}
      {actionError && <div className="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><AlertCircle className="mt-0.5 h-5 w-5 flex-shrink-0" /><span>{actionError instanceof Error ? actionError.message : 'No se pudo completar la acción.'}</span></div>}

      {tab === 'perfil' && (
        <>
          <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
            <div className="flex items-center gap-4 border-b border-slate-100 bg-slate-50/50 px-6 py-5">
              <div className="flex h-16 w-16 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-brand-100 font-bold text-brand-700">
                {data.profile.avatarUrl ? <img src={data.profile.avatarUrl} alt="Foto de perfil" className="h-full w-full object-cover" /> : data.profile.name.slice(0, 2).toUpperCase()}
              </div>
              <div className="min-w-0">
                <h2 className="truncate text-base font-semibold text-slate-900">{data.profile.name}</h2>
                <p className="text-xs text-slate-500">{data.profile.email}</p>
                <p className="mt-0.5 text-[11px] text-slate-400">{data.profile.agency} · {data.profile.roleLabel}</p>
              </div>
            </div>

            <div className="space-y-4 p-6">
              <form key={`avatar-${profileFormKey}`} className="space-y-3" onSubmit={(event) => {
                event.preventDefault();
                const form = new FormData(event.currentTarget);
                form.set('action', 'upload_avatar');
                uploadAvatar.mutate(form);
              }}>
                <label className="block text-xs font-medium text-slate-700">Foto de perfil</label>
                <div className="flex flex-wrap items-center gap-3">
                  <input type="file" name="avatar" accept="image/png, image/jpeg, image/webp, image/gif" required className="text-xs text-slate-500 file:mr-2 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-brand-700" />
                  <LoadingButton type="submit" pending={uploadAvatar.isPending} className="rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Subir foto</LoadingButton>
                </div>
                <p className="text-[11px] text-slate-400">PNG, JPG, WEBP o GIF. Máximo 2 MB.</p>
              </form>

              <div className="rounded-xl border border-slate-200 p-3.5">
                <div className="flex items-center justify-between gap-2">
                  <p className="text-xs font-medium text-slate-700">O elige un avatar prediseñado</p>
                  <span className="text-[10px] text-slate-400">{data.avatars.length} disponibles</span>
                </div>
                <div className="mt-3 grid grid-cols-4 justify-items-center gap-2 sm:grid-cols-8">
                  {data.avatars.map((avatar) => {
                    const selected = data.profile.avatarUrl === avatar.file;
                    return (
                      <button key={avatar.key} type="button" disabled={selectAvatar.isPending} onClick={() => selectAvatar.mutate(avatar.key)} className={`block h-12 w-12 overflow-hidden rounded-full border-2 transition ${selected ? 'border-brand-500 ring-2 ring-brand-200' : 'border-transparent hover:border-slate-300'}`} title={avatar.label} aria-label={`Usar avatar ${avatar.label}`}>
                        <img src={avatar.file} alt="" className="h-full w-full object-cover" />
                      </button>
                    );
                  })}
                </div>
                <p className="mt-2 text-[11px] text-slate-400">Hombres y mujeres. Se aplica de inmediato en el menú lateral.</p>
              </div>

              {data.profile.avatarUrl && (
                <button type="button" disabled={removeAvatar.isPending} onClick={() => removeAvatar.mutate()} className="text-xs font-medium text-rose-600 transition hover:text-rose-700 disabled:opacity-60">
                  {isPresetAvatar ? 'Quitar avatar actual' : 'Eliminar foto actual'}
                </button>
              )}
            </div>
          </div>

          <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
            <div className="border-b border-slate-100 bg-slate-50/50 px-6 py-4"><h2 className="text-sm font-semibold text-slate-900">Datos personales</h2></div>
            <form className="space-y-4 p-6" onSubmit={(event) => {
              event.preventDefault();
              const form = new FormData(event.currentTarget);
              saveProfile.mutate({ name: String(form.get('name') ?? ''), email: String(form.get('email') ?? '') });
            }}>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Nombre</label>
                  <Input name="name" required maxLength={100} defaultValue={data.profile.name} className="px-3.5 py-2.5 text-sm" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Correo electrónico</label>
                  <Input name="email" type="email" required defaultValue={data.profile.email} className="px-3.5 py-2.5 text-sm" />
                </div>
              </div>
              <div className="flex justify-end">
                <LoadingButton type="submit" pending={saveProfile.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Guardar cambios</LoadingButton>
              </div>
            </form>
          </div>

          <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
            <div className="border-b border-slate-100 bg-slate-50/50 px-6 py-4"><h2 className="text-sm font-semibold text-slate-900">Cambiar contraseña</h2></div>
            <form key={`password-${passwordKey}`} className="space-y-4 p-6" onSubmit={(event) => {
              event.preventDefault();
              const form = new FormData(event.currentTarget);
              changePassword.mutate({
                current_password: String(form.get('current_password') ?? ''),
                new_password: String(form.get('new_password') ?? ''),
                new_password_confirm: String(form.get('new_password_confirm') ?? ''),
              });
            }}>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Contraseña actual</label>
                <Input name="current_password" type="password" required autoComplete="current-password" className="px-3.5 py-2.5 text-sm" />
              </div>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Nueva contraseña</label>
                  <Input name="new_password" type="password" required minLength={8} autoComplete="new-password" className="px-3.5 py-2.5 text-sm" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Repite la nueva contraseña</label>
                  <Input name="new_password_confirm" type="password" required minLength={8} autoComplete="new-password" className="px-3.5 py-2.5 text-sm" />
                </div>
              </div>
              <div className="flex items-center justify-between gap-3">
                <button type="button" onClick={() => switchTab('seguridad')} className="text-xs font-medium text-brand-600 hover:text-brand-700">Configurar 2FA</button>
                <LoadingButton type="submit" pending={changePassword.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Cambiar contraseña</LoadingButton>
              </div>
            </form>
          </div>
        </>
      )}

      {tab === 'seguridad' && (
        <>
          {recoveryCodes.length > 0 && (
            <div className="space-y-3 rounded-2xl border border-amber-200 bg-amber-50 p-5">
              <h2 className="text-sm font-semibold text-amber-900">Guarda tus códigos de recuperación</h2>
              <p className="text-xs text-amber-800">Cada código sirve una sola vez. Guárdalos en un lugar seguro; no se volverán a mostrar.</p>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {recoveryCodes.map((code) => <code key={code} className="rounded-lg border border-amber-200 bg-white px-2 py-1.5 text-center font-mono text-xs text-slate-800">{code}</code>)}
              </div>
            </div>
          )}

          <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-5">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600"><ShieldCheck className="h-5 w-5" /></div>
                <div>
                  <h2 className="text-sm font-semibold text-slate-900">Verificación en dos pasos</h2>
                  <p className="text-xs text-slate-400">Protege tu cuenta con una aplicación autenticadora (TOTP).</p>
                </div>
              </div>
              <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${data.twoFactorEnabled ? 'border border-emerald-200/60 bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{data.twoFactorEnabled ? 'Activado' : 'Desactivado'}</span>
            </div>

            <div className="space-y-4 p-6">
              {data.twoFactorEnabled ? (
                <>
                  <p className="text-sm text-slate-600">Tu cuenta pide un código adicional al iniciar sesión. Si pierdes el dispositivo, usa uno de tus códigos de recuperación.</p>
                  <form className="max-w-sm space-y-3" onSubmit={(event) => {
                    event.preventDefault();
                    const form = new FormData(event.currentTarget);
                    disable2fa.mutate(String(form.get('password') ?? ''));
                  }}>
                    <div>
                      <label className="mb-1.5 block text-xs font-medium text-slate-700">Confirma tu contraseña para desactivarlo</label>
                      <Input name="password" type="password" required className="px-3.5 py-2.5 text-sm" />
                    </div>
                    <LoadingButton type="submit" pending={disable2fa.isPending} className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:opacity-60">Desactivar 2FA</LoadingButton>
                  </form>
                </>
              ) : twoFactorSetup ? (
                <>
                  <div className="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p className="text-xs font-semibold text-slate-700">1. Añade esta clave secreta a tu aplicación:</p>
                    <code className="block break-all rounded-lg border border-slate-200 bg-white px-3 py-2 font-mono text-sm tracking-wider text-slate-900">{twoFactorSetup.secret}</code>
                    <p className="break-all text-[11px] text-slate-500">O usa la URI: {twoFactorSetup.uri}</p>
                  </div>
                  <form className="max-w-sm space-y-3" onSubmit={(event) => {
                    event.preventDefault();
                    const form = new FormData(event.currentTarget);
                    confirm2fa.mutate(String(form.get('code') ?? ''));
                  }}>
                    <div>
                      <label className="mb-1.5 block text-xs font-medium text-slate-700">2. Escribe el código de 6 dígitos que muestra la app</label>
                      <Input name="code" required inputMode="numeric" maxLength={6} autoComplete="one-time-code" className="px-3.5 py-2.5 text-center font-mono text-lg tracking-widest" />
                    </div>
                    <LoadingButton type="submit" pending={confirm2fa.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Confirmar y activar</LoadingButton>
                  </form>
                </>
              ) : (
                <>
                  <p className="text-sm text-slate-600">Al activarlo, necesitarás una aplicación como Google Authenticator, Authy o 1Password.</p>
                  <button type="button" disabled={start2fa.isPending} onClick={() => start2fa.mutate()} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Activar 2FA</button>
                </>
              )}
            </div>
          </div>
        </>
      )}

      {tab === 'actividad' && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-xs text-slate-500">
              Historial de acciones de la agencia
              <span className="text-slate-400"> · {data.activityPagination.total} eventos</span>
              <span className="text-slate-400"> · Solo visible para administradores</span>
            </p>
            <div className="flex items-center gap-2">
              <ExportButton url={activityExport} format="csv" className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50">CSV</ExportButton>
              <ExportButton url={activityExportPdf} format="pdf" className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50">PDF</ExportButton>
            </div>
          </div>

          <form className="flex flex-col gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-2xs sm:flex-row" onSubmit={(event) => event.preventDefault()}>
            <Select value={activityAction} onChange={(event) => { setActivityAction(event.target.value); setActivityPage(1); }} className="px-3 py-2">
              <option value="">Todas las acciones</option>
              {Object.entries(data.activityLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </Select>
            <Select value={activityProject} onChange={(event) => { setActivityProject(event.target.value); setActivityPage(1); }} className="px-3 py-2">
              <option value="0">Todos los proyectos</option>
              {data.activityProjects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
            </Select>
            <button type="submit" className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700">Filtrar</button>
            {(activityAction !== '' || (activityProject !== '' && activityProject !== '0')) && (
              <button type="button" onClick={() => { setActivityAction(''); setActivityProject(''); setActivityPage(1); }} className="rounded-xl px-3 py-2 text-xs font-medium text-slate-500 transition hover:text-slate-800">Limpiar</button>
            )}
          </form>

          {data.activity.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-10 text-center text-sm text-slate-400">No hay actividad registrada con estos filtros.</div>
          ) : (
            <>
              <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
                {data.activity.map((item) => (
                  <li key={item.id} className="flex flex-col justify-between gap-2 px-4 py-3 sm:flex-row sm:items-center">
                    <div className="min-w-0">
                      <p className="text-xs font-semibold text-slate-800">
                        {item.label}
                        {item.projectName && <span className="font-normal text-slate-500"> · {item.projectName}</span>}
                      </p>
                      <p className="truncate text-[11px] text-slate-500">{item.description ?? ''}</p>
                    </div>
                    <div className="whitespace-nowrap text-[11px] text-slate-400">{item.user ?? 'Sistema'} · {formatDate(item.createdAt, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</div>
                  </li>
                ))}
              </ul>
              <PaginationControls pagination={data.activityPagination} onChange={setActivityPage} />
            </>
          )}
        </>
      )}

      {tab === 'tareas' && (
        <section className="space-y-5">
          <div className="space-y-5 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-2xs sm:p-8">
            <div className="flex items-start gap-3">
              <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Columns3 className="h-5 w-5" /></div>
              <div>
                <h2 className="text-sm font-semibold text-slate-800">Columnas del Kanban</h2>
                <p className="mt-1 text-xs leading-relaxed text-slate-500">
                  Renombra, ordena o añade columnas de estado. Se aplican al tablero Kanban, a la lista
                  y a los selectores de estado, solo en <strong>{data.profile.agency}</strong>.
                </p>
              </div>
            </div>

            <form id="save-task-statuses" onSubmit={(event) => {
              event.preventDefault();
              const form = new FormData(event.currentTarget);
              const statuses = data.taskStatuses.map((status) => ({
                key: status.key,
                label: String(form.get(`label_${status.key}`) ?? ''),
                kind: String(form.get(`kind_${status.key}`) ?? 'open'),
              }));
              kanbanAction.mutate({ action: 'save_task_statuses', statuses: JSON.stringify(statuses) });
            }}>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-slate-50/70 text-[11px] uppercase tracking-wide text-slate-400">
                    <tr>
                      <th className="px-3 py-3 text-left font-semibold">Nombre</th>
                      <th className="px-3 py-3 text-left font-semibold">Tipo</th>
                      <th className="px-3 py-3 text-right font-semibold">Orden</th>
                      <th className="px-3 py-3 text-right font-semibold">Acciones</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {data.taskStatuses.map((status, index) => {
                      const isDefault = data.taskStatusDefaults.includes(status.key);
                      return (
                        <tr key={status.key} className="hover:bg-slate-50/50">
                          <td className="min-w-[180px] px-3 py-3">
                            <Input name={`label_${status.key}`} defaultValue={status.label} maxLength={40} className="rounded-lg px-3 py-2 text-sm" />
                            <p className="mt-1 font-mono text-[10px] text-slate-400">{status.key}</p>
                          </td>
                          <td className="px-3 py-3">
                            <Select name={`kind_${status.key}`} defaultValue={status.kind} className="w-full rounded-lg px-2.5 py-2">
                              {Object.entries(KIND_LABELS).map(([kind, label]) => <option key={kind} value={kind}>{label}</option>)}
                            </Select>
                          </td>
                          <td className="px-3 py-3">
                            <div className="flex items-center justify-end gap-1">
                              <button type="button" disabled={index === 0 || kanbanAction.isPending} onClick={() => kanbanAction.mutate({ action: 'move_task_status', status_key: status.key, direction: 'up' })} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-800 disabled:cursor-not-allowed disabled:opacity-30" title="Subir"><ChevronUp className="h-4 w-4" /></button>
                              <button type="button" disabled={index === data.taskStatuses.length - 1 || kanbanAction.isPending} onClick={() => kanbanAction.mutate({ action: 'move_task_status', status_key: status.key, direction: 'down' })} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-800 disabled:cursor-not-allowed disabled:opacity-30" title="Bajar"><ChevronDown className="h-4 w-4" /></button>
                            </div>
                          </td>
                          <td className="px-3 py-3">
                            <div className="flex items-center justify-end">
                              {isDefault ? (
                                <span className="text-[10px] italic text-slate-400">Predeterminada</span>
                              ) : (
                                <button type="button" disabled={kanbanAction.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar columna', description: `¿Eliminar la columna «${status.label}»? Solo es posible si no tiene tareas.`, confirmLabel: 'Eliminar', tone: 'danger' })) kanbanAction.mutate({ action: 'delete_task_status', status_key: status.key }); }} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 disabled:opacity-60" title="Eliminar columna"><Trash2 className="h-4 w-4" /></button>
                              )}
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              <div className="flex items-center justify-between gap-3 border-t border-slate-100 pt-2">
                <p className="text-[10px] text-slate-400">Debe existir al menos una columna de pendientes y una de completadas.</p>
                <LoadingButton type="submit" pending={kanbanAction.isPending} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60"><Save className="h-4 w-4" /> Guardar cambios</LoadingButton>
              </div>
            </form>
          </div>

          <div className="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-2xs">
            <div>
              <h3 className="text-sm font-semibold text-slate-800">Añadir columna</h3>
              <p className="mt-1 text-xs text-slate-500">Crea una columna nueva; podrás moverla y renombrarla después.</p>
            </div>
            <form className="grid grid-cols-1 items-end gap-3 sm:grid-cols-3" onSubmit={(event) => {
              event.preventDefault();
              const formElement = event.currentTarget;
              const form = new FormData(formElement);
              kanbanAction.mutate({ action: 'add_task_status', label: String(form.get('label') ?? ''), kind: String(form.get('kind') ?? 'open') });
              formElement.reset();
            }}>
              <div className="sm:col-span-2">
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Nombre</label>
                <Input name="label" required maxLength={40} placeholder="Ej: En revisión" className="px-3.5 py-2.5 text-sm" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Tipo</label>
                <Select name="kind" defaultValue="open" className="w-full px-3 py-2.5">
                  {Object.entries(KIND_LABELS).map(([kind, label]) => <option key={kind} value={kind}>{label}</option>)}
                </Select>
              </div>
              <div className="flex justify-end sm:col-span-3">
                <LoadingButton type="submit" pending={kanbanAction.isPending} className="inline-flex items-center gap-1.5 rounded-xl border border-brand-200 bg-brand-50 px-4 py-2 text-xs font-semibold text-brand-700 transition hover:bg-brand-100 disabled:opacity-60"><Plus className="h-3.5 w-3.5" /> Añadir columna</LoadingButton>
              </div>
            </form>
          </div>

          <div className="flex flex-col justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs sm:flex-row sm:items-center">
            <div>
              <h3 className="text-xs font-semibold text-slate-700">Restablecer</h3>
              <p className="mt-0.5 text-[11px] text-slate-400">Vuelve a las columnas originales. Las columnas personalizadas con tareas se conservan.</p>
            </div>
            <button type="button" disabled={kanbanAction.isPending} onClick={async () => { if (await confirm({ title: 'Restablecer columnas', description: 'Se volverán a las columnas predeterminadas. Las columnas personalizadas con tareas se conservan.', confirmLabel: 'Restablecer' })) kanbanAction.mutate({ action: 'reset_task_statuses' }); }} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-60"><RotateCcw className="h-3.5 w-3.5" /> Restablecer</button>
          </div>

          <div className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs">
            <h3 className="mb-2 text-xs font-semibold text-slate-700">Ejemplo</h3>
            <p className="text-xs leading-relaxed text-slate-500">
              Puedes añadir «En revisión» entre «En curso» y la columna final, o crear «Bloqueado». El tipo
              determina el comportamiento: las columnas de tipo <strong>Completada</strong> cuentan como tareas terminadas
              en el progreso y el dashboard.
            </p>
          </div>
        </section>
      )}

      {tab === 'permisos' && data.permissions && (
        <section className="space-y-5">
          <div className="rounded-2xl border border-brand-200/80 bg-brand-50/60 p-5">
            <div className="flex items-start gap-3">
              <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-white text-brand-600 shadow-2xs"><ShieldCheck className="h-4 w-4" /></div>
              <div>
                <h2 className="text-base font-semibold text-slate-900">Permisos por rol</h2>
                <p className="mt-1 text-xs leading-relaxed text-slate-600">
                  Activa o desactiva módulos para cada rol. Los cambios se aplican inmediatamente al sidebar y a las páginas.
                  No es posible conceder módulos fuera del alcance máximo de cada rol.
                </p>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-slate-200/80 bg-white p-5">
            <div className="flex items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-700"><Crown className="h-4 w-4" /></span>
                <div>
                  <h3 className="text-sm font-semibold text-slate-900">Administrador</h3>
                  <p className="text-[11px] text-slate-400">Acceso total permanente</p>
                </div>
              </div>
              <span className="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Todos los módulos</span>
            </div>
            <div className="mt-3 flex flex-wrap gap-1.5">
              {Object.values(data.permissions.modules).map((label) => <span key={label} className="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-[10px] font-medium text-slate-600">{label}</span>)}
            </div>
            <p className="mt-3 text-[10px] text-slate-400">El rol administrador no se puede restringir para evitar bloqueos de acceso.</p>
          </div>

          <form className="space-y-5" onSubmit={(event) => {
            event.preventDefault();
            const form = new FormData(event.currentTarget);
            const permissions: Record<string, Record<string, boolean>> = {};
            ['project_manager', 'colaborador', 'cliente'].forEach((roleKey) => {
              permissions[roleKey] = {};
              Object.keys(data.permissions!.modules).forEach((moduleKey) => {
                if (form.get(`perm_${roleKey}_${moduleKey}`)) permissions[roleKey][moduleKey] = true;
              });
            });
            savePermissions.mutate(JSON.stringify(permissions));
          }}>
            {(['project_manager', 'colaborador', 'cliente'] as const).map((roleKey) => (
              <div key={roleKey} className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white">
                <div className="flex items-start justify-between gap-3 border-b border-slate-100 bg-slate-50/50 px-5 py-4">
                  <div>
                    <h3 className="text-sm font-semibold text-slate-900">{data.permissions!.labels[roleKey] ?? roleKey}</h3>
                    <p className="mt-0.5 text-[11px] text-slate-500">{ROLE_DESCRIPTIONS[roleKey]}</p>
                  </div>
                  <span className="rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500">{roleKey}</span>
                </div>

                <div className="space-y-5 p-5">
                  {Object.entries(data.permissions!.groups).map(([groupLabel, modules]) => (
                    <div key={groupLabel}>
                      <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">{groupLabel}</p>
                      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {modules.map((moduleKey) => {
                          const inCeiling = data.permissions!.ceilings[roleKey]?.includes(moduleKey) ?? false;
                          const isCore = data.permissions!.core.includes(moduleKey);
                          const enabled = inCeiling && ((data.permissions!.matrix[roleKey]?.[moduleKey] ?? false) || isCore);
                          const locked = !inCeiling || isCore;
                          return (
                            <label key={moduleKey} className={`flex items-start gap-2.5 rounded-xl border px-3 py-2.5 transition ${inCeiling ? 'cursor-pointer border-slate-200 bg-white hover:border-brand-200' : 'cursor-not-allowed border-slate-100 bg-slate-50/60 opacity-70'}`}>
                              <input type="checkbox" name={`perm_${roleKey}_${moduleKey}`} defaultChecked={enabled} disabled={locked} className="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500 disabled:opacity-60" />
                              <span className="min-w-0">
                                <span className={`block text-xs font-medium ${inCeiling ? 'text-slate-800' : 'text-slate-400'}`}>{data.permissions!.modules[moduleKey]}</span>
                                {isCore ? (
                                  <span className="mt-0.5 block text-[10px] text-emerald-600">Siempre activo</span>
                                ) : !inCeiling ? (
                                  <span className="mt-0.5 block text-[10px] text-slate-400">No disponible para este rol</span>
                                ) : null}
                              </span>
                            </label>
                          );
                        })}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))}

            <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white p-4">
              <p className="max-w-lg text-[11px] text-slate-500">
                Los módulos núcleo <strong>Dashboard</strong> y <strong>Configuración</strong> permanecen activos para todos los roles.
                El acceso a proyectos concretos sigue dependiendo de la asignación de cada usuario.
              </p>
              <LoadingButton type="submit" pending={savePermissions.isPending} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60"><Save className="h-4 w-4" /> Guardar permisos</LoadingButton>
            </div>
          </form>
        </section>
      )}

      {tab === 'marca' && data.branding && (
        <div className="overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-2xs">
          <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-5">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-indigo-50 font-bold text-brand-600"><ImageIcon className="h-5 w-5" /></div>
              <div>
                <h3 className="text-base font-semibold text-slate-900">Marca de la agencia</h3>
                <p className="text-xs text-slate-400">Nombre y logo que ven tu equipo y tus clientes dentro de la plataforma</p>
              </div>
            </div>
          </div>

          <form className="space-y-6 p-6 sm:p-8" onSubmit={(event) => {
            event.preventDefault();
            const form = new FormData(event.currentTarget);
            form.set('action', 'save_branding');
            saveBranding.mutate(form);
          }}>
            <div>
              <label className="mb-2 block text-xs font-medium text-slate-700">Vista Previa Actual</label>
              <div className="flex flex-col items-center justify-between gap-4 rounded-2xl border border-slate-200/80 bg-slate-50/70 p-5 sm:flex-row">
                <div className="flex items-center gap-3.5">
                  {data.branding.logoUrl ? (
                    <div className="flex h-14 max-w-[200px] items-center justify-center rounded-xl border border-slate-200 bg-white p-2 shadow-2xs">
                      <img src={data.branding.logoUrl} alt="Logo actual" className="max-h-full max-w-full object-contain" />
                    </div>
                  ) : (
                    <div className="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white"><Layers className="h-6 w-6" /></div>
                  )}
                  <div>
                    <h4 className="text-base font-semibold text-slate-900">{data.branding.name}</h4>
                    <span className="text-xs text-slate-400">{data.branding.logoUrl ? 'Logo personalizado cargado' : 'Usando icono predeterminado'}</span>
                  </div>
                </div>

                {data.branding.logoUrl && (
                  <button type="button" disabled={removeLogo.isPending} onClick={async () => { if (await confirm({ title: 'Restablecer logo', description: 'Se usará el icono predeterminado de Vértice.', confirmLabel: 'Restablecer' })) removeLogo.mutate(); }} className="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:opacity-60"><Trash2 className="h-3.5 w-3.5" /><span>Restablecer Logo</span></button>
                )}
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Nombre de la agencia *</label>
              <Input name="app_name" required defaultValue={data.branding.name} placeholder="Ej: Vertice" className="px-4 py-2.5 text-sm" />
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Enlace público de la agencia</label>
              <div className="flex items-center overflow-hidden rounded-xl border border-slate-200 bg-white">
                <span className="whitespace-nowrap border-r border-slate-200 bg-slate-50 px-3 py-2.5 text-xs text-slate-400">{data.appUrl}/</span>
                <input name="agency_slug" required maxLength={60} pattern="[a-z0-9][a-z0-9-]{1,58}[a-z0-9]" defaultValue={data.branding.slug} className="min-w-0 flex-1 border-0 px-3 py-2.5 font-mono text-sm focus:outline-none" />
              </div>
              <p className="mt-1 text-[11px] text-slate-400">Solo minúsculas, números y guiones. Al abrirlo se muestra el login con el logo de tu agencia.</p>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Subir Nuevo Logo</label>
              <label className="relative block cursor-pointer rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/40 p-6 text-center transition hover:border-brand-400 hover:bg-slate-50">
                <input type="file" name="app_logo" accept="image/png, image/jpeg, image/webp, image/gif" className="absolute inset-0 h-full w-full cursor-pointer opacity-0" onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (!file) { setBrandingPreview(null); return; }
                  const reader = new FileReader();
                  reader.onload = () => setBrandingPreview({ url: String(reader.result ?? ''), name: file.name });
                  reader.readAsDataURL(file);
                }} />
                {brandingPreview ? (
                  <div className="flex items-center justify-center gap-3">
                    <img src={brandingPreview.url} alt="Vista previa" className="h-12 max-w-[180px] rounded border border-slate-200 bg-white object-contain p-1" />
                    <span className="text-xs font-semibold text-slate-700">{brandingPreview.name}</span>
                  </div>
                ) : (
                  <>
                    <div className="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Upload className="h-5 w-5" /></div>
                    <p className="text-xs font-semibold text-slate-700">Haz clic o arrastra tu archivo de logo aquí</p>
                    <p className="mt-1 text-[11px] text-slate-400">Formatos recomendados: PNG o WEBP con fondo transparente (máx. 2MB)</p>
                  </>
                )}
              </label>
            </div>

            <div className="flex items-center justify-end border-t border-slate-100 pt-4">
              <LoadingButton type="submit" pending={saveBranding.isPending} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60 sm:text-sm"><Save className="h-4 w-4" /><span>Guardar Cambios</span></LoadingButton>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}

function PaginationControls({
  pagination,
  onChange,
}: {
  pagination: SettingsData['activityPagination'];
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
