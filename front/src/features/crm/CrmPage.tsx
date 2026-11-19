import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ArrowRight, Building2, History, Calendar, CheckCircle2, Clock, FilePlus, FileText, FolderOpen, GripVertical, LayoutGrid, List, Mail, MessageSquare, Pencil, Phone, Plus, Search, Target, TrendingUp, Trash2, Users, X } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Badge, Card, EmptyState, ErrorState, Input, PageHeader, Select, Skeleton } from '../../components/ui';
import { LoadingButton, Modal, useConfirm, useToast } from '../../components/feedback';
import { apiGet, apiPostRoot } from '../../lib/api';
import { formatDate, formatMoney, formatTotals } from '../../lib/format';
import type { CrmData } from '../../types';

type TabKey = 'pipeline' | 'companies' | 'contacts' | 'activities';
type PipelineView = 'kanban' | 'list' | 'forecast';

const TABS: Array<{ key: TabKey; label: string }> = [
  { key: 'pipeline', label: 'Pipeline' },
  { key: 'companies', label: 'Empresas' },
  { key: 'contacts', label: 'Contactos' },
  { key: 'activities', label: 'Actividades' },
];

const STAGE_META: Record<string, { dot: string; tone: 'slate' | 'brand' | 'amber' | 'emerald' | 'rose' }> = {
  nuevo: { dot: 'bg-slate-400', tone: 'slate' },
  contactado: { dot: 'bg-cyan-500', tone: 'brand' },
  propuesta: { dot: 'bg-brand-500', tone: 'brand' },
  negociacion: { dot: 'bg-amber-500', tone: 'amber' },
  ganado: { dot: 'bg-emerald-500', tone: 'emerald' },
  perdido: { dot: 'bg-rose-500', tone: 'rose' },
};

const STAGE_PROBABILITY: Record<string, number> = {
  nuevo: 10,
  contactado: 25,
  propuesta: 50,
  negociacion: 75,
  ganado: 100,
  perdido: 0,
};

const ACTIVITY_ICONS: Record<string, typeof Phone> = {
  llamada: Phone,
  correo: Mail,
  reunion: Users,
  nota: MessageSquare,
  tarea: CheckCircle2,
};

function daysSince(value: string | null): number {
  if (!value) return 999;
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return 999;
  return Math.floor((Date.now() - date.getTime()) / 86400000);
}

function isOpenDeal(deal: CrmData['deals'][number]): boolean {
  return deal.stage !== 'ganado' && deal.stage !== 'perdido';
}

function isStalled(deal: CrmData['deals'][number]): boolean {
  return isOpenDeal(deal) && daysSince(deal.lastActivityAt ?? deal.updatedAt ?? deal.createdAt) >= 14;
}

function dateOnly(value: string | null): string | null {
  return value ? value.slice(0, 10) : null;
}

function formatActivityDate(value: string | null): string {
  if (!value) return '';
  const date = value.replace(' ', 'T');
  const formatted = formatDate(date.slice(0, 10));
  return date.length > 10 ? `${formatted} · ${date.slice(11, 16)}` : formatted;
}

