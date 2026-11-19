import { useQuery } from '@tanstack/react-query';
import { ArrowRight, BarChart3, Banknote, Building2, CalendarDays, FilePlus2, FolderKanban, ListTodo, Receipt, Search, Settings, Target, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ComponentType } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { useBootstrap } from '../features/auth/BootstrapProvider';
import { apiGet } from '../lib/api';
import { useDialog } from './feedback';

interface SearchItem {
  id: number;
  title: string;
  subtitle: string;
  href: string;
  color?: string;
}

interface SearchGroup {
  key: string;
  label: string;
  items: SearchItem[];
}

interface SearchResponse {
  query: string;
  groups: SearchGroup[];
}

interface QuickAction {
  label: string;
  hint: string;
  href: string;
  icon: ComponentType<{ className?: string }>;
}

type PaletteRow =
  | { kind: 'action'; action: QuickAction }
  | { kind: 'result'; item: SearchItem };

const GROUP_ICONS: Record<string, ComponentType<{ className?: string }>> = {
  projects: FolderKanban,
  tasks: ListTodo,
  invoices: Receipt,
  crm: Building2,
};

export function GlobalSearch({ compact = false }: { compact?: boolean }) {
  const navigate = useNavigate();
  const bootstrap = useBootstrap();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [debounced, setDebounced] = useState('');
  const [active, setActive] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const dialogRef = useDialog(open, () => setOpen(false));

  const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
  const shortcut = isMac ? '⌘K' : 'Ctrl K';

  const quickActions = useMemo<QuickAction[]>(() => {
    const modules = bootstrap.modules;
    const actions: QuickAction[] = [
      { label: 'Ir al dashboard', hint: 'Resumen general', href: '/dashboard', icon: ArrowRight },
    ];
    if (modules.tasks !== false) actions.push({ label: 'Ir a tareas', hint: 'Todas las tareas', href: '/tasks', icon: ListTodo });
    if (modules.projects !== false) actions.push({ label: 'Ir a proyectos', hint: 'Cartera de proyectos', href: '/projects', icon: FolderKanban });
    if (modules.invoices !== false) actions.push({ label: 'Ir a facturación', hint: 'Facturas y documentos', href: '/billing', icon: Receipt });
    if (modules.finance !== false) actions.push({ label: 'Ir a cobros y pagos', hint: 'Cuentas por cobrar y pagar', href: '/finance', icon: Receipt });
    if (modules.cash !== false) actions.push({ label: 'Ir a caja', hint: 'Movimientos y cuentas', href: '/cash', icon: Banknote });
    if (modules.calendar !== false) actions.push({ label: 'Ir al calendario', hint: 'Agenda y vencimientos', href: '/calendar', icon: CalendarDays });
    if (modules.crm !== false) actions.push({ label: 'Ir al CRM', hint: 'Oportunidades y contactos', href: '/crm', icon: Target });
    if (modules.reports !== false) actions.push({ label: 'Ir a reportes', hint: 'Informes y exportaciones', href: '/reports', icon: BarChart3 });
    if (modules.quotes !== false) actions.push({ label: 'Nueva cotización', hint: 'Crear propuesta', href: '/quotes/new', icon: FilePlus2 });
    actions.push({ label: 'Configuración', hint: 'Perfil y preferencias', href: '/settings', icon: Settings });
    return actions;
  }, [bootstrap.modules]);

  const filteredActions = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (term === '') return quickActions;
    return quickActions.filter((action) => `${action.label} ${action.hint}`.toLowerCase().includes(term));
  }, [quickActions, query]);

  const { data, isFetching } = useQuery({
    queryKey: ['global-search', debounced],
    queryFn: () => apiGet<SearchResponse>('search.php', { q: debounced }),
    enabled: open && debounced.length >= 2,
    staleTime: 30_000,
  });

  useEffect(() => {
    const handler = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setOpen((current) => !current);
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, []);

  useEffect(() => {
    if (!open) return;
    setQuery('');
    setDebounced('');
    setActive(0);
    const timer = window.setTimeout(() => inputRef.current?.focus(), 30);
    return () => window.clearTimeout(timer);
  }, [open]);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(query.trim()), 180);
    return () => window.clearTimeout(timer);
  }, [query]);

  const sections = useMemo(() => {
    const list: Array<{ label: string; items: Array<{ row: PaletteRow; index: number }>; groupKey?: string }> = [];
    let index = 0;
    const showActions = debounced.length < 2 || filteredActions.length > 0;
    if (showActions && filteredActions.length > 0) {
      list.push({
        label: 'Acciones rápidas',
        items: filteredActions.map((action) => ({ row: { kind: 'action' as const, action }, index: index++ })),
      });
    }
    (data?.groups ?? []).forEach((group) => {
      if (group.items.length === 0) return;
      list.push({
        label: group.label,
        groupKey: group.key,
        items: group.items.map((item) => ({ row: { kind: 'result' as const, item }, index: index++ })),
      });
    });
    return list;
  }, [data, debounced, filteredActions]);

  const rows = useMemo(() => sections.flatMap((section) => section.items.map((entry) => entry.row)), [sections]);

  useEffect(() => {
    setActive(0);
  }, [debounced, rows.length]);

  useEffect(() => {
    if (!open) return;
    document.querySelector<HTMLElement>(`[data-palette-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
  }, [active, open]);

  const run = (row: PaletteRow) => {
    setOpen(false);
    if (row.kind === 'action') {
      navigate(row.action.href);
    } else {
      navigate(row.item.href);
    }
  };

  const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((current) => Math.min(current + 1, Math.max(0, rows.length - 1)));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((current) => Math.max(0, current - 1));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const row = rows[active];
      if (row) run(row);
    }
  };

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className={compact
          ? 'flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-900'
          : 'flex h-9 w-full max-w-md items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3 text-left text-xs text-slate-400 transition hover:border-slate-300 hover:text-slate-600'}
        aria-label="Buscar en Vértice"
        title={`Buscar (${shortcut})`}
      >
        <Search className="h-4 w-4 flex-shrink-0" />
        {!compact && <span className="flex-1 truncate">Buscar proyectos, tareas, facturas…</span>}
        {!compact && <kbd className="rounded-md border border-slate-200 bg-slate-50 px-1.5 py-0.5 font-sans text-[10px] font-semibold text-slate-400">{shortcut}</kbd>}
      </button>

      {open && createPortal(
        <div
          className="fixed inset-0 z-[80] flex items-start justify-center bg-slate-900/40 p-4 pt-[10vh] backdrop-blur-xs"
          onMouseDown={(event) => { if (event.target === event.currentTarget) setOpen(false); }}
        >
          <div ref={dialogRef} role="dialog" aria-modal="true" aria-label="Búsqueda global" className="flex max-h-[70vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
            <div className="flex items-center gap-3 border-b border-slate-100 px-4 py-3">
              <Search className="h-4 w-4 flex-shrink-0 text-slate-400" />
              <input
                ref={inputRef}
                data-autofocus
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="Buscar proyectos, tareas, facturas, clientes…"
                className="min-w-0 flex-1 border-0 bg-transparent text-sm text-slate-900 outline-none placeholder:text-slate-400"
                aria-label="Término de búsqueda"
              />
              {query !== '' && (
                <button type="button" onClick={() => setQuery('')} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100" aria-label="Limpiar búsqueda"><X className="h-4 w-4" /></button>
              )}
              <kbd className="hidden rounded-md border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px] font-semibold text-slate-400 sm:block">Esc</kbd>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto p-2">
              {sections.length === 0 && debounced.length >= 2 && !isFetching && (
                <div className="px-4 py-10 text-center">
                  <p className="text-sm font-semibold text-slate-700">Sin resultados</p>
                  <p className="mt-1 text-xs text-slate-400">Prueba con otro nombre, número de factura o proyecto.</p>
                </div>
              )}
              {sections.map((section) => {
                const Icon = section.groupKey ? GROUP_ICONS[section.groupKey] ?? Search : ArrowRight;
                return (
                  <div key={`${section.label}-${section.groupKey ?? 'actions'}`} className="mb-1">
                    <p className="px-2.5 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-slate-400">{section.label}</p>
                    {section.items.map(({ row, index }) => {
                      const activeRow = index === active;
                      if (row.kind === 'action') {
                        const ActionIcon = row.action.icon;
                        return (
                          <button
                            key={row.action.href + row.action.label}
                            type="button"
                            data-palette-index={index}
                            onMouseEnter={() => setActive(index)}
                            onClick={() => run(row)}
                            className={`flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition ${activeRow ? 'bg-brand-50 text-brand-900' : 'text-slate-700 hover:bg-slate-50'}`}
                          >
                            <span className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg ${activeRow ? 'bg-white text-brand-600' : 'bg-slate-100 text-slate-500'}`}><ActionIcon className="h-4 w-4" /></span>
                            <span className="min-w-0 flex-1">
                              <span className="block truncate text-xs font-semibold">{row.action.label}</span>
                              <span className="block truncate text-[11px] text-slate-400">{row.action.hint}</span>
                            </span>
                            <ArrowRight className={`h-3.5 w-3.5 flex-shrink-0 ${activeRow ? 'text-brand-500' : 'text-slate-300'}`} />
                          </button>
                        );
                      }
                      return (
                        <button
                          key={`${section.groupKey}-${row.item.id}`}
                          type="button"
                          data-palette-index={index}
                          onMouseEnter={() => setActive(index)}
                          onClick={() => run(row)}
                          className={`flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition ${activeRow ? 'bg-brand-50 text-brand-900' : 'text-slate-700 hover:bg-slate-50'}`}
                        >
                          <span className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg ${activeRow ? 'bg-white text-brand-600' : 'bg-slate-100 text-slate-500'}`}>
                            {row.item.color ? <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: row.item.color }} /> : <Icon className="h-4 w-4" />}
                          </span>
                          <span className="min-w-0 flex-1">
                            <span className="block truncate text-xs font-semibold">{row.item.title}</span>
                            <span className="block truncate text-[11px] text-slate-400">{row.item.subtitle}</span>
                          </span>
                          <ArrowRight className={`h-3.5 w-3.5 flex-shrink-0 ${activeRow ? 'text-brand-500' : 'text-slate-300'}`} />
                        </button>
                      );
                    })}
                  </div>
                );
              })}
              {isFetching && <p className="px-3 py-3 text-[11px] text-slate-400">Buscando…</p>}
            </div>

            <div className="flex items-center justify-between gap-3 border-t border-slate-100 bg-slate-50/70 px-4 py-2 text-[10px] text-slate-400">
              <span><kbd className="font-sans font-semibold">↑ ↓</kbd> navegar · <kbd className="font-sans font-semibold">Enter</kbd> abrir</span>
              <span className="hidden sm:block">Búsqueda global de Vértice</span>
            </div>
          </div>
        </div>,
        document.body,
      )}
    </>
  );
}
