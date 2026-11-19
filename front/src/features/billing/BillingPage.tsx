import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Ban, Banknote, CheckCircle2, Clock, Download, Eye, FileText, History, Paperclip, Pencil, Plus, Receipt, RotateCcw, Search, Trash2, Wallet, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Card, ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { ExportButton } from '../../components/ExportButton';
import { LoadingButton, Modal, useConfirm, useToast } from '../../components/feedback';
import { useUnsavedChanges } from '../../hooks/useUnsavedChanges';
import { apiGet, apiPost, apiPostRoot, apiUploadRoot } from '../../lib/api';
import { formatDate, formatMoney, formatTotals } from '../../lib/format';
import type { BillingData } from '../../types';

type Invoice = BillingData['invoices'][number];

const STATUS_BADGES: Record<string, string> = {
  pagada: 'bg-emerald-50 text-emerald-700 border-emerald-200/60',
  parcial: 'bg-sky-50 text-sky-700 border-sky-200/60',
  pendiente: 'bg-amber-50 text-amber-700 border-amber-200/60',
  anulada: 'bg-rose-50 text-rose-700 border-rose-200/60',
};

const STATUS_LABELS: Record<string, string> = {
  pagada: 'Cobrada',
  parcial: 'Parcial',
  pendiente: 'Pendiente',
  anulada: 'Anulada',
};

const PAYMENT_METHODS = ['Transferencia', 'Efectivo', 'Tarjeta', 'Cheque', 'Otro'];

interface InvoiceDocument {
  id: number;
  name: string;
  size_formatted?: string;
  created_at?: string;
  download_url: string;
}

function invoiceCurrency(invoice: Invoice): string {
  return invoice.currency || invoice.projectCurrency || 'USD';
}