function DealModal({
  initialDeal,
  initialMode,
  defaultStage,
  defaultCompanyId,
  crm,
  onClose,
  onChanged,
  onActivity,
  onDeleteActivity,
}: {
  initialDeal: CrmData['deals'][number] | null;
  initialMode: 'view' | 'edit' | 'create';
  defaultStage?: string;
  defaultCompanyId?: number;
  crm: CrmData;
  onClose: () => void;
  onChanged: () => void;
  onActivity: (deal: CrmData['deals'][number]) => void;
  onDeleteActivity: (activityId: number) => void;
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const [mode, setMode] = useState(initialMode);
  const [companyId, setCompanyId] = useState(initialDeal?.companyId ? String(initialDeal.companyId) : (defaultCompanyId ? String(defaultCompanyId) : ''));
  const [contactId, setContactId] = useState(initialDeal?.contactId ? String(initialDeal.contactId) : '');
  const deal = initialDeal;

  const saveDeal = useMutation({
    mutationFn: (body: Record<string, string>) => apiPostRoot<{ success: boolean; id: number }>('/api/crm.php', body),
    onSuccess: () => {
      toast(deal ? 'Oportunidad actualizada.' : 'Oportunidad creada.');
      onChanged();
      onClose();
    },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo guardar la oportunidad.', 'error'),
  });

  const deleteDeal = useMutation({
    mutationFn: (id: number) => apiPostRoot<{ success: boolean }>('/api/crm.php', { action: 'deal_delete', id }),
    onSuccess: () => { toast('Oportunidad eliminada.'); onChanged(); onClose(); },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo eliminar la oportunidad.', 'error'),
  });

  const convertDeal = useMutation({
    mutationFn: (id: number) => apiPostRoot<{ success: boolean; project_id: number; message: string }>('/api/crm.php', { action: 'deal_convert', id }),
    onSuccess: (result) => {
      toast(result.message);
      onChanged();
      onClose();
      navigate(`/projects/${result.project_id}`);
    },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo convertir la oportunidad.', 'error'),
  });

  const relatedActivities = crm.activities.filter((activity) => deal && activity.dealId === deal.id);
  const stalled = deal ? isStalled(deal) : false;
  const subtitle = deal?.company || deal?.contact || 'Nueva oportunidad';

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const body: Record<string, string> = { action: deal ? 'deal_update' : 'deal_create' };
    if (deal) body.id = String(deal.id);
    form.forEach((value, key) => { body[key] = String(value); });
    saveDeal.mutate(body);
  };

  return (
    <Modal open onClose={onClose} panelClassName="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
      <div className="flex items-start justify-between gap-3 border-b border-slate-100 bg-slate-50/70 px-5 py-4 sm:px-6">
        <div className="flex min-w-0 items-center gap-2.5">
          <span className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Target className="h-4 w-4" /></span>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h2 className="truncate text-base font-semibold text-slate-900">{deal?.title ?? 'Nueva oportunidad'}</h2>
              {deal && <span className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold ${STAGE_META[deal.stage]?.tone === 'emerald' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : STAGE_META[deal.stage]?.tone === 'rose' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-slate-200 bg-white text-slate-600'}`}>{crm.stages.find((stage) => stage.key === deal.stage)?.label ?? deal.stage}</span>}
              {stalled && <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Estancada</span>}
            </div>
            <p className="truncate text-[11px] text-slate-400">{subtitle}</p>
          </div>
        </div>
        <div className="flex flex-shrink-0 items-center gap-1">
          {mode === 'view' && deal && (
            <button type="button" onClick={() => setMode('edit')} className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"><Pencil className="h-3.5 w-3.5 text-brand-600" /> Editar</button>
          )}
          <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="Cerrar"><X className="h-5 w-5" /></button>
        </div>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto p-5 sm:p-6">
        {(mode === 'create' || mode === 'edit') ? (
          <form key={deal?.id ?? 'new'} className="space-y-4" onSubmit={submit}>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Título *</label>
              <Input name="title" required maxLength={150} defaultValue={deal?.title ?? ''} className="px-3.5 py-2.5 text-sm" />
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Empresa</label>
                <Select name="company_id" value={companyId} onChange={(event) => { setCompanyId(event.target.value); setContactId(''); }} className="w-full px-3.5 py-2.5 text-sm">
                  <option value="">Sin empresa</option>
                  {crm.companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
                </Select>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Contacto</label>
                <Select name="contact_id" value={contactId} onChange={(event) => setContactId(event.target.value)} className="w-full px-3.5 py-2.5 text-sm">
                  <option value="">Sin contacto</option>
                  {crm.contacts.filter((contact) => companyId === '' || String(contact.companyId) === companyId).map((contact) => <option key={contact.id} value={contact.id}>{contact.name}</option>)}
                </Select>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Importe</label>
                <Input inputMode="decimal" name="value" type="number" min="0" step="0.01" defaultValue={deal?.value ?? 0} className="px-3.5 py-2.5 text-sm font-semibold" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Moneda</label>
                <Select name="currency" defaultValue={deal?.currency ?? 'USD'} className="w-full px-3.5 py-2.5 font-mono text-sm">{crm.currencies.map((currency) => <option key={currency} value={currency}>{currency}</option>)}</Select>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Cierre previsto</label>
                <Input name="expected_close" type="date" defaultValue={dateOnly(deal?.expectedClose ?? null) ?? ''} className="px-3.5 py-2.5 text-sm" />
              </div>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Etapa</label>
                <Select name="stage" defaultValue={deal?.stage ?? defaultStage ?? 'nuevo'} className="w-full px-3.5 py-2.5 text-sm">{crm.stages.map((stage) => <option key={stage.key} value={stage.key}>{stage.label}</option>)}</Select>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Responsable</label>
                <Select name="owner_id" defaultValue={deal?.ownerId ?? ''} className="w-full px-3.5 py-2.5 text-sm"><option value="">Sin responsable</option>{crm.owners.map((owner) => <option key={owner.id} value={owner.id}>{owner.name}</option>)}</Select>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Origen</label>
                <Select name="source" defaultValue={deal?.source ?? ''} className="w-full px-3.5 py-2.5 text-sm"><option value="">Sin origen</option>{crm.sources.map((source) => <option key={source} value={source}>{source}</option>)}</Select>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Motivo de pérdida</label>
                <Input name="lost_reason" defaultValue={deal?.lostReason ?? ''} placeholder="Solo si está perdida" className="px-3.5 py-2.5 text-sm" />
              </div>
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Descripción</label>
              <textarea name="description" rows={3} defaultValue={deal?.description ?? ''} className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15" />
            </div>
            {saveDeal.isError && <ErrorState message={saveDeal.error instanceof Error ? saveDeal.error.message : 'No se pudo guardar la oportunidad.'} />}
            <div className="flex items-center justify-between gap-2 border-t border-slate-100 pt-4">
              <div>{deal && <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar oportunidad', description: deal.title, confirmLabel: 'Eliminar', tone: 'danger' })) deleteDeal.mutate(deal.id); }} className="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-[11px] font-semibold text-rose-600 transition hover:bg-rose-100"><Trash2 className="h-3.5 w-3.5" /> Eliminar</button>}</div>
              <div className="flex items-center gap-2">
                <button type="button" onClick={() => deal ? setMode('view') : onClose()} className="rounded-xl px-4 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-100">{deal ? 'Cancelar' : 'Cerrar'}</button>
                <LoadingButton type="submit" pending={saveDeal.isPending} pendingLabel="Guardando…" className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Guardar oportunidad</LoadingButton>
              </div>
            </div>
          </form>
        ) : deal ? (
          <div className="space-y-5">
            <div className="flex flex-wrap items-end justify-between gap-3">
              <div>
                <p className="text-[11px] text-slate-400">Importe</p>
                <p className="text-2xl font-bold tracking-tight text-slate-900">{formatMoney(deal.value, deal.currency)}</p>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                {deal.quotesCount > 0 && <span className="inline-flex items-center gap-1 rounded-full border border-brand-200 bg-brand-50 px-2.5 py-1 text-[10px] font-semibold text-brand-700"><FileText className="h-3 w-3" /> {deal.quotesCount} propuesta{deal.quotesCount === 1 ? '' : 's'}{deal.latestQuoteStatus ? ` · ${deal.latestQuoteStatus}` : ''}</span>}
                {deal.convertedProjectId && <Link to={`/projects/${deal.convertedProjectId}`} className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700"><FolderOpen className="h-3 w-3" /> {deal.convertedProject || 'Proyecto'}</Link>}
              </div>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {[
                { label: 'Empresa', value: deal.company || 'Sin empresa' },
                { label: 'Contacto', value: deal.contact || 'Sin contacto' },
                { label: 'Responsable', value: deal.owner || 'Sin responsable' },
                { label: 'Cierre previsto', value: deal.expectedClose ? formatDate(deal.expectedClose) : 'Sin fecha' },
              ].map((item) => (
                <div key={item.label} className="rounded-xl border border-slate-100 bg-slate-50/60 p-3">
                  <p className="text-[10px] font-medium text-slate-400">{item.label}</p>
                  <p className="mt-1 truncate text-xs font-semibold text-slate-700">{item.value}</p>
                </div>
              ))}
            </div>

            {(deal.source || deal.description || stalled) && (
              <div className="space-y-2 rounded-xl border border-slate-100 bg-slate-50/60 p-4">
                {deal.source && <p className="text-[11px] text-slate-500">Origen: <span className="font-semibold text-slate-700">{deal.source}</span></p>}
                {stalled && <p className="flex items-center gap-1 text-[11px] font-semibold text-amber-700"><AlertTriangle className="h-3.5 w-3.5" /> Sin actividad reciente ({daysSince(deal.lastActivityAt ?? deal.updatedAt ?? deal.createdAt)} días)</p>}
                {deal.description && <p className="whitespace-pre-line text-xs leading-relaxed text-slate-600">{deal.description}</p>}
              </div>
            )}

            {deal.nextActivityTitle ? (
              <div className="flex items-center gap-2 rounded-xl border border-brand-100 bg-brand-50/60 px-3.5 py-3 text-xs text-brand-800"><Calendar className="h-4 w-4" /><span className="font-semibold">Próxima acción:</span> {deal.nextActivityTitle}{deal.nextActivityAt ? ` · ${formatActivityDate(deal.nextActivityAt)}` : ''}</div>
            ) : isOpenDeal(deal) ? (
              <button type="button" onClick={() => onActivity(deal)} className="flex w-full items-center gap-2 rounded-xl border border-dashed border-slate-300 px-3.5 py-3 text-xs font-semibold text-slate-500 transition hover:border-brand-300 hover:text-brand-700"><Plus className="h-4 w-4" /> Añadir próxima acción o actividad</button>
            ) : null}

            <div className="flex flex-wrap gap-2 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => onActivity(deal)} className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"><MessageSquare className="h-3.5 w-3.5" /> Actividad</button>
              <Link to={`/quotes/new?deal_id=${deal.id}`} className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"><FilePlus className="h-3.5 w-3.5" /> Propuesta</Link>
              {deal.stage === 'ganado' && !deal.convertedProjectId && <button type="button" onClick={() => convertDeal.mutate(deal.id)} disabled={convertDeal.isPending} className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60"><ArrowRight className="h-3.5 w-3.5" /> Convertir en proyecto</button>}
            </div>

            <div>
              <div className="mb-2 flex items-center justify-between gap-2">
                <h3 className="text-xs font-semibold text-slate-800">Timeline de la oportunidad</h3>
                <button type="button" onClick={() => onActivity(deal)} className="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 hover:underline"><Plus className="h-3 w-3" /> Añadir</button>
              </div>
              <div className="space-y-2">
                {relatedActivities.length === 0 && <p className="rounded-xl border border-dashed border-slate-200 px-3 py-6 text-center text-[11px] text-slate-400">Sin actividades registradas.</p>}
                {relatedActivities.map((activity) => {
                  const Icon = ACTIVITY_ICONS[activity.type] ?? MessageSquare;
                  return (
                    <div key={activity.id} className="flex items-start gap-3 rounded-xl border border-slate-100 bg-slate-50/60 p-3">
                      <span className="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-white text-brand-600"><Icon className="h-3.5 w-3.5" /></span>
                      <div className="min-w-0 flex-1">
                        <p className="text-[11px] font-semibold text-slate-700">{activity.subject}</p>
                        <p className="text-[10px] text-slate-400">{formatActivityDate(activity.dueAt)}{activity.user ? ` · ${activity.user}` : ''}</p>
                        {activity.notes && <p className="mt-1 text-[11px] text-slate-500">{activity.notes}</p>}
                      </div>
                      <button type="button" onClick={() => onDeleteActivity(activity.id)} className="rounded p-1 text-slate-300 transition hover:text-rose-600" title="Eliminar actividad"><Trash2 className="h-3.5 w-3.5" /></button>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        ) : null}
      </div>
    </Modal>
  );
}

function CompanyModal({ initialCompany, onClose, onChanged }: { initialCompany: CrmData['companies'][number] | null; onClose: () => void; onChanged: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const company = initialCompany;
  const save = useMutation({
    mutationFn: (body: Record<string, string>) => apiPostRoot<{ success: boolean }>('/api/crm.php', body),
    onSuccess: () => { toast(company ? 'Empresa actualizada.' : 'Empresa creada.'); onChanged(); onClose(); },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo guardar la empresa.', 'error'),
  });
  const remove = useMutation({
    mutationFn: (id: number) => apiPostRoot<{ success: boolean }>('/api/crm.php', { action: 'company_delete', id }),
    onSuccess: () => { toast('Empresa eliminada.'); onChanged(); onClose(); },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo eliminar la empresa.', 'error'),
  });
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const body: Record<string, string> = { action: company ? 'company_update' : 'company_create' };
    if (company) body.id = String(company.id);
    form.forEach((value, key) => { body[key] = String(value); });
    save.mutate(body);
  };
  return (
    <Modal open onClose={onClose} panelClassName="max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-2xl border border-slate-100 bg-white shadow-2xl">
      <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/70 px-5 py-4">
        <div><h3 className="text-base font-semibold text-slate-900">{company ? 'Editar empresa' : 'Nueva empresa'}</h3><p className="text-[11px] text-slate-400">Datos comerciales y de contacto</p></div>
        <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X className="h-4 w-4" /></button>
      </div>
      <form className="space-y-3 p-5" onSubmit={submit}>
        <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Nombre *</label><Input name="name" required maxLength={150} defaultValue={company?.name ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Sector</label><Input name="industry" defaultValue={company?.industry ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Web</label><Input name="website" type="url" defaultValue={company?.website ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Email</label><Input name="email" type="email" defaultValue={company?.email ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Teléfono</label><Input name="phone" defaultValue={company?.phone ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
        </div>
        <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Dirección</label><Input name="address" defaultValue={company?.address ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
        <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Notas</label><textarea name="notes" rows={3} defaultValue={company?.notes ?? ''} className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" /></div>
        {save.isError && <ErrorState message={save.error instanceof Error ? save.error.message : 'No se pudo guardar la empresa.'} />}
        <div className="flex items-center justify-between gap-2 border-t border-slate-100 pt-4">
          <div>{company && <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar empresa', description: company.name, confirmLabel: 'Eliminar', tone: 'danger' })) remove.mutate(company.id); }} className="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-[11px] font-semibold text-rose-600"><Trash2 className="h-3.5 w-3.5" /> Eliminar</button>}</div>
          <div className="flex gap-2"><button type="button" onClick={onClose} className="rounded-xl px-4 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100">Cancelar</button><LoadingButton type="submit" pending={save.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">Guardar empresa</LoadingButton></div>
        </div>
      </form>
    </Modal>
  );
}

function ContactModal({ initialContact, companies, onClose, onChanged }: { initialContact: CrmData['contacts'][number] | null; companies: CrmData['companies']; onClose: () => void; onChanged: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const contact = initialContact;
  const save = useMutation({
    mutationFn: (body: Record<string, string>) => apiPostRoot<{ success: boolean }>('/api/crm.php', body),
    onSuccess: () => { toast(contact ? 'Contacto actualizado.' : 'Contacto creado.'); onChanged(); onClose(); },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo guardar el contacto.', 'error'),
  });
  const remove = useMutation({
    mutationFn: (id: number) => apiPostRoot<{ success: boolean }>('/api/crm.php', { action: 'contact_delete', id }),
    onSuccess: () => { toast('Contacto eliminado.'); onChanged(); onClose(); },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo eliminar el contacto.', 'error'),
  });
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const body: Record<string, string> = { action: contact ? 'contact_update' : 'contact_create' };
    if (contact) body.id = String(contact.id);
    form.forEach((value, key) => { body[key] = String(value); });
    save.mutate(body);
  };
  return (
    <Modal open onClose={onClose} panelClassName="max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-2xl border border-slate-100 bg-white shadow-2xl">
      <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/70 px-5 py-4">
        <div><h3 className="text-base font-semibold text-slate-900">{contact ? 'Editar contacto' : 'Nuevo contacto'}</h3><p className="text-[11px] text-slate-400">Persona de contacto de una empresa</p></div>
        <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X className="h-4 w-4" /></button>
      </div>
      <form className="space-y-3 p-5" onSubmit={submit}>
        <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Nombre *</label><Input name="name" required maxLength={100} defaultValue={contact?.name ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Empresa</label><Select name="company_id" defaultValue={contact?.companyId ?? ''} className="w-full px-3.5 py-2.5 text-sm"><option value="">Sin empresa</option>{companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}</Select></div>
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Puesto</label><Input name="role" defaultValue={contact?.role ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Email</label><Input name="email" type="email" defaultValue={contact?.email ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Teléfono</label><Input name="phone" defaultValue={contact?.phone ?? ''} className="px-3.5 py-2.5 text-sm" /></div>
        </div>
        <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Notas</label><textarea name="notes" rows={3} defaultValue={contact?.notes ?? ''} className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" /></div>
        {save.isError && <ErrorState message={save.error instanceof Error ? save.error.message : 'No se pudo guardar el contacto.'} />}
        <div className="flex items-center justify-between gap-2 border-t border-slate-100 pt-4">
          <div>{contact && <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar contacto', description: contact.name, confirmLabel: 'Eliminar', tone: 'danger' })) remove.mutate(contact.id); }} className="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-[11px] font-semibold text-rose-600"><Trash2 className="h-3.5 w-3.5" /> Eliminar</button>}</div>
          <div className="flex gap-2"><button type="button" onClick={onClose} className="rounded-xl px-4 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100">Cancelar</button><LoadingButton type="submit" pending={save.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">Guardar contacto</LoadingButton></div>
        </div>
      </form>
    </Modal>
  );
}

function ActivityModal({
  crm,
  defaults,
  onClose,
  onChanged,
}: {
  crm: CrmData;
  defaults: { deal?: CrmData['deals'][number]; company?: CrmData['companies'][number]; contact?: CrmData['contacts'][number] } | null;
  onClose: () => void;
  onChanged: () => void;
}) {
  const toast = useToast();
  const [dealId, setDealId] = useState(defaults?.deal ? String(defaults.deal.id) : '');
  const [companyId, setCompanyId] = useState(defaults?.company ? String(defaults.company.id) : (defaults?.deal?.companyId ? String(defaults.deal.companyId) : ''));
  const [contactId, setContactId] = useState(defaults?.contact ? String(defaults.contact.id) : (defaults?.deal?.contactId ? String(defaults.deal.contactId) : ''));
  const save = useMutation({
    mutationFn: (body: Record<string, string>) => apiPostRoot<{ success: boolean }>('/api/crm.php', body),
    onSuccess: () => { toast('Actividad registrada.'); onChanged(); onClose(); },
    onError: (error) => toast(error instanceof Error ? error.message : 'No se pudo registrar la actividad.', 'error'),
  });
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const body: Record<string, string> = { action: 'activity_create' };
    form.forEach((value, key) => { body[key] = String(value); });
    save.mutate(body);
  };
  return (
    <Modal open onClose={onClose} panelClassName="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-slate-100 bg-white shadow-2xl">
      <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/70 px-5 py-4">
        <div><h3 className="text-base font-semibold text-slate-900">Registrar actividad</h3><p className="text-[11px] text-slate-400">Llamada, correo, reunión, nota o tarea</p></div>
        <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X className="h-4 w-4" /></button>
      </div>
      <form className="space-y-3 p-5" onSubmit={submit}>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Tipo</label><Select name="type" defaultValue="nota" className="w-full px-3.5 py-2.5 text-sm">{crm.activityTypes.map((type) => <option key={type.key} value={type.key}>{type.label}</option>)}</Select></div>
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Fecha y hora</label><Input name="occurred_at" type="datetime-local" defaultValue={new Date().toISOString().slice(0, 16)} className="px-3.5 py-2.5 text-sm" /></div>
        </div>
        <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Título *</label><Input name="title" required maxLength={150} placeholder="Ej: Llamada de seguimiento" className="px-3.5 py-2.5 text-sm" /></div>
        <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Oportunidad</label><Select name="deal_id" value={dealId} onChange={(event) => { setDealId(event.target.value); const deal = crm.deals.find((item) => String(item.id) === event.target.value); setCompanyId(deal?.companyId ? String(deal.companyId) : companyId); setContactId(deal?.contactId ? String(deal.contactId) : contactId); }} className="w-full px-3.5 py-2.5 text-sm"><option value="">Sin oportunidad</option>{crm.deals.map((deal) => <option key={deal.id} value={deal.id}>{deal.title}</option>)}</Select></div>
        <div className="grid grid-cols-2 gap-3">
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Empresa</label><Select name="company_id" value={companyId} onChange={(event) => { setCompanyId(event.target.value); setContactId(''); }} className="w-full px-3.5 py-2.5 text-sm"><option value="">Sin empresa</option>{crm.companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}</Select></div>
          <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Contacto</label><Select name="contact_id" value={contactId} onChange={(event) => setContactId(event.target.value)} className="w-full px-3.5 py-2.5 text-sm"><option value="">Sin contacto</option>{crm.contacts.filter((contact) => companyId === '' || String(contact.companyId) === companyId).map((contact) => <option key={contact.id} value={contact.id}>{contact.name}</option>)}</Select></div>
        </div>
        <div><label className="mb-1.5 block text-xs font-medium text-slate-700">Notas</label><textarea name="notes" rows={3} className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" /></div>
        {save.isError && <ErrorState message={save.error instanceof Error ? save.error.message : 'No se pudo registrar la actividad.'} />}
        <div className="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" onClick={onClose} className="rounded-xl px-4 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100">Cancelar</button><LoadingButton type="submit" pending={save.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">Registrar actividad</LoadingButton></div>
      </form>
    </Modal>
  );
}

export function CrmPage() {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const toast = useToast();
  const confirm = useConfirm();
  const tabParam = searchParams.get('tab');
  const tab: TabKey = TABS.some((item) => item.key === tabParam) ? tabParam as TabKey : 'pipeline';
  const viewParam = searchParams.get('view');
  const defaultView: PipelineView = typeof window !== 'undefined' && window.innerWidth < 768 ? 'list' : 'kanban';
  const view: PipelineView = viewParam === 'kanban' || viewParam === 'list' || viewParam === 'forecast' ? viewParam : defaultView;
  const search = searchParams.get('search') ?? '';
  const ownerFilter = searchParams.get('owner') ?? '';
  const sourceFilter = searchParams.get('source') ?? '';
  const stageFilter = searchParams.get('stage') ?? '';
  const stalledOnly = searchParams.get('stalled') === '1';
  const [showFilters, setShowFilters] = useState(false);
  const [dealModal, setDealModal] = useState<{ deal: CrmData['deals'][number] | null; mode: 'view' | 'edit' | 'create'; stage?: string; companyId?: number } | null>(null);
  const [companyModal, setCompanyModal] = useState<{ company: CrmData['companies'][number] | null } | null>(null);
  const [contactModal, setContactModal] = useState<{ contact: CrmData['contacts'][number] | null } | null>(null);
  const [activityModal, setActivityModal] = useState<{ deal?: CrmData['deals'][number]; company?: CrmData['companies'][number]; contact?: CrmData['contacts'][number] } | null>(null);
  const [draggingDealId, setDraggingDealId] = useState<number | null>(null);
  const [dragOverStage, setDragOverStage] = useState<string | null>(null);

  const { data, isLoading, error } = useQuery({ queryKey: ['crm', search], queryFn: () => apiGet<CrmData>('crm.php', { search }) });

  const updateParams = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(searchParams);
    Object.entries(patch).forEach(([key, value]) => {
      if (value === null || value === '') next.delete(key);
      else next.set(key, value);
    });
    setSearchParams(next, { replace: true });
  };

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['crm'] });

  // Enlace directo desde el historial de una empresa: /crm?new=deal&company_id=ID
  useEffect(() => {
    if (!data || searchParams.get('new') !== 'deal') return;
    const companyParam = Number(searchParams.get('company_id') ?? 0) || undefined;
    setDealModal({ deal: null, mode: 'create', companyId: companyParam });
    updateParams({ new: null, company_id: null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data]);

  const moveDeal = useMutation({
    mutationFn: ({ id, stage }: { id: number; stage: string }) => apiPostRoot<{ success: boolean }>('/api/crm.php', { action: 'deal_move', id, stage }),
    onMutate: async ({ id, stage }) => {
      await queryClient.cancelQueries({ queryKey: ['crm'] });
      const previous = queryClient.getQueriesData<CrmData>({ queryKey: ['crm'] });
      queryClient.setQueriesData<CrmData>({ queryKey: ['crm'] }, (old) => old ? { ...old, deals: old.deals.map((deal) => deal.id === id ? { ...deal, stage } : deal) } : old);
      return { previous };
    },
    onError: (mutationError, _variables, context) => {
      context?.previous.forEach(([key, value]) => queryClient.setQueryData(key, value));
      toast(mutationError instanceof Error ? mutationError.message : 'No se pudo mover la oportunidad.', 'error');
    },
    onSuccess: () => toast('Etapa actualizada.'),
    onSettled: refresh,
  });

  const deleteActivity = useMutation({
    mutationFn: (id: number) => apiPostRoot<{ success: boolean }>('/api/crm.php', { action: 'activity_delete', id }),
    onSuccess: () => { refresh(); toast('Actividad eliminada.'); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo eliminar la actividad.', 'error'),
  });

  const convertDeal = useMutation({
    mutationFn: (id: number) => apiPostRoot<{ success: boolean; project_id: number; message: string }>('/api/crm.php', { action: 'deal_convert', id }),
    onSuccess: (result) => {
      toast(result.message);
      refresh();
      navigate(`/projects/${result.project_id}`);
    },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo convertir la oportunidad.', 'error'),
  });

  const filteredDeals = useMemo(() => (data?.deals ?? []).filter((deal) => {
    if (ownerFilter !== '' && String(deal.ownerId ?? '') !== ownerFilter) return false;
    if (sourceFilter !== '' && deal.source !== sourceFilter) return false;
    if (stageFilter !== '' && deal.stage !== stageFilter) return false;
    if (stalledOnly && !isStalled(deal)) return false;
    return true;
  }), [data, ownerFilter, sourceFilter, stageFilter, stalledOnly]);

  const companies = useMemo(() => (data?.companies ?? []).filter((company) => search === '' || `${company.name} ${company.email ?? ''}`.toLowerCase().includes(search.toLowerCase())), [data, search]);
  const contacts = useMemo(() => (data?.contacts ?? []).filter((contact) => search === '' || `${contact.name} ${contact.email ?? ''} ${contact.company ?? ''}`.toLowerCase().includes(search.toLowerCase())), [data, search]);
  const activities = useMemo(() => (data?.activities ?? []).filter((activity) => search === '' || `${activity.subject} ${activity.dealTitle ?? ''} ${activity.companyName ?? ''}`.toLowerCase().includes(search.toLowerCase())), [data, search]);

  const dealsByStage = useMemo(() => {
    const map: Record<string, CrmData['deals']> = {};
    filteredDeals.forEach((deal) => { (map[deal.stage] ??= []).push(deal); });
    return map;
  }, [filteredDeals]);

  const weightedPipeline = useMemo(() => {
    const totals: Record<string, number> = {};
    (data?.deals ?? []).filter(isOpenDeal).forEach((deal) => {
      totals[deal.currency] = (totals[deal.currency] ?? 0) + deal.value * (STAGE_PROBABILITY[deal.stage] ?? 0) / 100;
    });
    return totals;
  }, [data]);

  const forecastByOwner = useMemo(() => {
    const map = new Map<string, { name: string; count: number; totals: Record<string, number> }>();
    filteredDeals.filter(isOpenDeal).forEach((deal) => {
      const key = deal.owner || 'Sin responsable';
      const row = map.get(key) ?? { name: key, count: 0, totals: {} };
      row.count++;
      row.totals[deal.currency] = (row.totals[deal.currency] ?? 0) + deal.value;
      map.set(key, row);
    });
    return Array.from(map.values()).sort((a, b) => b.count - a.count);
  }, [filteredDeals]);

  const forecastBySource = useMemo(() => {
    const map = new Map<string, { name: string; count: number; totals: Record<string, number> }>();
    filteredDeals.filter(isOpenDeal).forEach((deal) => {
      const key = deal.source || 'Sin origen';
      const row = map.get(key) ?? { name: key, count: 0, totals: {} };
      row.count++;
      row.totals[deal.currency] = (row.totals[deal.currency] ?? 0) + deal.value;
      map.set(key, row);
    });
    return Array.from(map.values()).sort((a, b) => b.count - a.count);
  }, [filteredDeals]);

  const stalledCount = (data?.deals ?? []).filter(isStalled).length;
  const filtersActive = ownerFilter !== '' || sourceFilter !== '' || stageFilter !== '' || stalledOnly;

  if (isLoading) return <div className="space-y-5"><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">{Array.from({ length: 5 }).map((_, index) => <Skeleton key={index} className="h-28" />)}</div><Skeleton className="h-96" /></div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar el CRM.'} />;

  const primaryAction = () => {
    if (tab === 'companies') setCompanyModal({ company: null });
    else if (tab === 'contacts') setContactModal({ contact: null });
    else if (tab === 'activities') setActivityModal({});
    else setDealModal({ deal: null, mode: 'create' });
  };
  const actionLabel = tab === 'companies' ? 'Nueva empresa' : tab === 'contacts' ? 'Nuevo contacto' : tab === 'activities' ? 'Registrar actividad' : 'Nueva oportunidad';
  const ActionIcon = tab === 'companies' ? Building2 : tab === 'contacts' ? Users : tab === 'activities' ? MessageSquare : Target;

  return (
    <div className="space-y-5">
      <PageHeader
        title="CRM"
        subtitle="Oportunidades, empresas y contactos de tu agencia."
        actions={<button type="button" onClick={primaryAction} className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-brand-700"><ActionIcon className="h-4 w-4" /> {actionLabel}</button>}
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-5">
        <Card className="p-4"><div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Pipeline abierto</span><Target className="h-4 w-4 text-brand-500" /></div><p className="mt-2 text-xl font-bold tracking-tight text-slate-900">{formatTotals((data.deals.filter(isOpenDeal)).reduce<Record<string, number>>((totals, deal) => { totals[deal.currency] = (totals[deal.currency] ?? 0) + deal.value; return totals; }, {}))}</p><p className="text-[11px] text-slate-400">{data.stats.open_deals} oportunidad(es)</p></Card>
        <Card className="p-4"><div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Previsión ponderada</span><TrendingUp className="h-4 w-4 text-indigo-500" /></div><p className="mt-2 text-xl font-bold tracking-tight text-slate-900">{formatTotals(weightedPipeline)}</p><p className="text-[11px] text-slate-400">Según etapa del pipeline</p></Card>
        <Card className="p-4"><div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Ganado este mes</span><CheckCircle2 className="h-4 w-4 text-emerald-500" /></div><p className="mt-2 text-xl font-bold tracking-tight text-slate-900">{formatMoney(data.stats.won_value_month)}</p><p className="text-[11px] text-slate-400">{data.stats.won_month} ganada(s)</p></Card>
        <Card className="p-4"><div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Tasa de cierre</span><Target className="h-4 w-4 text-slate-400" /></div><p className="mt-2 text-xl font-bold tracking-tight text-slate-900">{data.stats.close_rate}%</p><p className="text-[11px] text-slate-400">{data.stats.won_deals} ganadas · {data.stats.lost_deals} perdidas</p></Card>
        <Card className="p-4"><div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Directorio</span><Building2 className="h-4 w-4 text-cyan-500" /></div><p className="mt-2 text-xl font-bold tracking-tight text-slate-900">{data.stats.companies} <span className="text-sm font-medium text-slate-400">empresas</span></p><p className="text-[11px] text-slate-400">{data.stats.contacts} contactos</p></Card>
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex w-fit items-center gap-1 rounded-xl bg-slate-100/80 p-1">
          {TABS.map((item) => <button key={item.key} type="button" onClick={() => updateParams({ tab: item.key })} className={`rounded-lg px-3 py-2 text-xs font-semibold transition ${tab === item.key ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}>{item.label}</button>)}
        </div>
        <div className="relative w-full sm:max-w-xs"><Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><Input value={search} onChange={(event) => updateParams({ search: event.target.value })} placeholder="Buscar oportunidad, empresa o contacto..." className="pl-9" /></div>
      </div>

      {tab === 'pipeline' && (
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex items-center gap-1 rounded-xl bg-slate-100 p-0.5">
            {([['kanban','Pipeline',LayoutGrid],['list','Lista',List],['forecast','Previsión',TrendingUp]] as const).map(([key,label,Icon]) => (
              <button key={key} type="button" onClick={() => updateParams({ view: key })} className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ${view === key ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}><Icon className="h-3.5 w-3.5" /><span className="hidden sm:inline">{label}</span></button>
            ))}
          </div>
          <button type="button" onClick={() => updateParams({ stalled: stalledOnly ? null : '1' })} className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold transition ${stalledOnly ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'}`}><Clock className="h-3 w-3" /> Estancadas {stalledCount > 0 && <span className="rounded-full bg-white/80 px-1 text-[10px] font-bold text-amber-700">{stalledCount}</span>}</button>
          <button type="button" onClick={() => setShowFilters((current) => !current)} className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold transition ${showFilters || filtersActive ? 'border-brand-200 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'}`}><FilterIcon /> Filtros {filtersActive && <span className="h-1.5 w-1.5 rounded-full bg-brand-600" />}</button>
          {filtersActive && <button type="button" onClick={() => updateParams({ owner: null, source: null, stage: null, stalled: null })} className="rounded-full p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title="Limpiar filtros"><X className="h-3.5 w-3.5" /></button>}
        </div>
      )}

      {showFilters && tab === 'pipeline' && (
        <Card className="grid grid-cols-1 gap-2 p-3 sm:grid-cols-3">
          <Select value={ownerFilter} onChange={(event) => updateParams({ owner: event.target.value })} className="px-3 py-1.5 text-xs"><option value="">Todos los responsables</option>{data.owners.map((owner) => <option key={owner.id} value={owner.id}>{owner.name}</option>)}</Select>
          <Select value={sourceFilter} onChange={(event) => updateParams({ source: event.target.value })} className="px-3 py-1.5 text-xs"><option value="">Todos los orígenes</option>{data.sources.map((source) => <option key={source} value={source}>{source}</option>)}</Select>
          <Select value={stageFilter} onChange={(event) => updateParams({ stage: event.target.value })} className="px-3 py-1.5 text-xs"><option value="">Todas las etapas</option>{data.stages.map((stage) => <option key={stage.key} value={stage.key}>{stage.label}</option>)}</Select>
        </Card>
      )}

      {tab === 'pipeline' && view === 'kanban' && (
        <div className="flex items-start gap-3 overflow-x-auto pb-3">
          {data.stages.map((stage) => {
            const stageDeals = dealsByStage[stage.key] ?? [];
            const totals: Record<string, number> = {};
            stageDeals.forEach((deal) => { totals[deal.currency] = (totals[deal.currency] ?? 0) + deal.value; });
            const meta = STAGE_META[stage.key] ?? STAGE_META.nuevo;
            return (
              <div key={stage.key} className="w-[300px] flex-shrink-0">
                <div className="mb-2 flex items-center justify-between gap-2 px-1">
                  <div className="flex min-w-0 items-center gap-2"><span className={`h-2 w-2 rounded-full ${meta.dot}`} /><h2 className="truncate text-xs font-bold text-slate-700">{stage.label}</h2><span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-400">{stageDeals.length}</span></div>
                  <span className="whitespace-nowrap text-[10px] font-semibold text-slate-400">{formatTotals(totals)}</span>
                </div>
                <div
                  onDragOver={(event) => { if (draggingDealId === null) return; event.preventDefault(); setDragOverStage(stage.key); }}
                  onDragLeave={() => setDragOverStage((current) => current === stage.key ? null : current)}
                  onDrop={(event) => { event.preventDefault(); const id = Number(event.dataTransfer.getData('text/plain')) || draggingDealId; setDraggingDealId(null); setDragOverStage(null); if (id) moveDeal.mutate({ id, stage: stage.key }); }}
                  className={`min-h-[120px] space-y-2 rounded-xl p-2 transition ${dragOverStage === stage.key ? 'bg-brand-50 ring-2 ring-brand-300' : 'bg-slate-100/60'}`}
                >
                  {stageDeals.length === 0 ? <p className="py-8 text-center text-[11px] text-slate-400">Sin oportunidades</p> : stageDeals.map((deal) => {
                    const stalled = isStalled(deal);
                    return (
                      <article key={deal.id} onClick={() => setDealModal({ deal, mode: 'view' })} className={`group cursor-pointer space-y-2 rounded-xl border bg-white p-3.5 shadow-2xs transition hover:border-slate-300 ${stalled ? 'border-amber-200' : 'border-slate-200/80'}`}>
                        <div className="flex items-start justify-between gap-2">
                          <div className="flex min-w-0 items-start gap-1">
                            <span draggable onDragStart={(event) => { event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', String(deal.id)); setDraggingDealId(deal.id); }} onDragEnd={() => { setDraggingDealId(null); setDragOverStage(null); }} onClick={(event) => event.stopPropagation()} className="-ml-1 mt-0.5 cursor-grab rounded p-0.5 text-slate-300 opacity-0 transition hover:text-slate-500 group-hover:opacity-100" title="Arrastrar etapa"><GripVertical className="h-3.5 w-3.5" /></span>
                            <h3 className="min-w-0 text-sm font-semibold leading-snug text-slate-800">{deal.title}</h3>
                          </div>
                          <div className="flex flex-shrink-0 items-center gap-0.5 opacity-0 transition group-hover:opacity-100">
                            {deal.contactEmail && <a href={`mailto:${deal.contactEmail}`} onClick={(event) => event.stopPropagation()} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title={`Email ${deal.contactEmail}`}><Mail className="h-3.5 w-3.5" /></a>}
                            {(deal.contactPhone || deal.companyPhone) && <a href={`tel:${deal.contactPhone || deal.companyPhone}`} onClick={(event) => event.stopPropagation()} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Llamar"><Phone className="h-3.5 w-3.5" /></a>}
                            {deal.stage === 'ganado' && !deal.convertedProjectId && <button type="button" onClick={(event) => { event.stopPropagation(); convertDeal.mutate(deal.id); }} disabled={convertDeal.isPending} className="rounded-md p-1 text-emerald-600 hover:bg-emerald-50 disabled:opacity-50" title="Convertir en proyecto"><ArrowRight className="h-3.5 w-3.5" /></button>}
                            <button type="button" onClick={(event) => { event.stopPropagation(); setDealModal({ deal, mode: 'edit' }); }} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Editar"><Pencil className="h-3.5 w-3.5" /></button>
                            <button type="button" onClick={(event) => { event.stopPropagation(); setActivityModal({ deal }); }} className="rounded-md p-1 text-slate-400 hover:bg-brand-50 hover:text-brand-600" title="Registrar actividad"><MessageSquare className="h-3.5 w-3.5" /></button>
                            <Link to={`/quotes/new?deal_id=${deal.id}`} onClick={(event) => event.stopPropagation()} className="rounded-md p-1 text-slate-400 hover:bg-brand-50 hover:text-brand-600" title="Crear propuesta"><FilePlus className="h-3.5 w-3.5" /></Link>
                          </div>
                        </div>
                        {(deal.company || deal.contact) && <p className="truncate text-[11px] text-slate-500">{deal.company}{deal.company && deal.contact ? ' · ' : ''}{deal.contact}</p>}
                        <div className="flex items-center justify-between gap-2 pt-0.5"><span className="text-xs font-bold text-slate-800">{formatMoney(deal.value, deal.currency)}</span>{deal.expectedClose && <span className="inline-flex items-center gap-1 text-[10px] text-slate-400"><Calendar className="h-3 w-3" />{formatDate(deal.expectedClose)}</span>}</div>
                        {stalled && <p className="inline-flex items-center gap-1 text-[10px] font-semibold text-amber-700"><AlertTriangle className="h-3 w-3" /> {daysSince(deal.lastActivityAt ?? deal.updatedAt ?? deal.createdAt)} días sin actividad</p>}
                        {deal.nextActivityTitle ? <p className="inline-flex items-center gap-1 truncate text-[10px] font-medium text-brand-700"><Calendar className="h-3 w-3" /> {deal.nextActivityTitle}</p> : isOpenDeal(deal) ? <button type="button" onClick={(event) => { event.stopPropagation(); setActivityModal({ deal }); }} className="inline-flex items-center gap-1 text-[10px] font-semibold text-slate-400 hover:text-brand-600"><Plus className="h-3 w-3" /> Añadir próxima acción</button> : null}
                        {deal.quotesCount > 0 && <span className="inline-flex items-center gap-1.5 text-[10px] font-semibold text-brand-700"><FileText className="h-3 w-3" />{deal.quotesCount} propuesta(s){deal.latestQuoteStatus ? ` · ${deal.latestQuoteStatus}` : ''}</span>}
                        {deal.convertedProjectId && <Link to={`/projects/${deal.convertedProjectId}`} onClick={(event) => event.stopPropagation()} className="inline-flex items-center gap-1.5 text-[10px] font-semibold text-emerald-700"><FolderOpen className="h-3 w-3" />{deal.convertedProject || 'Proyecto'}</Link>}
                        <div className="flex items-center justify-between gap-2 border-t border-slate-100 pt-2">
                          <Select value={deal.stage} disabled={moveDeal.isPending} onClick={(event) => event.stopPropagation()} onChange={(event) => moveDeal.mutate({ id: deal.id, stage: event.target.value })} className="px-1.5 py-1 text-[11px]">{data.stages.map((option) => <option key={option.key} value={option.key}>{option.label}</option>)}</Select>
                          <span className="max-w-[110px] truncate text-[10px] text-slate-400">{deal.owner || 'Sin responsable'}</span>
                        </div>
                      </article>
                    );
                  })}
                  <button type="button" onClick={() => setDealModal({ deal: null, mode: 'create', stage: stage.key })} className="flex w-full items-center justify-center gap-1 rounded-lg border border-dashed border-slate-300 py-2 text-[10px] font-semibold text-slate-400 transition hover:border-brand-300 hover:text-brand-600"><Plus className="h-3 w-3" /> Nueva oportunidad</button>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {tab === 'pipeline' && view === 'list' && (
        <Card className="overflow-hidden">
          <div className="hidden overflow-x-auto md:block">
            <table className="w-full text-left text-xs text-slate-600">
              <thead className="border-b border-slate-100 bg-slate-50/70 text-[10px] uppercase tracking-wider text-slate-400"><tr><th className="px-5 py-3">Oportunidad</th><th className="px-3 py-3">Empresa</th><th className="px-3 py-3">Etapa</th><th className="px-3 py-3 text-right">Importe</th><th className="px-3 py-3">Cierre</th><th className="px-3 py-3">Próxima acción</th><th className="px-3 py-3">Responsable</th><th className="px-5 py-3 text-right"></th></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {filteredDeals.map((deal) => <tr key={deal.id} onClick={() => setDealModal({ deal, mode: 'view' })} className="cursor-pointer transition hover:bg-slate-50/60"><td className="px-5 py-3"><p className="font-semibold text-slate-800">{deal.title}</p>{deal.contact && <p className="text-[10px] text-slate-400">{deal.contact}</p>}</td><td className="px-3 py-3 text-xs text-slate-500">{deal.company || '—'}</td><td className="px-3 py-3"><Badge tone={STAGE_META[deal.stage]?.tone ?? 'slate'}>{data.stages.find((stage) => stage.key === deal.stage)?.label ?? deal.stage}</Badge></td><td className="px-3 py-3 text-right font-semibold text-slate-800">{formatMoney(deal.value, deal.currency)}</td><td className="px-3 py-3 text-xs text-slate-500">{deal.expectedClose ? formatDate(deal.expectedClose) : '—'}</td><td className="px-3 py-3 max-w-[180px] truncate text-[11px]">{deal.nextActivityTitle || <span className="text-slate-300">Sin próxima acción</span>}</td><td className="px-3 py-3 text-[11px] text-slate-500">{deal.owner || '—'}</td><td className="px-5 py-3 text-right"><button type="button" onClick={(event) => { event.stopPropagation(); setDealModal({ deal, mode: 'edit' }); }} className="rounded p-1 text-slate-400 hover:text-brand-600"><Pencil className="h-3.5 w-3.5" /></button></td></tr>)}
              </tbody>
            </table>
          </div>
          <div className="divide-y divide-slate-100 md:hidden">
            {filteredDeals.map((deal) => <button key={deal.id} type="button" onClick={() => setDealModal({ deal, mode: 'view' })} className="block w-full space-y-2 p-4 text-left"><div className="flex items-start justify-between gap-2"><p className="text-sm font-semibold text-slate-800">{deal.title}</p><Badge tone={STAGE_META[deal.stage]?.tone ?? 'slate'}>{data.stages.find((stage) => stage.key === deal.stage)?.label ?? deal.stage}</Badge></div><p className="text-[11px] text-slate-500">{deal.company || 'Sin empresa'} · {deal.owner || 'Sin responsable'}</p><div className="flex items-center justify-between"><span className="text-sm font-bold text-slate-900">{formatMoney(deal.value, deal.currency)}</span><span className="text-[10px] text-slate-400">{deal.nextActivityTitle || 'Sin próxima acción'}</span></div></button>)}
          </div>
          {filteredDeals.length === 0 && <EmptyState title="Sin oportunidades" description="Ajusta los filtros o crea una nueva." action={<button type="button" onClick={() => setDealModal({ deal: null, mode: 'create' })} className="rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white">Nueva oportunidad</button>} />}
        </Card>
      )}

      {tab === 'pipeline' && view === 'forecast' && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Card className="p-5"><h2 className="text-sm font-semibold text-slate-800">Previsión por etapa</h2><p className="mt-0.5 text-[11px] text-slate-400">Valor abierto y ponderado según probabilidad</p><div className="mt-4 space-y-2">{data.stages.filter((stage) => stage.key !== 'perdido').map((stage) => { const stageDeals = filteredDeals.filter((deal) => deal.stage === stage.key); const total = stageDeals.reduce<Record<string, number>>((totals, deal) => { totals[deal.currency] = (totals[deal.currency] ?? 0) + deal.value; return totals; }, {}); const weighted = stageDeals.reduce<Record<string, number>>((totals, deal) => { totals[deal.currency] = (totals[deal.currency] ?? 0) + deal.value * (STAGE_PROBABILITY[stage.key] ?? 0) / 100; return totals; }, {}); return <div key={stage.key} className="flex items-center justify-between gap-3 rounded-xl border border-slate-100 bg-slate-50/60 px-3 py-2"><div className="flex items-center gap-2"><span className={`h-2 w-2 rounded-full ${STAGE_META[stage.key]?.dot}`} /><span className="text-xs font-semibold text-slate-700">{stage.label}</span><span className="rounded-full bg-white px-1.5 text-[10px] font-bold text-slate-500">{stageDeals.length}</span></div><div className="text-right"><p className="text-xs font-semibold text-slate-800">{formatTotals(total)}</p><p className="text-[10px] text-slate-400">Ponderado {formatTotals(weighted)}</p></div></div>; })}</div></Card>
          <Card className="p-5"><h2 className="text-sm font-semibold text-slate-800">Equipo y origen</h2><p className="mt-0.5 text-[11px] text-slate-400">Pipeline abierto por responsable y origen</p><div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2"><ForecastList title="Por responsable" rows={forecastByOwner} /><ForecastList title="Por origen" rows={forecastBySource} /></div></Card>
        </div>
      )}

      {tab === 'companies' && (
        <Card className="overflow-hidden">
          <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-4"><h2 className="text-sm font-semibold text-slate-800">Empresas</h2><button type="button" onClick={() => setCompanyModal({ company: null })} className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1.5 text-[11px] font-semibold text-white"><Plus className="h-3 w-3" /> Nueva</button></div>
          {companies.length === 0 ? <EmptyState title="Aún no hay empresas" description="Crea la primera para empezar." action={<button type="button" onClick={() => setCompanyModal({ company: null })} className="rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white">Crear empresa</button>} /> : <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50/70 text-[11px] uppercase tracking-wide text-slate-400"><tr><th className="px-5 py-3">Empresa</th><th className="hidden px-3 py-3 md:table-cell">Sector</th><th className="px-3 py-3">Contactos</th><th className="px-3 py-3">Oportunidades</th><th className="hidden px-3 py-3 lg:table-cell">Valor abierto</th><th className="px-5 py-3 text-right"></th></tr></thead><tbody className="divide-y divide-slate-100">{companies.map((company) => <tr key={company.id} className="hover:bg-slate-50/60"><td className="px-5 py-3"><Link to={`/crm/companies/${company.id}`} className="font-semibold text-slate-800 hover:text-brand-700">{company.name}</Link>{company.email && <p className="text-[11px] text-slate-400">{company.email}</p>}<p className="mt-0.5 text-[10px] text-slate-400">{company.projects} proyectos · {company.quotes} propuestas</p></td><td className="hidden px-3 py-3 text-xs text-slate-500 md:table-cell">{company.industry || '—'}</td><td className="px-3 py-3 text-xs text-slate-600">{company.contacts}</td><td className="px-3 py-3 text-xs text-slate-600">{company.deals}</td><td className="hidden px-3 py-3 text-xs font-semibold text-slate-700 lg:table-cell">{formatMoney(company.openValue)}</td><td className="px-5 py-3 text-right"><div className="inline-flex gap-1"><Link to={`/crm/companies/${company.id}`} className="rounded p-1 text-slate-400 hover:text-brand-600" title="Historial comercial"><History className="h-3.5 w-3.5" /></Link><button type="button" onClick={() => setCompanyModal({ company })} className="rounded p-1 text-slate-400 hover:text-brand-600" title="Editar"><Pencil className="h-3.5 w-3.5" /></button><button type="button" onClick={() => { setActivityModal({ company }); }} className="rounded p-1 text-slate-400 hover:text-brand-600" title="Registrar actividad"><MessageSquare className="h-3.5 w-3.5" /></button></div></td></tr>)}</tbody></table></div>}
        </Card>
      )}

      {tab === 'contacts' && (
        <Card className="overflow-hidden">
          <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-4"><h2 className="text-sm font-semibold text-slate-800">Contactos</h2><button type="button" onClick={() => setContactModal({ contact: null })} className="inline-flex items-center gap-1 rounded-lg bg-brand-600 px-2.5 py-1.5 text-[11px] font-semibold text-white"><Plus className="h-3 w-3" /> Nuevo</button></div>
          {contacts.length === 0 ? <EmptyState title="Aún no hay contactos" description="Crea el primer contacto." action={<button type="button" onClick={() => setContactModal({ contact: null })} className="rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white">Crear contacto</button>} /> : <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50/70 text-[11px] uppercase tracking-wide text-slate-400"><tr><th className="px-5 py-3">Contacto</th><th className="hidden px-3 py-3 md:table-cell">Empresa</th><th className="hidden px-3 py-3 lg:table-cell">Puesto</th><th className="px-5 py-3 text-right"></th></tr></thead><tbody className="divide-y divide-slate-100">{contacts.map((contact) => <tr key={contact.id} className="hover:bg-slate-50/60"><td className="px-5 py-3"><p className="font-semibold text-slate-800">{contact.name}</p>{contact.email && <p className="text-[11px] text-slate-400">{contact.email}</p>}</td><td className="hidden px-3 py-3 text-xs text-slate-500 md:table-cell">{contact.company || '—'}</td><td className="hidden px-3 py-3 text-xs text-slate-500 lg:table-cell">{contact.role || '—'}</td><td className="px-5 py-3 text-right"><div className="inline-flex gap-1"><button type="button" onClick={() => setContactModal({ contact })} className="rounded p-1 text-slate-400 hover:text-brand-600" title="Editar"><Pencil className="h-3.5 w-3.5" /></button><button type="button" onClick={() => setActivityModal({ contact })} className="rounded p-1 text-slate-400 hover:text-brand-600" title="Registrar actividad"><MessageSquare className="h-3.5 w-3.5" /></button></div></td></tr>)}</tbody></table></div>}
        </Card>
      )}

      {tab === 'activities' && (
        <div className="space-y-3">
          <div className="flex justify-end"><button type="button" onClick={() => setActivityModal({})} className="inline-flex items-center gap-1 rounded-xl bg-brand-600 px-3 py-2 text-xs font-semibold text-white"><Plus className="h-3.5 w-3.5" /> Registrar actividad</button></div>
          {activities.length === 0 ? <Card><EmptyState title="Sin actividades" description="Registra llamadas, reuniones o notas." action={<button type="button" onClick={() => setActivityModal({})} className="rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white">Registrar actividad</button>} /></Card> : activities.map((activity) => {
            const Icon = ACTIVITY_ICONS[activity.type] ?? MessageSquare;
            return <Card key={activity.id} className="flex items-start gap-3 p-4"><span className="mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Icon className="h-4 w-4" /></span><div className="min-w-0 flex-1"><p className="text-xs font-semibold text-slate-800">{activity.subject}</p><p className="text-[10px] text-slate-400">{activity.dealTitle || activity.companyName || activity.contactName || 'General'}{activity.user ? ` · ${activity.user}` : ''}{activity.dueAt ? ` · ${formatActivityDate(activity.dueAt)}` : ''}</p>{activity.notes && <p className="mt-1 line-clamp-2 text-[11px] text-slate-500">{activity.notes}</p>}</div><button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar actividad', description: activity.subject, confirmLabel: 'Eliminar', tone: 'danger' })) deleteActivity.mutate(activity.id); }} className="rounded p-1 text-slate-300 hover:text-rose-600" title="Eliminar"><Trash2 className="h-3.5 w-3.5" /></button></Card>;
          })}
        </div>
      )}

      {dealModal && <DealModal key={`${dealModal.mode}-${dealModal.deal?.id ?? 'new'}-${dealModal.stage ?? ''}`} initialDeal={dealModal.deal} initialMode={dealModal.mode} defaultStage={dealModal.stage} defaultCompanyId={dealModal.companyId} crm={data} onClose={() => setDealModal(null)} onChanged={refresh} onActivity={(deal) => { setDealModal(null); setActivityModal({ deal }); }} onDeleteActivity={(id) => deleteActivity.mutate(id)} />}
      {companyModal && <CompanyModal initialCompany={companyModal.company} onClose={() => setCompanyModal(null)} onChanged={refresh} />}
      {contactModal && <ContactModal initialContact={contactModal.contact} companies={data.companies} onClose={() => setContactModal(null)} onChanged={refresh} />}
      {activityModal && <ActivityModal crm={data} defaults={activityModal} onClose={() => setActivityModal(null)} onChanged={refresh} />}
    </div>
  );
}

function ForecastList({ title, rows }: { title: string; rows: Array<{ name: string; count: number; totals: Record<string, number> }> }) {
  return (
    <div>
      <p className="mb-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">{title}</p>
      <div className="space-y-1.5">
        {rows.length === 0 && <p className="text-[11px] text-slate-400">Sin datos.</p>}
        {rows.map((row) => <div key={row.name} className="flex items-center justify-between gap-2 rounded-lg border border-slate-100 bg-slate-50/60 px-2.5 py-1.5"><span className="truncate text-[11px] font-medium text-slate-600">{row.name} <span className="text-slate-400">({row.count})</span></span><span className="flex-shrink-0 text-[10px] font-semibold text-slate-700">{formatTotals(row.totals)}</span></div>)}
      </div>
    </div>
  );
}

function FilterIcon() {
  return <svg className="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M4 6h16M7 12h10M10 18h4" strokeLinecap="round" /></svg>;
}
