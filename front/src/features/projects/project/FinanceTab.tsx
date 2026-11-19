import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDownLeft, Calculator, Download, FileText, Paperclip, Plus, Receipt, Trash2, TrendingUp, UserPlus, Wallet } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { LoadingButton, useConfirm, useToast } from '../../../components/feedback';
import { Card, EmptyState, ErrorState, Input, Select } from '../../../components/ui';
import { apiPostRoot, apiUploadRoot } from '../../../lib/api';
import { formatDate, formatMoney, formatTotals } from '../../../lib/format';
import type { ProjectInsightsData } from '../../../types';
import { ensureOk, errorMessage, FieldLabel, ModalActions, ModalShell, todayIso, useProject } from './shared';

const CURRENCIES = ['USD', 'EUR', 'MXN', 'PEN', 'COP', 'CLP', 'ARS'];

type Finances = NonNullable<ProjectInsightsData['finances']>;
type Invoice = Finances['invoices'][number];

const INVOICE_BADGE: Record<string, string> = {
  pagada: 'bg-emerald-50 text-emerald-700 border-emerald-200/60',
  parcial: 'bg-sky-50 text-sky-700 border-sky-200/60',
  pendiente: 'bg-amber-50 text-amber-700 border-amber-200/60',
  anulada: 'bg-rose-50 text-rose-700 border-rose-200/60',
};

function Metric({ label, value, hint, tone = 'text-slate-900', border = 'border-slate-200/80', bold, to }: { label: string; value: string; hint: ReactNode; tone?: string; border?: string; bold?: boolean; to?: string }) {
  const body = (
    <>
      <span className="block text-[11px] font-medium text-slate-500">{label}</span>
      <div className={`text-lg tracking-tight sm:text-xl ${bold ? 'font-bold' : 'font-semibold'} ${tone}`}>{value}</div>
      <span className="text-[10px] text-slate-400">{hint}</span>
    </>
  );
  const className = `space-y-1 rounded-2xl border bg-white p-4 shadow-2xs ${border}`;
  return to ? <Link to={to} className={`${className} transition hover:border-slate-300 hover:shadow-sm`}>{body}</Link> : <div className={className}>{body}</div>;
}

