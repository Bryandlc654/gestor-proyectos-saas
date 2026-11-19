import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ArrowDown, ArrowLeft, ArrowUp, Copy, CopyPlus, Plus, Save, Target, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Card, ErrorState, Input, PageHeader, Select, Skeleton } from '../../components/ui';
import { LoadingButton, useToast } from '../../components/feedback';
import { useUnsavedChanges } from '../../hooks/useUnsavedChanges';
import { apiGet, apiPost } from '../../lib/api';
import { formatMoney } from '../../lib/format';
import type { CrmDealSummary, QuoteDetailData, QuotesData } from '../../types';

interface LineItem {
  description: string;
  quantity: string;
  unitPrice: string;
}

interface QuoteForm {
  title: string;
  clientName: string;
  clientCompany: string;
  clientEmail: string;
  projectId: string;
  issueDate: string;
  validUntil: string;
  currency: string;
  discountType: string;
  discountValue: string;
  taxRate: string;
  notes: string;
  terms: string;
  status: string;
}

const CURRENCIES = ['USD', 'EUR', 'MXN', 'PEN', 'COP', 'CLP', 'ARS'];

const emptyItem: LineItem = { description: '', quantity: '1', unitPrice: '0' };

function todayIso(): string {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function createDefaultForm(): QuoteForm {
  return {
    title: '',
    clientName: '',
    clientCompany: '',
    clientEmail: '',
    projectId: '',
    issueDate: todayIso(),
    validUntil: '',
    currency: 'USD',
    discountType: 'none',
    discountValue: '0',
    taxRate: '0',
    notes: '',
    terms: '',
    status: 'borrador',
  };
}

function createDefaultItems(): LineItem[] {
  return [{ ...emptyItem }];
}

function snapshot(form: QuoteForm, items: LineItem[]): string {
  return JSON.stringify({ form, items });
}

function lineTotal(item: LineItem): number {
  return (Number(item.quantity) || 0) * (Number(item.unitPrice) || 0);
}

const DRAFT_KEY = 'vertice:quote-draft:new';

export function QuoteFormPage() {
  const { id } = useParams();
  const isEdit = Boolean(id);
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const toast = useToast();
  const draftRestoredRef = useRef(false);
  const dealPrefilledRef = useRef(false);
  const [searchParams] = useSearchParams();
  const dealParam = Number(searchParams.get('deal_id') ?? 0) || null;

  const { data: editData, isLoading: loadingQuote, error: editError } = useQuery({
    queryKey: ['quote', id],
    queryFn: () => apiGet<QuoteDetailData>('quotes.php', { id }),
    enabled: isEdit,
    staleTime: Number.POSITIVE_INFINITY,
    refetchOnWindowFocus: false,
  });
  const { data: options, isLoading: loadingOptions } = useQuery({
    queryKey: ['quotes', 'options'],
    queryFn: () => apiGet<QuotesData>('quotes.php'),
    staleTime: 60_000,
  });

  // Propuesta comercial: la cotización puede nacer de (o estar vinculada a) una oportunidad del CRM.
  const dealId = isEdit ? (editData?.quote.dealId ?? null) : dealParam;
  const { data: dealData } = useQuery({
    queryKey: ['crm-deal', dealId],
    queryFn: () => apiGet<CrmDealSummary>('crm-deal.php', { id: dealId ?? 0 }),
    enabled: dealId !== null,
    staleTime: 60_000,
  });

  const [form, setForm] = useState<QuoteForm>(createDefaultForm);
  const [items, setItems] = useState<LineItem[]>(createDefaultItems);
  const [baseline, setBaseline] = useState<string>(() => snapshot(createDefaultForm(), createDefaultItems()));
  const [error, setError] = useState<string | null>(null);
  const [draftRestored, setDraftRestored] = useState(false);
  const [draftSavedAt, setDraftSavedAt] = useState<number | null>(null);
  const [savedId, setSavedId] = useState<number | null>(null);

  useEffect(() => {
    if (!editData) return;
    const quote = editData.quote;
    const nextForm: QuoteForm = {
      title: quote.title,
      clientName: quote.clientName,
      clientCompany: quote.clientCompany ?? '',
      clientEmail: quote.clientEmail ?? '',
      projectId: quote.projectId ? String(quote.projectId) : '',
      issueDate: quote.issueDate,
      validUntil: quote.validUntil ?? '',
      currency: quote.currency,
      discountType: quote.discountType,
      discountValue: String(quote.discountValue),
      taxRate: String(quote.taxRate),
      notes: quote.notes ?? '',
      terms: quote.terms ?? '',
      status: quote.status,
    };
    const nextItems = editData.items.length > 0
      ? editData.items.map((item) => ({ description: item.description, quantity: String(item.quantity), unitPrice: String(item.unitPrice) }))
      : createDefaultItems();
    setForm(nextForm);
    setItems(nextItems);
    setBaseline(snapshot(nextForm, nextItems));
  }, [editData]);

  // Restaura el borrador local de una cotización nueva a medio escribir.
  useEffect(() => {
    if (isEdit || draftRestoredRef.current || dealParam !== null) return;
    draftRestoredRef.current = true;
    try {
      const raw = window.localStorage.getItem(DRAFT_KEY);
      if (!raw) return;
      const parsed = JSON.parse(raw) as { form?: Partial<QuoteForm>; items?: LineItem[] };
      const restoredForm = { ...createDefaultForm(), ...(parsed.form ?? {}) };
      const restoredItems = Array.isArray(parsed.items) && parsed.items.length > 0 ? parsed.items : createDefaultItems();
      const hasContent = restoredForm.title.trim() !== ''
        || restoredForm.clientName.trim() !== ''
        || restoredItems.some((item) => item.description.trim() !== '' || lineTotal(item) > 0);
      if (!hasContent) {
        window.localStorage.removeItem(DRAFT_KEY);
        return;
      }
      setForm(restoredForm);
      setItems(restoredItems);
      setDraftRestored(true);
    } catch {
      window.localStorage.removeItem(DRAFT_KEY);
    }
  }, [isEdit]);

  // Una propuesta nueva desde una oportunidad llega con el cliente y el valor estimado precargados.
  useEffect(() => {
    if (isEdit || !dealData || dealPrefilledRef.current) return;
    dealPrefilledRef.current = true;
    const { prefill } = dealData;
    const nextForm: QuoteForm = {
      ...createDefaultForm(),
      title: prefill.title,
      clientName: prefill.clientName,
      clientCompany: prefill.clientCompany,
      clientEmail: prefill.clientEmail,
      currency: prefill.currency,
      projectId: prefill.projectId,
    };
    const nextItems = [{ ...prefill.item }];
    setForm(nextForm);
    setItems(nextItems);
    setBaseline(snapshot(nextForm, nextItems));
  }, [dealData, isEdit]);

  const totals = useMemo(() => {
    const subtotal = items.reduce((sum, item) => sum + lineTotal(item), 0);
    const discountValue = Number(form.discountValue) || 0;
    const discount = form.discountType === 'percent'
      ? subtotal * (discountValue / 100)
      : form.discountType === 'fixed' ? discountValue : 0;
    const taxable = Math.max(0, subtotal - discount);
    const tax = taxable * ((Number(form.taxRate) || 0) / 100);
    return { subtotal, discount, tax, total: taxable + tax };
  }, [items, form.discountType, form.discountValue, form.taxRate]);

  const current = useMemo(() => snapshot(form, items), [form, items]);
  const dirty = savedId === null && current !== baseline;

  // Borrador local: solo para cotizaciones nuevas.
  useEffect(() => {
    if (isEdit || !dirty) return;
    const timer = window.setTimeout(() => {
      try {
        window.localStorage.setItem(DRAFT_KEY, JSON.stringify({ form, items }));
        setDraftSavedAt(Date.now());
      } catch {
        // localStorage lleno o deshabilitado: se ignora.
      }
    }, 500);
    return () => window.clearTimeout(timer);
  }, [dirty, form, isEdit, items]);

  useUnsavedChanges(dirty, isEdit ? 'Tienes cambios sin guardar en esta cotización.' : 'Tienes una cotización sin guardar. Si sales ahora se perderá el borrador.');

  // Navega únicamente cuando el estado ya no está sucio, para no disparar el guard.
  useEffect(() => {
    if (savedId !== null) navigate(`/quotes/${savedId}`, { replace: !isEdit });
  }, [isEdit, navigate, savedId]);

  const save = useMutation({
    mutationFn: async () => {
      const body: Record<string, string | number> = {
        id: id ?? 0,
        title: form.title.trim(),
        client_name: form.clientName.trim(),
        client_company: form.clientCompany.trim(),
        client_email: form.clientEmail.trim(),
        project_id: form.projectId,
        issue_date: form.issueDate,
        valid_until: form.validUntil,
        currency: form.currency,
        discount_type: form.discountType,
        discount_value: form.discountValue,
        tax_rate: form.taxRate,
        notes: form.notes,
        terms: form.terms,
        status: form.status,
      };
      if (dealId !== null) body.deal_id = dealId;
      items.forEach((item, index) => {
        body[`items[${index}][description]`] = item.description;
        body[`items[${index}][quantity]`] = item.quantity;
        body[`items[${index}][unit_price]`] = item.unitPrice;
      });
      return apiPost<{ success: boolean; id: number }>('quote-save.php', body);
    },
    onSuccess: (result) => {
      window.localStorage.removeItem(DRAFT_KEY);
      void queryClient.invalidateQueries({ queryKey: ['quotes'] });
      void queryClient.invalidateQueries({ queryKey: ['quote'] });
      if (dealId !== null) void queryClient.invalidateQueries({ queryKey: ['crm'] });
      toast(isEdit ? 'Cotización actualizada.' : 'Cotización creada.');
      setSavedId(result.id);
    },
    onError: (mutationError) => setError(mutationError instanceof Error ? mutationError.message : 'No se pudo guardar la cotización.'),
  });

  const update = (patch: Partial<QuoteForm>) => setForm((currentForm) => ({ ...currentForm, ...patch }));

  const updateClient = (value: string) => {
    const match = (options?.contacts ?? []).find((contact) => contact.name.trim().toLowerCase() === value.trim().toLowerCase());
    setForm((currentForm) => ({
      ...currentForm,
      clientName: value,
      ...(match ? {
        clientEmail: match.email ?? currentForm.clientEmail,
        clientCompany: match.company ?? currentForm.clientCompany,
      } : {}),
    }));
  };

  const updateItem = (index: number, patch: Partial<LineItem>) => setItems((currentItems) => currentItems.map((item, itemIndex) => (itemIndex === index ? { ...item, ...patch } : item)));

  const addItem = () => setItems((currentItems) => [...currentItems, { ...emptyItem }]);

  const duplicateItem = (index: number) => setItems((currentItems) => {
    const next = [...currentItems];
    next.splice(index + 1, 0, { ...currentItems[index] });
    return next;
  });

  const moveItem = (index: number, direction: -1 | 1) => setItems((currentItems) => {
    const target = index + direction;
    if (target < 0 || target >= currentItems.length) return currentItems;
    const next = [...currentItems];
    [next[index], next[target]] = [next[target], next[index]];
    return next;
  });

  const removeItem = (index: number) => setItems((currentItems) => (
    currentItems.length > 1 ? currentItems.filter((_, itemIndex) => itemIndex !== index) : createDefaultItems()
  ));

  const discardDraft = () => {
    window.localStorage.removeItem(DRAFT_KEY);
    const nextForm = createDefaultForm();
    const nextItems = createDefaultItems();
    setForm(nextForm);
    setItems(nextItems);
    setBaseline(snapshot(nextForm, nextItems));
    setDraftRestored(false);
    setDraftSavedAt(null);
    setError(null);
    toast('Borrador descartado.');
  };

  const validate = (): string | null => {
    if (form.title.trim() === '') return 'Añade un título a la cotización.';
    if (form.clientName.trim() === '') return 'Añade el nombre del cliente.';
    if (form.clientEmail.trim() !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.clientEmail.trim())) return 'El email del cliente no es válido.';
    if (form.validUntil !== '' && form.validUntil < form.issueDate) return 'La fecha de validez no puede ser anterior a la fecha de emisión.';
    if (items.some((item) => item.description.trim() === '')) return 'Cada ítem necesita una descripción.';
    if (items.some((item) => !(Number(item.quantity) > 0))) return 'Las cantidades deben ser mayores que cero.';
    if (items.some((item) => Number(item.unitPrice) < 0)) return 'Los precios no pueden ser negativos.';
    return null;
  };

  const submit = () => {
    const message = validate();
    if (message) {
      setError(message);
      return;
    }
    setError(null);
    save.mutate();
  };

  if ((isEdit && loadingQuote) || loadingOptions) {
    return <div className="space-y-4"><Skeleton className="h-10 w-64" /><Skeleton className="h-96" /></div>;
  }
  if (isEdit && editError) {
    return <ErrorState message={editError instanceof Error ? editError.message : 'No se pudo cargar la cotización.'} />;
  }

  const contacts = options?.contacts ?? [];
  const expiredWarning = form.validUntil !== '' && form.validUntil < todayIso();

  return (
    <div className="space-y-6">
      <Link to={isEdit ? `/quotes/${id}` : '/quotes'} className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-900"><ArrowLeft className="h-3.5 w-3.5" /> Volver a cotizaciones</Link>
      <PageHeader
        title={isEdit ? 'Editar cotización' : 'Nueva cotización'}
        subtitle="Los totales se recalculan en el servidor al guardar."
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <span className="hidden rounded-xl bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-700 sm:inline-flex">Total · {formatMoney(totals.total, form.currency)}</span>
            <Link to={isEdit ? `/quotes/${id}` : '/quotes'} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Cancelar</Link>
            <LoadingButton pending={save.isPending} pendingLabel="Guardando..." onClick={submit} disabled={form.title.trim() === '' || form.clientName.trim() === ''} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60"><Save className="h-3.5 w-3.5" /> {isEdit ? 'Guardar cambios' : 'Guardar cotización'}</LoadingButton>
          </div>
        }
      />

      {dealData && (
        <div className="flex items-start gap-3 rounded-2xl border border-brand-200/80 bg-brand-50/70 p-4 sm:p-5">
          <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-white text-brand-600 shadow-2xs"><Target className="h-4 w-4" /></div>
          <div className="min-w-0 flex-1">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-brand-600">Propuesta comercial</p>
            <p className="mt-0.5 font-semibold text-slate-900">{dealData.deal.title}</p>
            <p className="mt-0.5 text-xs text-slate-500">
              {dealData.deal.company && <><Link to={`/crm/companies/${dealData.deal.company.id}`} className="font-medium text-brand-700 hover:underline">{dealData.deal.company.name}</Link> · </>}
              Valor estimado: {formatMoney(dealData.deal.value, dealData.deal.currency)} · Etapa: {dealData.deal.stageLabel}
            </p>
            <p className="mt-1 text-[11px] text-slate-500">{isEdit ? 'Esta cotización está vinculada a la oportunidad. Los cambios se reflejan en su seguimiento.' : 'Hemos precargado el cliente y un ítem con el valor estimado. Puedes ajustar todo antes de guardar.'}</p>
          </div>
        </div>
      )}

      {draftRestored && !isEdit && (
        <div className="flex flex-col gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800 sm:flex-row sm:items-center sm:justify-between">
          <span className="inline-flex items-center gap-2 font-semibold"><AlertTriangle className="h-4 w-4" /> Restauramos el borrador local de esta cotización.</span>
          <button type="button" onClick={discardDraft} className="inline-flex items-center gap-1.5 self-start rounded-lg border border-amber-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-amber-700 hover:bg-amber-100"><Trash2 className="h-3 w-3" /> Descartar borrador</button>
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div className="space-y-4">
          <Card className="p-5">
            <h2 className="text-sm font-semibold text-slate-800">Cliente</h2>
            <p className="mt-0.5 text-[11px] text-slate-400">Escribe o elige un contacto del CRM para autocompletar empresa y email.</p>
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
              <label className="block text-xs font-medium text-slate-700">
                Nombre del cliente *
                <Input
                  value={form.clientName}
                  onChange={(event) => updateClient(event.target.value)}
                  list="quote-client-options"
                  autoComplete="off"
                  placeholder="Ej. María López"
                  className="mt-1"
                />
                <datalist id="quote-client-options">
                  {contacts.map((contact) => <option key={`contact-${contact.id}`} value={contact.name}>{contact.company ? `${contact.company}${contact.position ? ` · ${contact.position}` : ''}` : contact.position ?? ''}</option>)}
                  {(options?.clients ?? []).filter((name) => !contacts.some((contact) => contact.name === name)).map((name) => <option key={`client-${name}`} value={name} />)}
                </datalist>
              </label>
              <label className="block text-xs font-medium text-slate-700">
                Empresa
                <Input
                  value={form.clientCompany}
                  onChange={(event) => update({ clientCompany: event.target.value })}
                  list="quote-company-options"
                  autoComplete="off"
                  className="mt-1"
                />
                <datalist id="quote-company-options">
                  {(options?.companies ?? []).map((company) => <option key={company} value={company} />)}
                </datalist>
              </label>
              <label className="block text-xs font-medium text-slate-700">Email<Input type="email" value={form.clientEmail} onChange={(event) => update({ clientEmail: event.target.value })} autoComplete="off" className="mt-1" /></label>
              <label className="block text-xs font-medium text-slate-700">Proyecto<Select value={form.projectId} onChange={(event) => update({ projectId: event.target.value })} className="mt-1 w-full"><option value="">Sin proyecto</option>{(options?.projects ?? []).map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}</Select></label>
            </div>
          </Card>

          <Card className="p-5">
            <h2 className="text-sm font-semibold text-slate-800">Datos de la cotización</h2>
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
              <label className="block text-xs font-medium text-slate-700 sm:col-span-2">Título *<Input value={form.title} onChange={(event) => update({ title: event.target.value })} maxLength={180} placeholder="Ej. Rediseño de sitio corporativo" className="mt-1" /></label>
              <label className="block text-xs font-medium text-slate-700">Emisión<Input type="date" value={form.issueDate} onChange={(event) => update({ issueDate: event.target.value })} className="mt-1" /></label>
              <label className="block text-xs font-medium text-slate-700">Válida hasta<Input type="date" value={form.validUntil} min={form.issueDate} onChange={(event) => update({ validUntil: event.target.value })} className="mt-1" /></label>
              <label className="block text-xs font-medium text-slate-700">Moneda<Select value={form.currency} onChange={(event) => update({ currency: event.target.value })} className="mt-1 w-full">{CURRENCIES.map((currency) => <option key={currency} value={currency}>{currency}</option>)}</Select></label>
              <label className="block text-xs font-medium text-slate-700">Estado<Select value={form.status} onChange={(event) => update({ status: event.target.value })} className="mt-1 w-full"><option value="borrador">Borrador</option><option value="enviada">Enviada</option><option value="aceptada">Aceptada</option><option value="rechazada">Rechazada</option></Select></label>
            </div>
            {expiredWarning && <p className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-rose-50 px-2.5 py-1.5 text-[11px] font-semibold text-rose-600"><AlertTriangle className="h-3.5 w-3.5" /> La fecha de validez ya pasó.</p>}
          </Card>

          <Card className="p-5">
            <div className="flex items-center justify-between gap-2">
              <div>
                <h2 className="text-sm font-semibold text-slate-800">Ítems</h2>
                <p className="mt-0.5 text-[11px] text-slate-400">{items.length} línea{items.length === 1 ? '' : 's'} · subtotal {formatMoney(totals.subtotal, form.currency)}</p>
              </div>
              <button type="button" onClick={addItem} className="inline-flex items-center gap-1 rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"><Plus className="h-3.5 w-3.5" /> Añadir ítem</button>
            </div>
            <div className="mt-4 space-y-2.5">
              {items.map((item, index) => (
                <div key={index} className="group rounded-xl border border-slate-200 bg-white p-2.5 transition hover:border-slate-300">
                  <div className="flex items-start gap-2">
                    <span className="mt-2.5 hidden w-5 flex-shrink-0 text-center text-[10px] font-bold text-slate-300 sm:block">{index + 1}</span>
                    <div className="min-w-0 flex-1 space-y-2">
                      <Input placeholder="Descripción del servicio o producto" value={item.description} onChange={(event) => updateItem(index, { description: event.target.value })} maxLength={255} />
                      <div className="grid grid-cols-2 gap-2 sm:grid-cols-[110px_140px_minmax(0,1fr)]">
                        <label className="block"><span className="mb-0.5 block text-[10px] font-medium text-slate-400 sm:hidden">Cantidad</span><Input inputMode="decimal" type="number" min="0.01" step="0.01" placeholder="Cant." value={item.quantity} onChange={(event) => updateItem(index, { quantity: event.target.value })} /></label>
                        <label className="block"><span className="mb-0.5 block text-[10px] font-medium text-slate-400 sm:hidden">Precio</span><Input inputMode="decimal" type="number" min="0" step="0.01" placeholder="Precio unitario" value={item.unitPrice} onChange={(event) => updateItem(index, { unitPrice: event.target.value })} /></label>
                        <div className="col-span-2 flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 sm:col-span-1 sm:justify-end sm:bg-transparent sm:px-0 sm:py-0">
                          <span className="text-[10px] font-medium text-slate-400 sm:hidden">Total línea</span>
                          <span className="text-xs font-bold text-slate-800">{formatMoney(lineTotal(item), form.currency)}</span>
                        </div>
                      </div>
                    </div>
                    <div className="flex flex-col gap-0.5 sm:flex-row sm:items-center">
                      <button type="button" onClick={() => duplicateItem(index)} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title="Duplicar ítem"><Copy className="h-3.5 w-3.5" /></button>
                      <button type="button" onClick={() => moveItem(index, -1)} disabled={index === 0} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" title="Subir"><ArrowUp className="h-3.5 w-3.5" /></button>
                      <button type="button" onClick={() => moveItem(index, 1)} disabled={index === items.length - 1} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30" title="Bajar"><ArrowDown className="h-3.5 w-3.5" /></button>
                      <button type="button" onClick={() => removeItem(index)} className="rounded-lg p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar ítem"><Trash2 className="h-3.5 w-3.5" /></button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
            <button type="button" onClick={addItem} className="mt-3 flex w-full items-center justify-center gap-1.5 rounded-xl border border-dashed border-slate-300 py-2.5 text-[11px] font-semibold text-slate-500 transition hover:border-brand-300 hover:text-brand-600"><CopyPlus className="h-3.5 w-3.5" /> Añadir otro ítem</button>
          </Card>

          <Card className="p-5">
            <div className="grid gap-3 sm:grid-cols-2">
              <label className="block text-xs font-medium text-slate-700">Notas<textarea value={form.notes} onChange={(event) => update({ notes: event.target.value })} rows={3} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-xs" placeholder="Condiciones especiales, alcance, entregables..." /></label>
              <label className="block text-xs font-medium text-slate-700">Términos<textarea value={form.terms} onChange={(event) => update({ terms: event.target.value })} rows={3} className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-xs" placeholder="Forma de pago, vigencia, validez..." /></label>
            </div>
          </Card>
        </div>

        <div className="space-y-4 xl:sticky xl:top-6 xl:self-start">
          <Card className="p-5">
            <div className="flex items-center justify-between"><h2 className="text-sm font-semibold text-slate-800">Totales</h2><span className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{form.currency}</span></div>
            <div className="mt-3 space-y-2 text-xs">
              <div className="flex justify-between text-slate-500"><span>Subtotal</span><span>{formatMoney(totals.subtotal, form.currency)}</span></div>
              <div className="grid grid-cols-2 gap-2">
                <Select value={form.discountType} onChange={(event) => update({ discountType: event.target.value })}><option value="none">Sin descuento</option><option value="percent">% descuento</option><option value="fixed">Monto fijo</option></Select>
                <Input inputMode="decimal" type="number" min="0" step="0.01" value={form.discountValue} onChange={(event) => update({ discountValue: event.target.value })} disabled={form.discountType === 'none'} className="disabled:opacity-50" />
              </div>
              <div className="flex justify-between text-slate-500"><span>Descuento</span><span>-{formatMoney(totals.discount, form.currency)}</span></div>
              <label className="flex items-center justify-between gap-2 text-slate-500"><span>Impuesto %</span><Input inputMode="decimal" type="number" min="0" max="100" step="0.01" value={form.taxRate} onChange={(event) => update({ taxRate: event.target.value })} className="max-w-[80px]" /></label>
              <div className="flex items-baseline justify-between border-t border-slate-200 pt-2.5"><span className="text-sm font-semibold text-slate-900">Total</span><span className="text-lg font-bold tracking-tight text-slate-900">{formatMoney(totals.total, form.currency)}</span></div>
              <p className="text-[10px] text-slate-400">Impuesto {formatMoney(totals.tax, form.currency)} · {items.length} ítem{items.length === 1 ? '' : 's'}</p>
            </div>
          </Card>

          <Card className="space-y-2 p-5">
            {error && <ErrorState message={error} />}
            <LoadingButton pending={save.isPending} pendingLabel="Guardando..." onClick={submit} disabled={form.title.trim() === '' || form.clientName.trim() === ''} className="w-full rounded-xl bg-brand-600 px-4 py-2.5 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60">{isEdit ? 'Guardar cambios' : 'Guardar cotización'}</LoadingButton>
            {!isEdit && <p className="text-center text-[10px] text-slate-400">{draftSavedAt ? `Borrador guardado en este navegador · ${new Date(draftSavedAt).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit' })}` : 'Tus cambios se guardan como borrador local mientras escribes.'}</p>}
            <p className="text-center text-[10px] text-slate-400">Podrás enviarla, aceptarla y convertirla en proyecto o factura desde el detalle.</p>
          </Card>
        </div>
      </div>
    </div>
  );
}
