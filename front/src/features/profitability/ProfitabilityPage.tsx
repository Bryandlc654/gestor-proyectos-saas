import { useQuery } from '@tanstack/react-query';
import { ArrowDownLeft, ArrowUpRight, BarChart3, Search, Target, TrendingUp, Wallet } from 'lucide-react';
import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Card, ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { apiGet } from '../../lib/api';
import { formatTotals } from '../../lib/format';
import type { ProfitabilityData } from '../../types';

const STATUS_LABELS: Record<string, string> = {
  activo: 'Activo',
  en_espera: 'En espera',
  completado: 'Completado',
};

const STATUS_CLASSES: Record<string, string> = {
  activo: 'bg-emerald-50 text-emerald-700 border-emerald-200/70',
  en_espera: 'bg-amber-50 text-amber-700 border-amber-200/70',
  completado: 'bg-slate-100 text-slate-600 border-slate-200/70',
};

export function ProfitabilityPage() {
  const [status, setStatus] = useState('todos');
  const [searchParams] = useSearchParams();
  const [search, setSearch] = useState(() => searchParams.get('search') ?? '');
  const [sort, setSort] = useState('margin');
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useQuery({
    queryKey: ['profitability', status, search, sort, page],
    queryFn: () => apiGet<ProfitabilityData>('profitability.php', { status, search, sort, page }),
  });

  if (isLoading) return <div className="space-y-4">{Array.from({ length: 6 }).map((_, index) => <Skeleton key={index} className="h-24" />)}</div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar la rentabilidad.'} />;

  const totals = data.totals;

  const costs: Record<string, number> = {};
  [totals.direct_expenses, totals.collaborator_payments].forEach((map) => {
    Object.entries(map).forEach(([currency, amount]) => {
      costs[currency] = (costs[currency] ?? 0) + amount;
    });
  });

  const filtered = search !== '' || status !== 'todos';

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Rentabilidad</h1>
          <p className="hidden text-[11px] text-slate-400 sm:block">Cuánto gana la agencia con cada proyecto</p>
        </div>
        <div className="flex items-center gap-2">
          <Link to="/finance" className="hidden items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 sm:inline-flex">
            <Wallet className="h-3.5 w-3.5" />
            Cobros y pagos
          </Link>
          <Link to="/reports" className="hidden items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 sm:inline-flex">
            <BarChart3 className="h-3.5 w-3.5" />
            Reportes
          </Link>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <Card className="space-y-2 p-5">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Presupuesto inicial</span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-50 text-slate-400"><Target className="h-4 w-4" /></span>
          </div>
          <p className="text-xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[24px]">{formatTotals(totals.budget)}</p>
          <span className="text-xs font-medium text-slate-400">{totals.project_count} proyectos</span>
        </Card>

        <Card className="space-y-2 p-5">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Ingresos asociados</span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-500"><ArrowDownLeft className="h-4 w-4" /></span>
          </div>
          <p className="text-xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[24px]">{formatTotals(totals.income)}</p>
          <span className="text-xs font-medium text-slate-400">Facturado (sin anuladas)</span>
        </Card>

        <Card className="space-y-2 p-5">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Costos totales</span>
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-500"><ArrowUpRight className="h-4 w-4" /></span>
          </div>
          <p className="text-xl font-semibold leading-none tracking-tight text-slate-900 sm:text-[24px]">{formatTotals(costs)}</p>
          <span className="text-xs font-medium text-slate-400">Gastos directos + colaboradores</span>
        </Card>

        <div className="space-y-2 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs" style={{ borderColor: data.hasNegativeMargin ? '#fecdd3' : '#a7f3d0' }}>
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Margen</span>
            <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${data.hasNegativeMargin ? 'bg-rose-50 text-rose-500' : 'bg-emerald-50 text-emerald-500'}`}><TrendingUp className="h-4 w-4" /></span>
          </div>
          <p className={`text-xl font-semibold leading-none tracking-tight sm:text-[24px] ${data.hasNegativeMargin ? 'text-rose-600' : 'text-emerald-600'}`}>{formatTotals(totals.margin)}</p>
          <span className="text-xs font-medium text-slate-400">
            {totals.positive_margin_projects} con margen positivo
            {totals.negative_margin_projects > 0 ? ` · ${totals.negative_margin_projects} con pérdida` : ''}
          </span>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
        <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200/80 bg-white px-4 py-3 shadow-2xs">
          <span className="text-[11px] font-medium text-slate-500">Dinero cobrado</span>
          <span className="text-sm font-semibold text-emerald-700">{formatTotals(totals.collected)}</span>
        </div>
        <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200/80 bg-white px-4 py-3 shadow-2xs">
          <span className="text-[11px] font-medium text-slate-500">Dinero pendiente</span>
          <span className="text-sm font-semibold text-amber-700">{formatTotals(totals.pending)}</span>
        </div>
        <div className="col-span-2 flex items-center justify-between gap-3 rounded-xl border border-slate-200/80 bg-white px-4 py-3 shadow-2xs lg:col-span-1">
          <span className="text-[11px] font-medium text-slate-500">Pagos a colaboradores</span>
          <span className="text-sm font-semibold text-indigo-700">{formatTotals(totals.collaborator_payments)}</span>
        </div>
      </div>

      <Card className="p-4">
        <form className="flex w-full flex-col items-center gap-3 md:flex-row" onSubmit={(event) => event.preventDefault()}>
          <div className="relative w-full flex-1">
            <Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Buscar proyecto..." className="pl-10 text-xs sm:text-sm" />
          </div>
          <div className="flex w-full flex-wrap items-center gap-2 md:w-auto">
            <Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}>
              <option value="todos">Todos los estados</option>
              {Object.entries(STATUS_LABELS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </Select>
            <Select value={sort} onChange={(event) => { setSort(event.target.value); setPage(1); }}>
              <option value="margin">Mayor margen</option>
              <option value="income">Mayores ingresos</option>
              <option value="budget">Mayor presupuesto</option>
              <option value="name">Nombre A-Z</option>
            </Select>
            {filtered && (
              <button type="button" onClick={() => { setSearch(''); setStatus('todos'); setPage(1); }} className="whitespace-nowrap rounded-xl px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">Limpiar</button>
            )}
          </div>
        </form>
      </Card>

      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
          <div className="flex items-center gap-2">
            <h2 className="text-sm font-semibold text-slate-900">Rentabilidad por proyecto</h2>
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">{data.totalRows}</span>
          </div>
          <p className="text-[11px] text-slate-400">Margen = ingresos − gastos directos − pagos a colaboradores (comprometidos)</p>
        </div>

        {data.rows.length === 0 ? (
          <div className="p-12 text-center">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-500"><TrendingUp className="h-6 w-6" /></div>
            <h3 className="mt-4 font-semibold text-slate-900">{filtered ? 'No hay proyectos que coincidan' : 'Todavía no hay proyectos para analizar'}</h3>
            <p className="mt-1 text-sm text-slate-500">{filtered ? 'Prueba con otro estado o término de búsqueda.' : 'Cuando tengas proyectos con facturas o gastos, aquí verás su margen.'}</p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[1120px] text-left text-xs text-slate-600">
                <thead className="border-b border-slate-100 bg-slate-50/70 text-[11px] font-medium text-slate-500">
                  <tr>
                    <th className="min-w-[190px] px-4 py-3">Proyecto</th>
                    <th className="px-3 py-3 text-right"><button type="button" onClick={() => { setSort('budget'); setPage(1); }} className="hover:text-brand-600">Presupuesto</button></th>
                    <th className="px-3 py-3 text-right"><button type="button" onClick={() => { setSort('income'); setPage(1); }} className="hover:text-brand-600">Ingresos</button></th>
                    <th className="px-3 py-3 text-right">Gastos directos</th>
                    <th className="px-3 py-3 text-right">Colaboradores</th>
                    <th className="px-3 py-3 text-right">Cobrado</th>
                    <th className="px-3 py-3 text-right">Pendiente</th>
                    <th className="px-3 py-3 text-right"><button type="button" onClick={() => { setSort('margin'); setPage(1); }} className="hover:text-brand-600">Margen</button></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {data.rows.map((row) => {
                    const metrics = row.metrics;
                    const marginPositive = metrics.margin_project >= 0;
                    return (
                      <tr key={row.id} className="transition hover:bg-slate-50/60">
                        <td className="px-4 py-3.5">
                          <div className="flex min-w-0 items-center gap-2.5">
                            <span className="h-2.5 w-2.5 flex-shrink-0 rounded-full" style={{ backgroundColor: row.color }} />
                            <div className="min-w-0">
                              <Link to={`/projects/${row.id}?tab=finanzas`} className="block max-w-[190px] truncate font-semibold text-slate-800 transition hover:text-brand-600" title={row.name}>{row.name}</Link>
                              <div className="mt-0.5 flex items-center gap-1.5">
                                <span className={`rounded-full border px-1.5 py-0.5 text-[10px] font-semibold ${STATUS_CLASSES[row.status] ?? 'bg-slate-50 text-slate-500 border-slate-200/70'}`}>{STATUS_LABELS[row.status] ?? row.status}</span>
                                <span className="font-mono text-[10px] text-slate-400">{metrics.currency}</span>
                                {metrics.has_other_currencies && <span className="text-[10px] text-amber-600" title="Hay importes en otras monedas agrupados por moneda, sin conversión">· varias monedas</span>}
                              </div>
                            </div>
                          </div>
                        </td>
                        <td className="whitespace-nowrap px-3 py-3.5 text-right font-medium text-slate-700">{formatTotals(metrics.budget_by_currency)}</td>
                        <td className="whitespace-nowrap px-3 py-3.5 text-right font-semibold text-slate-900">{formatTotals(metrics.income)}</td>
                        <td className="whitespace-nowrap px-3 py-3.5 text-right text-rose-600">{formatTotals(metrics.direct_expenses)}</td>
                        <td className="whitespace-nowrap px-3 py-3.5 text-right text-indigo-600">{formatTotals(metrics.collaborator_payments)}</td>
                        <td className="whitespace-nowrap px-3 py-3.5 text-right text-emerald-700">{formatTotals(metrics.collected)}</td>
                        <td className="whitespace-nowrap px-3 py-3.5 text-right text-amber-700">{formatTotals(metrics.pending)}</td>
                        <td className="whitespace-nowrap px-3 py-3.5 text-right">
                          <div className={`font-bold ${marginPositive ? 'text-emerald-600' : 'text-rose-600'}`}>{formatTotals(metrics.margin)}</div>
                          {metrics.margin_percent !== null && (
                            <div className={`text-[10px] font-medium ${marginPositive ? 'text-emerald-600/80' : 'text-rose-600/80'}`}>{metrics.margin_percent.toFixed(1).replace('.', ',')}%</div>
                          )}
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
      </Card>

      <p className="text-[11px] text-slate-400">Los importes se agrupan por moneda y no se convierten entre divisas. El margen principal usa la moneda del proyecto. El margen usa los ingresos facturados (no solo lo cobrado) y los costos comprometidos; las facturas y gastos anulados no se incluyen.</p>
    </div>
  );
}

function PaginationControls({
  pagination,
  onChange,
}: {
  pagination: ProfitabilityData['pagination'];
  onChange: (page: number) => void;
}) {
  if (pagination.pages <= 1) return null;

  const pages = Array.from(new Set([1, pagination.page - 1, pagination.page, pagination.page + 1, pagination.pages]))
    .filter((value) => value >= 1 && value <= pagination.pages)
    .sort((a, b) => a - b);

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
