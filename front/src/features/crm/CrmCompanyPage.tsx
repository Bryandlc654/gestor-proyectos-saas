import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Building2, Calendar, CheckSquare, Circle, Download, Eye, FilePlus, FileText, FolderOpen, FolderPlus, Globe, Mail, MapPin, Phone, Plus, StickyNote, Target, UserCheck, UserRound, Users } from 'lucide-react';
import { useNavigate, useParams, Link } from 'react-router-dom';
import { useConfirm, useToast } from '../../components/feedback';
import { ErrorState, Skeleton } from '../../components/ui';
import { apiGet, apiPostRoot } from '../../lib/api';
import { formatDate, formatMoney, formatTotals } from '../../lib/format';
import type { CrmCompanyHistory, CrmCompanyQuote } from '../../types';

const STAGE_COLORS: Record<string, string> = {
  nuevo: 'bg-slate-100 text-slate-700 border-slate-200',
  contactado: 'bg-sky-50 text-sky-700 border-sky-200',
  propuesta: 'bg-brand-50 text-brand-700 border-brand-200',
  negociacion: 'bg-navy-50 text-navy-800 border-navy-200',
  ganado: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  perdido: 'bg-rose-50 text-rose-700 border-rose-200',
};

const QUOTE_BADGE: Record<string, string> = {
  enviada: 'bg-blue-50 text-blue-700 border-blue-200/80',
  aceptada: 'bg-emerald-50 text-emerald-700 border-emerald-200/80',
  rechazada: 'bg-rose-50 text-rose-700 border-rose-200/80',
};

const ACTIVITY_ICONS: Record<string, typeof Phone> = { llamada: Phone, correo: Mail, reunion: Users, nota: StickyNote, tarea: CheckSquare };