function todayIso(): string {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function addDaysIso(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function statusOf(invoice: Invoice): string {
  return invoice.displayStatus ?? (invoice.status === 'pagada' ? 'pagada' : invoice.paid > 0 ? 'parcial' : invoice.status);
}

export function BillingPage() {
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const toast = useToast();
  const [searchParams, setSearchParams] = useSearchParams();
  const [status, setStatus] = useState(() => searchParams.get('status') ?? 'todas');
  const [search, setSearch] = useState(() => searchParams.get('search') ?? '');
  const [projectId, setProjectId] = useState(() => searchParams.get('project_id') ?? '');
  const [page, setPage] = useState(() => Number(searchParams.get('page') ?? 1) || 1);

  const [showCreate, setShowCreate] = useState(false);
  const [createDirty, setCreateDirty] = useState(false);
  const [createForm, setCreateForm] = useState({
    projectId: '',
    client: '',
    number: '',
    amount: '',
    currency: 'USD',
    concept: '',
    dueDate: '',
    status: 'pendiente',
    notes: '',
  });

  const [detailId, setDetailId] = useState<number | null>(null);
  const [docError, setDocError] = useState('');
  const [paymentInvoice, setPaymentInvoice] = useState<Invoice | null>(null);
  const [payAmount, setPayAmount] = useState('');
  const [payDate, setPayDate] = useState(todayIso);
  const [editingInvoice, setEditingInvoice] = useState<Invoice | null>(null);
  const [editCurrency, setEditCurrency] = useState('USD');
  const [editDirty, setEditDirty] = useState(false);

  const { data, isLoading, error } = useQuery({
    queryKey: ['billing', status, search, projectId, page],
    queryFn: () => apiGet<BillingData>('billing.php', { status, search, project_id: projectId, page }),
  });

  useEffect(() => {
    const next = new URLSearchParams();
    if (status !== 'todas') next.set('status', status);
    if (search !== '') next.set('search', search);
    if (projectId !== '') next.set('project_id', projectId);
    if (page > 1) next.set('page', String(page));
    setSearchParams(next, { replace: true });
  }, [page, projectId, search, setSearchParams, status]);

  useUnsavedChanges((showCreate && createDirty) || (editingInvoice !== null && editDirty), 'Tienes cambios sin guardar en la factura.');

  const detailInvoice = detailId !== null ? (data?.invoices.find((invoice) => invoice.id === detailId) ?? null) : null;

  const docsQuery = useQuery({
    queryKey: ['invoice-docs', detailInvoice?.id],
    enabled: detailInvoice !== null && detailInvoice.projectId !== null,
    queryFn: () => apiPostRoot<{ success: boolean; documents: InvoiceDocument[] }>('/api/invoices.php', {
      action: 'documents_list',
      invoice_id: detailInvoice?.id ?? 0,
      project_id: detailInvoice?.projectId ?? 0,
    }),
  });

  const refreshAll = () => {
    void queryClient.invalidateQueries({ queryKey: ['billing'] });
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    void queryClient.invalidateQueries({ queryKey: ['cash'] });
    void queryClient.invalidateQueries({ queryKey: ['finance'] });
    void queryClient.invalidateQueries({ queryKey: ['project'] });
    void queryClient.invalidateQueries({ queryKey: ['project-tabs'] });
    void queryClient.invalidateQueries({ queryKey: ['project-insights'] });
    void queryClient.invalidateQueries({ queryKey: ['invoice-docs'] });
  };

  const closeCreate = async () => {
    if (createDirty && !(await confirm({ title: 'Descartar factura', description: 'Se perderán los datos introducidos.', confirmLabel: 'Descartar', cancelLabel: 'Seguir editando', tone: 'danger' }))) return;
    setCreateDirty(false);
    setShowCreate(false);
  };

  const closeEdit = async () => {
    if (editDirty && !(await confirm({ title: 'Descartar cambios', description: 'Se perderán los cambios de la factura.', confirmLabel: 'Descartar', cancelLabel: 'Seguir editando', tone: 'danger' }))) return;
    setEditDirty(false);
    setEditingInvoice(null);
  };

  const openCreate = () => {
    setCreateForm({ projectId: '', client: '', number: '', amount: '', currency: 'USD', concept: '', dueDate: '', status: 'pendiente', notes: '' });
    setCreateDirty(false);
    setShowCreate(true);
  };

  const openPayment = (invoice: Invoice) => {
    setPaymentInvoice(invoice);
    setPayAmount(Math.max(0, invoice.balance).toFixed(2));
    setPayDate(todayIso());
  };

  const openDetail = (invoice: Invoice) => {
    setDocError('');
    setDetailId(invoice.id);
  };

  const createInvoice = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean; number: string }>('billing.php', { action: 'create', ...body }),
    onSuccess: (result) => {
      setCreateDirty(false);
      setShowCreate(false);
      refreshAll();
      toast(`Factura ${result.number} creada.`);
    },
  });

  const updateInvoice = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('billing.php', { action: 'update', ...body }),
    onSuccess: () => { setEditDirty(false); setEditingInvoice(null); refreshAll(); toast('Factura actualizada.'); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo actualizar la factura.', 'error'),
  });

  const setInvoiceStatus = useMutation({
    mutationFn: ({ invoiceId, nextStatus }: { invoiceId: number; nextStatus: string; silent?: boolean }) => apiPost<{ success: boolean }>('billing.php', { action: 'update_status', invoice_id: invoiceId, status: nextStatus }),
    onSuccess: (_result, variables) => {
      refreshAll();
      if (variables.silent) return;
      if (variables.nextStatus === 'anulada') {
        toast('Factura anulada.', 'info', {
          action: {
            label: 'Deshacer',
            onClick: () => setInvoiceStatus.mutate({ invoiceId: variables.invoiceId, nextStatus: 'reactivate', silent: true }),
          },
        });
      } else if (variables.nextStatus === 'reactivate') {
        toast('Factura reactivada.');
      } else if (variables.nextStatus === 'pagada') {
        toast('Factura marcada como cobrada.');
      } else if (variables.nextStatus === 'pendiente') {
        toast('Factura reabierta como pendiente.');
      } else {
        toast('Estado de la factura actualizado.');
      }
    },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo cambiar el estado.', 'error'),
  });

  const addInvoicePayment = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('billing.php', { action: 'add_invoice_payment', ...body }),
    onSuccess: () => { setPaymentInvoice(null); refreshAll(); toast('Cobro registrado.'); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo registrar el cobro.', 'error'),
  });

  const deleteInvoicePayment = useMutation({
    mutationFn: (paymentId: number) => apiPost<{ success: boolean }>('billing.php', { action: 'delete_payment', payment_id: paymentId }),
    onSuccess: () => { refreshAll(); toast('Cobro eliminado y saldo recalculado.'); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo eliminar el cobro.', 'error'),
  });

  const deleteInvoice = useMutation({
    mutationFn: (invoiceId: number) => apiPost<{ success: boolean }>('billing.php', { action: 'delete', invoice_id: invoiceId }),
    onSuccess: () => { setDetailId(null); refreshAll(); toast('Factura eliminada.'); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo eliminar la factura.', 'error'),
  });

  const uploadDocument = useMutation({
    mutationFn: (file: File) => {
      const formData = new FormData();
      formData.set('action', 'document_upload');
      formData.set('invoice_id', String(detailInvoice?.id ?? 0));
      formData.set('project_id', String(detailInvoice?.projectId ?? 0));
      formData.set('file', file);
      return apiUploadRoot<{ success: boolean }>('/api/invoices.php', formData);
    },
    onSuccess: () => { setDocError(''); void queryClient.invalidateQueries({ queryKey: ['invoice-docs'] }); toast('Documento subido.'); },
    onError: (mutationError) => setDocError(mutationError instanceof Error ? mutationError.message : 'No se pudo subir el documento.'),
  });

  const deleteDocument = useMutation({
    mutationFn: (documentId: number) => apiPostRoot<{ success: boolean }>('/api/invoices.php', {
      action: 'document_delete',
      document_id: documentId,
      project_id: detailInvoice?.projectId ?? 0,
    }),
    onSuccess: () => { setDocError(''); void queryClient.invalidateQueries({ queryKey: ['invoice-docs'] }); toast('Documento eliminado.'); },
    onError: (mutationError) => setDocError(mutationError instanceof Error ? mutationError.message : 'No se pudo eliminar el documento.'),
  });

  const askStatus = async (invoice: Invoice, nextStatus: string, title: string, description: string) => {
    if (await confirm({ title, description, confirmLabel: title, tone: nextStatus === 'anulada' ? 'danger' : 'default' })) {
      setInvoiceStatus.mutate({ invoiceId: invoice.id, nextStatus });
    }
  };

  const askDelete = async (invoice: Invoice) => {
    if (await confirm({ title: 'Eliminar factura', description: `Se eliminará ${invoice.number} y sus documentos. Esta acción no se puede deshacer.`, confirmLabel: 'Eliminar', tone: 'danger' })) {
      deleteInvoice.mutate(invoice.id);
    }
  };

  if (isLoading) return <div className="space-y-5"><div className="grid grid-cols-2 gap-4 lg:grid-cols-4">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} className="h-32" />)}</div><Skeleton className="h-80" /></div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar la facturación.'} />;

  const filtered = search !== '' || status !== 'todas' || projectId !== '';
  const pagination = data.pagination;
  const resetFilters = () => { setSearch(''); setStatus('todas'); setProjectId(''); setPage(1); };
  const applyStatus = (nextStatus: string) => { setStatus(nextStatus); setPage(1); };
  const windowPages = Array.from(new Set([1, pagination.page - 1, pagination.page, pagination.page + 1, pagination.pages]))
    .filter((value) => value >= 1 && value <= pagination.pages)
    .sort((a, b) => a - b);
  const statsCards = [
    { key: 'todas', label: 'Total facturado', value: formatTotals(data.stats.total), hint: `${data.stats.countTotal} facturas emitidas`, icon: Receipt, tone: 'bg-slate-50 text-slate-400' },
    { key: 'pagada', label: 'Cobrado', value: formatTotals(data.stats.paid), hint: `${data.stats.countPaid} cobradas · ${data.stats.effectiveness}% efectividad`, icon: CheckCircle2, tone: 'bg-emerald-50 text-emerald-500' },
    { key: 'pendiente', label: 'Por cobrar', value: formatTotals(data.stats.pending), hint: `${data.stats.countPending} pendientes`, icon: Clock, tone: 'bg-amber-50 text-amber-500' },
    { key: 'vencida', label: 'Vencido', value: formatTotals(data.stats.overdue), hint: `${data.stats.countOverdue} facturas vencidas`, icon: AlertTriangle, tone: 'bg-rose-50 text-rose-500' },
  ];

  const rowActions = (invoice: Invoice) => {
    const balance = Math.max(0, invoice.balance);
    const canCollect = invoice.status !== 'anulada' && balance > 0;
    return (
      <div className="inline-flex items-center justify-end gap-0.5">
        <button type="button" onClick={() => openDetail(invoice)} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-brand-50 hover:text-brand-600" title="Ver detalle"><Eye className="h-3.5 w-3.5" /></button>
        {canCollect && <button type="button" onClick={() => openPayment(invoice)} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-emerald-50 hover:text-emerald-600" title="Registrar cobro"><Banknote className="h-3.5 w-3.5" /></button>}
        <button type="button" onClick={() => { setEditingInvoice(invoice); setEditCurrency(invoiceCurrency(invoice)); setEditDirty(false); }} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title="Editar"><Pencil className="h-3.5 w-3.5" /></button>
        {invoice.status === 'anulada' ? (
          <button type="button" disabled={setInvoiceStatus.isPending} onClick={() => void askStatus(invoice, 'reactivate', 'Reactivar factura', 'Volverá a su estado según los cobros registrados y se restaurarán sus movimientos de caja.')} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-emerald-50 hover:text-emerald-600" title="Reactivar"><RotateCcw className="h-3.5 w-3.5" /></button>
        ) : (
          <button type="button" disabled={setInvoiceStatus.isPending} onClick={() => void askStatus(invoice, 'anulada', 'Anular factura', 'Dejará de contar como pendiente y sus cobros se quitarán de caja.')} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-amber-50 hover:text-amber-600" title="Anular"><Ban className="h-3.5 w-3.5" /></button>
        )}
        <button type="button" disabled={deleteInvoice.isPending} onClick={() => void askDelete(invoice)} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar"><Trash2 className="h-3.5 w-3.5" /></button>
      </div>
    );
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Facturación</h1>
          <p className="hidden text-[11px] text-slate-400 sm:block">Emite, cobra y controla todas tus facturas</p>
        </div>
        <div className="flex items-center gap-2">
          <ExportButton url="/export.php?type=invoices&format=csv" format="csv" className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50" title="Exportar facturas a CSV">CSV</ExportButton>
          <ExportButton url="/export.php?type=invoices&format=pdf" format="pdf" className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50" title="Exportar facturas a PDF">PDF</ExportButton>
          <button type="button" onClick={openCreate} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700"><Plus className="h-4 w-4" /> Nueva factura</button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        {statsCards.map((card) => {
          const Icon = card.icon;
          const active = status === card.key;
          return (
            <button key={card.key} type="button" onClick={() => applyStatus(status === card.key && card.key !== 'todas' ? 'todas' : card.key)} aria-pressed={active} className={`text-left transition ${active ? 'ring-2 ring-brand-500/40' : ''}`}>
              <Card className="h-full space-y-2 p-4 hover:border-slate-300 sm:p-5">
                <div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">{card.label}</span><span className={`flex h-8 w-8 items-center justify-center rounded-lg ${card.tone}`}><Icon className="h-4 w-4" /></span></div>
                <p className="text-xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[26px]">{card.value}</p>
                <span className="text-[11px] font-medium text-slate-400">{card.hint}</span>
              </Card>
            </button>
          );
        })}
      </div>

      <Card className="p-4">
        <div className="flex w-full flex-col items-center gap-3 md:flex-row">
          <div className="relative w-full flex-1"><Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Buscar por N° factura, cliente, concepto o proyecto..." className="pl-10 text-xs sm:text-sm" /></div>
          <div className="flex w-full items-center gap-2 md:w-auto">
            <Select value={status} onChange={(event) => applyStatus(event.target.value)}>
              <option value="todas">Todos los estados</option>
              <option value="pendiente">Pendientes</option>
              <option value="parcial">Parciales</option>
              <option value="vencida">Vencidas</option>
              <option value="pagada">Cobradas</option>
              <option value="anulada">Anuladas</option>
            </Select>
            <Select value={projectId} onChange={(event) => { setProjectId(event.target.value); setPage(1); }} className="max-w-[190px] truncate">
              <option value="">Todos los proyectos</option>
              {data.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
            </Select>
            {filtered && <button type="button" onClick={resetFilters} className="whitespace-nowrap rounded-xl px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-800"><RotateCcw className="mr-1 inline h-3.5 w-3.5" />Limpiar</button>}
          </div>
        </div>
      </Card>

      <Card className="overflow-hidden">
        <div className="flex items-center justify-between border-b border-slate-100 px-4 py-4 sm:px-5">
          <div className="flex items-center gap-2">
            <h2 className="text-sm font-semibold text-slate-900">Facturas</h2>
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{data.stats.countTotal}</span>
          </div>
          <span className="text-[11px] text-slate-400">Ordenadas por vencimiento y estado</span>
        </div>

        <div className="hidden overflow-x-auto md:block">
          <table className="w-full text-left text-xs text-slate-600">
            <thead className="border-b border-slate-100 bg-slate-50/70 text-[11px] font-medium text-slate-500">
              <tr>
                <th className="px-4 py-3">N° / Concepto</th>
                <th className="px-4 py-3">Cliente</th>
                <th className="px-4 py-3">Proyecto</th>
                <th className="px-4 py-3">Importe</th>
                <th className="px-4 py-3">Vencimiento</th>
                <th className="px-4 py-3">Estado</th>
                <th className="px-4 py-3 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.invoices.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-14 text-center">
                    <div className={`mx-auto flex h-12 w-12 items-center justify-center rounded-2xl ${filtered ? 'bg-slate-100 text-slate-400' : 'bg-brand-50 text-brand-500'}`}>{filtered ? <Search className="h-5 w-5" /> : <Receipt className="h-5 w-5" />}</div>
                    <p className="mt-3 text-sm font-semibold text-slate-800">{filtered ? 'No hay facturas que coincidan' : 'Aún no hay facturas'}</p>
                    <p className="mt-1 text-xs text-slate-500">{filtered ? 'Prueba con otro estado, proyecto o término de búsqueda.' : 'Emite tu primera factura y registra sus cobros.'}</p>
                    {filtered ? <button type="button" onClick={resetFilters} className="mt-4 inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"><RotateCcw className="h-3.5 w-3.5" />Limpiar filtros</button> : <button type="button" onClick={openCreate} className="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700"><Plus className="h-3.5 w-3.5" />Nueva factura</button>}
                  </td>
                </tr>
              )}
              {data.invoices.map((invoice) => {
                const displayStatus = statusOf(invoice);
                const balance = Math.max(0, invoice.balance);
                const currency = invoiceCurrency(invoice);
                const progress = invoice.amount > 0 ? Math.min(100, Math.round((invoice.paid / invoice.amount) * 100)) : 0;
                return (
                  <tr key={invoice.id} className="transition hover:bg-slate-50/60">
                    <td className="min-w-[190px] px-4 py-3">
                      <button type="button" onClick={() => openDetail(invoice)} className="font-mono text-[11px] font-bold text-brand-600 hover:underline">{invoice.number}</button>
                      <div className="mt-0.5 max-w-[240px] truncate font-medium text-slate-800">{invoice.concept}</div>
                    </td>
                    <td className="max-w-[170px] truncate px-4 py-3" title={invoice.client ?? ''}>{invoice.client || <span className="text-slate-300">—</span>}</td>
                    <td className="whitespace-nowrap px-4 py-3">
                      {invoice.projectId ? <Link to={`/projects/${invoice.projectId}?tab=finanzas`} className="inline-flex max-w-[170px] items-center gap-1.5 font-semibold text-slate-700 hover:text-brand-600"><span className="h-2 w-2 flex-shrink-0 rounded-full" style={{ backgroundColor: invoice.projectColor }} /><span className="truncate">{invoice.projectName}</span></Link> : <span className="inline-flex items-center gap-1.5 text-slate-400"><Wallet className="h-3 w-3" /> General</span>}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3">
                      <span className="font-bold text-slate-900">{formatMoney(invoice.amount, currency)}</span>
                      {invoice.paid > 0 && <div className="mt-1 h-1 w-24 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-emerald-500" style={{ width: `${progress}%` }} /></div>}
                      {invoice.paid > 0 && <div className="mt-1 text-[10px] font-medium text-emerald-700">Cobrado {formatMoney(invoice.paid, currency)}</div>}
                      {balance > 0 && invoice.paid > 0 && <div className="text-[10px] text-slate-400">Saldo {formatMoney(balance, currency)}</div>}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3">
                      <span className="text-slate-500">{invoice.dueDate ? formatDate(invoice.dueDate) : '—'}</span>
                      {invoice.overdue && <div className="mt-1 inline-flex items-center gap-1 rounded-full border border-rose-200 bg-rose-50 px-1.5 py-0.5 text-[9px] font-bold text-rose-600"><AlertTriangle className="h-2.5 w-2.5" />{invoice.daysOverdue}d vencida</div>}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3"><span className={`inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold ${STATUS_BADGES[displayStatus] ?? 'bg-slate-50 text-slate-700 border-slate-200/60'}`}>{STATUS_LABELS[displayStatus] ?? displayStatus}</span></td>
                    <td className="whitespace-nowrap px-4 py-3 text-right">{rowActions(invoice)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        <div className="divide-y divide-slate-100 md:hidden">
          {data.invoices.length === 0 && <p className="px-4 py-10 text-center text-xs text-slate-400">{filtered ? 'No hay facturas con estos filtros.' : 'Aún no hay facturas.'}</p>}
          {data.invoices.map((invoice) => {
            const displayStatus = statusOf(invoice);
            const balance = Math.max(0, invoice.balance);
            const currency = invoiceCurrency(invoice);
            return (
              <div key={invoice.id} className="space-y-3 p-4">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <button type="button" onClick={() => openDetail(invoice)} className="truncate font-mono text-[11px] font-bold text-brand-600">{invoice.number}</button>
                    <p className="mt-0.5 truncate text-sm font-semibold text-slate-800">{invoice.concept}</p>
                    <p className="mt-0.5 truncate text-[11px] text-slate-500">{invoice.client || 'Sin cliente'}{invoice.projectName ? ` · ${invoice.projectName}` : ' · General'}</p>
                  </div>
                  <span className={`flex-shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-semibold ${STATUS_BADGES[displayStatus] ?? 'bg-slate-50 text-slate-700 border-slate-200'}`}>{STATUS_LABELS[displayStatus] ?? displayStatus}</span>
                </div>
                <div className="flex items-end justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2">
                  <div><p className="text-[10px] text-slate-400">Importe</p><p className="text-sm font-bold text-slate-900">{formatMoney(invoice.amount, currency)}</p></div>
                  <div className="text-right text-[10px] text-slate-500">
                    <p>Cobrado <span className="font-semibold text-emerald-700">{formatMoney(invoice.paid, currency)}</span></p>
                    {balance > 0 && <p>Saldo <span className="font-semibold text-slate-700">{formatMoney(balance, currency)}</span></p>}
                    {invoice.dueDate && <p className={invoice.overdue ? 'font-semibold text-rose-600' : ''}>{invoice.overdue ? `Vencida hace ${invoice.daysOverdue}d` : `Vence ${formatDate(invoice.dueDate)}`}</p>}
                  </div>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-[10px] text-slate-400">{invoice.payments.length} cobro{invoice.payments.length === 1 ? '' : 's'}</span>
                  <div className="flex items-center gap-1">
                    <button type="button" onClick={() => openDetail(invoice)} className="rounded-lg border border-slate-200 p-2 text-slate-500" title="Detalle"><Eye className="h-4 w-4" /></button>
                    {invoice.status !== 'anulada' && balance > 0 && <button type="button" onClick={() => openPayment(invoice)} className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700"><Banknote className="mr-1 inline h-3.5 w-3.5" />Cobrar</button>}
                    <button type="button" onClick={() => { setEditingInvoice(invoice); setEditCurrency(currency); setEditDirty(false); }} className="rounded-lg border border-slate-200 p-2 text-slate-500" title="Editar"><Pencil className="h-4 w-4" /></button>
                    <button type="button" disabled={deleteInvoice.isPending} onClick={() => void askDelete(invoice)} className="rounded-lg border border-rose-200 p-2 text-rose-500" title="Eliminar"><Trash2 className="h-4 w-4" /></button>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      </Card>

      {pagination.pages > 1 && (
        <nav className="flex flex-col items-center justify-between gap-3 sm:flex-row" aria-label="Paginación">
          <p className="text-xs text-slate-500">Mostrando <strong className="text-slate-800">{(pagination.page - 1) * pagination.perPage + 1}–{Math.min(pagination.page * pagination.perPage, pagination.total)}</strong> de <strong className="text-slate-800">{pagination.total}</strong></p>
          <div className="flex items-center gap-1">
            {pagination.page > 1 && <button type="button" onClick={() => setPage(pagination.page - 1)} className="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100" aria-label="Página anterior">←</button>}
            {windowPages.map((value, index) => (
              <span key={value} className="flex items-center gap-1">
                {index > 0 && value - windowPages[index - 1] > 1 && <span className="px-1.5 text-xs text-slate-400">…</span>}
                {value === pagination.page
                  ? <span className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white" aria-current="page">{value}</span>
                  : <button type="button" onClick={() => setPage(value)} className="rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100">{value}</button>}
              </span>
            ))}
            {pagination.page < pagination.pages && <button type="button" onClick={() => setPage(pagination.page + 1)} className="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100" aria-label="Página siguiente">→</button>}
          </div>
        </nav>
      )}

      {showCreate && (
        <Modal open onClose={closeCreate} panelClassName="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-slate-100 bg-white shadow-2xl">
          <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
            <div className="flex items-center gap-2.5">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Receipt className="h-4 w-4" /></div>
              <div><h3 className="text-base font-semibold text-slate-800">Emitir factura</h3><p className="text-[11px] text-slate-400">Puedes asociarla a un proyecto o dejarla como cuenta general.</p></div>
            </div>
            <button type="button" onClick={() => void closeCreate()} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
          </div>
          <form className="space-y-4 p-6" onChange={() => setCreateDirty(true)} onSubmit={(event) => {
            event.preventDefault();
            createInvoice.mutate({
              project_id: createForm.projectId,
              client_name: createForm.client,
              invoice_number: createForm.number,
              amount: createForm.amount,
              currency: createForm.currency,
              concept: createForm.concept,
              due_date: createForm.dueDate,
              status: createForm.status,
              notes: createForm.notes,
            });
          }}>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Proyecto</label>
              <Select
                value={createForm.projectId}
                onChange={(event) => {
                  const project = data.projects.find((item) => String(item.id) === event.target.value);
                  setCreateForm((current) => ({
                    ...current,
                    projectId: event.target.value,
                    currency: project?.currency || current.currency,
                    client: project?.client && current.client.trim() === '' ? project.client : current.client,
                    amount: project && project.budget > 0 && current.amount.trim() === '' ? project.budget.toFixed(2) : current.amount,
                  }));
                }}
                className="w-full px-3.5 py-2.5 text-xs sm:text-sm"
              >
                <option value="">Sin proyecto · cuenta general</option>
                {data.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
              </Select>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Cliente / Deudor</label>
              <Input value={createForm.client} onChange={(event) => setCreateForm({ ...createForm, client: event.target.value })} list="billing-clients" maxLength={150} placeholder="Elige un cliente o escribe uno nuevo" className="px-3.5 py-2 text-xs sm:text-sm" />
              <datalist id="billing-clients">{data.clients.map((client) => <option key={client} value={client} />)}</datalist>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">N° de factura</label>
                <Input value={createForm.number} onChange={(event) => setCreateForm({ ...createForm, number: event.target.value })} maxLength={50} placeholder="Automático" className="px-3.5 py-2 font-mono text-xs sm:text-sm" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Monto *</label>
                <Input inputMode="decimal" value={createForm.amount} onChange={(event) => setCreateForm({ ...createForm, amount: event.target.value })} type="number" step="0.01" min="0.01" required placeholder="0.00" className="px-3.5 py-2 text-xs font-bold sm:text-sm" />
              </div>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Moneda</label>
                <Select value={createForm.currency} onChange={(event) => setCreateForm({ ...createForm, currency: event.target.value })} className="w-full px-3.5 py-2 font-mono text-xs sm:text-sm">{data.currencies.map((currency) => <option key={currency} value={currency}>{currency}</option>)}</Select>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Estado inicial</label>
                <Select value={createForm.status} onChange={(event) => setCreateForm({ ...createForm, status: event.target.value })} className="w-full px-3.5 py-2 text-xs sm:text-sm"><option value="pendiente">Pendiente de pago</option><option value="pagada">Cobrada</option></Select>
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Concepto *</label>
              <Input value={createForm.concept} onChange={(event) => setCreateForm({ ...createForm, concept: event.target.value })} required maxLength={255} placeholder="Ej: Anticipo 50% inicio de proyecto" className="px-3.5 py-2 text-xs sm:text-sm" />
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Fecha de vencimiento</label>
              <Input value={createForm.dueDate} onChange={(event) => setCreateForm({ ...createForm, dueDate: event.target.value })} type="date" className="px-3.5 py-2 text-xs sm:text-sm" />
              <div className="mt-1.5 flex flex-wrap gap-1.5">
                <button type="button" onClick={() => setCreateForm({ ...createForm, dueDate: addDaysIso(15) })} className="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-200">+15 días</button>
                <button type="button" onClick={() => setCreateForm({ ...createForm, dueDate: addDaysIso(30) })} className="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-200">+30 días</button>
                <button type="button" onClick={() => setCreateForm({ ...createForm, dueDate: addDaysIso(60) })} className="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-200">+60 días</button>
                {createForm.dueDate && <button type="button" onClick={() => setCreateForm({ ...createForm, dueDate: '' })} className="rounded-lg px-2 py-1 text-[10px] font-semibold text-slate-400 hover:bg-slate-100">Sin vencimiento</button>}
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Notas (opcional)</label>
              <textarea value={createForm.notes} onChange={(event) => setCreateForm({ ...createForm, notes: event.target.value })} rows={2} maxLength={2000} placeholder="Método de pago, cuenta de transferencia o detalles..." className="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15 sm:text-sm" />
            </div>

            {createInvoice.isError && <ErrorState message={createInvoice.error instanceof Error ? createInvoice.error.message : 'No se pudo emitir la factura.'} />}

            <div className="flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
              <button type="button" onClick={() => void closeCreate()} className="rounded-xl px-3.5 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
              <LoadingButton type="submit" pending={createInvoice.isPending} className="rounded-xl bg-brand-600 px-4 py-1.5 text-xs font-semibold text-white shadow-2xs transition hover:bg-brand-700 disabled:opacity-60">Emitir factura</LoadingButton>
            </div>
          </form>
        </Modal>
      )}

      {paymentInvoice && (
        <Modal open onClose={() => setPaymentInvoice(null)} panelClassName="w-full max-w-md overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
          <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
            <div className="flex items-center gap-2.5">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600"><Banknote className="h-4 w-4" /></div>
              <div><h3 className="text-base font-semibold text-slate-800">Registrar cobro</h3><p className="max-w-[260px] truncate text-[11px] text-slate-400">{paymentInvoice.number} · {paymentInvoice.concept}</p></div>
            </div>
            <button type="button" onClick={() => setPaymentInvoice(null)} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
          </div>
          <form className="space-y-4 p-6" onSubmit={(event) => {
            event.preventDefault();
            addInvoicePayment.mutate({
              invoice_id: String(paymentInvoice.id),
              amount: payAmount,
              paid_at: payDate,
              method: (new FormData(event.currentTarget).get('method') as string) ?? 'Transferencia',
              notes: (new FormData(event.currentTarget).get('notes') as string) ?? '',
            });
          }}>
            <div className="rounded-xl bg-slate-50 px-3.5 py-3 text-[11px] text-slate-500">
              <div className="flex items-center justify-between"><span>Importe factura</span><span className="font-semibold text-slate-700">{formatMoney(paymentInvoice.amount, invoiceCurrency(paymentInvoice))}</span></div>
              <div className="mt-1 flex items-center justify-between"><span>Cobrado</span><span className="font-semibold text-emerald-700">{formatMoney(paymentInvoice.paid, invoiceCurrency(paymentInvoice))}</span></div>
              <div className="mt-1 flex items-center justify-between"><span>Saldo pendiente</span><span className="font-semibold text-slate-800">{formatMoney(Math.max(0, paymentInvoice.balance), invoiceCurrency(paymentInvoice))}</span></div>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Importe a cobrar *</label>
              <Input inputMode="decimal" value={payAmount} onChange={(event) => setPayAmount(event.target.value)} type="number" required min="0.01" max={Math.max(0, paymentInvoice.balance)} step="0.01" className="px-3.5 py-2.5 text-sm font-semibold" />
              <div className="mt-1.5 flex flex-wrap gap-1.5">
                {[0.25, 0.5].map((factor) => (
                  <button key={factor} type="button" onClick={() => setPayAmount((Math.max(0, paymentInvoice.balance) * factor).toFixed(2))} className="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-200">{factor * 100}%</button>
                ))}
                <button type="button" onClick={() => setPayAmount(Math.max(0, paymentInvoice.balance).toFixed(2))} className="rounded-lg bg-emerald-50 px-2 py-1 text-[10px] font-semibold text-emerald-700 hover:bg-emerald-100">Saldo total</button>
              </div>
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Fecha</label>
                <Input value={payDate} onChange={(event) => setPayDate(event.target.value)} name="paid_at" type="date" required className="px-3.5 py-2.5 text-sm" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Método</label>
                <Select name="method" defaultValue="Transferencia" className="w-full px-3.5 py-2.5 text-sm">{PAYMENT_METHODS.map((method) => <option key={method} value={method}>{method}</option>)}</Select>
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Notas</label>
              <Input name="notes" maxLength={255} placeholder="Ej: Primer pago acordado" className="px-3.5 py-2.5 text-sm" />
            </div>

            {addInvoicePayment.isError && <ErrorState message={addInvoicePayment.error instanceof Error ? addInvoicePayment.error.message : 'No se pudo registrar el cobro.'} />}

            <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => setPaymentInvoice(null)} className="rounded-xl px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
              <LoadingButton type="submit" pending={addInvoicePayment.isPending} className="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60">Guardar cobro</LoadingButton>
            </div>
          </form>
        </Modal>
      )}

      {editingInvoice && (
        <Modal open onClose={closeEdit} panelClassName="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-slate-100 bg-white shadow-2xl">
          <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
            <div className="flex items-center gap-2.5">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Pencil className="h-4 w-4" /></div>
              <div><h3 className="text-base font-semibold text-slate-800">Editar factura</h3><p className="max-w-[280px] truncate text-[11px] text-slate-400">{editingInvoice.number} · {editingInvoice.concept}</p></div>
            </div>
            <button type="button" onClick={() => void closeEdit()} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
          </div>

          <form className="space-y-4 p-6" onChange={() => setEditDirty(true)} onSubmit={(event) => {
            event.preventDefault();
            const form = new FormData(event.currentTarget);
            const body: Record<string, string> = { invoice_id: String(editingInvoice.id) };
            form.forEach((value, key) => { body[key] = String(value); });
            updateInvoice.mutate(body);
          }}>
            {editingInvoice.paid > 0 && (
              <div className="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-[11px] leading-relaxed text-amber-800">
                Esta factura ya tiene cobros por <strong>{formatMoney(editingInvoice.paid, invoiceCurrency(editingInvoice))}</strong>. No puedes cambiar el proyecto ni la moneda, y el importe no puede ser menor que lo cobrado.
              </div>
            )}

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Proyecto</label>
              {editingInvoice.paid > 0 && <input type="hidden" name="project_id" value={editingInvoice.projectId !== null ? String(editingInvoice.projectId) : ''} />}
              <Select
                name={editingInvoice.paid > 0 ? undefined : 'project_id'}
                defaultValue={editingInvoice.projectId !== null ? String(editingInvoice.projectId) : ''}
                disabled={editingInvoice.paid > 0}
                onChange={(event) => {
                  const project = data.projects.find((item) => String(item.id) === event.target.value);
                  if (project) setEditCurrency(project.currency || 'USD');
                }}
                className="w-full px-3.5 py-2.5 text-sm disabled:bg-slate-100 disabled:text-slate-500"
              >
                <option value="">Sin proyecto (cuenta general)</option>
                {data.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
              </Select>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Cliente / Deudor</label>
              <Input name="client_name" list="billing-edit-clients" maxLength={150} defaultValue={editingInvoice.client ?? ''} placeholder="Cliente o deudor" className="px-3.5 py-2.5 text-sm" />
              <datalist id="billing-edit-clients">{data.clients.map((client) => <option key={client} value={client} />)}</datalist>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">N° Factura *</label>
                <Input name="invoice_number" required maxLength={50} defaultValue={editingInvoice.number} className="px-3.5 py-2.5 font-mono text-sm" />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Importe *</label>
                <Input inputMode="decimal" name="amount" type="number" required step="0.01" min={editingInvoice.paid > 0 ? editingInvoice.paid : 0.01} defaultValue={editingInvoice.amount.toFixed(2)} className="px-3.5 py-2.5 text-sm font-semibold" />
              </div>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Moneda</label>
                {editingInvoice.paid > 0 && <input type="hidden" name="currency" value={editingInvoice.currency || 'USD'} />}
                <Select name={editingInvoice.paid > 0 ? undefined : 'currency'} value={editCurrency} onChange={(event) => setEditCurrency(event.target.value)} disabled={editingInvoice.paid > 0} className="w-full px-3.5 py-2.5 font-mono text-sm disabled:bg-slate-100 disabled:text-slate-500">
                  {data.currencies.map((currency) => <option key={currency} value={currency}>{currency}</option>)}
                </Select>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Vencimiento</label>
                <Input name="due_date" type="date" defaultValue={editingInvoice.dueDate ?? ''} className="px-3.5 py-2.5 text-sm" />
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Concepto *</label>
              <Input name="concept" required maxLength={255} defaultValue={editingInvoice.concept} className="px-3.5 py-2.5 text-sm" />
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Notas</label>
              <textarea name="notes" rows={3} maxLength={2000} defaultValue={editingInvoice.notes ?? ''} placeholder="Condiciones, referencia o detalles del cobro…" className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15" />
            </div>

            {updateInvoice.isError && <ErrorState message={updateInvoice.error instanceof Error ? updateInvoice.error.message : 'No se pudo actualizar la factura.'} />}

            <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
              <button type="button" onClick={() => void closeEdit()} className="rounded-xl px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
              <LoadingButton type="submit" pending={updateInvoice.isPending} pendingLabel="Guardando…" className="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60">Guardar cambios</LoadingButton>
            </div>
          </form>
        </Modal>
      )}

      {detailInvoice && (
        <Modal open onClose={() => setDetailId(null)} panelClassName="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
          <div className="flex items-start justify-between gap-3 border-b border-slate-100 bg-slate-50/60 px-5 py-4 sm:px-6">
            <div className="min-w-0">
              <p className="font-mono text-[11px] font-bold text-brand-600">{detailInvoice.number}</p>
              <h3 className="mt-0.5 truncate text-base font-semibold text-slate-900">{detailInvoice.concept}</h3>
              <p className="mt-0.5 truncate text-[11px] text-slate-500">{detailInvoice.client || 'Sin cliente'}{detailInvoice.projectName ? ` · ${detailInvoice.projectName}` : ' · Cuenta general'}</p>
            </div>
            <div className="flex flex-shrink-0 items-center gap-2">
              <span className={`rounded-full border px-2 py-0.5 text-[11px] font-semibold ${STATUS_BADGES[statusOf(detailInvoice)] ?? 'bg-slate-50 text-slate-700 border-slate-200'}`}>{STATUS_LABELS[statusOf(detailInvoice)] ?? statusOf(detailInvoice)}</span>
              <button type="button" onClick={() => setDetailId(null)} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X className="h-4 w-4" /></button>
            </div>
          </div>

          <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-5 sm:p-6">
            {detailInvoice.overdue && (
              <div className="flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-xs font-semibold text-rose-700"><AlertTriangle className="h-4 w-4" /> Vencida hace {detailInvoice.daysOverdue} día{detailInvoice.daysOverdue === 1 ? '' : 's'} ({detailInvoice.dueDate ? formatDate(detailInvoice.dueDate) : ''}).</div>
            )}

            <div className="grid grid-cols-3 gap-2 rounded-xl border border-slate-200/80 p-3 text-center sm:p-4">
              <div><p className="text-[10px] uppercase tracking-wider text-slate-400">Importe</p><p className="mt-0.5 text-sm font-bold text-slate-900 sm:text-base">{formatMoney(detailInvoice.amount, invoiceCurrency(detailInvoice))}</p></div>
              <div><p className="text-[10px] uppercase tracking-wider text-slate-400">Cobrado</p><p className="mt-0.5 text-sm font-bold text-emerald-700 sm:text-base">{formatMoney(detailInvoice.paid, invoiceCurrency(detailInvoice))}</p></div>
              <div><p className="text-[10px] uppercase tracking-wider text-slate-400">Saldo</p><p className="mt-0.5 text-sm font-bold text-slate-900 sm:text-base">{formatMoney(Math.max(0, detailInvoice.balance), invoiceCurrency(detailInvoice))}</p></div>
            </div>

            <div className="flex flex-wrap gap-2">
              {detailInvoice.status !== 'anulada' && detailInvoice.balance > 0 && (
                <button type="button" onClick={() => { setDetailId(null); openPayment(detailInvoice); }} className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700"><Banknote className="h-3.5 w-3.5" /> Registrar cobro</button>
              )}
              {detailInvoice.status === 'pendiente' && detailInvoice.balance > 0 && (
                <button type="button" disabled={setInvoiceStatus.isPending} onClick={() => void askStatus(detailInvoice, 'pagada', 'Marcar como cobrada', 'Se registrará el cobro del saldo pendiente y se actualizará caja.')} className="inline-flex items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:opacity-60"><CheckCircle2 className="h-3.5 w-3.5" /> Marcar cobrada</button>
              )}
              <button type="button" onClick={() => { setDetailId(null); setEditingInvoice(detailInvoice); setEditCurrency(invoiceCurrency(detailInvoice)); setEditDirty(false); }} className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"><Pencil className="h-3.5 w-3.5" /> Editar</button>
              {detailInvoice.status === 'anulada' ? (
                <button type="button" disabled={setInvoiceStatus.isPending} onClick={() => void askStatus(detailInvoice, 'reactivate', 'Reactivar factura', 'Volverá a su estado según los cobros registrados y se restaurarán sus movimientos de caja.')} className="inline-flex items-center gap-1.5 rounded-xl border border-emerald-200 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-50 disabled:opacity-60"><RotateCcw className="h-3.5 w-3.5" /> Reactivar</button>
              ) : (
                <button type="button" disabled={setInvoiceStatus.isPending} onClick={() => void askStatus(detailInvoice, 'anulada', 'Anular factura', 'Dejará de contar como pendiente y sus cobros se quitarán de caja.')} className="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 disabled:opacity-60"><Ban className="h-3.5 w-3.5" /> Anular</button>
              )}
              <button type="button" disabled={deleteInvoice.isPending} onClick={() => void askDelete(detailInvoice)} className="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 disabled:opacity-60"><Trash2 className="h-3.5 w-3.5" /> Eliminar</button>
            </div>

            {detailInvoice.notes && <div className="rounded-xl bg-slate-50 px-3.5 py-3 text-xs leading-relaxed text-slate-600"><p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Notas</p>{detailInvoice.notes}</div>}

            <section>
              <div className="flex items-center justify-between">
                <h4 className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-800"><History className="h-3.5 w-3.5 text-slate-400" /> Historial de cobros</h4>
                <span className="text-[10px] text-slate-400">{detailInvoice.payments.length} registro{detailInvoice.payments.length === 1 ? '' : 's'}</span>
              </div>
              <div className="mt-2 space-y-1.5">
                {detailInvoice.payments.length === 0 && <p className="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-center text-[11px] text-slate-400">Aún no hay cobros registrados.</p>}
                {detailInvoice.payments.map((payment) => (
                  <div key={payment.id} className="flex items-center justify-between gap-2 rounded-xl border border-slate-200/70 px-3 py-2">
                    <div className="min-w-0">
                      <p className="text-xs font-semibold text-slate-800">{formatMoney(payment.amount, payment.currency)}</p>
                      <p className="truncate text-[10px] text-slate-400">{formatDate(payment.paidAt)}{payment.method ? ` · ${payment.method}` : ''}{payment.user ? ` · ${payment.user}` : ''}</p>
                      {payment.notes && <p className="truncate text-[10px] text-slate-500">{payment.notes}</p>}
                    </div>
                    <button type="button" disabled={deleteInvoicePayment.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar cobro', description: 'El saldo y el estado de la factura se recalcularán. También se quitará de caja.', confirmLabel: 'Eliminar', tone: 'danger' })) deleteInvoicePayment.mutate(payment.id); }} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar cobro"><Trash2 className="h-3.5 w-3.5" /></button>
                  </div>
                ))}
              </div>
            </section>

            <section>
              <h4 className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-800"><Paperclip className="h-3.5 w-3.5 text-slate-400" /> Documentos</h4>
              <div className="mt-2 space-y-1.5">
                {docsQuery.isLoading && <p className="text-[11px] text-slate-400">Cargando documentos…</p>}
                {docsQuery.isError && <p className="text-[11px] text-rose-600">{docsQuery.error instanceof Error ? docsQuery.error.message : 'No se pudieron cargar los documentos.'}</p>}
                {docsQuery.data && (docsQuery.data.documents ?? []).length === 0 && <p className="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-center text-[11px] text-slate-400">Sin documentos todavía.</p>}
                {(docsQuery.data?.documents ?? []).map((doc) => (
                  <div key={doc.id} className="flex items-center gap-2 rounded-xl border border-slate-200/70 px-3 py-2">
                    <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500"><FileText className="h-4 w-4" /></span>
                    <div className="min-w-0 flex-1"><p className="truncate text-xs font-semibold text-slate-800">{doc.name}</p><p className="text-[10px] text-slate-400">{doc.size_formatted || ''}{doc.size_formatted && doc.created_at ? ' · ' : ''}{doc.created_at || ''}</p></div>
                    <a href={doc.download_url} className="rounded-lg p-1.5 text-slate-400 hover:bg-brand-50 hover:text-brand-600" title="Descargar"><Download className="h-4 w-4" /></a>
                    <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar documento', description: 'El archivo se quitará de esta factura.', confirmLabel: 'Eliminar', tone: 'danger' })) deleteDocument.mutate(doc.id); }} className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Eliminar documento"><Trash2 className="h-4 w-4" /></button>
                  </div>
                ))}
              </div>
              {detailInvoice.projectId !== null ? (
                <form className="mt-2 space-y-2 border-t border-slate-100 pt-3" onSubmit={(event) => {
                  event.preventDefault();
                  const file = (event.currentTarget.elements.namedItem('file') as HTMLInputElement | null)?.files?.[0];
                  if (!file) { setDocError('Selecciona un archivo para subir.'); return; }
                  event.currentTarget.reset();
                  uploadDocument.mutate(file);
                }}>
                  <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <input type="file" name="file" required className="block w-full flex-1 rounded-xl border border-slate-200 px-2 py-1.5 text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-brand-700 hover:file:bg-brand-100" />
                    <LoadingButton type="submit" pending={uploadDocument.isPending} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60">Subir documento</LoadingButton>
                  </div>
                  {docError && <p className="text-[11px] text-rose-600">{docError}</p>}
                </form>
              ) : (
                <p className="mt-2 text-[10px] text-slate-400">Los documentos se guardan en la ficha del proyecto. Asocia la factura a un proyecto para adjuntar archivos.</p>
              )}
            </section>
          </div>
        </Modal>
      )}
    </div>
  );
}
