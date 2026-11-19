import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Chart from 'chart.js/auto';
import type { ChartConfiguration } from 'chart.js';
import {
  AlertTriangle,
  ArrowDownLeft,
  ArrowRight,
  ArrowUpRight,
  Landmark,
  List,
  Lock,
  Pencil,
  Plus,
  Power,
  RefreshCw,
  Save,
  Scale,
  Search,
  Tags,
  Trash2,
  Unlink,
  WalletCards,
  X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Card, ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { useConfirm, useDialog, useToast, LoadingButton } from '../../components/feedback';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate, formatMoney, formatTotals } from '../../lib/format';
import type { CashData } from '../../types';

const TABS = [
  { key: 'movimientos', label: 'Movimientos', icon: List },
  { key: 'categorias', label: 'Categorías', icon: Tags },
  { key: 'cuentas', label: 'Cuentas', icon: WalletCards },
  { key: 'automatizacion', label: 'Automatización', icon: RefreshCw },
] as const;

function currentMonthRange() {
  const now = new Date();
  const first = new Date(now.getFullYear(), now.getMonth(), 1);
  const last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
  const iso = (value: Date) => `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;
  return { from: iso(first), to: iso(last) };
}

function ChartCanvas({ id, config }: { id: string; config: ChartConfiguration }) {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);

  useEffect(() => {
    if (!canvasRef.current) return;
    const chart = new Chart(canvasRef.current, config);
    return () => chart.destroy();
  }, [config]);

  return <canvas id={id} ref={canvasRef} />;
}

export function CashPage() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const rawTab = searchParams.get('tab') ?? 'movimientos';
  const tab = (TABS.some((item) => item.key === rawTab) ? rawTab : 'movimientos') as (typeof TABS)[number]['key'];

  const initialRange = currentMonthRange();
  const [from, setFrom] = useState(initialRange.from);
  const [to, setTo] = useState(initialRange.to);
  const [type, setType] = useState('todos');
  const [accountId, setAccountId] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [search, setSearch] = useState('');
  const [chartCurrency, setChartCurrency] = useState('');
  const [page, setPage] = useState(1);
  const [generatedPage, setGeneratedPage] = useState(1);

  const [movementOpen, setMovementOpen] = useState(false);
  const [movementEdit, setMovementEdit] = useState<CashData['movements'][number] | null>(null);
  const [movementType, setMovementType] = useState('ingreso');
  const [movementCategory, setMovementCategory] = useState('');

  const [categoryOpen, setCategoryOpen] = useState(false);
  const [categoryEdit, setCategoryEdit] = useState<CashData['categories'][number] | null>(null);

  const [accountOpen, setAccountOpen] = useState(false);
  const [accountEdit, setAccountEdit] = useState<CashData['accounts'][number] | null>(null);

  const confirm = useConfirm();
  const toast = useToast();
  const movementRef = useDialog(movementOpen, () => { setMovementOpen(false); setMovementEdit(null); });
  const categoryRef = useDialog(categoryOpen, () => { setCategoryOpen(false); setCategoryEdit(null); });
  const accountRef = useDialog(accountOpen, () => { setAccountOpen(false); setAccountEdit(null); });

  const { data, isLoading, error } = useQuery({
    queryKey: ['cash', tab, from, to, type, accountId, categoryId, projectId, search, chartCurrency, page, generatedPage],
    queryFn: () => apiGet<CashData>('cash.php', {
      tab,
      from,
      to,
      type,
      account_id: accountId,
      category_id: categoryId,
      project_id: projectId,
      search,
      chart_currency: chartCurrency,
      page,
      generated_page: generatedPage,
    }),
  });

  const refreshAll = () => {
    void queryClient.invalidateQueries({ queryKey: ['cash'] });
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    void queryClient.invalidateQueries({ queryKey: ['finance'] });
    void queryClient.invalidateQueries({ queryKey: ['billing'] });
  };

  const saveMovement = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('cash.php', { action: 'save_movement', ...body }),
    onSuccess: () => { setMovementOpen(false); setMovementEdit(null); refreshAll(); toast('Movimiento guardado.'); },
  });

  const deleteMovement = useMutation({
    mutationFn: (movementId: number) => apiPost<{ success: boolean }>('cash.php', { action: 'delete_movement', movement_id: movementId }),
    onSuccess: () => { refreshAll(); toast('Movimiento eliminado.'); },
  });

  const saveCategory = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('cash.php', { action: 'save_category', ...body }),
    onSuccess: () => { setCategoryOpen(false); setCategoryEdit(null); refreshAll(); toast('Categoría guardada.'); },
  });

  const deleteCategory = useMutation({
    mutationFn: (id: number) => apiPost<{ success: boolean }>('cash.php', { action: 'delete_category', category_id: id }),
    onSuccess: () => { refreshAll(); toast('Categoría eliminada.'); },
  });

  const toggleCategory = useMutation({
    mutationFn: (id: number) => apiPost<{ success: boolean }>('cash.php', { action: 'toggle_category', category_id: id }),
    onSuccess: () => { refreshAll(); toast('Estado de la categoría actualizado.'); },
  });

  const saveAccount = useMutation({
    mutationFn: (body: Record<string, string>) => apiPost<{ success: boolean }>('cash.php', { action: 'save_account', ...body }),
    onSuccess: () => { setAccountOpen(false); setAccountEdit(null); refreshAll(); toast('Cuenta guardada.'); },
  });

  const deleteAccount = useMutation({
    mutationFn: (id: number) => apiPost<{ success: boolean }>('cash.php', { action: 'delete_account', account_id: id }),
    onSuccess: () => { refreshAll(); toast('Cuenta eliminada.'); },
  });

  const toggleAccount = useMutation({
    mutationFn: (id: number) => apiPost<{ success: boolean }>('cash.php', { action: 'toggle_account', account_id: id }),
    onSuccess: () => { refreshAll(); toast('Estado de la cuenta actualizado.'); },
  });

  const saveAutomation = useMutation({
    mutationFn: (rules: string) => apiPost<{ success: boolean }>('cash.php', { action: 'save_automation', rules }),
    onSuccess: () => { refreshAll(); toast('Automatización actualizada.'); },
  });

  const deleteGenerated = useMutation({
    mutationFn: (movementId: number) => apiPost<{ success: boolean }>('cash.php', { action: 'delete_generated_movement', movement_id: movementId }),
    onSuccess: () => { refreshAll(); toast('Movimiento automático quitado de caja.'); },
  });

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-9 w-40" />
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} className="h-32" />)}</div>
        <Skeleton className="h-80" />
      </div>
    );
  }

  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar la caja.'} />;

  const netPositive = Object.values(data.summary.net).every((value) => value >= 0);
  const switchTab = (next: string) => {
    const params = new URLSearchParams();
    params.set('tab', next);
    setSearchParams(params);
    setPage(1);
    setGeneratedPage(1);
  };

  const openMovement = (movement: CashData['movements'][number] | null) => {
    setMovementEdit(movement);
    setMovementType(movement?.type ?? 'ingreso');
    setMovementCategory(movement ? String(movement.categoryId) : '');
    setMovementOpen(true);
  };

  const openCategory = (category: CashData['categories'][number] | null) => {
    setCategoryEdit(category);
    setCategoryOpen(true);
  };

  const openAccount = (account: CashData['accounts'][number] | null) => {
    setAccountEdit(account);
    setAccountOpen(true);
  };

  const chartConfig: ChartConfiguration<'bar'> = {
    type: 'bar',
    data: {
      labels: data.chart.series.labels,
      datasets: [
        { label: 'Ingresos', data: data.chart.series.income, backgroundColor: '#10B981', borderRadius: 5, maxBarThickness: 28 },
        { label: 'Egresos', data: data.chart.series.expense, backgroundColor: '#F43F5E', borderRadius: 5, maxBarThickness: 28 },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        tooltip: {
          callbacks: {
            label: (context) => `${context.dataset.label ?? ''}: ${Number(context.parsed.y).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${data.chart.currency}`,
          },
        },
      },
      scales: {
        y: { beginAtZero: true, grid: { color: '#F1F5F9' }, ticks: { font: { size: 10 } } },
        x: { grid: { display: false }, ticks: { font: { size: 10 } } },
      },
    },
  };

  const accountOptions = data.accounts.filter((account) => account.active || String(account.id) === String(movementEdit?.accountId ?? ''));
  const categoryOptions = data.categories.filter((category) => category.type === movementType && (category.active || String(category.id) === movementCategory));
  const automation = data.automation;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Caja</h1>
          <p className="hidden text-[11px] text-slate-400 sm:block">Ingresos y egresos reales por cuenta · independiente de facturación</p>
        </div>
        <button type="button" onClick={() => openMovement(null)} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 sm:text-sm">
          <Plus className="h-4 w-4" />
          <span className="hidden sm:inline">Nuevo movimiento</span>
          <span className="sm:hidden">Nuevo</span>
        </button>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card className="p-4 sm:p-5">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-medium text-slate-500">Saldo actual</span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Landmark className="h-4 w-4" /></span>
          </div>
          <p className="mt-2 text-lg font-semibold tracking-tight text-slate-900 sm:text-xl">{formatTotals(data.summary.balance)}</p>
          <p className="mt-0.5 text-[11px] text-slate-400">Apertura + movimientos hasta la fecha final</p>
        </Card>
        <Card className="p-4 sm:p-5">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-medium text-slate-500">Ingresos del periodo</span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600"><ArrowDownLeft className="h-4 w-4" /></span>
          </div>
          <p className="mt-2 text-lg font-semibold tracking-tight text-emerald-700 sm:text-xl">{formatTotals(data.summary.income)}</p>
          <p className="mt-0.5 text-[11px] text-slate-400">{data.summary.incomeCount} movimiento{data.summary.incomeCount === 1 ? '' : 's'}</p>
        </Card>
        <Card className="p-4 sm:p-5">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-medium text-slate-500">Egresos del periodo</span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600"><ArrowUpRight className="h-4 w-4" /></span>
          </div>
          <p className="mt-2 text-lg font-semibold tracking-tight text-rose-700 sm:text-xl">{formatTotals(data.summary.expense)}</p>
          <p className="mt-0.5 text-[11px] text-slate-400">{data.summary.expenseCount} movimiento{data.summary.expenseCount === 1 ? '' : 's'}</p>
        </Card>
        <Card className="p-4 sm:p-5">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-medium text-slate-500">Resultado neto</span>
            <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${netPositive ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'}`}><Scale className="h-4 w-4" /></span>
          </div>
          <p className={`mt-2 text-lg font-semibold tracking-tight sm:text-xl ${netPositive ? 'text-slate-900' : 'text-rose-700'}`}>{formatTotals(data.summary.net)}</p>
          <p className="mt-0.5 text-[11px] text-slate-400">Ingresos menos egresos</p>
        </Card>
      </div>

      <div className="flex w-fit max-w-full items-center gap-1.5 overflow-x-auto rounded-xl bg-slate-100/80 p-1">
        {TABS.map((item) => {
          const Icon = item.icon;
          const badge = item.key === 'movimientos' ? data.summary.count : item.key === 'categorias' ? data.categories.length : item.key === 'cuentas' ? data.accounts.length : null;
          return (
            <button key={item.key} type="button" onClick={() => switchTab(item.key)} className={`inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3.5 py-2 text-xs font-semibold transition ${tab === item.key ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}>
              <Icon className="h-3.5 w-3.5" />
              {item.label}
              {badge !== null && <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">{badge}</span>}
            </button>
          );
        })}
      </div>

      {tab === 'movimientos' && (
        <>
          <form className="flex w-full flex-col gap-3 rounded-2xl border border-slate-200/80 bg-white p-3" onSubmit={(event) => { event.preventDefault(); setPage(1); }}>
            <div className="flex flex-col items-stretch gap-2 md:flex-row md:items-center">
              <div className="flex items-center gap-2">
                <Input type="date" value={from} onChange={(event) => { setFrom(event.target.value); setPage(1); }} aria-label="Desde" className="px-3 py-2" />
                <span className="text-xs text-slate-400">a</span>
                <Input type="date" value={to} onChange={(event) => { setTo(event.target.value); setPage(1); }} aria-label="Hasta" className="px-3 py-2" />
              </div>
              <Select value={type} onChange={(event) => { setType(event.target.value); setPage(1); }}>
                <option value="todos">Ingresos y egresos</option>
                <option value="ingreso">Solo ingresos</option>
                <option value="egreso">Solo egresos</option>
              </Select>
              <Select value={accountId} onChange={(event) => { setAccountId(event.target.value); setPage(1); }} className="max-w-[190px] truncate">
                <option value="">Todas las cuentas</option>
                {data.accounts.map((account) => <option key={account.id} value={account.id}>{account.name}{account.active ? '' : ' (inactiva)'}</option>)}
              </Select>
            </div>
            <div className="flex flex-col items-stretch gap-2 md:flex-row md:items-center">
              <Select value={categoryId} onChange={(event) => { setCategoryId(event.target.value); setPage(1); }} className="max-w-[210px] truncate">
                <option value="">Todas las categorías</option>
                {data.categories.map((category) => <option key={category.id} value={category.id}>{category.name} · {category.type === 'ingreso' ? 'Ingreso' : 'Egreso'}</option>)}
              </Select>
              <Select value={projectId} onChange={(event) => { setProjectId(event.target.value); setPage(1); }} className="max-w-[190px] truncate">
                <option value="">Todos los proyectos</option>
                {data.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
              </Select>
              <div className="relative min-w-[180px] flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Buscar descripción, referencia, cuenta..." className="pl-10" />
              </div>
              <button type="submit" className="rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50">Filtrar</button>
              {data.filtersActive && <button type="button" onClick={() => { const range = currentMonthRange(); setFrom(range.from); setTo(range.to); setType('todos'); setAccountId(''); setCategoryId(''); setProjectId(''); setSearch(''); setPage(1); }} className="whitespace-nowrap rounded-xl px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">Limpiar</button>}
            </div>
          </form>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <Card className="p-5 lg:col-span-2">
              <div className="mb-4 flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
                <div>
                  <h2 className="text-sm font-semibold text-slate-800">Ingresos vs egresos</h2>
                  <p className="mt-0.5 text-[11px] text-slate-400">Últimos 6 meses · {data.chart.currency}</p>
                </div>
                <Select value={chartCurrency || data.chart.currency} onChange={(event) => setChartCurrency(event.target.value)} className="px-2.5 py-1.5 text-[11px]" aria-label="Moneda del gráfico">
                  {data.chart.currencies.map((currency) => <option key={currency} value={currency}>{currency}</option>)}
                </Select>
              </div>
              <div className="relative h-64 sm:h-72"><ChartCanvas id="cashChart" config={chartConfig} /></div>
            </Card>

            <Card className="p-5">
              <div className="mb-3 flex items-center justify-between gap-2">
                <h2 className="text-sm font-semibold text-slate-800">Categorías del periodo</h2>
                <button type="button" onClick={() => switchTab('categorias')} className="text-[11px] font-semibold text-brand-600 hover:text-brand-700">Gestionar</button>
              </div>
              {data.summary.categories.length === 0 ? (
                <p className="py-6 text-center text-xs text-slate-400">Sin movimientos en este periodo.</p>
              ) : (
                <ul className="space-y-2.5">
                  {data.summary.categories.slice(0, 6).map((category) => {
                    const rowType = category.type === 'ingreso' ? 'ingreso' : 'egreso';
                    return (
                      <li key={`${category.name}-${category.currency}`} className="flex items-center justify-between gap-2">
                        <div className="flex min-w-0 items-center gap-2">
                          <span className="h-2.5 w-2.5 flex-shrink-0 rounded-full" style={{ backgroundColor: category.color || (rowType === 'ingreso' ? '#059669' : '#E11D48') }} />
                          <span className="truncate text-xs text-slate-700">{category.name}</span>
                          <span className="text-[10px] text-slate-400">{category.movements}</span>
                        </div>
                        <span className={`text-xs font-semibold tabular-nums ${rowType === 'ingreso' ? 'text-emerald-700' : 'text-rose-700'}`}>{formatMoney(category.total, category.currency)}</span>
                      </li>
                    );
                  })}
                </ul>
              )}
            </Card>
          </div>

          <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
            <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
              <div>
                <h2 className="text-sm font-semibold text-slate-800">Movimientos de caja</h2>
                <p className="mt-0.5 text-[11px] text-slate-400">{data.pagination.total} movimiento{data.pagination.total === 1 ? '' : 's'} con los filtros actuales</p>
              </div>
              <button type="button" onClick={() => openMovement(null)} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-50 px-3 py-2 text-xs font-semibold text-brand-700 transition hover:bg-brand-100"><Plus className="h-3.5 w-3.5" /> Registrar</button>
            </div>

            {data.movements.length === 0 ? (
              <div className="p-12 text-center">
                <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500"><Landmark className="h-6 w-6" /></div>
                <h3 className="text-sm font-semibold text-slate-800">Sin movimientos en esta vista</h3>
                <p className="mt-1 text-xs text-slate-500">Registra un ingreso o egreso, o ajusta los filtros.</p>
                <button type="button" onClick={() => openMovement(null)} className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-brand-700"><Plus className="h-3.5 w-3.5" /> Nuevo movimiento</button>
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full text-left">
                    <thead className="border-b border-slate-100 bg-slate-50/70">
                      <tr className="text-[10px] uppercase tracking-wider text-slate-400">
                        <th className="px-4 py-2.5 font-semibold sm:px-5">Fecha</th>
                        <th className="px-4 py-2.5 font-semibold sm:px-5">Detalle</th>
                        <th className="px-4 py-2.5 font-semibold sm:px-5">Categoría</th>
                        <th className="px-4 py-2.5 font-semibold sm:px-5">Cuenta</th>
                        <th className="px-4 py-2.5 text-right font-semibold sm:px-5">Importe</th>
                        <th className="px-4 py-2.5 text-right font-semibold sm:px-5">Acciones</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {data.movements.map((movement) => {
                        const movementTypeRow = movement.type === 'ingreso' ? 'ingreso' : 'egreso';
                        return (
                          <tr key={movement.id} className="transition-colors hover:bg-slate-50/60">
                            <td className="whitespace-nowrap px-4 py-3 text-xs text-slate-600 sm:px-5">{formatDate(movement.occurredOn)}</td>
                            <td className="min-w-[220px] px-4 py-3 sm:px-5">
                              <div className="flex items-start gap-2">
                                <span className={`mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg ${movementTypeRow === 'ingreso' ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'}`}>
                                  {movementTypeRow === 'ingreso' ? <ArrowDownLeft className="h-3.5 w-3.5" /> : <ArrowUpRight className="h-3.5 w-3.5" />}
                                </span>
                                <div className="min-w-0">
                                  <p className="truncate text-xs font-semibold text-slate-800">{movement.description}</p>
                                  <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                    <span className={`rounded-full border px-1.5 py-0.5 text-[10px] font-semibold ${movementTypeRow === 'ingreso' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'}`}>{movementTypeRow === 'ingreso' ? 'Ingreso' : 'Egreso'}</span>
                                    {movement.sourceType !== 'manual' && (
                                      <span className="inline-flex items-center gap-1 rounded-full border border-indigo-200/60 bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700"><RefreshCw className="h-2.5 w-2.5" /> Automático</span>
                                    )}
                                    {movement.projectName && (
                                      <span className="inline-flex items-center gap-1 text-[10px] text-slate-500">
                                        <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: movement.projectColor }} />
                                        {movement.projectName}
                                      </span>
                                    )}
                                    {movement.reference && <span className="font-mono text-[10px] text-slate-400">{movement.reference}</span>}
                                  </div>
                                </div>
                              </div>
                            </td>
                            <td className="whitespace-nowrap px-4 py-3 text-xs text-slate-600 sm:px-5">
                              <span className="inline-flex items-center gap-1.5">
                                <span className="h-2 w-2 rounded-full" style={{ backgroundColor: movement.categoryColor }} />
                                {movement.categoryName}
                              </span>
                            </td>
                            <td className="whitespace-nowrap px-4 py-3 text-xs text-slate-600 sm:px-5">{movement.accountName}</td>
                            <td className="whitespace-nowrap px-4 py-3 text-right sm:px-5">
                              <span className={`text-xs font-semibold tabular-nums sm:text-sm ${movementTypeRow === 'ingreso' ? 'text-emerald-700' : 'text-rose-700'}`}>
                                {movementTypeRow === 'ingreso' ? '+' : '−'}{formatMoney(movement.amount, movement.currency)}
                              </span>
                            </td>
                            <td className="whitespace-nowrap px-4 py-3 text-right sm:px-5">
                              <div className="flex items-center justify-end gap-1">
                                {movement.sourceType === 'manual' ? (
                                  <>
                                    <button type="button" onClick={() => openMovement(movement)} className="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-900" title="Editar movimiento"><Pencil className="h-3.5 w-3.5" /></button>
                                    <button type="button" disabled={deleteMovement.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar movimiento', description: 'El movimiento se quitará de la caja.', confirmLabel: 'Eliminar', tone: 'danger' })) deleteMovement.mutate(movement.id); }} className="rounded-md p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar movimiento"><Trash2 className="h-3.5 w-3.5" /></button>
                                  </>
                                ) : (
                                  <button type="button" onClick={() => switchTab('automatizacion')} className="rounded-md p-1.5 text-indigo-400 transition hover:bg-indigo-50 hover:text-indigo-700" title="Movimiento automático: gestiónalo en Automatización"><Lock className="h-3.5 w-3.5" /></button>
                                )}
                              </div>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
                <div className="px-5 pb-5">
                  <PaginationControls pagination={data.pagination} onChange={setPage} />
                </div>
              </>
            )}
          </section>
        </>
      )}

      {tab === 'categorias' && (
        <>
          <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
              <h2 className="text-sm font-semibold text-slate-800">Categorías de caja</h2>
              <p className="mt-0.5 text-xs text-slate-500">Clasifican cada ingreso y egreso. Las categorías con movimientos se desactivan, no se eliminan.</p>
            </div>
            <button type="button" onClick={() => openCategory(null)} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700"><Plus className="h-4 w-4" /> Nueva categoría</button>
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {(['ingreso', 'egreso'] as const).map((categoryType) => {
              const rows = data.categories.filter((category) => category.type === categoryType);
              return (
                <section key={categoryType} className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
                  <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
                    <div className="flex items-center gap-2">
                      <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${categoryType === 'ingreso' ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'}`}>
                        {categoryType === 'ingreso' ? <ArrowDownLeft className="h-4 w-4" /> : <ArrowUpRight className="h-4 w-4" />}
                      </span>
                      <h3 className="text-sm font-semibold text-slate-800">{categoryType === 'ingreso' ? 'Ingresos' : 'Egresos'}</h3>
                    </div>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500">{rows.length}</span>
                  </div>
                  <div className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <p className="px-5 py-8 text-center text-xs text-slate-400">No hay categorías de {categoryType === 'ingreso' ? 'ingreso' : 'egreso'}.</p>
                    ) : rows.map((category) => (
                      <div key={category.id} className="flex items-center justify-between gap-3 px-5 py-3">
                        <div className="flex min-w-0 items-center gap-2.5">
                          <span className="h-2.5 w-2.5 flex-shrink-0 rounded-full" style={{ backgroundColor: category.color || (categoryType === 'ingreso' ? '#059669' : '#E11D48') }} />
                          <div className="min-w-0">
                            <p className="truncate text-xs font-semibold text-slate-800">{category.name}</p>
                            <p className="text-[10px] text-slate-400">{category.movements} movimiento{category.movements === 1 ? '' : 's'}</p>
                          </div>
                        </div>
                        <div className="flex flex-shrink-0 items-center gap-1">
                          <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${category.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{category.active ? 'Activa' : 'Inactiva'}</span>
                          <button type="button" onClick={() => openCategory(category)} className="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-900" title="Editar categoría"><Pencil className="h-3.5 w-3.5" /></button>
                          <button type="button" disabled={toggleCategory.isPending} onClick={() => toggleCategory.mutate(category.id)} className="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-900" title={category.active ? 'Desactivar' : 'Activar'}><Power className="h-3.5 w-3.5" /></button>
                          {category.movements === 0 && (
                            <button type="button" disabled={deleteCategory.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar categoría', description: 'Esta acción no se puede deshacer.', confirmLabel: 'Eliminar', tone: 'danger' })) deleteCategory.mutate(category.id); }} className="rounded-md p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar categoría"><Trash2 className="h-3.5 w-3.5" /></button>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </section>
              );
            })}
          </div>
        </>
      )}

      {tab === 'cuentas' && (
        <>
          <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
              <h2 className="text-sm font-semibold text-slate-800">Cuentas de caja</h2>
              <p className="mt-0.5 text-xs text-slate-500">Efectivo, banco, tarjeta u otra. El saldo actual suma la apertura y todos sus movimientos.</p>
            </div>
            <button type="button" onClick={() => openAccount(null)} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700"><Plus className="h-4 w-4" /> Nueva cuenta</button>
          </div>

          {data.accounts.length === 0 ? (
            <div className="rounded-2xl border border-slate-200/80 bg-white p-12 text-center">
              <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><WalletCards className="h-6 w-6" /></div>
              <h3 className="text-sm font-semibold text-slate-800">Aún no hay cuentas</h3>
              <p className="mt-1 text-xs text-slate-500">Crea una cuenta para empezar a registrar ingresos y egresos.</p>
              <button type="button" onClick={() => openAccount(null)} className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-brand-700">Crear cuenta</button>
            </div>
          ) : (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
              {data.accounts.map((account) => (
                <div key={account.id} className={`space-y-3 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs ${account.active ? '' : 'opacity-75'}`}>
                  <div className="flex items-start justify-between gap-2">
                    <div className="flex min-w-0 items-center gap-2.5">
                      <span className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl text-white" style={{ backgroundColor: account.color }}><WalletCards className="h-4 w-4" /></span>
                      <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-800">{account.name}</p>
                        <p className="text-[10px] text-slate-400">{data.accountTypes[account.type] ?? 'Otra'} · {account.currency}</p>
                      </div>
                    </div>
                    <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${account.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{account.active ? 'Activa' : 'Inactiva'}</span>
                  </div>

                  <div>
                    <p className="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Saldo actual</p>
                    <p className="mt-0.5 text-xl font-semibold tracking-tight tabular-nums text-slate-900">{formatMoney(account.balance, account.currency)}</p>
                  </div>

                  <div className="grid grid-cols-2 gap-2 text-[11px]">
                    <div className="rounded-lg bg-slate-50 px-2.5 py-1.5">
                      <span className="block text-slate-400">Apertura</span>
                      <span className="font-semibold tabular-nums text-slate-700">{formatMoney(account.openingBalance, account.currency)}</span>
                    </div>
                    <div className="rounded-lg bg-slate-50 px-2.5 py-1.5">
                      <span className="block text-slate-400">Movimientos</span>
                      <span className="font-semibold text-slate-700">{account.movements}</span>
                    </div>
                  </div>

                  <div className="flex items-center justify-end gap-1 border-t border-slate-100 pt-1">
                    <button type="button" onClick={() => openAccount(account)} className="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-900" title="Editar cuenta"><Pencil className="h-3.5 w-3.5" /></button>
                    <button type="button" disabled={toggleAccount.isPending} onClick={() => toggleAccount.mutate(account.id)} className="rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-900" title={account.active ? 'Desactivar' : 'Activar'}><Power className="h-3.5 w-3.5" /></button>
                    {account.movements === 0 && (
                      <button type="button" disabled={deleteAccount.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar cuenta', description: 'La cuenta de caja se eliminará permanentemente.', confirmLabel: 'Eliminar', tone: 'danger' })) deleteAccount.mutate(account.id); }} className="rounded-md p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Eliminar cuenta"><Trash2 className="h-3.5 w-3.5" /></button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}

      {tab === 'automatizacion' && automation && (
        <>
          <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
              <h2 className="text-sm font-semibold text-slate-800">Automatización de caja</h2>
              <p className="mt-0.5 text-xs text-slate-500">Crea un ingreso al cobrar una factura y un egreso al registrar un pago. Cada origen genera un solo movimiento.</p>
            </div>
            <button type="button" onClick={() => switchTab('movimientos')} className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-700 hover:text-brand-800">Ver movimientos <ArrowRight className="h-3.5 w-3.5" /></button>
          </div>

          {(automation.missing.some((item) => item.kind === 'invoice') || automation.missing.some((item) => item.kind === 'payable')) && (
            <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
              <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
              <span>
                Hay operaciones sin cuenta de caja activa en su moneda
                {automation.missing.some((item) => item.kind === 'invoice') && <> · facturas en <strong>{Array.from(new Set(automation.missing.filter((item) => item.kind === 'invoice').map((item) => item.currency))).join(', ')}</strong></>}
                {automation.missing.some((item) => item.kind === 'payable') && <> · pagos en <strong>{Array.from(new Set(automation.missing.filter((item) => item.kind === 'payable').map((item) => item.currency))).join(', ')}</strong></>}
                . No se sincronizarán hasta que crees una cuenta activa en esa moneda.
              </span>
            </div>
          )}

          <form className="space-y-5" onSubmit={(event) => {
            event.preventDefault();
            const form = new FormData(event.currentTarget);
            const rules = automation.rules.map((rule) => ({
              id: rule.id,
              account_id: Number(form.get(`account_${rule.id}`) ?? 0),
              category_id: Number(form.get(`category_${rule.id}`) ?? 0),
              is_active: form.get(`active_${rule.id}`) ? 1 : 0,
            }));
            saveAutomation.mutate(JSON.stringify(rules));
          }}>
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              {([
                { kind: 'invoice', title: 'Cobros de facturas', text: 'Cada cobro de una factura, total o parcial, crea un ingreso.', tone: 'emerald' },
                { kind: 'payable', title: 'Pagos a proveedores', text: 'Al registrar un pago de una cuenta por pagar se crea un egreso.', tone: 'rose' },
              ] as const).map((section) => {
                const sectionRules = automation.rules.filter((rule) => rule.kind === section.kind);
                return (
                  <section key={section.kind} className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
                    <div className="flex items-center gap-2.5 border-b border-slate-100 px-5 py-4">
                      <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${section.tone === 'emerald' ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'}`}>
                        {section.kind === 'invoice' ? <ArrowDownLeft className="h-4 w-4" /> : <ArrowUpRight className="h-4 w-4" />}
                      </span>
                      <div>
                        <h3 className="text-sm font-semibold text-slate-800">{section.title}</h3>
                        <p className="text-[11px] text-slate-400">{section.text}</p>
                      </div>
                    </div>

                    {sectionRules.length === 0 ? (
                      <p className="px-5 py-8 text-center text-xs text-slate-400">No hay una cuenta de caja disponible para esta regla.</p>
                    ) : (
                      <div className="divide-y divide-slate-100">
                        {sectionRules.map((rule) => {
                          const ruleReady = rule.isActive && rule.accountActive && rule.categoryActive;
                          const accountOptionsForRule = data.accounts.filter((account) => account.currency === rule.currency);
                          const categoryOptionsForRule = data.categories.filter((category) => category.type === (section.kind === 'invoice' ? 'ingreso' : 'egreso'));
                          return (
                            <div key={rule.id} className="space-y-3 p-4">
                              <div className="flex items-center justify-between gap-2">
                                <span className="inline-flex items-center gap-1.5 text-[11px] font-bold text-slate-700">
                                  <span className={`h-2 w-2 rounded-full ${section.tone === 'emerald' ? 'bg-emerald-500' : 'bg-rose-500'}`} />
                                  {rule.currency}
                                </span>
                                <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${ruleReady ? 'bg-emerald-50 text-emerald-700' : rule.isActive ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500'}`}>
                                  {ruleReady ? 'Se aplica' : rule.isActive ? 'Requiere atención' : 'Pausada'}
                                </span>
                              </div>

                              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                  <label className="mb-1 block text-[10px] font-medium text-slate-500">Cuenta</label>
                                  <Select name={`account_${rule.id}`} defaultValue={rule.accountId} className="w-full rounded-lg px-2.5 py-2">
                                    {accountOptionsForRule.map((account) => <option key={account.id} value={account.id}>{account.name}{account.active ? '' : ' (inactiva)'}</option>)}
                                  </Select>
                                </div>
                                <div>
                                  <label className="mb-1 block text-[10px] font-medium text-slate-500">Categoría</label>
                                  <Select name={`category_${rule.id}`} defaultValue={rule.categoryId} className="w-full rounded-lg px-2.5 py-2">
                                    {categoryOptionsForRule.map((category) => <option key={category.id} value={category.id}>{category.name}{category.active ? '' : ' (inactiva)'}</option>)}
                                  </Select>
                                </div>
                              </div>

                              <label className="inline-flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-600">
                                <input type="checkbox" name={`active_${rule.id}`} defaultChecked={rule.isActive} className="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                                Regla activa
                              </label>

                              {rule.isActive && !ruleReady && <p className="text-[10px] text-amber-600">La cuenta o la categoría está inactiva: la regla no se aplicará hasta activarla.</p>}
                            </div>
                          );
                        })}
                      </div>
                    )}
                  </section>
                );
              })}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white p-4">
              <p className="max-w-xl text-[11px] text-slate-500">Los movimientos automáticos no se editan desde Caja para no desincronizar la factura o el pago. Puedes quitarlos abajo; el estado de la factura o del pago no cambia.</p>
              <LoadingButton type="submit" pending={saveAutomation.isPending} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-xs font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60"><Save className="h-4 w-4" /> Guardar automatización</LoadingButton>
            </div>
          </form>

          <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
            <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
              <div>
                <h2 className="text-sm font-semibold text-slate-800">Movimientos generados</h2>
                <p className="mt-0.5 text-[11px] text-slate-400">{automation.generatedPagination.total} movimiento{automation.generatedPagination.total === 1 ? '' : 's'} creado{automation.generatedPagination.total === 1 ? '' : 's'} por facturas o pagos</p>
              </div>
            </div>

            {automation.generated.length === 0 ? (
              <div className="p-10 text-center">
                <div className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-100 text-slate-500"><RefreshCw className="h-5 w-5" /></div>
                <h3 className="text-sm font-semibold text-slate-800">Aún no hay movimientos automáticos</h3>
                <p className="mt-1 text-xs text-slate-500">Marca una factura como cobrada o registra un pago para verlos aquí.</p>
              </div>
            ) : (
              <>
                <div className="overflow-x-auto">
                  <table className="w-full text-left">
                    <thead className="border-b border-slate-100 bg-slate-50/70">
                      <tr className="text-[10px] uppercase tracking-wider text-slate-400">
                        <th className="px-4 py-2.5 font-semibold sm:px-5">Fecha</th>
                        <th className="px-4 py-2.5 font-semibold sm:px-5">Origen</th>
                        <th className="px-4 py-2.5 font-semibold sm:px-5">Movimiento</th>
                        <th className="px-4 py-2.5 text-right font-semibold sm:px-5">Importe</th>
                        <th className="px-4 py-2.5 text-right font-semibold sm:px-5">Quitar</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {automation.generated.map((generated) => {
                        const generatedType = generated.type === 'ingreso' ? 'ingreso' : 'egreso';
                        const isInvoice = generated.sourceType === 'invoice' || generated.sourceType === 'invoice_payment';
                        const sourceLabel = isInvoice ? `Factura ${generated.invoiceNumber || `#${generated.id}`}` : `Pago a ${generated.supplier || 'proveedor'}`;
                        const sourceUrl = isInvoice
                          ? `/app/finance?tab=cobrar&search=${encodeURIComponent(generated.invoiceNumber ?? '')}`
                          : `/app/finance?tab=pagar&search=${encodeURIComponent(generated.supplier ?? '')}`;
                        return (
                          <tr key={generated.id} className="transition-colors hover:bg-slate-50/60">
                            <td className="whitespace-nowrap px-4 py-3 text-xs text-slate-600 sm:px-5">{formatDate(generated.occurredOn)}</td>
                            <td className="whitespace-nowrap px-4 py-3 text-xs sm:px-5"><Link to={sourceUrl} className="font-semibold text-brand-700 hover:text-brand-800">{sourceLabel}</Link></td>
                            <td className="min-w-[200px] px-4 py-3 text-xs text-slate-600 sm:px-5">
                              <p className="truncate font-medium text-slate-800">{generated.description}</p>
                              <p className="mt-0.5 text-[10px] text-slate-400">{generated.accountName} · {generated.categoryName}</p>
                            </td>
                            <td className="whitespace-nowrap px-4 py-3 text-right text-xs font-semibold tabular-nums sm:px-5 sm:text-sm">
                              <span className={generatedType === 'ingreso' ? 'text-emerald-700' : 'text-rose-700'}>{generatedType === 'ingreso' ? '+' : '−'}{formatMoney(generated.amount, generated.currency)}</span>
                            </td>
                            <td className="px-4 py-3 text-right sm:px-5">
                              <button type="button" disabled={deleteGenerated.isPending} onClick={async () => { if (await confirm({ title: 'Quitar movimiento de caja', description: 'La factura o el pago conservará su estado.', confirmLabel: 'Quitar', tone: 'danger' })) deleteGenerated.mutate(generated.id); }} className="rounded-md p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" title="Quitar de caja"><Unlink className="h-3.5 w-3.5" /></button>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
                <div className="px-5 pb-5">
                  <PaginationControls pagination={automation.generatedPagination} onChange={setGeneratedPage} />
                </div>
              </>
            )}
          </section>
        </>
      )}

      {(deleteMovement.isError || saveCategory.isError || deleteCategory.isError || toggleCategory.isError || saveAccount.isError || deleteAccount.isError || toggleAccount.isError || saveAutomation.isError || deleteGenerated.isError) && (
        <ErrorState message={(() => {
          const mutationError = deleteMovement.error ?? saveCategory.error ?? deleteCategory.error ?? toggleCategory.error ?? saveAccount.error ?? deleteAccount.error ?? toggleAccount.error ?? saveAutomation.error ?? deleteGenerated.error;
          return mutationError instanceof Error ? mutationError.message : 'No se pudo completar la operación.';
        })()} />
      )}

      {movementOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs" onClick={() => { setMovementOpen(false); setMovementEdit(null); }}>
          <div ref={movementRef} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-slate-100 bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
              <div className="flex items-center gap-2.5">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Landmark className="h-4 w-4" /></div>
                <div>
                  <h3 className="text-base font-semibold text-slate-800">{movementEdit ? 'Editar movimiento de caja' : 'Nuevo movimiento de caja'}</h3>
                  <p className="text-[11px] text-slate-400">El importe se registra en la moneda de la cuenta.</p>
                </div>
              </div>
              <button type="button" onClick={() => setMovementOpen(false)} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
            </div>

            <form key={movementEdit?.id ?? 'new'} className="space-y-4 p-6" onSubmit={(event) => {
              event.preventDefault();
              const form = new FormData(event.currentTarget);
              const body: Record<string, string> = {};
              form.forEach((value, key) => { body[key] = String(value); });
              if (movementEdit) body.movement_id = String(movementEdit.id);
              saveMovement.mutate(body);
            }}>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Tipo *</label>
                  <Select name="type" value={movementType} onChange={(event) => { setMovementType(event.target.value); setMovementCategory(''); }} className="w-full px-3.5 py-2.5 text-sm">
                    <option value="ingreso">Ingreso</option>
                    <option value="egreso">Egreso</option>
                  </Select>
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Fecha *</label>
                  <Input name="occurred_on" type="date" required defaultValue={movementEdit?.occurredOn ?? data.today} className="px-3.5 py-2.5 text-sm" />
                </div>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Importe *</label>
                  <Input inputMode="decimal" name="amount" type="number" required min="0.01" step="0.01" placeholder="0.00" defaultValue={movementEdit ? movementEdit.amount.toFixed(2) : ''} className="px-3.5 py-2.5 text-sm font-semibold" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Cuenta *</label>
                  <Select name="account_id" required defaultValue={movementEdit?.accountId ?? ''} className="w-full px-3.5 py-2.5 text-sm">
                    {accountOptions.length === 0 && <option value="">Sin cuentas disponibles</option>}
                    {accountOptions.map((account) => <option key={account.id} value={account.id}>{account.name} ({account.currency}){account.active ? '' : ' · inactiva'}</option>)}
                  </Select>
                </div>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Categoría *</label>
                  <Select name="category_id" required value={movementCategory} onChange={(event) => setMovementCategory(event.target.value)} className="w-full px-3.5 py-2.5 text-sm">
                    <option value="">Selecciona una categoría</option>
                    {categoryOptions.map((category) => <option key={category.id} value={category.id}>{category.name}{category.active ? '' : ' · inactiva'}</option>)}
                  </Select>
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Proyecto (opcional)</label>
                  <Select name="project_id" defaultValue={movementEdit?.projectId ?? ''} className="w-full px-3.5 py-2.5 text-sm">
                    <option value="">Sin proyecto</option>
                    {data.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
                  </Select>
                </div>
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Descripción *</label>
                <Input name="description" required maxLength={255} placeholder="Ej: Pago de renta de noviembre" defaultValue={movementEdit?.description ?? ''} className="px-3.5 py-2.5 text-sm" />
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Referencia</label>
                  <Input name="reference" maxLength={80} placeholder="N° recibo, transferencia…" defaultValue={movementEdit?.reference ?? ''} className="px-3.5 py-2.5 font-mono text-sm" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Notas</label>
                  <Input name="notes" maxLength={2000} placeholder="Detalle adicional" defaultValue={movementEdit?.notes ?? ''} className="px-3.5 py-2.5 text-sm" />
                </div>
              </div>

              {saveMovement.isError && <ErrorState message={saveMovement.error instanceof Error ? saveMovement.error.message : 'No se pudo guardar el movimiento.'} />}

              <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onClick={() => setMovementOpen(false)} className="rounded-xl px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
                <LoadingButton type="submit" pending={saveMovement.isPending} className="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60">Guardar movimiento</LoadingButton>
              </div>
            </form>
          </div>
        </div>
      )}

      {categoryOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs" onClick={() => { setCategoryOpen(false); setCategoryEdit(null); }}>
          <div ref={categoryRef} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" className="w-full max-w-md overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
              <h3 className="text-base font-semibold text-slate-800">{categoryEdit ? 'Editar categoría' : 'Nueva categoría'}</h3>
              <button type="button" onClick={() => setCategoryOpen(false)} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
            </div>
            <form key={categoryEdit?.id ?? 'new'} className="space-y-4 p-6" onSubmit={(event) => {
              event.preventDefault();
              const form = new FormData(event.currentTarget);
              const body: Record<string, string> = {};
              form.forEach((value, key) => { body[key] = String(value); });
              if (categoryEdit) body.category_id = String(categoryEdit.id);
              saveCategory.mutate(body);
            }}>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Nombre *</label>
                <Input name="name" required maxLength={80} placeholder="Ej: Servicios" defaultValue={categoryEdit?.name ?? ''} className="px-3.5 py-2.5 text-sm" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Tipo *</label>
                  <Select name="type" defaultValue={categoryEdit?.type ?? 'ingreso'} className="w-full px-3.5 py-2.5 text-sm">
                    <option value="ingreso">Ingreso</option>
                    <option value="egreso">Egreso</option>
                  </Select>
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Color</label>
                  <input type="color" name="color" defaultValue={categoryEdit?.color ?? '#0E66FF'} className="h-[42px] w-full rounded-xl border border-slate-200 bg-white px-1 py-1" />
                </div>
              </div>
              {categoryEdit && categoryEdit.movements > 0 && <p className="text-[11px] text-amber-600">Esta categoría ya tiene movimientos: no puedes cambiar su tipo.</p>}
              {saveCategory.isError && <ErrorState message={saveCategory.error instanceof Error ? saveCategory.error.message : 'No se pudo guardar la categoría.'} />}
              <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onClick={() => setCategoryOpen(false)} className="rounded-xl px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
                <LoadingButton type="submit" pending={saveCategory.isPending} className="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Guardar categoría</LoadingButton>
              </div>
            </form>
          </div>
        </div>
      )}

      {accountOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs" onClick={() => { setAccountOpen(false); setAccountEdit(null); }}>
          <div ref={accountRef} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" className="w-full max-w-md overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
              <h3 className="text-base font-semibold text-slate-800">{accountEdit ? 'Editar cuenta' : 'Nueva cuenta'}</h3>
              <button type="button" onClick={() => setAccountOpen(false)} className="rounded-lg p-1 text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button>
            </div>
            <form key={accountEdit?.id ?? 'new'} className="space-y-4 p-6" onSubmit={(event) => {
              event.preventDefault();
              const form = new FormData(event.currentTarget);
              const body: Record<string, string> = {};
              form.forEach((value, key) => { body[key] = String(value); });
              if (accountEdit) body.account_id = String(accountEdit.id);
              saveAccount.mutate(body);
            }}>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Nombre *</label>
                <Input name="name" required maxLength={80} placeholder="Ej: Cuenta corriente" defaultValue={accountEdit?.name ?? ''} className="px-3.5 py-2.5 text-sm" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Tipo *</label>
                  <Select name="type" defaultValue={accountEdit?.type ?? 'efectivo'} className="w-full px-3.5 py-2.5 text-sm">
                    <option value="efectivo">Efectivo</option>
                    <option value="banco">Banco</option>
                    <option value="tarjeta">Tarjeta</option>
                    <option value="otro">Otra</option>
                  </Select>
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Moneda *</label>
                  <Select name="currency" defaultValue={accountEdit?.currency ?? 'USD'} className="w-full px-3.5 py-2.5 font-mono text-sm">
                    {data.currencies.map((currency) => <option key={currency} value={currency}>{currency}</option>)}
                  </Select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Saldo inicial</label>
                  <Input inputMode="decimal" name="opening_balance" type="number" step="0.01" defaultValue={accountEdit ? accountEdit.openingBalance.toFixed(2) : '0.00'} className="px-3.5 py-2.5 text-sm font-semibold" />
                </div>
                <div>
                  <label className="mb-1.5 block text-xs font-medium text-slate-700">Color</label>
                  <input type="color" name="color" defaultValue={accountEdit?.color ?? '#0E66FF'} className="h-[42px] w-full rounded-xl border border-slate-200 bg-white px-1 py-1" />
                </div>
              </div>
              {accountEdit && accountEdit.movements > 0 && <p className="text-[11px] text-amber-600">Esta cuenta ya tiene movimientos: no puedes cambiar su moneda.</p>}
              {saveAccount.isError && <ErrorState message={saveAccount.error instanceof Error ? saveAccount.error.message : 'No se pudo guardar la cuenta.'} />}
              <div className="flex items-center justify-end gap-3 border-t border-slate-100 pt-4">
                <button type="button" onClick={() => setAccountOpen(false)} className="rounded-xl px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100">Cancelar</button>
                <LoadingButton type="submit" pending={saveAccount.isPending} className="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">Guardar cuenta</LoadingButton>
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
  onChange,
}: {
  pagination: { page: number; perPage: number; total: number; pages: number };
  onChange: (page: number) => void;
}) {
  if (pagination.pages <= 1) return null;

  const windowPages = Array.from(new Set([1, pagination.page - 1, pagination.page, pagination.page + 1, pagination.pages]))
    .filter((value) => value >= 1 && value <= pagination.pages)
    .sort((a, b) => a - b);

  return (
    <nav className="mt-6 flex flex-col items-center justify-between gap-3 sm:flex-row" aria-label="Paginación">
      <p className="text-xs text-slate-500">Mostrando <strong className="text-slate-800">{(pagination.page - 1) * pagination.perPage + 1}–{Math.min(pagination.page * pagination.perPage, pagination.total)}</strong> de <strong className="text-slate-800">{pagination.total}</strong></p>
      <div className="flex items-center gap-1">
        {pagination.page > 1 && <button type="button" onClick={() => onChange(pagination.page - 1)} className="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100" aria-label="Página anterior">←</button>}
        {windowPages.map((value, index) => (
          <span key={value} className="flex items-center gap-1">
            {index > 0 && value - windowPages[index - 1] > 1 && <span className="px-1.5 text-xs text-slate-400">…</span>}
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