function formatDateTime(value: string): string {
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('es-ES', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function addTo(map: Record<string, number>, currency: string, amount: number) {
  if (Math.abs(amount) < 0.005) return;
  const code = (currency || 'USD').toUpperCase();
  map[code] = Math.round(((map[code] ?? 0) + amount) * 100) / 100;
}

function quoteTotals(quotes: CrmCompanyQuote[]): Record<string, number> {
  const totals: Record<string, number> = {};
  quotes.forEach((quote) => addTo(totals, quote.currency, quote.total));
  return totals;
}

function Kpi({ label, value, hint, tone = 'text-slate-900' }: { label: string; value: string; hint: string; tone?: string }) {
  return (
    <div className="space-y-1 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-2xs">
      <span className="text-[11px] font-medium text-slate-500">{label}</span>
      <div className={`text-lg font-bold ${tone}`}>{value}</div>
      <span className="text-[10px] text-slate-400">{hint}</span>
    </div>
  );
}

export function CrmCompanyPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const { data, isLoading, error } = useQuery({ queryKey: ['crm-company', id], queryFn: () => apiGet<CrmCompanyHistory>('crm-company.php', { id }), enabled: Boolean(id) });

  const convert = useMutation({
    mutationFn: (dealId: number) => apiPostRoot<{ success: boolean; project_id: number; message: string }>('/api/crm.php', { action: 'deal_convert', id: dealId }),
    onSuccess: (result) => {
      if (!result.success) { toast(result.message || 'No se pudo convertir la oportunidad.', 'error'); return; }
      toast(result.message);
      void queryClient.invalidateQueries({ queryKey: ['crm'] });
      void queryClient.invalidateQueries({ queryKey: ['crm-company'] });
      void queryClient.invalidateQueries({ queryKey: ['projects'], refetchType: 'none' });
      navigate(`/projects/${result.project_id}`);
    },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo convertir la oportunidad.', 'error'),
  });

  if (isLoading) return <div className="space-y-4"><Skeleton className="h-12 w-72" /><Skeleton className="h-28" /><Skeleton className="h-96" /></div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar el historial.'} />;

  const { company, contacts, deals, quotes, activities } = data;
  const openTotals: Record<string, number> = {};
  const wonTotals: Record<string, number> = {};
  const acceptedTotals: Record<string, number> = {};
  let openDeals = 0;
  let wonDeals = 0;
  let lostDeals = 0;
  let projectCount = 0;
  deals.forEach((deal) => {
    if (deal.stage === 'ganado') {
      wonDeals++;
      addTo(wonTotals, deal.currency, deal.value);
      if (deal.convertedProjectId) projectCount++;
    } else if (deal.stage === 'perdido') {
      lostDeals++;
    } else {
      openDeals++;
      addTo(openTotals, deal.currency, deal.value);
    }
  });
  quotes.filter((quote) => quote.status === 'aceptada').forEach((quote) => addTo(acceptedTotals, quote.currency, quote.total));
  const newDealUrl = `/crm?new=deal&company_id=${company.id}`;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <Link to="/crm?tab=companies" className="-ml-1.5 flex-shrink-0 rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-900" title="Volver al CRM"><ArrowLeft className="h-5 w-5" /></Link>
          <div className="min-w-0">
            <h1 className="truncate text-base font-semibold tracking-tight text-slate-900 sm:text-lg">{company.name}</h1>
            <p className="hidden text-[11px] text-slate-400 sm:block">Historial comercial del cliente</p>
          </div>
        </div>
        <Link to={newDealUrl} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 sm:text-sm"><Plus className="h-4 w-4" /><span>Nueva oportunidad</span></Link>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-5">
        <Kpi label="Pipeline abierto" value={formatTotals(openTotals)} hint={`${openDeals} oportunidades`} />
        <Kpi label="Ganado" value={formatTotals(wonTotals)} hint={`${wonDeals} ganadas · ${lostDeals} perdidas`} tone="text-emerald-600" />
        <Kpi label="Propuestas" value={formatTotals(quoteTotals(quotes))} hint={`${quotes.length} cotizaciones`} tone="text-brand-600" />
        <Kpi label="Aceptado" value={formatTotals(acceptedTotals)} hint="Propuestas aceptadas" tone="text-emerald-600" />
        <Kpi label="Proyectos" value={String(projectCount)} hint="Convertidos desde oportunidades" />
      </div>

      <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <div className="min-w-0 space-y-6">
          <section className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs sm:p-6">
            <div className="flex items-start gap-3">
              <div className="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-navy-50 text-navy-700"><Building2 className="h-5 w-5" /></div>
              <div className="min-w-0">
                <h2 className="text-base font-semibold text-slate-900">{company.name}</h2>
                <p className="text-xs text-slate-500">{company.industry || 'Sin sector definido'}</p>
                <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1.5 text-xs text-slate-500">
                  {company.website && <a href={company.website} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 hover:text-brand-600"><Globe className="h-3.5 w-3.5" />{company.website}</a>}
                  {company.email && <a href={`mailto:${company.email}`} className="inline-flex items-center gap-1.5 hover:text-brand-600"><Mail className="h-3.5 w-3.5" />{company.email}</a>}
                  {company.phone && <span className="inline-flex items-center gap-1.5"><Phone className="h-3.5 w-3.5" />{company.phone}</span>}
                  {company.address && <span className="inline-flex items-center gap-1.5"><MapPin className="h-3.5 w-3.5" />{company.address}</span>}
                </div>
                {company.notes && <p className="mt-3 whitespace-pre-line text-xs text-slate-500">{company.notes}</p>}
              </div>
            </div>
          </section>

          <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
            <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
              <div>
                <h2 className="text-sm font-semibold text-slate-800">Oportunidades y propuestas</h2>
                <p className="mt-0.5 text-[11px] text-slate-400">Historial de negociaciones y cotizaciones del cliente.</p>
              </div>
              <Link to={newDealUrl} className="inline-flex items-center gap-1.5 rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700 transition hover:bg-brand-100"><Plus className="h-3.5 w-3.5" />Oportunidad</Link>
            </div>

            {deals.length === 0 ? (
              <div className="p-10 text-center">
                <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 text-slate-400"><Target className="h-5 w-5" /></div>
                <p className="mt-3 text-sm font-semibold text-slate-800">Sin oportunidades todavía</p>
                <p className="mt-1 text-xs text-slate-500">Crea la primera oportunidad para empezar el historial comercial.</p>
              </div>
            ) : (
              <div className="divide-y divide-slate-100">
                {deals.map((deal) => (
                  <article key={deal.id} className="space-y-3 p-5">
                    <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <h3 className="font-semibold text-slate-900">{deal.title}</h3>
                          <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold ${STAGE_COLORS[deal.stage] ?? STAGE_COLORS.nuevo}`}>{deal.stageLabel}</span>
                        </div>
                        <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-500">
                          {deal.contact && <span className="inline-flex items-center gap-1"><UserRound className="h-3 w-3" />{deal.contact}</span>}
                          {deal.owner && <span className="inline-flex items-center gap-1"><UserCheck className="h-3 w-3" />{deal.owner}</span>}
                          {deal.expectedClose && <span className="inline-flex items-center gap-1"><Calendar className="h-3 w-3" />Cierre: {formatDate(deal.expectedClose)}</span>}
                        </div>
                      </div>
                      <div className="flex-shrink-0 sm:text-right">
                        <p className="font-semibold text-slate-900">{formatMoney(deal.value, deal.currency)}</p>
                        <p className="mt-0.5 text-[10px] text-slate-400">Valor estimado</p>
                        {deal.convertedProjectId ? (
                          <Link to={`/projects/${deal.convertedProjectId}`} className="mt-1 inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-700 hover:text-emerald-800"><FolderOpen className="h-3 w-3" />{deal.convertedProjectName ?? `Proyecto #${deal.convertedProjectId}`}</Link>
                        ) : deal.stage === 'ganado' ? (
                          <button type="button" disabled={convert.isPending} onClick={async () => { if (await confirm({ title: 'Convertir en proyecto', description: `Se creará un proyecto a partir de "${deal.title}" usando el valor estimado como presupuesto inicial.`, confirmLabel: 'Convertir' })) convert.mutate(deal.id); }} className="mt-1 inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-700 hover:text-emerald-800 disabled:opacity-60"><FolderPlus className="h-3 w-3" />Convertir en proyecto</button>
                        ) : null}
                      </div>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200/70 bg-slate-50 px-3.5 py-2.5">
                      <div className="flex items-center gap-2 text-[11px] text-slate-500">
                        <FileText className="h-3.5 w-3.5 text-brand-500" />
                        {deal.quotes.length === 0 ? 'Sin propuestas registradas' : `${deal.quotes.length} ${deal.quotes.length === 1 ? 'propuesta' : 'propuestas'} · ${formatTotals(quoteTotals(deal.quotes))}`}
                      </div>
                      <Link to={`/quotes/new?deal_id=${deal.id}`} className="inline-flex items-center gap-1.5 rounded-lg border border-brand-200 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-brand-700 transition hover:bg-brand-50"><FilePlus className="h-3.5 w-3.5" />Nueva propuesta</Link>
                    </div>

                    {deal.quotes.length > 0 && (
                      <div className="overflow-x-auto">
                        <table className="w-full text-xs">
                          <thead className="text-[10px] uppercase tracking-wide text-slate-400">
                            <tr><th className="py-1.5 pr-3 text-left font-semibold">Propuesta</th><th className="px-3 py-1.5 text-left font-semibold">Estado</th><th className="px-3 py-1.5 text-right font-semibold">Total</th><th className="hidden px-3 py-1.5 text-left font-semibold sm:table-cell">Fecha</th><th className="py-1.5 pl-3 text-right font-semibold">Ver</th></tr>
                          </thead>
                          <tbody className="divide-y divide-slate-100">
                            {deal.quotes.map((quote) => (
                              <tr key={quote.id} className="hover:bg-slate-50/60">
                                <td className="py-2 pr-3">
                                  <Link to={`/quotes/${quote.id}`} className="font-mono text-[10px] font-bold text-brand-600 hover:text-brand-700">{quote.number}</Link>
                                  <div className="max-w-[260px] truncate text-slate-600">{quote.title}</div>
                                </td>
                                <td className="px-3 py-2"><span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[9px] font-semibold ${QUOTE_BADGE[quote.status] ?? 'bg-slate-100 text-slate-600 border-slate-200/80'}`}>{quote.statusLabel}</span></td>
                                <td className="whitespace-nowrap px-3 py-2 text-right font-semibold text-slate-800">{formatMoney(quote.total, quote.currency)}</td>
                                <td className="hidden whitespace-nowrap px-3 py-2 text-slate-400 sm:table-cell">{formatDate(quote.issueDate)}</td>
                                <td className="whitespace-nowrap py-2 pl-3 text-right">
                                  <Link to={`/quotes/${quote.id}`} className="inline-flex rounded p-1 text-slate-400 hover:bg-brand-50 hover:text-brand-600" title="Ver propuesta"><Eye className="h-3.5 w-3.5" /></Link>
                                  <a href={`/quote-pdf?id=${quote.id}`} download className="inline-flex rounded p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Descargar PDF"><Download className="h-3.5 w-3.5" /></a>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </article>
                ))}
              </div>
            )}
          </section>

          <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
            <div className="border-b border-slate-100 px-5 py-4">
              <h2 className="text-sm font-semibold text-slate-800">Actividades recientes</h2>
              <p className="mt-0.5 text-[11px] text-slate-400">Llamadas, correos, reuniones y notas del cliente.</p>
            </div>
            {activities.length === 0 ? <p className="px-5 py-8 text-center text-sm text-slate-400">Sin actividades registradas.</p> : (
              <div className="divide-y divide-slate-100">
                {activities.map((activity) => {
                  const Icon = ACTIVITY_ICONS[activity.type] ?? Circle;
                  return (
                    <div key={activity.id} className="flex items-start gap-3 px-5 py-3.5">
                      <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500"><Icon className="h-4 w-4" /></div>
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="text-sm font-medium text-slate-800">{activity.title}</p>
                          <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500">{activity.typeLabel}</span>
                        </div>
                        {activity.notes && <p className="mt-0.5 whitespace-pre-line text-xs text-slate-500">{activity.notes}</p>}
                        <p className="mt-1 text-[10px] text-slate-400">
                          {formatDateTime(activity.occurredAt)}
                          {activity.user ? ` · ${activity.user}` : ''}
                          {activity.dealTitle ? ` · ${activity.dealTitle}` : ''}
                        </p>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </section>
        </div>

        <aside className="space-y-4">
          <div className="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs">
            <div className="flex items-center justify-between gap-2">
              <h2 className="text-sm font-semibold text-slate-900">Contactos</h2>
              <Link to="/crm?tab=contacts" className="text-[11px] font-semibold text-brand-600 hover:text-brand-700">Gestionar</Link>
            </div>
            {contacts.length === 0 ? <p className="text-xs text-slate-400">Sin contactos asociados.</p> : (
              <div className="space-y-2">
                {contacts.map((contact) => (
                  <div key={contact.id} className="rounded-xl border border-slate-200/70 bg-slate-50 px-3 py-2.5">
                    <p className="text-xs font-semibold text-slate-800">{contact.name}</p>
                    {contact.role && <p className="text-[10px] text-slate-400">{contact.role}</p>}
                    {contact.email && <a href={`mailto:${contact.email}`} className="mt-0.5 block truncate text-[11px] text-brand-600 hover:underline">{contact.email}</a>}
                    {contact.phone && <p className="mt-0.5 text-[10px] text-slate-500">{contact.phone}</p>}
                  </div>
                ))}
              </div>
            )}
          </div>

          <div className="space-y-2 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs">
            <h2 className="text-sm font-semibold text-slate-900">Resumen</h2>
            {[
              ['Oportunidades', String(deals.length), 'text-slate-800'],
              ['Ganadas', String(wonDeals), 'text-emerald-600'],
              ['Perdidas', String(lostDeals), 'text-rose-600'],
              ['Propuestas', String(quotes.length), 'text-brand-600'],
              ['Proyectos', String(projectCount), 'text-slate-800'],
            ].map(([label, value, tone]) => (
              <div key={label} className="flex items-center justify-between gap-2 text-xs"><span className="text-slate-500">{label}</span><span className={`font-semibold ${tone}`}>{value}</span></div>
            ))}
          </div>
        </aside>
      </div>
    </div>
  );
}
