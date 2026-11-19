import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ArrowLeft, Check, Copy, Download, ExternalLink, FileText, FolderOpen, Mail, Pencil, Receipt, Send, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Badge, Card, ErrorState, PageHeader, Select, Skeleton } from '../../components/ui';
import { Modal, useConfirm, useToast } from '../../components/feedback';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate, formatMoney } from '../../lib/format';
import type { QuoteDetailData } from '../../types';

function todayString(): string {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function activityDate(value: string | null): string {
  if (!value) return '';
  return value.replace(' ', 'T').slice(0, 16).replace('T', ' · ');
}

export function QuoteDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const toast = useToast();
  const [preview, setPreview] = useState(false);

  const { data, isLoading, error } = useQuery({
    queryKey: ['quote', id],
    queryFn: () => apiGet<QuoteDetailData>('quotes.php', { id }),
    enabled: Boolean(id),
  });

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['quote', id] });
    void queryClient.invalidateQueries({ queryKey: ['quotes'] });
    void queryClient.invalidateQueries({ queryKey: ['crm'] });
  };

  const setStatus = useMutation({
    mutationFn: (status: string) => apiPost<{ success: boolean }>('quotes.php', { action: 'set_status', quote_id: id, status }),
    onSuccess: (_result, status) => { refresh(); toast(`Cotización marcada como ${status}.`); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo cambiar el estado.', 'error'),
  });

  const sendQuote = useMutation({
    mutationFn: () => apiPost<{ success: boolean; message: string }>('quotes.php', { action: 'send', quote_id: id }),
    onSuccess: (result) => { refresh(); toast(result.message); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo enviar la cotización.', 'error'),
  });

  const duplicateQuote = useMutation({
    mutationFn: () => apiPost<{ success: boolean; id: number; number: string }>('quotes.php', { action: 'duplicate', quote_id: id }),
    onSuccess: (result) => { refresh(); toast(`Cotización duplicada como ${result.number}.`); navigate(`/quotes/${result.id}/edit`); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo duplicar la cotización.', 'error'),
  });

  const deleteQuote = useMutation({
    mutationFn: () => apiPost<{ success: boolean }>('quotes.php', { action: 'delete', quote_id: id }),
    onSuccess: () => { toast('Cotización eliminada.'); navigate('/quotes'); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo eliminar la cotización.', 'error'),
  });

  const convertProject = useMutation({
    mutationFn: () => apiPost<{ success: boolean; project_id: number; message: string }>('quotes.php', { action: 'convert_project', quote_id: id }),
    onSuccess: (result) => { refresh(); toast(result.message); navigate(`/projects/${result.project_id}`); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo crear el proyecto.', 'error'),
  });

  const generateInvoice = useMutation({
    mutationFn: () => apiPost<{ success: boolean; invoice_id: number; invoice_number: string; message: string }>('quotes.php', { action: 'generate_invoice', quote_id: id }),
    onSuccess: (result) => { refresh(); toast(`${result.message}${result.invoice_number ? ` (${result.invoice_number})` : ''}`); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo generar la factura.', 'error'),
  });

  if (isLoading) return <div className="space-y-4"><Skeleton className="h-10 w-64" /><Skeleton className="h-64" /></div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar la cotización.'} />;

  const quote = data.quote;
  const expired = quote.validUntil && quote.validUntil < todayString() && quote.status !== 'aceptada' && quote.status !== 'rechazada';
  const accepted = quote.status === 'aceptada';

  const askSend = async () => {
    if (!quote.clientEmail) { toast('Añade un email de cliente antes de enviar.', 'error'); return; }
    if (await confirm({ title: 'Enviar cotización', description: `Se enviará ${quote.number} a ${quote.clientEmail}.`, confirmLabel: 'Enviar', cancelLabel: 'Cancelar' })) sendQuote.mutate();
  };
  const askAccept = async () => {
    if (await confirm({ title: 'Aceptar cotización', description: `${quote.number} se marcará como aceptada.`, confirmLabel: 'Aceptar' })) setStatus.mutate('aceptada');
  };
  const askReject = async () => {
    if (await confirm({ title: 'Rechazar cotización', description: `${quote.number} se marcará como rechazada.`, confirmLabel: 'Rechazar', tone: 'danger' })) setStatus.mutate('rechazada');
  };
  const askDelete = async () => {
    if (await confirm({ title: 'Eliminar cotización', description: quote.title, confirmLabel: 'Eliminar', tone: 'danger' })) deleteQuote.mutate();
  };

  return (
    <div className="space-y-6">
      <Link to="/quotes" className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-900"><ArrowLeft className="h-3.5 w-3.5" /> Volver a cotizaciones</Link>
      <PageHeader
        title={`${quote.number} · ${quote.title}`}
        subtitle={`${quote.clientName}${quote.companyName ? ` · ${quote.companyName}` : ''} · ${formatDate(quote.issueDate)}${quote.version > 1 ? ` · Versión ${quote.version}` : ''}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Link to={`/quotes/${quote.id}/edit`} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"><Pencil className="h-3.5 w-3.5" /> Editar</Link>
            <button type="button" onClick={() => setPreview(true)} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"><Download className="h-3.5 w-3.5" /> Vista previa</button>
            <button type="button" onClick={() => void askSend()} disabled={sendQuote.isPending} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-60"><Send className="h-3.5 w-3.5" /> Enviar</button>
          </div>
        }
      />

      {expired && <div className="flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-semibold text-rose-700"><AlertTriangle className="h-4 w-4" /> Esta cotización venció el {formatDate(quote.validUntil)}.</div>}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div className="space-y-4">
          <Card className="overflow-hidden">
            <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
              <div className="flex flex-wrap items-center gap-2">
                <Badge tone={quote.status === 'aceptada' ? 'emerald' : quote.status === 'rechazada' ? 'rose' : quote.status === 'enviada' ? 'brand' : 'slate'}>{quote.statusLabel}</Badge>
                {quote.projectName && <span className="text-[11px] text-slate-400">{quote.projectName}</span>}
                {quote.emailCount > 0 && <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500"><Mail className="h-3 w-3" /> Enviada {quote.emailCount} vez{quote.emailCount === 1 ? '' : 'es'}</span>}
              </div>
              <Select value={quote.status} disabled={setStatus.isPending} onChange={(event) => setStatus.mutate(event.target.value)} className="w-auto">{data.statuses.map((status) => <option key={status.key} value={status.key}>{status.label}</option>)}</Select>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-left">
                <thead className="border-b border-slate-100 bg-slate-50/70 text-[10px] uppercase tracking-wider text-slate-400"><tr><th className="px-5 py-2.5 font-semibold">Descripción</th><th className="px-5 py-2.5 text-right font-semibold">Cantidad</th><th className="px-5 py-2.5 text-right font-semibold">Precio</th><th className="px-5 py-2.5 text-right font-semibold">Total</th></tr></thead>
                <tbody className="divide-y divide-slate-100">{data.items.map((item) => <tr key={item.id}><td className="px-5 py-3 text-xs text-slate-700">{item.description}</td><td className="px-5 py-3 text-right text-xs text-slate-600">{item.quantity}</td><td className="px-5 py-3 text-right text-xs text-slate-600">{formatMoney(item.unitPrice, quote.currency)}</td><td className="px-5 py-3 text-right text-xs font-semibold text-slate-800">{formatMoney(item.total, quote.currency)}</td></tr>)}</tbody>
              </table>
            </div>
            <div className="border-t border-slate-100 p-5">
              <div className="ml-auto max-w-xs space-y-1.5 text-xs">
                <div className="flex justify-between text-slate-500"><span>Subtotal</span><span>{formatMoney(quote.subtotal, quote.currency)}</span></div>
                {quote.discountAmount > 0 && <div className="flex justify-between text-slate-500"><span>Descuento</span><span>-{formatMoney(quote.discountAmount, quote.currency)}</span></div>}
                {quote.taxAmount > 0 && <div className="flex justify-between text-slate-500"><span>Impuesto ({quote.taxRate}%)</span><span>{formatMoney(quote.taxAmount, quote.currency)}</span></div>}
                <div className="flex justify-between border-t border-slate-200 pt-2 text-sm font-semibold text-slate-900"><span>Total</span><span>{formatMoney(quote.total, quote.currency)}</span></div>
              </div>
            </div>
          </Card>

          {(quote.notes || quote.terms) && <div className="grid gap-4 md:grid-cols-2">{quote.notes && <Card className="p-5"><h2 className="text-xs font-semibold text-slate-800">Notas</h2><p className="mt-2 whitespace-pre-line text-xs text-slate-600">{quote.notes}</p></Card>}{quote.terms && <Card className="p-5"><h2 className="text-xs font-semibold text-slate-800">Términos</h2><p className="mt-2 whitespace-pre-line text-xs text-slate-600">{quote.terms}</p></Card>}</div>}
        </div>

        <div className="space-y-4">
          <Card className="p-5">
            <h2 className="text-xs font-semibold text-slate-800">Acciones</h2>
            <div className="mt-3 grid gap-2">
              {!accepted && quote.status !== 'rechazada' && <button type="button" onClick={() => void askAccept()} disabled={setStatus.isPending} className="inline-flex items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60"><Check className="h-3.5 w-3.5" /> Marcar aceptada</button>}
              {quote.status !== 'rechazada' && !accepted && <button type="button" onClick={() => void askReject()} disabled={setStatus.isPending} className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-600 transition hover:bg-rose-100 disabled:opacity-60"><X className="h-3.5 w-3.5" /> Rechazar</button>}
              <button type="button" onClick={() => duplicateQuote.mutate()} disabled={duplicateQuote.isPending} className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 disabled:opacity-60"><Copy className="h-3.5 w-3.5" /> Duplicar</button>
              <button type="button" onClick={() => void askDelete()} disabled={deleteQuote.isPending} className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 disabled:opacity-60"><Trash2 className="h-3.5 w-3.5" /> Eliminar</button>
            </div>
          </Card>

          <Card className="space-y-3 p-5">
            <h2 className="text-xs font-semibold text-slate-800">Seguimiento</h2>
            <div className="space-y-2 text-[11px]">
              <div className="flex items-start gap-2"><span className="mt-1 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-slate-300" /><span className="text-slate-500">Creada {formatDate(quote.issueDate)}{quote.createdBy ? ` por ${quote.createdBy}` : ''}</span></div>
              {quote.sentAt && <div className="flex items-start gap-2"><span className="mt-1 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-blue-500" /><span className="text-slate-500">Enviada {activityDate(quote.sentAt)}{quote.emailCount > 1 ? ` · ${quote.emailCount} envíos` : ''}</span></div>}
              {quote.acceptedAt && <div className="flex items-start gap-2"><span className="mt-1 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-emerald-500" /><span className="text-slate-500">Aceptada {activityDate(quote.acceptedAt)}</span></div>}
              {quote.rejectedAt && <div className="flex items-start gap-2"><span className="mt-1 h-1.5 w-1.5 flex-shrink-0 rounded-full bg-rose-500" /><span className="text-slate-500">Rechazada {activityDate(quote.rejectedAt)}</span></div>}
              {!quote.sentAt && !quote.acceptedAt && !quote.rejectedAt && <p className="text-slate-400">Aún sin actividad de envío.</p>}
            </div>
            {quote.parentQuoteId && <Link to={`/quotes/${quote.parentQuoteId}`} className="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-700 hover:underline"><ExternalLink className="h-3 w-3" /> Ver cotización original</Link>}
          </Card>

          <Card className="space-y-3 p-5">
            <h2 className="text-xs font-semibold text-slate-800">Conversión</h2>
            {quote.projectId ? (
              <Link to={`/projects/${quote.projectId}`} className="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700"><FolderOpen className="h-3.5 w-3.5" /> Abrir proyecto</Link>
            ) : (
              <button type="button" disabled={!accepted || convertProject.isPending} onClick={async () => { if (await confirm({ title: 'Crear proyecto', description: 'Se creará un proyecto con el importe y la moneda de esta cotización.', confirmLabel: 'Crear proyecto' })) convertProject.mutate(); }} className="flex w-full items-center justify-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-40"><FolderOpen className="h-3.5 w-3.5" /> Crear proyecto desde cotización</button>
            )}
            {!accepted && <p className="text-[10px] text-slate-400">Marca la cotización como aceptada para habilitar la conversión.</p>}
            {quote.invoiceId ? (
              <Link to={`/finance?tab=cobrar&search=${encodeURIComponent(quote.invoiceNumber ?? '')}`} className="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-semibold text-amber-700"><Receipt className="h-3.5 w-3.5" /> Factura {quote.invoiceNumber ?? `#${quote.invoiceId}`}</Link>
            ) : (
              <button type="button" disabled={!accepted || generateInvoice.isPending} onClick={async () => { if (await confirm({ title: 'Generar factura', description: 'Se creará una cuenta por cobrar con el total de la cotización.', confirmLabel: 'Generar factura' })) generateInvoice.mutate(); }} className="flex w-full items-center justify-center gap-1.5 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-semibold text-amber-700 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-40"><Receipt className="h-3.5 w-3.5" /> Generar factura / cobro</button>
            )}
            {quote.dealTitle && <Link to="/crm?tab=pipeline" className="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-700 hover:underline"><FileText className="h-3 w-3" /> Oportunidad: {quote.dealTitle}</Link>}
          </Card>
        </div>
      </div>

      {setStatus.isError && <ErrorState message="No se pudo cambiar el estado." />}

      <Modal open={preview} onClose={() => setPreview(false)} panelClassName="flex h-[85vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
        <div className="flex items-center justify-between gap-3 border-b border-slate-100 bg-slate-50/70 px-5 py-4"><div><h3 className="text-sm font-semibold text-slate-900">Vista previa · {quote.number}</h3><p className="text-[11px] text-slate-400">{quote.title}</p></div><div className="flex gap-2"><a href={`/quote-pdf?id=${quote.id}`} download className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-[11px] font-semibold text-white"><Download className="h-3.5 w-3.5" /> Descargar</a><button type="button" onClick={() => setPreview(false)} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X className="h-4 w-4" /></button></div></div>
        <iframe src={`/quote-pdf?id=${quote.id}&preview=1`} title={`PDF ${quote.number}`} className="min-h-0 flex-1 bg-slate-100" />
      </Modal>
    </div>
  );
}
