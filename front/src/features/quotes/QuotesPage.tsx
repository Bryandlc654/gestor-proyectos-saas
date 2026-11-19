import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Check, CheckCircle2, Clock, Copy, Download, Eye, FilePlus, FileText, GripVertical, LayoutGrid, List, Mail, Pencil, PieChart, Plus, RotateCcw, Search, Send, Target, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Card, ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { Modal, useConfirm, useToast } from '../../components/feedback';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate, formatMoney, formatTotals } from '../../lib/format';
import type { QuotesData } from '../../types';

type Quote = QuotesData['quotes'][number];
type ViewKey = 'pipeline' | 'table';

const STATUS_STYLES: Record<string, { badge: string; dot: string }> = {
  borrador: { badge: 'bg-slate-100 text-slate-600 border-slate-200/80', dot: 'bg-slate-400' },
  enviada: { badge: 'bg-blue-50 text-blue-700 border-blue-200/80', dot: 'bg-blue-500' },
  aceptada: { badge: 'bg-emerald-50 text-emerald-700 border-emerald-200/80', dot: 'bg-emerald-500' },
  rechazada: { badge: 'bg-rose-50 text-rose-700 border-rose-200/80', dot: 'bg-rose-500' },
};

const PIPELINE_STATUSES = ['borrador', 'enviada', 'aceptada', 'rechazada'];

