import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle,
  ArrowDownLeft,
  ArrowRight,
  ArrowUpRight,
  Banknote,
  Building2,
  Calendar,
  CheckCircle2,
  ChevronDown,
  Clock,
  ExternalLink,
  History,
  Pencil,
  Plus,
  Receipt,
  RotateCcw,
  Search,
  Trash2,
  Wallet,
  X,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Card, ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { useConfirm, useDialog, useToast, LoadingButton } from '../../components/feedback';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate, formatMoney, formatTotals } from '../../lib/format';
import type { FinanceData } from '../../types';

const INVOICE_STATUS_META: Record<string, { label: string; className: string }> = {
  pendiente: { label: 'Pendiente', className: 'bg-amber-50 text-amber-700 border-amber-200' },
  parcial: { label: 'Parcial', className: 'bg-sky-50 text-sky-700 border-sky-200' },
  pagada: { label: 'Cobrada', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  anulada: { label: 'Anulada', className: 'bg-slate-100 text-slate-500 border-slate-200' },
};

const PAYABLE_STATUS_META: Record<string, { label: string; className: string }> = {
  pendiente: { label: 'Pendiente', className: 'bg-amber-50 text-amber-700 border-amber-200' },
  parcial: { label: 'Parcial', className: 'bg-sky-50 text-sky-700 border-sky-200' },
  pagada: { label: 'Pagada', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  anulada: { label: 'Anulada', className: 'bg-slate-100 text-slate-500 border-slate-200' },
};

const PAYABLE_KIND_META: Record<string, { label: string; className: string }> = {
  direct: { label: 'Gasto directo', className: 'bg-slate-100 text-slate-600 border-slate-200' },
  collaborator: { label: 'Colaborador', className: 'bg-indigo-50 text-indigo-700 border-indigo-200' },
};

const PAYMENT_METHODS = ['Transferencia', 'Efectivo', 'Tarjeta', 'Cheque', 'Otro'];

export function FinancePage() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const tab = searchParams.get('tab') === 'pagar' ? 'pagar' : 'cobrar';

  const [search, setSearch] = useState(() => searchParams.get('search') ?? '');
  const [status, setStatus] = useState('todas');
  const [projectId, setProjectId] = useState(() => searchParams.get('project_id') ?? '');
  const deepLinkPayable = searchParams.get('new') === 'payable';
  const [page, setPage] = useState(1);

  const [receivableOpen, setReceivableOpen] = useState(false);
  const [receivableCurrency, setReceivableCurrency] = useState('USD');
  const [payableOpen, setPayableOpen] = useState(deepLinkPayable);
  const [editingPayable, setEditingPayable] = useState<FinanceData['payables'][number] | null>(null);
  const [payableKind, setPayableKind] = useState(() => (deepLinkPayable && searchParams.get('kind') === 'collaborator' ? 'collaborator' : 'direct'));
  const [payableCurrency, setPayableCurrency] = useState('USD');
  const [paymentFor, setPaymentFor] = useState<FinanceData['payables'][number] | null>(null);
  const [invoicePaymentFor, setInvoicePaymentFor] = useState<FinanceData['receivables'][number] | null>(null);
  const [expandedInvoice, setExpandedInvoice] = useState<number | null>(null);

  const confirm = useConfirm();
  const toast = useToast();
  const receivableRef = useDialog(receivableOpen, () => setReceivableOpen(false));
  const payableRef = useDialog(payableOpen, () => { setPayableOpen(false); setEditingPayable(null); });
  const paymentRef = useDialog(paymentFor !== null, () => setPaymentFor(null));
  const invoicePaymentRef = useDialog(invoicePaymentFor !== null, () => setInvoicePaymentFor(null));

  const { data, isLoading, error } = useQuery({
    queryKey: ['finance', tab, status, search, projectId, page],
    queryFn: () => apiGet<FinanceData>('finance.php', { tab, status, search, project_id: projectId, page }),
  });

  const refreshAll = () => {
    void queryClient.invalidateQueries({ queryKey: ['finance'] });
    void queryClient.invalidateQueries({ queryKey: ['billing'] });
    void queryClient.invalidateQueries({ queryKey: ['cash'] });
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
  };

  const createReceivable = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('finance.php', { action: 'create_receivable', ...body }),
    onSuccess: () => { setReceivableOpen(false); refreshAll(); toast('Cuenta por cobrar creada.'); },
  });

  const savePayable = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('finance.php', body),
    onSuccess: () => { setPayableOpen(false); setEditingPayable(null); refreshAll(); toast('Cuenta por pagar guardada.'); },
  });

  const deletePayable = useMutation({
    mutationFn: (payableId: number) => apiPost<{ success: boolean }>('finance.php', { action: 'delete_payable', payable_id: payableId }),
    onSuccess: () => { refreshAll(); toast('Cuenta por pagar eliminada.'); },
  });

  const addPayment = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('finance.php', { action: 'add_payment', ...body }),
    onSuccess: () => { setPaymentFor(null); refreshAll(); toast('Pago registrado.'); },
  });

  const deletePayment = useMutation({
    mutationFn: (paymentId: number) => apiPost<{ success: boolean }>('finance.php', { action: 'delete_payment', payment_id: paymentId }),
    onSuccess: () => { refreshAll(); toast('Pago eliminado.'); },
  });

  const addInvoicePayment = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('finance.php', { action: 'add_invoice_payment', ...body }),
    onSuccess: () => { setInvoicePaymentFor(null); refreshAll(); toast('Cobro registrado.'); },
  });

  const deleteInvoicePayment = useMutation({
    mutationFn: (paymentId: number) => apiPost<{ success: boolean }>('finance.php', { action: 'delete_invoice_payment', payment_id: paymentId }),
    onSuccess: () => { refreshAll(); toast('Cobro eliminado.'); },
  });

  const changeInvoiceStatus = useMutation({
    mutationFn: ({ invoiceId, action }: { invoiceId: number; action: 'invoice_paid' | 'invoice_pending' }) => apiPost<{ success: boolean }>('finance.php', { action, invoice_id: invoiceId }),
    onSuccess: () => { refreshAll(); toast('Factura actualizada.'); },
  });

  if (isLoading) return <div className="space-y-4">{Array.from({ length: 5 }).map((_, index) => <Skeleton key={index} className="h-24" />)}</div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudieron cargar los cobros y pagos.'} />;

  const filtered = search !== '' || status !== 'todas' || projectId !== '';
  const stats = data.stats;
  const pagination = data.pagination;
  const windowPages = Array.from(new Set([1, pagination.page - 1, pagination.page, pagination.page + 1, pagination.pages]))
    .filter((value) => value >= 1 && value <= pagination.pages)
    .sort((a, b) => a - b);

  const switchTab = (next: 'cobrar' | 'pagar') => {
    setSearch('');
    setStatus('todas');
    setPage(1);
    const params = new URLSearchParams();
    params.set('tab', next);
    if (projectId !== '' && projectId !== '0') params.set('project_id', projectId);
    setSearchParams(params);
  };

  const openEditPayable = (payable: FinanceData['payables'][number]) => {
    setEditingPayable(payable);
    setPayableKind(payable.kind || 'direct');
    setPayableCurrency(payable.currency || 'USD');
    setPayableOpen(true);
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Cobros y pagos</h1>
          <p className="hidden text-[11px] text-slate-400 sm:block">Cuentas por cobrar y por pagar de la agencia</p>
        </div>
        {tab === 'cobrar' ? (
          <button type="button" onClick={() => { setReceivableCurrency('USD'); setReceivableOpen(true); }} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 sm:text-sm">
            <Plus className="h-4 w-4" />
            <span className="hidden sm:inline">Nueva cuenta por cobrar</span>
            <span className="sm:hidden">Nueva</span>
          </button>
        ) : (
          <button type="button" onClick={() => { setEditingPayable(null); setPayableKind('direct'); setPayableCurrency('USD'); setPayableOpen(true); }} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 sm:text-sm">
            <Plus className="h-4 w-4" />
            <span className="hidden sm:inline">Nueva cuenta por pagar</span>
            <span className="sm:hidden">Nueva</span>
          </button>
        )}
      </div>

      {tab === 'cobrar' && stats.receivables.countOverdue > 0 && (
        <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
          <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
          <span>Tienes {stats.receivables.countOverdue} factura{stats.receivables.countOverdue === 1 ? '' : 's'} vencida{stats.receivables.countOverdue === 1 ? '' : 's'} por cobrar.</span>
        </div>
      )}
      {tab === 'pagar' && stats.payables.countOverdue > 0 && (
        <div className="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700">
          <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
          <span>Tienes {stats.payables.countOverdue} cuenta{stats.payables.countOverdue === 1 ? '' : 's'} por pagar vencida{stats.payables.countOverdue === 1 ? '' : 's'}.</span>
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card className="p-4 sm:p-5">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-medium text-slate-500">Por cobrar</span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600"><ArrowDownLeft className="h-4 w-4" /></span>
          </div>
          <p className="mt-2 text-lg font-semibold tracking-tight text-slate-900 sm:text-xl">{formatTotals(stats.receivables.pending)}</p>
          <p className="mt-0.5 text-[11px] text-slate-400">{stats.receivables.countPending} factura{stats.receivables.countPending === 1 ? '' : 's'} pendiente{stats.receivables.countPending === 1 ? '' : 's'}</p>
        </Card>
        <Card className="p-4 sm:p-5">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-medium text-slate-500">Vencido por cobrar</span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600"><Clock className="h-4 w-4" /></span>
          </div>
          <p className="mt-2 text-lg font-semibold tracking-tight text-slate-900 sm:text-xl">{formatTotals(stats.receivables.overdue)}</p>
          <p className="mt-0.5 text-[11px] text-slate-400">{stats.receivables.countOverdue} vencida{stats.receivables.countOverdue === 1 ? '' : 's'}</p>
        </Card>
        <Card className="p-4 sm:p-5">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-medium text-slate-500">Por pagar</span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600"><ArrowUpRight className="h-4 w-4" /></span>
          </div>
          <p className="mt-2 text-lg font-semibold tracking-tight text-slate-900 sm:text-xl">{formatTotals(stats.payables.outstanding)}</p>
          <p className="mt-0.5 text-[11px] text-slate-400">{stats.payables.countOpen} cuenta{stats.payables.countOpen === 1 ? '' : 's'} pendiente{stats.payables.countOpen === 1 ? '' : 's'}</p>
        </Card>
        <Card className="p-4 sm:p-5">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-medium text-slate-500">Vencido por pagar</span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600"><AlertTriangle className="h-4 w-4" /></span>
          </div>
          <p className="mt-2 text-lg font-semibold tracking-tight text-slate-900 sm:text-xl">{formatTotals(stats.payables.overdue)}</p>
          <p className="mt-0.5 text-[11px] text-slate-400">{stats.payables.countOverdue} vencida{stats.payables.countOverdue === 1 ? '' : 's'}</p>
        </Card>
      </div>

      <div className="flex w-fit items-center gap-1.5 rounded-xl bg-slate-100/80 p-1">
        <button type="button" onClick={() => switchTab('cobrar')} className={`inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-xs font-semibold transition ${tab === 'cobrar' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}>
          <ArrowDownLeft className="h-3.5 w-3.5" />
          Por cobrar
          <span className="rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">{stats.receivables.countPending}</span>
        </button>
        <button type="button" onClick={() => switchTab('pagar')} className={`inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-xs font-semibold transition ${tab === 'pagar' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}>
          <ArrowUpRight className="h-3.5 w-3.5" />
          Por pagar
          <span className="rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold text-rose-700">{stats.payables.countOpen}</span>
        </button>
      </div>

      <form className="flex w-full flex-col items-center gap-3 md:flex-row" onSubmit={(event) => event.preventDefault()}>
        <div className="relative w-full flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder={tab === 'cobrar' ? 'Buscar factura, cliente, concepto o proyecto...' : 'Buscar proveedor, concepto o proyecto...'} className="pl-10 text-xs sm:text-sm" />
        </div>
        <Select value={projectId} onChange={(event) => { setProjectId(event.target.value); setPage(1); }} className="w-full max-w-[210px] truncate">
          <option value="">Todos los proyectos</option>
          {data.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
        </Select>
        <Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }} className="w-full md:w-auto">
          <option value="todas">Todos los estados</option>
          <option value="pendientes">Pendientes</option>
          <option value="parciales">Parciales</option>
          <option value="vencidas">Vencidas</option>
          <option value="pagadas">{tab === 'cobrar' ? 'Cobradas' : 'Pagadas'}</option>
          {tab === 'cobrar' && <option value="anuladas">Anuladas</option>}
        </Select>
        <button type="submit" className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 md:w-auto">Filtrar</button>
        {filtered && <button type="button" onClick={() => { setSearch(''); setStatus('todas'); setProjectId(''); setPage(1); }} className="whitespace-nowrap rounded-xl px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">Limpiar</button>}
      </form>

      {tab === 'cobrar' ? (
        <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
          <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
            <div>
              <h2 className="text-sm font-semibold text-slate-800">Facturas por cobrar</h2>
              <p className="mt-0.5 text-[11px] text-slate-400">{data.counts.receivables} facturas registradas · {formatTotals(stats.receivables.paid)} cobrado</p>
            </div>
            <Link to="/billing" className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 transition hover:text-brand-800">
              Ir a facturación <ArrowRight className="h-3.5 w-3.5" />
            </Link>
          </div>

          {data.receivables.length === 0 ? (
            <div className="p-12 text-center">
              <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600"><CheckCircle2 className="h-6 w-6" /></div>
              <h3 className="text-sm font-semibold text-slate-800">Sin facturas en esta vista</h3>
              <p className="mt-1 text-xs text-slate-500">Crea una cuenta por cobrar o revisa los filtros.</p>
              <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
                <button type="button" onClick={() => setReceivableOpen(true)} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-brand-700"><Plus className="h-3.5 w-3.5" /> Nueva cuenta por cobrar</button>
                <Link to="/billing" className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50">Ir a facturación</Link>
              </div>
            </div>
          ) : (
            <>
              <div className="hidden overflow-x-auto md:block">
                <table className="w-full text-left text-xs text-slate-600">
                  <thead className="border-b border-slate-100 bg-slate-50/70 text-[11px] font-medium text-slate-500">
                    <tr>
                      <th className="px-4 py-3">Factura</th>
                      <th className="px-4 py-3">Proyecto</th>
                      <th className="px-4 py-3">Cliente / Deudor</th>
                      <th className="px-4 py-3">Concepto</th>
                      <th className="px-4 py-3">Vencimiento</th>
                      <th className="px-4 py-3 text-right">Importe</th>
                      <th className="px-4 py-3 text-right">Cobrado</th>
                      <th className="px-4 py-3 text-right">Saldo</th>
                      <th className="px-4 py-3">Estado</th>
                      <th className="px-4 py-3 text-right">Acciones</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {data.receivables.map((invoice) => {
                      const displayStatus = invoice.displayStatus ?? (invoice.status === 'pagada' ? 'pagada' : invoice.paid > 0 ? 'parcial' : invoice.status);
                      const meta = INVOICE_STATUS_META[displayStatus] ?? INVOICE_STATUS_META.pendiente;
                      const balance = Math.max(0, invoice.balance);
                      return (
                        <Fragment key={invoice.id}>
                          <tr className="transition hover:bg-slate-50/60">
                            <td className="whitespace-nowrap px-4 py-3 font-semibold text-slate-800">{invoice.number}</td>
                            <td className="whitespace-nowrap px-4 py-3">
                              {invoice.projectId ? (
                                <Link to={`/projects/${invoice.projectId}?tab=finanzas`} className="inline-flex items-center gap-1.5 font-medium text-slate-700 transition hover:text-brand-600">
                                  <span className="h-2 w-2 rounded-full" style={{ backgroundColor: invoice.projectColor }} />
                                  {invoice.projectName}
                                </Link>
                              ) : (
                                <span className="inline-flex items-center gap-1.5 text-slate-500"><Wallet className="h-3 w-3 text-slate-400" /> Sin proyecto</span>
                              )}
                            </td>
                            <td className="max-w-[200px] truncate px-4 py-3" title={invoice.client ?? ''}>{invoice.client || <span className="text-slate-300">—</span>}</td>
                            <td className="max-w-[240px] truncate px-4 py-3" title={invoice.concept}>{invoice.concept}</td>
                            <td className="whitespace-nowrap px-4 py-3">
                              {invoice.dueDate ? (
                                <>
                                  <span className="text-slate-600">{formatDate(invoice.dueDate)}</span>
                                  {invoice.overdue && <span className="ml-1 text-[10px] font-semibold text-rose-600">· {invoice.daysOverdue}d</span>}
                                </>
                              ) : (
                                <span className="text-slate-400">—</span>
                              )}
                            </td>
                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-800">{formatMoney(invoice.amount, invoice.currency)}</td>
                            <td className="whitespace-nowrap px-4 py-3 text-right font-semibold text-emerald-700">{invoice.paid > 0 ? formatMoney(invoice.paid, invoice.currency) : <span className="text-slate-300">—</span>}</td>
                            <td className={`whitespace-nowrap px-4 py-3 text-right font-semibold ${balance > 0 ? 'text-slate-800' : 'text-slate-300'}`}>{balance > 0 ? formatMoney(balance, invoice.currency) : '—'}</td>
                            <td className="whitespace-nowrap px-4 py-3">
                              <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold ${invoice.overdue ? 'bg-rose-50 text-rose-700 border-rose-200' : meta.className}`}>{invoice.overdue ? 'Vencida' : meta.label}</span>
                            </td>
                            <td className="whitespace-nowrap px-4 py-3 text-right">
                              <div className="inline-flex items-center justify-end gap-1.5">
                                {invoice.projectId && (
                                  <Link to={`/projects/${invoice.projectId}?tab=finanzas`} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-brand-50 hover:text-brand-600" title="Ver en el proyecto"><ExternalLink className="h-3.5 w-3.5" /></Link>
                                )}
                                {invoice.status !== 'anulada' && balance > 0 && (
                                  <button type="button" onClick={() => setInvoicePaymentFor(invoice)} className="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-[11px] font-semibold text-emerald-700 transition hover:bg-emerald-100" title="Registrar un cobro total o parcial"><Banknote className="h-3 w-3" /> Cobrar</button>
                                )}
                                {invoice.status === 'pagada' && (
                                  <button type="button" disabled={changeInvoiceStatus.isPending} onClick={async () => { if (await confirm({ title: 'Reabrir factura', description: 'Se eliminarán todos los cobros registrados y la factura volverá a pendiente.', confirmLabel: 'Reabrir', tone: 'danger' })) changeInvoiceStatus.mutate({ invoiceId: invoice.id, action: 'invoice_pending' }); }} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50" title="Volver a pendiente"><RotateCcw className="h-3 w-3" /> Reabrir</button>
                                )}
                                {invoice.payments.length > 0 && (
                                  <button type="button" onClick={() => setExpandedInvoice(expandedInvoice === invoice.id ? null : invoice.id)} className="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1.5 text-[11px] font-semibold text-slate-500 transition hover:bg-slate-50" title="Ver historial de cobros">
                                    <History className="h-3 w-3" /> {invoice.payments.length}
                                    <ChevronDown className={`h-3 w-3 transition-transform ${expandedInvoice === invoice.id ? 'rotate-180' : ''}`} />
                                  </button>
                                )}
                              </div>
                            </td>
                          </tr>
                          {expandedInvoice === invoice.id && invoice.payments.length > 0 && (
                            <tr className="bg-slate-50/70">
                              <td colSpan={10} className="px-4 py-3">
                                <div className="mx-auto max-w-2xl divide-y divide-slate-200/70 rounded-xl border border-slate-200 bg-white">
                                  {invoice.payments.map((payment) => (
                                    <div key={payment.id} className="flex items-center justify-between gap-3 px-3 py-2 text-[11px]">
                                      <div className="min-w-0">
                                        <p className="font-semibold text-slate-700">{formatMoney(payment.amount, payment.currency)}</p>
                                        <p className="text-slate-400">
                                          {formatDate(payment.paidAt)}
                                          {payment.method ? ` · ${payment.method}` : ''}
                                          {payment.user ? ` · ${payment.user}` : ''}
                                        </p>
                                        {payment.notes && <p className="max-w-xs truncate text-slate-500">{payment.notes}</p>}
                                      </div>
                                      <button type="button" disabled={deleteInvoicePayment.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar cobro', description: 'El saldo de la factura se recalculará.', confirmLabel: 'Eliminar', tone: 'danger' })) deleteInvoicePayment.mutate(payment.id); }} className="rounded p-1 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar cobro"><Trash2 className="h-3.5 w-3.5" /></button>
                                    </div>
                                  ))}
                                </div>
                              </td>
                            </tr>
                          )}
                        </Fragment>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              <div className="divide-y divide-slate-100 md:hidden">
                {data.receivables.map((invoice) => {
                  const displayStatus = invoice.displayStatus ?? (invoice.status === 'pagada' ? 'pagada' : invoice.paid > 0 ? 'parcial' : invoice.status);
                  const meta = INVOICE_STATUS_META[displayStatus] ?? INVOICE_STATUS_META.pendiente;
                  const balance = Math.max(0, invoice.balance);
                  return (
                    <div key={invoice.id} className="space-y-3 p-4">
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="truncate text-xs font-bold text-slate-900">{invoice.number}</p>
                          <p className="mt-0.5 truncate text-[11px] text-slate-600">{invoice.concept}</p>
                          <p className="mt-0.5 truncate text-[10px] text-slate-400">{invoice.projectName || 'Sin proyecto'}{invoice.client ? ` · ${invoice.client}` : ''}</p>
                        </div>
                        <span className={`flex-shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-semibold ${invoice.overdue ? 'border-rose-200 bg-rose-50 text-rose-700' : meta.className}`}>{invoice.overdue ? 'Vencida' : meta.label}</span>
                      </div>
                      <div className="grid grid-cols-3 gap-2 rounded-xl bg-slate-50 px-3 py-2 text-[10px]">
                        <div><span className="block text-slate-400">Importe</span><span className="font-bold text-slate-900">{formatMoney(invoice.amount, invoice.currency)}</span></div>
                        <div><span className="block text-slate-400">Cobrado</span><span className="font-bold text-emerald-700">{formatMoney(invoice.paid, invoice.currency)}</span></div>
                        <div className="text-right"><span className="block text-slate-400">Saldo</span><span className="font-bold text-slate-800">{formatMoney(balance, invoice.currency)}</span></div>
                      </div>
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-[10px] text-slate-400">{invoice.dueDate ? `Vence ${formatDate(invoice.dueDate)}` : 'Sin vencimiento'}{invoice.payments.length > 0 ? ` · ${invoice.payments.length} cobro${invoice.payments.length === 1 ? '' : 's'}` : ''}</span>
                        <div className="flex items-center gap-1.5">
                          {invoice.projectId && <Link to={`/projects/${invoice.projectId}?tab=finanzas`} className="rounded-lg border border-slate-200 p-2 text-slate-400" title="Ver proyecto"><ExternalLink className="h-3.5 w-3.5" /></Link>}
                          {invoice.status !== 'anulada' && balance > 0 && <button type="button" onClick={() => setInvoicePaymentFor(invoice)} className="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-2 text-[10px] font-semibold text-emerald-700"><Banknote className="h-3.5 w-3.5" /> Cobrar</button>}
                          {invoice.status === 'pagada' && <button type="button" disabled={changeInvoiceStatus.isPending} onClick={async () => { if (await confirm({ title: 'Reabrir factura', description: 'Se eliminarán todos los cobros registrados y la factura volverá a pendiente.', confirmLabel: 'Reabrir', tone: 'danger' })) changeInvoiceStatus.mutate({ invoiceId: invoice.id, action: 'invoice_pending' }); }} className="rounded-lg border border-slate-200 p-2 text-slate-500" title="Reabrir"><RotateCcw className="h-3.5 w-3.5" /></button>}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
              <div className="px-4 pb-4">
                <PaginationControls pagination={pagination} pages={windowPages} onChange={setPage} />
              </div>
            </>
          )}
        </section>
      ) : (
        <section className="space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 className="text-sm font-semibold text-slate-800">Cuentas por pagar</h2>
              <p className="mt-0.5 text-[11px] text-slate-400">{data.counts.payables} registradas · {formatTotals(stats.payables.paid)} pagado</p>
            </div>
            <p className="text-[11px] text-slate-400">{pagination.total} resultado{pagination.total === 1 ? '' : 's'}</p>
          </div>

          {data.payables.length === 0 ? (
            <div className="rounded-2xl border border-slate-200/80 bg-white p-12 text-center shadow-2xs">
              <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-rose-600"><Receipt className="h-6 w-6" /></div>
              <h3 className="text-sm font-semibold text-slate-800">Sin cuentas por pagar</h3>
              <p className="mb-4 mt-1 text-xs text-slate-500">Registra gastos o facturas de proveedores para controlar sus vencimientos y pagos.</p>
              <button type="button" onClick={() => { setEditingPayable(null); setPayableKind('direct'); setPayableCurrency('USD'); setPayableOpen(true); }} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-brand-700"><Plus className="h-3.5 w-3.5" /> Nueva cuenta por pagar</button>
            </div>
          ) : (
            <>
              <div className="space-y-3">
                {data.payables.map((payable) => {
                  const isPaid = payable.dbStatus === 'pagada';
                  const statusKey = isPaid ? 'pagada' : payable.paid > 0 ? 'parcial' : 'pendiente';
                  const meta = PAYABLE_STATUS_META[payable.overdue ? 'pendiente' : statusKey] ?? PAYABLE_STATUS_META.pendiente;
                  const kindMeta = PAYABLE_KIND_META[payable.kind] ?? PAYABLE_KIND_META.direct;
                  const balance = Math.max(0, payable.balance);
                  return (
                    <article key={payable.id} className="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-2xs sm:p-5">
                      <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold ${payable.overdue ? 'bg-rose-50 text-rose-700 border-rose-200' : meta.className}`}>{payable.overdue ? 'Vencida' : meta.label}</span>
                            {payable.category && <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">{payable.category}</span>}
                            <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold ${kindMeta.className}`}>{kindMeta.label}</span>
                          </div>
                          <p className="mt-1.5 truncate text-sm font-semibold text-slate-800">{payable.supplier}</p>
                          <p className="truncate text-xs text-slate-500" title={payable.concept}>{payable.concept}</p>
                        </div>
                        <div className="flex-shrink-0 text-right">
                          <p className="text-lg font-semibold tracking-tight text-slate-900">{formatMoney(payable.amount, payable.currency)}</p>
                          <p className={`text-[11px] ${isPaid ? 'text-emerald-600' : 'text-slate-400'}`}>{isPaid ? 'Pagada por completo' : `Saldo: ${formatMoney(balance, payable.currency)}`}</p>
                        </div>
                      </div>

                      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-500">
                        {payable.projectName ? (
                          <Link to={`/projects/${payable.projectId}?tab=finanzas`} className="inline-flex items-center gap-1.5 transition hover:text-brand-600">
                            <span className="h-2 w-2 rounded-full" style={{ backgroundColor: payable.projectColor }} />
                            {payable.projectName}
                          </Link>
                        ) : (
                          <span className="inline-flex items-center gap-1.5"><Building2 className="h-3 w-3" /> Gasto general</span>
                        )}
                        <span className="inline-flex items-center gap-1">
                          <Calendar className="h-3 w-3" />
                          {payable.dueDate ? `Vence ${formatDate(payable.dueDate)}` : 'Sin vencimiento'}
                          {payable.overdue && <strong className="font-semibold text-rose-600">· {payable.daysOverdue}d</strong>}
                        </span>
                        <span>Pagado: {formatMoney(payable.paid, payable.currency)}</span>
                      </div>

                      <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
                        <div className="flex flex-wrap items-center gap-1.5">
                          {!isPaid && (
                            <button type="button" onClick={() => setPaymentFor(payable)} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-[11px] font-semibold text-white transition hover:bg-brand-700"><Banknote className="h-3.5 w-3.5" /> Registrar pago</button>
                          )}
                          <button type="button" onClick={() => openEditPayable(payable)} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50"><Pencil className="h-3.5 w-3.5" /> Editar</button>
                          <button type="button" disabled={deletePayable.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar cuenta por pagar', description: 'Se eliminará la cuenta junto con su historial de pagos.', confirmLabel: 'Eliminar', tone: 'danger' })) deletePayable.mutate(payable.id); }} className="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-[11px] font-semibold text-rose-600 transition hover:bg-rose-100"><Trash2 className="h-3.5 w-3.5" /> Eliminar</button>
                        </div>

                        {payable.payments.length > 0 && (
                          <details className="group w-full sm:w-auto">
                            <summary className="inline-flex cursor-pointer list-none items-center gap-1.5 text-[11px] font-semibold text-slate-500 hover:text-slate-800">
                              <History className="h-3.5 w-3.5" />
                              Historial ({payable.payments.length})
                              <ChevronDown className="h-3 w-3 transition-transform group-open:rotate-180" />
                            </summary>
                            <div className="mt-2 w-full divide-y divide-slate-200/70 rounded-xl border border-slate-200 bg-slate-50/60">
                              {payable.payments.map((payment) => (
                                <div key={payment.id} className="flex items-center justify-between gap-3 px-3 py-2 text-[11px]">
                                  <div className="min-w-0">
                                    <p className="font-semibold text-slate-700">{formatMoney(payment.amount, payment.currency)}</p>
                                    <p className="text-slate-400">
                                      {formatDate(payment.paidAt)}
                                      {payment.method ? ` · ${payment.method}` : ''}
                                      {payment.user ? ` · ${payment.user}` : ''}
                                    </p>
                                    {payment.notes && <p className="max-w-xs truncate text-slate-500">{payment.notes}</p>}
                                  </div>
                                  <button type="button" disabled={deletePayment.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar pago', description: 'El saldo de la cuenta se recalculará.', confirmLabel: 'Eliminar', tone: 'danger' })) deletePayment.mutate(payment.id); }} className="rounded p-1 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar pago"><Trash2 className="h-3.5 w-3.5" /></button>
                                </div>
                              ))}
                            </div>
                          </details>
                        )}
                      </div>
                    </article>
                  );
                })}
              </div>
              <PaginationControls pagination={pagination} pages={windowPages} onChange={setPage} />
            </>
          )}
        </section>
      )}

      {(changeInvoiceStatus.isError || deletePayable.isError || deletePayment.isError || addPayment.isError || addInvoicePayment.isError || deleteInvoicePayment.isError) && (
        <ErrorState message={(() => {
          const mutationError = changeInvoiceStatus.error ?? deletePayable.error ?? deletePayment.error ?? addPayment.error ?? addInvoicePayment.error ?? deleteInvoicePayment.error;
          return mutationError instanceof Error ? mutationError.message : 'No se pudo completar la operación.';
        })()} />
      )}

      {receivableOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs" onClick={() => setReceivableOpen(false)}>
          <div ref={receivableRef} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" className="w-full max-w-xl overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
              <div className="flex items-center gap-2.5">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600"><Receipt className="h-4 w-4" /></div>
                <div>
                  <h3 className="text-base font-semibold text-slate-800">Nueva cuenta por cobrar</h3>
                  <p className="text-[11px] text-slate-400">Se registrará como factura del proyecto.</p>
                </div>
              </div>
              <button type="button" onClick={() => setReceivableOpen(false)} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
            </div>

            <form className="space-y-4 p-6" onSubmit={(event) => {
              event.preventDefault();
              const form = new FormData(event.currentTarget);
              const body: Record<string, string> = {};
              form.forEach((value, key) => { body[key] = String(value); });
              createReceivable.mutate(body);
            }}>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Proyecto *</label>
                <Select name="project_id" defaultValue={projectId || ''} onChange={(event) => {
                  const project = data.projects.find((item) => String(item.id) === event.target.value);
                  if (project) setReceivableCurrency(project.currency || 'USD');
                }} className="w-full px-3.5 py-2.5 text-sm">
                  <option value="">Sin proyecto (cuenta general)</option>
                  {data.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                </Select>
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Cliente / Deudor</label>
                <Input name="client_name" list="finance-receivable-clients" maxLength={150} placeholder="Elige un cliente o escribe un nombre nuevo" className="px-3.5 py-2.5 text-sm" />
                <datalist id="finance-receivable-clients">{data.clients.map((client) => <option key={client} value={client} />)}</datalist>
                <p className="mt-1 text-[10px] text-slate-400">Sugerencias de clientes, colaboradores y contactos del CRM.</p>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">N° Factura</label>
                  <Input name="invoice_number" maxLength={50} placeholder="Automático si se omite" className="px-3.5 py-2.5 font-mono text-sm" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Moneda</label>
                  <Select name="currency" value={receivableCurrency} onChange={(event) => setReceivableCurrency(event.target.value)} className="w-full px-3.5 py-2.5 font-mono text-sm">
                    {data.currencies.map((currency) => <option key={currency} value={currency}>{currency}</option>)}
                  </Select>
                </div>
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Concepto *</label>
                <Input name="concept" required maxLength={255} placeholder="Ej: Anticipo 50% del proyecto" className="px-3.5 py-2.5 text-sm" />
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Importe *</label>
                  <Input inputMode="decimal" name="amount" type="number" required min="0.01" step="0.01" placeholder="0.00" className="px-3.5 py-2.5 text-sm font-semibold" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Vencimiento</label>
                  <Input name="due_date" type="date" className="px-3.5 py-2.5 text-sm" />
                </div>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Estado inicial</label>
                  <Select name="status" defaultValue="pendiente" className="w-full px-3.5 py-2.5 text-sm">
                    <option value="pendiente">Pendiente de cobro</option>
                    <option value="pagada">Ya cobrada</option>
                  </Select>
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Notas</label>
                  <Input name="notes" maxLength={2000} placeholder="Referencia, condiciones…" className="px-3.5 py-2.5 text-sm" />
                </div>
              </div>

              {createReceivable.isError && <ErrorState message={createReceivable.error instanceof Error ? createReceivable.error.message : 'No se pudo registrar la cuenta por cobrar.'} />}

              <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onClick={() => setReceivableOpen(false)} className="rounded-xl px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
                <LoadingButton type="submit" pending={createReceivable.isPending} className="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60">Guardar cuenta por cobrar</LoadingButton>
              </div>
            </form>
          </div>
        </div>
      )}

      {payableOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs" onClick={() => { setPayableOpen(false); setEditingPayable(null); }}>
          <div ref={payableRef} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" className="w-full max-w-xl overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
              <div className="flex items-center gap-2.5">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600"><Receipt className="h-4 w-4" /></div>
                <h3 className="text-base font-semibold text-slate-800">{editingPayable ? 'Editar cuenta por pagar' : 'Nueva cuenta por pagar'}</h3>
              </div>
              <button type="button" onClick={() => { setPayableOpen(false); setEditingPayable(null); }} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
            </div>

            <form className="space-y-4 p-6" onSubmit={(event) => {
              event.preventDefault();
              const form = new FormData(event.currentTarget);
              const body: Record<string, string> = {};
              form.forEach((value, key) => { body[key] = String(value); });
              body.action = editingPayable ? 'update_payable' : 'create_payable';
              savePayable.mutate(body);
            }}>
              {editingPayable && <input type="hidden" name="payable_id" value={editingPayable.id} />}

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Tipo de salida</label>
                <Select name="kind" value={payableKind} onChange={(event) => setPayableKind(event.target.value)} className="w-full px-3.5 py-2.5 text-sm">
                  <option value="direct">Gasto directo / proveedor</option>
                  <option value="collaborator">Pago a colaborador</option>
                </Select>
                <p className="mt-1 text-[10px] text-slate-400">Los pagos a colaboradores se separan de los gastos directos en la rentabilidad del proyecto.</p>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">{payableKind === 'collaborator' ? 'Colaborador *' : 'Proveedor / colaborador *'}</label>
                  <Input name="supplier" list="finance-payable-suppliers" required maxLength={150} defaultValue={editingPayable?.supplier ?? ''} placeholder={payableKind === 'collaborator' ? 'Nombre del colaborador' : 'Elige uno o escribe un nombre nuevo'} className="px-3.5 py-2.5 text-sm" />
                  <datalist id="finance-payable-suppliers">{data.contacts.map((contact) => <option key={contact} value={contact} />)}</datalist>
                  <p className="mt-1 text-[10px] text-slate-400">Sugerencias de proveedores, colaboradores y contactos del CRM.</p>
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Categoría</label>
                  <Input name="category" list="finance-payable-categories" maxLength={60} defaultValue={editingPayable?.category ?? ''} placeholder={payableKind === 'collaborator' ? 'Ej: Diseño, desarrollo, redacción' : 'Ej: Servicios'} className="px-3.5 py-2.5 text-sm" />
                  <datalist id="finance-payable-categories">
                    {['Proveedores', 'Servicios', 'Software', 'Impuestos', 'Alquiler', 'Marketing', 'Otros'].map((category) => <option key={category} value={category} />)}
                  </datalist>
                </div>
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Concepto *</label>
                <Input name="concept" required maxLength={255} defaultValue={editingPayable?.concept ?? ''} placeholder="Ej: Honorarios de constitución de sociedad" className="px-3.5 py-2.5 text-sm" />
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Proyecto asociado</label>
                  <Select name="project_id" defaultValue={editingPayable?.projectId ? String(editingPayable.projectId) : (projectId || '')} onChange={(event) => {
                    const project = data.projects.find((item) => String(item.id) === event.target.value);
                    if (project) setPayableCurrency(project.currency || 'USD');
                  }} className="w-full px-3.5 py-2.5 text-sm">
                    <option value="">Gasto general (sin proyecto)</option>
                    {data.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                  </Select>
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Vencimiento</label>
                  <Input name="due_date" type="date" defaultValue={editingPayable?.dueDate ?? ''} className="px-3.5 py-2.5 text-sm" />
                </div>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Importe *</label>
                  <Input inputMode="decimal" name="amount" type="number" required min="0.01" step="0.01" placeholder="0.00" defaultValue={editingPayable ? editingPayable.amount.toFixed(2) : ''} className="px-3.5 py-2.5 text-sm font-semibold" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Moneda</label>
                  <Select name="currency" value={payableCurrency} onChange={(event) => setPayableCurrency(event.target.value)} className="w-full px-3.5 py-2.5 font-mono text-sm">
                    {data.currencies.map((currency) => <option key={currency} value={currency}>{currency}</option>)}
                  </Select>
                </div>
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Notas</label>
                <textarea name="notes" rows={2} maxLength={2000} defaultValue={editingPayable?.notes ?? ''} placeholder="Referencia, condiciones de pago…" className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15" />
              </div>

              {savePayable.isError && <ErrorState message={savePayable.error instanceof Error ? savePayable.error.message : 'No se pudo guardar la cuenta por pagar.'} />}

              <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onClick={() => { setPayableOpen(false); setEditingPayable(null); }} className="rounded-xl px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
                <LoadingButton type="submit" pending={savePayable.isPending} className="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60">Guardar</LoadingButton>
              </div>
            </form>
          </div>
        </div>
      )}

      {paymentFor && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs" onClick={() => setPaymentFor(null)}>
          <div ref={paymentRef} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" className="w-full max-w-md overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
              <div className="flex items-center gap-2.5">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Banknote className="h-4 w-4" /></div>
                <div>
                  <h3 className="text-base font-semibold text-slate-800">Registrar pago</h3>
                  <p className="max-w-[260px] truncate text-[11px] text-slate-400">{paymentFor.supplier} · {paymentFor.concept}</p>
                </div>
              </div>
              <button type="button" onClick={() => setPaymentFor(null)} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
            </div>

            <form className="space-y-4 p-6" onSubmit={(event) => {
              event.preventDefault();
              const form = new FormData(event.currentTarget);
              const body: Record<string, string> = { payable_id: String(paymentFor.id) };
              form.forEach((value, key) => { body[key] = String(value); });
              addPayment.mutate(body);
            }}>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Importe *</label>
                  <Input inputMode="decimal" name="amount" type="number" required min="0.01" step="0.01" defaultValue={Math.max(0, paymentFor.balance).toFixed(2)} className="px-3.5 py-2.5 text-sm font-semibold" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Fecha</label>
                  <Input name="paid_at" type="date" required defaultValue={data.today} className="px-3.5 py-2.5 text-sm" />
                </div>
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Método</label>
                <Select name="method" defaultValue="Transferencia" className="w-full px-3.5 py-2.5 text-sm">
                  {PAYMENT_METHODS.map((method) => <option key={method} value={method}>{method}</option>)}
                </Select>
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Notas</label>
                <Input name="notes" maxLength={255} placeholder="Ej: Pago parcial acordado" className="px-3.5 py-2.5 text-sm" />
              </div>

              {addPayment.isError && <ErrorState message={addPayment.error instanceof Error ? addPayment.error.message : 'No se pudo registrar el pago.'} />}

              <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onClick={() => setPaymentFor(null)} className="rounded-xl px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
                <LoadingButton type="submit" pending={addPayment.isPending} className="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60">Guardar pago</LoadingButton>
              </div>
            </form>
          </div>
        </div>
      )}
      {invoicePaymentFor && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs" onClick={() => setInvoicePaymentFor(null)}>
          <div ref={invoicePaymentRef} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" className="w-full max-w-md overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
              <div className="flex items-center gap-2.5">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600"><Banknote className="h-4 w-4" /></div>
                <div>
                  <h3 className="text-base font-semibold text-slate-800">Registrar cobro</h3>
                  <p className="max-w-[260px] truncate text-[11px] text-slate-400">{invoicePaymentFor.number} · {invoicePaymentFor.concept}</p>
                </div>
              </div>
              <button type="button" onClick={() => setInvoicePaymentFor(null)} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
            </div>

            <form className="space-y-4 p-6" onSubmit={(event) => {
              event.preventDefault();
              const form = new FormData(event.currentTarget);
              const body: Record<string, string> = { invoice_id: String(invoicePaymentFor.id) };
              form.forEach((value, key) => { body[key] = String(value); });
              addInvoicePayment.mutate(body);
            }}>
              <div className="rounded-xl bg-slate-50 px-3.5 py-3 text-[11px] text-slate-500">
                <div className="flex items-center justify-between"><span>Importe factura</span><span className="font-semibold text-slate-700">{formatMoney(invoicePaymentFor.amount, invoicePaymentFor.currency)}</span></div>
                <div className="mt-1 flex items-center justify-between"><span>Cobrado</span><span className="font-semibold text-emerald-700">{formatMoney(invoicePaymentFor.paid, invoicePaymentFor.currency)}</span></div>
                <div className="mt-1 flex items-center justify-between"><span>Saldo pendiente</span><span className="font-semibold text-slate-800">{formatMoney(Math.max(0, invoicePaymentFor.balance), invoicePaymentFor.currency)}</span></div>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Importe a cobrar *</label>
                  <Input inputMode="decimal" name="amount" type="number" required min="0.01" max={Math.max(0, invoicePaymentFor.balance)} step="0.01" defaultValue={Math.max(0, invoicePaymentFor.balance).toFixed(2)} className="px-3.5 py-2.5 text-sm font-semibold" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Fecha</label>
                  <Input name="paid_at" type="date" required defaultValue={data.today} className="px-3.5 py-2.5 text-sm" />
                </div>
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Método</label>
                <Select name="method" defaultValue="Transferencia" className="w-full px-3.5 py-2.5 text-sm">
                  {PAYMENT_METHODS.map((method) => <option key={method} value={method}>{method}</option>)}
                </Select>
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Notas</label>
                <Input name="notes" maxLength={255} placeholder="Ej: Primer pago acordado" className="px-3.5 py-2.5 text-sm" />
              </div>

              {addInvoicePayment.isError && <ErrorState message={addInvoicePayment.error instanceof Error ? addInvoicePayment.error.message : 'No se pudo registrar el cobro.'} />}

              <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onClick={() => setInvoicePaymentFor(null)} className="rounded-xl px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
                <LoadingButton type="submit" pending={addInvoicePayment.isPending} className="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60">Guardar cobro</LoadingButton>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}

function PaginationControls({
  pagination,
  pages,
  onChange,
}: {
  pagination: FinanceData['pagination'];
  pages: number[];
  onChange: (page: number) => void;
}) {
  if (pagination.pages <= 1) return null;

  return (
    <nav className="mt-6 flex flex-col items-center justify-between gap-3 sm:flex-row" aria-label="Paginación">
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