export function FinanceTab() {
  const { projectId, data, insights, refresh } = useProject();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [modal, setModal] = useState<'invoice' | 'budget' | null>(null);
  const [docsFor, setDocsFor] = useState<Invoice | null>(null);
  const finances = insights?.finances;

  const afterChange = () => {
    refresh.insights();
    void queryClient.invalidateQueries({ queryKey: ['billing'], refetchType: 'none' });
    void queryClient.invalidateQueries({ queryKey: ['finance'], refetchType: 'none' });
    void queryClient.invalidateQueries({ queryKey: ['profitability'], refetchType: 'none' });
  };

  const setStatus = useMutation({
    mutationFn: ({ invoiceId, status }: { invoiceId: number; status: string }) =>
      ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/invoices.php', { action: 'update_status', project_id: projectId, invoice_id: invoiceId, status })),
    onSuccess: () => { afterChange(); toast('Estado de la factura actualizado.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo cambiar el estado.'), 'error'),
  });

  const remove = useMutation({
    mutationFn: (invoiceId: number) => ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/invoices.php', { action: 'delete', project_id: projectId, invoice_id: invoiceId })),
    onSuccess: () => { afterChange(); toast('Factura eliminada.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo eliminar la factura.'), 'error'),
  });

  if (!finances) return <Card><EmptyState title="Finanzas no disponibles" description="Solo la agencia dueña con permisos financieros puede ver esta sección." /></Card>;

  const canManage = Boolean(finances.canManage);
  const currency = finances.currency;
  const profit = finances.profit;
  const budgetMap = profit?.budget ?? { [currency]: finances.budget };
  const collectedProject = profit?.collected[currency] ?? 0;
  const collectedPct = profit && profit.incomeProject > 0 ? Math.round((collectedProject / profit.incomeProject) * 100) : 0;
  const marginPositive = (profit?.marginProject ?? 0) >= 0;
  const marginBorder = marginPositive ? 'border-emerald-200/80' : 'border-rose-200/80';
  const marginTone = marginPositive ? 'text-emerald-600' : 'text-rose-600';
  const payables = finances.payables.filter((payable) => payable.status !== 'anulada');
  const today = todayIso();

  return (
    <section className="space-y-5">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2">
          <h2 className="text-base font-semibold text-slate-900">Finanzas</h2>
          <span className="rounded-full bg-slate-100 px-2 py-0.5 font-mono text-xs font-semibold text-slate-600">{currency}</span>
          {profit?.mixedCurrencies && <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700" title="Hay importes en más de una moneda; se muestran agrupados sin conversión">Varias monedas</span>}
        </div>
        <div className="flex flex-wrap items-center gap-2 sm:justify-end">
          <Link to={`/finance?tab=cobrar&project_id=${projectId}`} className="inline-flex items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100" title="Ver cuentas por cobrar de este proyecto"><ArrowDownLeft className="h-3.5 w-3.5" />Cuentas por cobrar</Link>
          <Link to={`/quotes?project_id=${projectId}`} className="inline-flex items-center gap-1.5 rounded-xl border border-brand-200 bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-700 transition hover:bg-brand-100" title="Ver cotizaciones de este proyecto"><FileText className="h-3.5 w-3.5" />Cotizaciones</Link>
          <Link to={`/profitability?search=${encodeURIComponent(data.project.name)}`} className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50" title="Ver esta rentabilidad en la vista consolidada"><TrendingUp className="h-3.5 w-3.5" />Rentabilidad</Link>
          {canManage && (
            <>
              <button type="button" onClick={() => setModal('budget')} className="inline-flex items-center gap-1.5 rounded-xl bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-200"><Calculator className="h-3.5 w-3.5 text-slate-500" />Costo</button>
              <button type="button" onClick={() => setModal('invoice')} className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-brand-700"><Plus className="h-3.5 w-3.5" />Nueva factura</button>
            </>
          )}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <Metric label="Presupuesto inicial" value={formatTotals(budgetMap)} hint="Planificado" />
        <Metric label="Ingresos asociados" value={profit ? formatTotals(profit.income) : formatMoney(finances.totals.paid + finances.totals.pending, currency)} hint={`${profit?.invoiceCount ?? finances.invoices.length} facturas · sin anuladas`} />
        <Metric label="Gastos directos" value={formatTotals(profit?.direct ?? {})} hint={`Pagado: ${formatTotals(profit?.directPaid ?? {})}`} tone="text-rose-600" />
        <Metric label="Pagos a colaboradores" value={formatTotals(profit?.collaborator ?? {})} hint={`Pagado: ${formatTotals(profit?.collaboratorPaid ?? {})}`} tone="text-indigo-600" />
        <Metric label="Dinero cobrado" value={profit ? formatTotals(profit.collected) : formatMoney(finances.totals.paid, currency)} hint={`${collectedPct}% de los ingresos`} tone="text-emerald-700" />
        <Metric label="Dinero pendiente" value={profit ? formatTotals(profit.pending) : formatMoney(finances.totals.pending, currency)} hint="Por cobrar" tone="text-amber-700" to={`/finance?tab=cobrar&project_id=${projectId}`} />
        <Metric label="Margen" value={formatTotals(profit?.margin ?? {})} hint="Ingresos − costos" tone={marginTone} border={marginBorder} bold />
        <Metric label="Margen sobre ingresos" value={profit?.marginPercent != null ? `${profit.marginPercent.toLocaleString('es', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%` : '—'} hint="Rentabilidad del proyecto" tone={marginTone} border={marginBorder} bold />
      </div>

      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
          <div className="flex items-center gap-2"><h3 className="text-xs font-semibold text-slate-900 sm:text-sm">Gastos directos y pagos a colaboradores</h3><span className="text-xs text-slate-400">{payables.length}</span></div>
          <div className="flex items-center gap-2">
            <Link to={`/finance?tab=pagar&project_id=${projectId}`} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50"><Wallet className="h-3.5 w-3.5" />Gestionar</Link>
            <Link to={`/finance?tab=pagar&project_id=${projectId}&kind=collaborator&new=payable`} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-2.5 py-1.5 text-[11px] font-semibold text-white transition hover:bg-brand-700"><UserPlus className="h-3.5 w-3.5" />Pago a colaborador</Link>
          </div>
        </div>
        {payables.length === 0 ? (
          <div className="p-8 text-center">
            <div className="mx-auto mb-2 flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 text-slate-400"><Receipt className="h-5 w-5" /></div>
            <p className="text-sm font-semibold text-slate-800">Sin gastos ni pagos registrados</p>
            <p className="mt-1 text-xs text-slate-500">Registra un gasto directo o un pago a colaborador para calcular el margen real.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[820px] text-left text-xs">
              <thead className="border-b border-slate-100 bg-slate-50/70 text-[11px] font-medium text-slate-500">
                <tr><th className="px-4 py-2.5">Tipo</th><th className="px-3 py-2.5">Proveedor / colaborador</th><th className="px-3 py-2.5">Concepto</th><th className="px-3 py-2.5 text-right">Importe</th><th className="px-3 py-2.5 text-right">Pagado</th><th className="px-3 py-2.5 text-right">Saldo</th><th className="px-3 py-2.5">Vencimiento</th><th className="px-3 py-2.5">Estado</th></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {payables.map((payable) => {
                  const isPaid = payable.status === 'pagada';
                  const hasPayments = payable.paid > 0;
                  const overdue = !isPaid && payable.dueDate !== null && payable.dueDate < today;
                  const label = isPaid ? 'Pagada' : hasPayments ? 'Parcial' : 'Pendiente';
                  const badge = isPaid ? 'bg-emerald-50 text-emerald-700 border-emerald-200/70' : overdue ? 'bg-rose-50 text-rose-700 border-rose-200/70' : hasPayments ? 'bg-sky-50 text-sky-700 border-sky-200/70' : 'bg-amber-50 text-amber-700 border-amber-200/70';
                  const collaborator = payable.kind === 'collaborator';
                  return (
                    <tr key={payable.id} className="transition hover:bg-slate-50/60">
                      <td className="px-4 py-2.5"><span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold ${collaborator ? 'border-indigo-200/70 bg-indigo-50 text-indigo-700' : 'border-slate-200/70 bg-slate-100 text-slate-600'}`}>{collaborator ? 'Colaborador' : 'Gasto directo'}</span></td>
                      <td className="max-w-[180px] truncate px-3 py-2.5 font-medium text-slate-800" title={payable.supplier}>{payable.supplier}</td>
                      <td className="max-w-[220px] truncate px-3 py-2.5 text-slate-600" title={payable.concept}>{payable.concept}</td>
                      <td className="whitespace-nowrap px-3 py-2.5 text-right font-semibold text-slate-900">{formatMoney(payable.amount, payable.currency || currency)}</td>
                      <td className="whitespace-nowrap px-3 py-2.5 text-right text-emerald-700">{formatMoney(payable.paid, payable.currency || currency)}</td>
                      <td className="whitespace-nowrap px-3 py-2.5 text-right text-slate-600">{formatMoney(payable.balance, payable.currency || currency)}</td>
                      <td className="whitespace-nowrap px-3 py-2.5 text-slate-500">{payable.dueDate ? formatDate(payable.dueDate) : '—'}</td>
                      <td className="whitespace-nowrap px-3 py-2.5"><span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold ${badge}`}>{label}</span></td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card className="overflow-hidden">
        <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
          <h3 className="text-xs font-semibold text-slate-900 sm:text-sm">Historial de cobros</h3>
          <span className="text-xs text-slate-400">{finances.invoices.length} facturas</span>
        </div>
        {finances.invoices.length === 0 ? (
          <div className="p-12 text-center">
            <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><Receipt className="h-6 w-6" /></div>
            <h4 className="text-sm font-semibold text-slate-800">No hay facturas registradas</h4>
            <p className="mb-4 mt-1 text-xs text-slate-500">Emite la primera factura o hito de cobro para este proyecto.</p>
            {canManage && <button type="button" onClick={() => setModal('invoice')} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs">+ Emitir factura</button>}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-xs">
              <thead><tr className="border-b border-slate-100 bg-slate-50/50 text-[11px] font-medium text-slate-500"><th className="px-4 py-3">N° factura</th><th className="px-4 py-3">Concepto / hito</th><th className="px-4 py-3">Importe</th><th className="px-4 py-3">Vencimiento</th><th className="px-4 py-3">Estado</th><th className="px-4 py-3 text-right">Acciones</th></tr></thead>
              <tbody className="divide-y divide-slate-100">
                {finances.invoices.map((invoice) => {
                  const invoiceCurrency = invoice.currency || currency;
                  return (
                    <tr key={invoice.id} className="transition hover:bg-slate-50/60">
                      <td className="px-4 py-3 font-mono font-bold text-slate-900">{invoice.number}</td>
                      <td className="px-4 py-3">
                        <div className="font-semibold text-slate-800">{invoice.concept}</div>
                        {invoice.clientName && <div className="mt-0.5 text-[11px] text-slate-500">Cliente: {invoice.clientName}</div>}
                        {invoice.notes && <div className="mt-0.5 max-w-xs truncate text-[11px] text-slate-400">{invoice.notes}</div>}
                      </td>
                      <td className="px-4 py-3 font-bold text-slate-900">
                        {formatMoney(invoice.amount, invoiceCurrency)}
                        {invoice.paid > 0 && <div className="text-[10px] font-medium text-emerald-700">Cobrado {formatMoney(invoice.paid, invoiceCurrency)}</div>}
                        {invoice.paid > 0 && invoice.balance > 0 && <div className="text-[10px] font-normal text-slate-400">Saldo {formatMoney(invoice.balance, invoiceCurrency)}</div>}
                      </td>
                      <td className="px-4 py-3 text-slate-500">
                        {invoice.dueDate ? formatDate(invoice.dueDate) : <span className="text-slate-300">-</span>}
                        {invoice.status === 'pagada' && invoice.paidAt && <div className="text-[10px] font-medium text-emerald-600">Pagado el {formatDate(invoice.paidAt.slice(0, 10))}</div>}
                      </td>
                      <td className="px-4 py-3"><span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium capitalize ${INVOICE_BADGE[invoice.displayStatus] ?? 'border-slate-200/60 bg-slate-50 text-slate-700'}`}>{invoice.displayStatus}</span></td>
                      <td className="px-4 py-3 text-right">
                        {canManage ? (
                          <div className="inline-flex items-center gap-1">
                            <button type="button" onClick={() => setDocsFor(invoice)} className="rounded-lg p-1 text-slate-400 transition hover:bg-brand-50 hover:text-brand-600" title="Documentos de la factura"><Paperclip className="h-3.5 w-3.5" /></button>
                            <select aria-label={`Estado de ${invoice.number}`} value={invoice.status} disabled={setStatus.isPending} onChange={(event) => setStatus.mutate({ invoiceId: invoice.id, status: event.target.value })} className="cursor-pointer rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 focus:ring-1 focus:ring-brand-500">
                              <option value="pendiente">Pendiente</option>
                              <option value="pagada">Pagada</option>
                              <option value="anulada">Anulada</option>
                            </select>
                            <button type="button" disabled={remove.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar factura', description: `Se eliminará ${invoice.number}. Esta acción no se puede deshacer.`, confirmLabel: 'Eliminar', tone: 'danger' })) remove.mutate(invoice.id); }} className="rounded-lg p-1 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar factura"><Trash2 className="h-3.5 w-3.5" /></button>
                          </div>
                        ) : <span className="text-[11px] italic text-slate-400">Solo lectura</span>}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {modal === 'invoice' && <InvoiceModal finances={finances} onClose={() => setModal(null)} onSaved={() => { afterChange(); setModal(null); }} />}
      {modal === 'budget' && <BudgetModal finances={finances} onClose={() => setModal(null)} onSaved={() => { afterChange(); refresh.project(); setModal(null); }} />}
      {docsFor && <InvoiceDocsModal invoice={docsFor} onClose={() => setDocsFor(null)} />}
    </section>
  );
}

function InvoiceModal({ finances, onClose, onSaved }: { finances: Finances; onClose: () => void; onSaved: () => void }) {
  const { projectId } = useProject();
  const toast = useToast();
  const create = useMutation({
    mutationFn: (body: Record<string, string>) => ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/invoices.php', { action: 'create', project_id: projectId, ...body })),
    onSuccess: (result) => { toast(result.message || 'Factura registrada.'); onSaved(); },
  });
  return (
    <ModalShell title="Emitir factura / cobro" subtitle="Hito de cobro del proyecto" onClose={onClose}>
      <form className="space-y-4 p-6" onSubmit={(event) => {
        event.preventDefault();
        const body: Record<string, string> = {};
        new FormData(event.currentTarget).forEach((value, key) => { body[key] = String(value); });
        create.mutate(body);
      }}>
        <div className="grid grid-cols-2 gap-3">
          <div><FieldLabel>N° factura</FieldLabel><Input name="invoice_number" placeholder="Auto si se omite" className="px-3.5 py-2 font-mono text-xs" /></div>
          <div><FieldLabel>Moneda</FieldLabel><Select name="currency" defaultValue={finances.currency} className="w-full px-3 py-2 font-mono">{CURRENCIES.map((code) => <option key={code} value={code}>{code}</option>)}</Select></div>
        </div>
        <div>
          <FieldLabel>Cliente / deudor</FieldLabel>
          <Input name="client_name" list="project-invoice-clients" maxLength={150} placeholder="Elige un cliente o escribe un nombre nuevo" className="px-3.5 py-2.5 text-sm" />
          <datalist id="project-invoice-clients">{(finances.clientSuggestions ?? []).map((name) => <option key={name} value={name} />)}</datalist>
          <p className="mt-1 text-[10px] text-slate-400">Sugerencias de clientes, colaboradores y contactos del CRM.</p>
        </div>
        <div><FieldLabel>Concepto o hito de cobro *</FieldLabel><Input name="concept" required placeholder="Ej: Anticipo inicial 50% o Entrega Fase 1" className="px-3.5 py-2.5 text-sm" autoFocus /></div>
        <div className="grid grid-cols-2 gap-3">
          <div><FieldLabel>Importe *</FieldLabel><Input name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00" inputMode="decimal" className="px-3.5 py-2 text-xs font-semibold" /></div>
          <div><FieldLabel>Fecha de vencimiento</FieldLabel><Input name="due_date" type="date" className="px-3 py-2 text-xs" /></div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div><FieldLabel>Estado</FieldLabel><Select name="status" defaultValue="pendiente" className="w-full px-3 py-2"><option value="pendiente">Por cobrar (pendiente)</option><option value="pagada">Pagada / cobrada</option><option value="anulada">Anulada</option></Select></div>
          <div><FieldLabel>Notas / referencia</FieldLabel><Input name="notes" placeholder="Transferencia, cheque, etc." className="px-3 py-2 text-xs" /></div>
        </div>
        {create.isError && <ErrorState message={errorMessage(create.error, 'No se pudo registrar la factura.')} />}
        <ModalActions onCancel={onClose}><LoadingButton type="submit" pending={create.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-60">Guardar factura</LoadingButton></ModalActions>
      </form>
    </ModalShell>
  );
}

function BudgetModal({ finances, onClose, onSaved }: { finances: Finances; onClose: () => void; onSaved: () => void }) {
  const { projectId } = useProject();
  const toast = useToast();
  const save = useMutation({
    mutationFn: (body: { budget: string; currency: string }) => ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/invoices.php', { action: 'update_budget', project_id: projectId, ...body })),
    onSuccess: (result) => { toast(result.message || 'Costo del proyecto actualizado.'); onSaved(); },
  });
  return (
    <ModalShell title="Costo total del proyecto" subtitle="Presupuesto planificado" onClose={onClose}>
      <form className="space-y-4 p-6" onSubmit={(event) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        save.mutate({ budget: String(form.get('budget') ?? '0'), currency: String(form.get('currency') ?? finances.currency) });
      }}>
        <div className="grid grid-cols-3 gap-3">
          <div className="col-span-2"><FieldLabel>Monto del presupuesto</FieldLabel><Input name="budget" type="number" step="0.01" min="0" required inputMode="decimal" defaultValue={finances.budget} className="px-3.5 py-2.5 text-sm font-semibold" autoFocus /></div>
          <div><FieldLabel>Moneda</FieldLabel><Select name="currency" defaultValue={finances.currency} className="w-full px-3 py-2.5 font-mono">{CURRENCIES.map((code) => <option key={code} value={code}>{code}</option>)}</Select></div>
        </div>
        <p className="text-[11px] text-slate-400">Es el costo planificado contra el que se comparan los ingresos y el margen.</p>
        {save.isError && <ErrorState message={errorMessage(save.error, 'No se pudo guardar el costo.')} />}
        <ModalActions onCancel={onClose}><LoadingButton type="submit" pending={save.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-700 disabled:opacity-60">Guardar costo</LoadingButton></ModalActions>
      </form>
    </ModalShell>
  );
}

interface InvoiceDocument { id: number; name: string; size_formatted?: string; created_at?: string; download_url: string }

function InvoiceDocsModal({ invoice, onClose }: { invoice: Invoice; onClose: () => void }) {
  const { projectId } = useProject();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [docError, setDocError] = useState('');
  const queryKey = ['invoice-docs', invoice.id];
  const docs = useQuery({
    queryKey,
    queryFn: () => ensureOk(apiPostRoot<{ success: boolean; message?: string; documents: InvoiceDocument[] }>('/api/invoices.php', { action: 'documents_list', invoice_id: invoice.id, project_id: projectId })),
  });
  const upload = useMutation({
    mutationFn: (file: File) => {
      const formData = new FormData();
      formData.set('action', 'document_upload');
      formData.set('invoice_id', String(invoice.id));
      formData.set('project_id', String(projectId));
      formData.set('file', file);
      return ensureOk(apiUploadRoot<{ success: boolean; message?: string }>('/api/invoices.php', formData));
    },
    onSuccess: () => { setDocError(''); void queryClient.invalidateQueries({ queryKey }); toast('Documento subido.'); },
    onError: (error) => setDocError(errorMessage(error, 'No se pudo subir el documento.')),
  });
  const remove = useMutation({
    mutationFn: (documentId: number) => ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/invoices.php', { action: 'document_delete', document_id: documentId, project_id: projectId })),
    onSuccess: () => { setDocError(''); void queryClient.invalidateQueries({ queryKey }); toast('Documento eliminado.'); },
    onError: (error) => setDocError(errorMessage(error, 'No se pudo eliminar el documento.')),
  });
  const list = docs.data?.documents ?? [];
  return (
    <ModalShell title="Documentos de la factura" subtitle={invoice.number} onClose={onClose}>
      <div className="space-y-3 p-6">
        {docs.isLoading && <p className="text-[11px] text-slate-400">Cargando documentos…</p>}
        {docs.isError && <ErrorState message={errorMessage(docs.error, 'No se pudieron cargar los documentos.')} />}
        {docs.data && list.length === 0 && <p className="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-center text-[11px] text-slate-400">Sin documentos todavía.</p>}
        <div className="space-y-1.5">
          {list.map((doc) => (
            <div key={doc.id} className="flex items-center gap-2 rounded-xl border border-slate-200/70 px-3 py-2">
              <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500"><FileText className="h-4 w-4" /></span>
              <div className="min-w-0 flex-1"><p className="truncate text-xs font-semibold text-slate-800">{doc.name}</p><p className="text-[10px] text-slate-400">{[doc.size_formatted, doc.created_at].filter(Boolean).join(' · ')}</p></div>
              <a href={doc.download_url} className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600" title="Descargar"><Download className="h-4 w-4" /></a>
              <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar documento', description: 'El archivo se quitará de esta factura.', confirmLabel: 'Eliminar', tone: 'danger' })) remove.mutate(doc.id); }} className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Eliminar documento"><Trash2 className="h-4 w-4" /></button>
            </div>
          ))}
        </div>
        <form className="space-y-2 border-t border-slate-100 pt-3" onSubmit={(event) => {
          event.preventDefault();
          const file = (event.currentTarget.elements.namedItem('file') as HTMLInputElement | null)?.files?.[0];
          if (!file) { setDocError('Selecciona un archivo para subir.'); return; }
          event.currentTarget.reset();
          upload.mutate(file);
        }}>
          <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
            <input type="file" name="file" required className="block w-full flex-1 rounded-xl border border-slate-200 px-2 py-1.5 text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-brand-700 hover:file:bg-brand-100" />
            <LoadingButton type="submit" pending={upload.isPending} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60">Subir documento</LoadingButton>
          </div>
          {docError && <p className="text-[11px] text-rose-600">{docError}</p>}
        </form>
      </div>
    </ModalShell>
  );
}