function todayString(): string {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function expiryInfo(quote: Quote): { label: string; tone: string } | null {
  if (!quote.validUntil || quote.status === 'aceptada' || quote.status === 'rechazada') return null;
  const today = todayString();
  if (quote.validUntil < today) return { label: 'Vencida', tone: 'border-rose-200 bg-rose-50 text-rose-700' };
  const days = Math.ceil((new Date(`${quote.validUntil}T23:59:59`).getTime() - Date.now()) / 86400000);
  if (days <= 7) return { label: `Vence en ${days}d`, tone: 'border-amber-200 bg-amber-50 text-amber-700' };
  return null;
}

function PdfPreviewModal({ quote, onClose }: { quote: Quote | null; onClose: () => void }) {
  if (!quote) return null;
  return (
    <Modal open onClose={onClose} panelClassName="flex h-[85vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
      <div className="flex items-center justify-between gap-3 border-b border-slate-100 bg-slate-50/70 px-5 py-4">
        <div className="min-w-0"><h3 className="truncate text-sm font-semibold text-slate-900">Vista previa · {quote.number}</h3><p className="truncate text-[11px] text-slate-400">{quote.title}</p></div>
        <div className="flex items-center gap-2">
          <a href={`/quote-pdf?id=${quote.id}`} download className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-[11px] font-semibold text-white"><Download className="h-3.5 w-3.5" /> Descargar</a>
          <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100"><X className="h-4 w-4" /></button>
        </div>
      </div>
      <iframe src={`/quote-pdf?id=${quote.id}&preview=1`} title={`PDF ${quote.number}`} className="min-h-0 flex-1 bg-slate-100" />
    </Modal>
  );
}

export function QuotesPage() {
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const toast = useToast();
  const [status, setStatus] = useState('todos');
  const [search, setSearch] = useState('');
  const [searchParams] = useSearchParams();
  const [projectId, setProjectId] = useState(() => searchParams.get('project_id') ?? '');
  const [view, setView] = useState<ViewKey>(() => (typeof window !== 'undefined' && window.innerWidth < 768 ? 'table' : 'pipeline'));
  const [previewQuote, setPreviewQuote] = useState<Quote | null>(null);
  const [draggingId, setDraggingId] = useState<number | null>(null);
  const [dragOverStatus, setDragOverStatus] = useState<string | null>(null);

  const { data, isLoading, error } = useQuery({
    queryKey: ['quotes', status, search, projectId],
    queryFn: () => apiGet<QuotesData>('quotes.php', { status, search, project_id: projectId }),
  });

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['quotes'] });
    void queryClient.invalidateQueries({ queryKey: ['quote'] });
    void queryClient.invalidateQueries({ queryKey: ['crm'] });
  };

  const changeStatus = useMutation({
    mutationFn: ({ id, nextStatus }: { id: number; nextStatus: string }) => apiPost<{ success: boolean }>('quotes.php', { action: 'set_status', quote_id: id, status: nextStatus }),
    onSuccess: (_result, variables) => { refresh(); toast(`Cotización marcada como ${variables.nextStatus}.`); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo cambiar el estado.', 'error'),
  });

  const sendQuote = useMutation({
    mutationFn: (id: number) => apiPost<{ success: boolean; message: string }>('quotes.php', { action: 'send', quote_id: id }),
    onSuccess: (result) => { refresh(); toast(result.message); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo enviar la cotización.', 'error'),
  });

  const duplicateQuote = useMutation({
    mutationFn: (id: number) => apiPost<{ success: boolean; id: number; number: string }>('quotes.php', { action: 'duplicate', quote_id: id }),
    onSuccess: (result) => { refresh(); toast(`Cotización duplicada como ${result.number}.`); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo duplicar la cotización.', 'error'),
  });

  const deleteQuote = useMutation({
    mutationFn: (id: number) => apiPost<{ success: boolean }>('quotes.php', { action: 'delete', quote_id: id }),
    onSuccess: () => { refresh(); toast('Cotización eliminada.'); },
    onError: (mutationError) => toast(mutationError instanceof Error ? mutationError.message : 'No se pudo eliminar la cotización.', 'error'),
  });

  if (isLoading) return <div className="space-y-5"><div className="grid grid-cols-2 gap-4 lg:grid-cols-4">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} className="h-32" />)}</div><Skeleton className="h-80" /></div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudieron cargar las cotizaciones.'} />;

  const filtered = search !== '' || status !== 'todos' || projectId !== '';
  const quotesByStatus: Record<string, Quote[]> = {};
  PIPELINE_STATUSES.forEach((key) => { quotesByStatus[key] = []; });
  data.quotes.forEach((quote) => { (quotesByStatus[quote.status] ??= []).push(quote); });

  const askSend = async (quote: Quote) => {
    if (!quote.clientEmail) {
      toast('La cotización no tiene email de cliente. Edítala antes de enviarla.', 'error');
      return;
    }
    if (await confirm({ title: 'Enviar cotización', description: `Se enviará ${quote.number} a ${quote.clientEmail}.`, confirmLabel: 'Enviar', cancelLabel: 'Cancelar' })) sendQuote.mutate(quote.id);
  };
  const askAccept = async (quote: Quote) => {
    if (await confirm({ title: 'Aceptar cotización', description: `${quote.number} se marcará como aceptada.`, confirmLabel: 'Aceptar' })) changeStatus.mutate({ id: quote.id, nextStatus: 'aceptada' });
  };
  const askReject = async (quote: Quote) => {
    if (await confirm({ title: 'Rechazar cotización', description: `${quote.number} se marcará como rechazada.`, confirmLabel: 'Rechazar', tone: 'danger' })) changeStatus.mutate({ id: quote.id, nextStatus: 'rechazada' });
  };
  const askDelete = async (quote: Quote) => {
    if (await confirm({ title: 'Eliminar cotización', description: quote.title, confirmLabel: 'Eliminar', tone: 'danger' })) deleteQuote.mutate(quote.id);
  };

  const quickActions = (quote: Quote, compact = false) => (
    <div className="inline-flex items-center justify-end gap-0.5">
      <Link to={`/quotes/${quote.id}`} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-brand-50 hover:text-brand-600" title="Ver detalle"><Eye className="h-3.5 w-3.5" /></Link>
      <Link to={`/quotes/${quote.id}/edit`} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title="Editar"><Pencil className="h-3.5 w-3.5" /></Link>
      <button type="button" onClick={() => setPreviewQuote(quote)} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Vista previa PDF"><Download className="h-3.5 w-3.5" /></button>
      <button type="button" onClick={() => void askSend(quote)} disabled={sendQuote.isPending} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-blue-50 hover:text-blue-600 disabled:opacity-50" title="Enviar por email"><Send className="h-3.5 w-3.5" /></button>
      {!compact && quote.status !== 'aceptada' && <button type="button" onClick={() => void askAccept(quote)} disabled={changeStatus.isPending} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-emerald-50 hover:text-emerald-600 disabled:opacity-50" title="Aceptar"><Check className="h-3.5 w-3.5" /></button>}
      {!compact && quote.status !== 'rechazada' && quote.status !== 'aceptada' && <button type="button" onClick={() => void askReject(quote)} disabled={changeStatus.isPending} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 disabled:opacity-50" title="Rechazar"><X className="h-3.5 w-3.5" /></button>}
      <button type="button" onClick={() => duplicateQuote.mutate(quote.id)} disabled={duplicateQuote.isPending} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50" title="Duplicar"><Copy className="h-3.5 w-3.5" /></button>
      {!compact && <button type="button" onClick={() => void askDelete(quote)} disabled={deleteQuote.isPending} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 disabled:opacity-50" title="Eliminar"><Trash2 className="h-3.5 w-3.5" /></button>}
    </div>
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Cotizaciones</h1><p className="hidden text-[11px] text-slate-400 sm:block">Propuestas comerciales con seguimiento, envío y PDF</p></div>
        <Link to="/quotes/new" className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 hover:bg-brand-700 sm:text-sm"><Plus className="h-4 w-4" /> Nueva cotización</Link>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <Card className="space-y-2 p-5"><div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Total cotizado</span><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-50 text-slate-400"><FileText className="h-4 w-4" /></span></div><p className="text-2xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[26px]">{formatTotals(Object.fromEntries(Object.entries(data.totals).map(([currency, value]) => [currency, value.total])))}</p><span className="text-xs font-medium text-slate-400">{data.stats.count} cotizaciones</span></Card>
        <Card className="space-y-2 p-5"><div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Aceptadas</span><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-500"><CheckCircle2 className="h-4 w-4" /></span></div><p className="text-2xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[26px]">{formatTotals(data.acceptedTotals)}</p><span className="text-xs font-medium text-slate-400">{data.stats.countAccepted} aceptadas</span></Card>
        <Card className="space-y-2 p-5"><div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">En negociación</span><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-500"><Clock className="h-4 w-4" /></span></div><p className="text-2xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[26px]">{formatTotals(data.pendingTotals)}</p><span className="text-xs font-medium text-slate-400">{data.stats.countPending} borradores o enviadas</span></Card>
        <Card className="space-y-2 p-5"><div className="flex items-center justify-between"><span className="text-xs font-medium text-slate-500">Tasa de aceptación</span><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-500"><PieChart className="h-4 w-4" /></span></div><p className="text-2xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[26px]">{data.stats.acceptanceRate}%</p><span className="text-xs font-medium text-slate-400">del importe cotizado</span></Card>
      </div>

      <Card className="p-4">
        <div className="flex w-full flex-col items-center gap-3 md:flex-row">
          <div className="relative w-full flex-1"><Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar por número, título, cliente, proyecto u oportunidad..." className="pl-10" /></div>
          <div className="flex w-full flex-wrap items-center gap-2 md:w-auto">
            <div className="flex items-center rounded-xl bg-slate-100 p-0.5">
              <button type="button" onClick={() => setView('pipeline')} className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ${view === 'pipeline' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}><LayoutGrid className="h-3.5 w-3.5" /><span className="hidden sm:inline">Pipeline</span></button>
              <button type="button" onClick={() => setView('table')} className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ${view === 'table' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}><List className="h-3.5 w-3.5" /><span className="hidden sm:inline">Tabla</span></button>
            </div>
            <Select value={status} onChange={(event) => setStatus(event.target.value)}><option value="todos">Todos los estados</option>{data.statuses.map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}</Select>
            <Select value={projectId} onChange={(event) => setProjectId(event.target.value)} className="max-w-[200px]"><option value="0">Todos los proyectos</option>{data.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}</Select>
            {filtered && <button type="button" onClick={() => { setSearch(''); setStatus('todos'); setProjectId(''); }} className="whitespace-nowrap rounded-xl px-3 py-2 text-xs font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-800"><RotateCcw className="mr-1 inline h-3.5 w-3.5" />Limpiar</button>}
          </div>
        </div>
      </Card>

      {view === 'pipeline' ? (
        <div className="flex items-start gap-3 overflow-x-auto pb-3">
          {PIPELINE_STATUSES.map((statusKey) => {
            const style = STATUS_STYLES[statusKey];
            const columnQuotes = quotesByStatus[statusKey] ?? [];
            const totals: Record<string, number> = {};
            columnQuotes.forEach((quote) => { totals[quote.currency] = (totals[quote.currency] ?? 0) + quote.total; });
            return (
              <div key={statusKey} className="w-[290px] flex-shrink-0">
                <div className="mb-2 flex items-center justify-between gap-2 px-1"><div className="flex items-center gap-2"><span className={`h-2 w-2 rounded-full ${style.dot}`} /><h2 className="text-xs font-bold capitalize text-slate-700">{data.statuses.find((item) => item.key === statusKey)?.label ?? statusKey}</h2><span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-400">{columnQuotes.length}</span></div><span className="text-[10px] font-semibold text-slate-400">{formatTotals(totals)}</span></div>
                <div onDragOver={(event) => { if (draggingId === null) return; event.preventDefault(); setDragOverStatus(statusKey); }} onDragLeave={() => setDragOverStatus((current) => current === statusKey ? null : current)} onDrop={(event) => { event.preventDefault(); const id = Number(event.dataTransfer.getData('text/plain')) || draggingId; setDraggingId(null); setDragOverStatus(null); if (id) changeStatus.mutate({ id, nextStatus: statusKey }); }} className={`min-h-[120px] space-y-2 rounded-xl p-2 transition ${dragOverStatus === statusKey ? 'bg-brand-50 ring-2 ring-brand-300' : 'bg-slate-100/60'}`}>
                  {columnQuotes.length === 0 ? <p className="py-8 text-center text-[11px] text-slate-400">Sin cotizaciones</p> : columnQuotes.map((quote) => {
                    const expiry = expiryInfo(quote);
                    return (
                      <article key={quote.id} className="group space-y-2 rounded-xl border border-slate-200/80 bg-white p-3.5 shadow-2xs transition hover:border-slate-300">
                        <div className="flex items-start justify-between gap-2">
                          <div className="flex min-w-0 items-start gap-1">
                            <span draggable onDragStart={(event) => { event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', String(quote.id)); setDraggingId(quote.id); }} onDragEnd={() => { setDraggingId(null); setDragOverStatus(null); }} className="-ml-1 mt-0.5 cursor-grab rounded p-0.5 text-slate-300 opacity-0 transition group-hover:opacity-100" title="Mover estado"><GripVertical className="h-3.5 w-3.5" /></span>
                            <div className="min-w-0"><Link to={`/quotes/${quote.id}`} className="font-mono text-[10px] font-bold text-brand-600 hover:underline">{quote.number}</Link><h3 className="truncate text-sm font-semibold text-slate-800">{quote.title}</h3></div>
                          </div>
                          {quote.version > 1 && <span className="flex-shrink-0 rounded-full bg-indigo-50 px-1.5 py-0.5 text-[9px] font-bold text-indigo-600">v{quote.version}</span>}
                        </div>
                        <p className="truncate text-[11px] text-slate-500">{quote.client}{quote.company ? ` · ${quote.company}` : ''}</p>
                        <div className="flex items-center justify-between gap-2"><span className="text-xs font-bold text-slate-800">{formatMoney(quote.total, quote.currency)}</span>{quote.validUntil && <span className="text-[10px] text-slate-400">{formatDate(quote.validUntil)}</span>}</div>
                        {expiry && <span className={`inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[9px] font-semibold ${expiry.tone}`}><AlertTriangle className="h-3 w-3" />{expiry.label}</span>}
                        {quote.dealTitle && <p className="flex items-center gap-1 truncate text-[10px] font-semibold text-brand-600"><Target className="h-3 w-3" />{quote.dealTitle}</p>}
                        {quote.emailCount > 0 && <p className="flex items-center gap-1 text-[10px] text-slate-400"><Mail className="h-3 w-3" /> Enviada {quote.emailCount} vez{quote.emailCount === 1 ? '' : 'es'}</p>}
                        <div className="flex items-center justify-between gap-1 border-t border-slate-100 pt-2"><span className="text-[10px] text-slate-400">{quote.itemCount} ítem{quote.itemCount === 1 ? '' : 's'}</span><div className="opacity-0 transition group-hover:opacity-100">{quickActions(quote, true)}</div></div>
                      </article>
                    );
                  })}
                  <Link to="/quotes/new" className="flex w-full items-center justify-center gap-1 rounded-lg border border-dashed border-slate-300 py-2 text-[10px] font-semibold text-slate-400 transition hover:border-brand-300 hover:text-brand-600"><Plus className="h-3 w-3" /> Nueva cotización</Link>
                </div>
              </div>
            );
          })}
        </div>
      ) : (
        <Card className="overflow-hidden">
          <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div className="flex items-center gap-2"><h3 className="text-sm font-semibold text-slate-900">Listado de cotizaciones</h3><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{data.stats.count}</span></div></div>
          {data.quotes.length === 0 ? (
            <div className="p-12 text-center">
              <div className={`mx-auto flex h-14 w-14 items-center justify-center rounded-2xl ${filtered ? 'bg-slate-100 text-slate-400' : 'bg-brand-50 text-brand-500'}`}>{filtered ? <Search className="h-6 w-6" /> : <FilePlus className="h-6 w-6" />}</div>
              <h3 className="mt-4 font-semibold text-slate-900">{filtered ? 'No hay cotizaciones que coincidan' : 'Aún no hay cotizaciones'}</h3>
              <p className="mt-1 text-sm text-slate-500">{filtered ? 'Prueba con otro estado, proyecto o término de búsqueda.' : 'Crea tu primera propuesta y descárgala en PDF con el logo de tu empresa.'}</p>
              {filtered ? <button type="button" onClick={() => { setSearch(''); setStatus('todos'); setProjectId(''); }} className="mt-5 inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"><RotateCcw className="h-4 w-4" />Limpiar filtros</button> : <Link to="/quotes/new" className="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700"><Plus className="h-4 w-4" />Nueva cotización</Link>}
            </div>
          ) : (
            <>
              <div className="hidden overflow-x-auto md:block">
                <table className="w-full text-left text-xs text-slate-600">
                  <thead className="border-b border-slate-100 bg-slate-50/70 text-[11px] font-medium text-slate-500"><tr><th className="px-4 py-3">N° / Título</th><th className="px-4 py-3">Cliente</th><th className="px-4 py-3">Proyecto</th><th className="px-4 py-3">Validez</th><th className="px-4 py-3 text-right">Total</th><th className="px-4 py-3">Estado</th><th className="px-4 py-3 text-right">Acciones</th></tr></thead>
                  <tbody className="divide-y divide-slate-100">
                    {data.quotes.map((quote) => {
                      const style = STATUS_STYLES[quote.status] ?? STATUS_STYLES.borrador;
                      const expiry = expiryInfo(quote);
                      return (
                        <tr key={quote.id} className="transition hover:bg-slate-50/60">
                          <td className="min-w-[210px] px-4 py-3"><Link to={`/quotes/${quote.id}`} className="font-mono text-[11px] font-bold text-brand-600 hover:text-brand-700">{quote.number}</Link>{quote.version > 1 && <span className="ml-1 rounded-full bg-indigo-50 px-1.5 py-0.5 text-[9px] font-bold text-indigo-600">v{quote.version}</span>}<div className="mt-0.5 max-w-[260px] truncate font-medium text-slate-800">{quote.title}</div><div className="mt-0.5 text-[11px] text-slate-400">{quote.itemCount} {quote.itemCount === 1 ? 'ítem' : 'ítems'}</div>{quote.dealTitle && <span className="mt-1 inline-flex max-w-[250px] items-center gap-1 text-[10px] font-semibold text-brand-600"><Target className="h-3 w-3" /><span className="truncate">{quote.dealTitle}</span></span>}</td>
                          <td className="max-w-[190px] px-4 py-3"><div className="truncate font-medium text-slate-800">{quote.client}</div>{quote.company && <div className="truncate text-[11px] text-slate-400">{quote.company}</div>}</td>
                          <td className="whitespace-nowrap px-4 py-3">{quote.projectName ? <Link to={`/projects/${quote.projectId}?tab=finanzas`} className="inline-flex max-w-[190px] items-center gap-1.5 font-semibold text-slate-700 hover:text-brand-600"><span className="h-2 w-2 flex-shrink-0 rounded-full" style={{ backgroundColor: quote.projectColor }} /><span className="truncate">{quote.projectName}</span></Link> : <span className="text-slate-400">Sin proyecto</span>}</td>
                          <td className="whitespace-nowrap px-4 py-3"><span className="text-slate-500">{quote.validUntil ? formatDate(quote.validUntil) : '—'}</span>{expiry && <div className={`mt-0.5 inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[9px] font-semibold ${expiry.tone}`}><AlertTriangle className="h-2.5 w-2.5" />{expiry.label}</div>}</td>
                          <td className="whitespace-nowrap px-4 py-3 text-right font-bold text-slate-900">{formatMoney(quote.total, quote.currency)}</td>
                          <td className="whitespace-nowrap px-4 py-3"><span className={`inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-semibold ${style.badge}`}><span className={`h-1.5 w-1.5 rounded-full ${style.dot}`} />{quote.statusLabel}</span>{quote.emailCount > 0 && <div className="mt-1 flex items-center gap-1 text-[9px] text-slate-400"><Mail className="h-2.5 w-2.5" />{quote.emailCount}</div>}</td>
                          <td className="whitespace-nowrap px-4 py-3 text-right">{quickActions(quote)}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
              <div className="divide-y divide-slate-100 md:hidden">
                {data.quotes.map((quote) => {
                  const style = STATUS_STYLES[quote.status] ?? STATUS_STYLES.borrador;
                  const expiry = expiryInfo(quote);
                  return (
                    <div key={quote.id} className="space-y-3 p-4">
                      <div className="flex items-start justify-between gap-2"><div className="min-w-0"><Link to={`/quotes/${quote.id}`} className="font-mono text-[11px] font-bold text-brand-600">{quote.number}</Link><p className="truncate text-sm font-semibold text-slate-800">{quote.title}</p><p className="truncate text-[11px] text-slate-500">{quote.client}{quote.company ? ` · ${quote.company}` : ''}</p></div><span className={`flex-shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-semibold ${style.badge}`}>{quote.statusLabel}</span></div>
                      <div className="flex items-end justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2"><div><p className="text-[10px] text-slate-400">Total</p><p className="text-sm font-bold text-slate-900">{formatMoney(quote.total, quote.currency)}</p></div><div className="text-right text-[10px] text-slate-500">{quote.validUntil ? `Válida hasta ${formatDate(quote.validUntil)}` : 'Sin validez'}{expiry && <p className={`font-semibold ${expiry.tone.includes('rose') ? 'text-rose-600' : 'text-amber-600'}`}>{expiry.label}</p>}</div></div>
                      <div className="flex justify-end">{quickActions(quote)}</div>
                    </div>
                  );
                })}
              </div>
            </>
          )}
        </Card>
      )}

      <PdfPreviewModal quote={previewQuote} onClose={() => setPreviewQuote(null)} />
    </div>
  );
}
