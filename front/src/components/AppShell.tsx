import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Check, ChevronDown, FolderKanban, LayoutDashboard, ListTodo, LogOut, Menu, PanelLeftClose, PanelLeftOpen, Plus, Receipt, RefreshCw, Settings, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type TouchEvent } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { useBootstrap } from '../features/auth/BootstrapProvider';
import { initials } from '../lib/format';
import { DynamicIcon } from './icons';
import { GlobalSearch } from './GlobalSearch';
import { NotificationBell } from './NotificationBell';

const PAGE_TITLES: Array<[RegExp, string]> = [
  [/^\/dashboard/, 'Dashboard'],
  [/^\/tasks/, 'Tareas'],
  [/^\/calendar/, 'Calendario'],
  [/^\/crm/, 'CRM'],
  [/^\/billing/, 'Facturación'],
  [/^\/finance/, 'Cobros y pagos'],
  [/^\/profitability/, 'Rentabilidad'],
  [/^\/reports/, 'Reportes'],
  [/^\/quotes\/\d+\/edit/, 'Editar cotización'],
  [/^\/quotes\/new/, 'Nueva cotización'],
  [/^\/quotes\/\d+/, 'Cotización'],
  [/^\/quotes/, 'Cotizaciones'],
  [/^\/settings/, 'Configuración'],
  [/^\/users/, 'Usuarios y roles'],
  [/^\/templates/, 'Plantillas'],
  [/^\/trash/, 'Papelera'],
  [/^\/support/, 'Soporte'],
  [/^\/connections/, 'Agencias conectadas'],
  [/^\/api-tokens/, 'Integraciones API'],
  [/^\/projects\/\d+/, 'Detalle del proyecto'],
  [/^\/projects/, 'Proyectos'],
  [/^\/cash/, 'Caja'],
];

export function AppShell() {
  const bootstrap = useBootstrap();
  const location = useLocation();
  const queryClient = useQueryClient();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(() => localStorage.getItem('vertice_sidebar_collapsed') === '1');
  const [projectGroups, setProjectGroups] = useState<Record<string, boolean>>(() => Object.fromEntries(bootstrap.projectsGroups.map((group) => [group.key, true])));
  const [openGroup, setOpenGroup] = useState<string>('');
  const mainRef = useRef<HTMLElement>(null);
  const pullStart = useRef<number | null>(null);
  const [pullDistance, setPullDistance] = useState(0);
  const [refreshing, setRefreshing] = useState(false);

  useEffect(() => { setMobileOpen(false); }, [location.pathname]);
  useEffect(() => { localStorage.setItem('vertice_sidebar_collapsed', collapsed ? '1' : '0'); }, [collapsed]);

  const title = PAGE_TITLES.find(([pattern]) => pattern.test(location.pathname))?.[1] ?? 'Vértice';
  useEffect(() => { document.title = `${title} · Vértice`; }, [title]);
  const standaloneGroup = useMemo(() => bootstrap.navGroups.find((group) => group.key === 'operacion'), [bootstrap.navGroups]);
  const standalone = standaloneGroup?.items ?? [];
  const groups = useMemo(() => bootstrap.navGroups.filter((group) => group.key !== 'operacion'), [bootstrap.navGroups]);
  const bottomItems = useMemo(() => {
    const items = [{ to: '/dashboard', label: 'Inicio', icon: LayoutDashboard }];
    if (bootstrap.modules.projects !== false) items.push({ to: '/projects', label: 'Proyectos', icon: FolderKanban });
    if (bootstrap.modules.tasks !== false) items.push({ to: '/tasks', label: 'Tareas', icon: ListTodo });
    if (bootstrap.modules.invoices !== false) items.push({ to: '/billing', label: 'Facturas', icon: Receipt });
    return items;
  }, [bootstrap.modules]);

  const handleTouchStart = (event: TouchEvent<HTMLElement>) => {
    if ((mainRef.current?.scrollTop ?? 0) <= 0) pullStart.current = event.touches[0]?.clientY ?? null;
  };
  const handleTouchMove = (event: TouchEvent<HTMLElement>) => {
    if (pullStart.current === null || refreshing) return;
    const delta = (event.touches[0]?.clientY ?? pullStart.current) - pullStart.current;
    if (delta <= 0) {
      setPullDistance(0);
      return;
    }
    setPullDistance(Math.min(70, delta * 0.45));
  };
  const handleTouchEnd = () => {
    pullStart.current = null;
    if (pullDistance >= 45 && !refreshing) {
      setRefreshing(true);
      setPullDistance(58);
      void queryClient.invalidateQueries().finally(() => {
        window.setTimeout(() => {
          setRefreshing(false);
          setPullDistance(0);
        }, 450);
      });
    } else {
      setPullDistance(0);
    }
  };

  useEffect(() => {
    const activeGroup = groups.find((group) => group.items.some((item) => item.href.replace(/^\/app/, '') === location.pathname || (item.href.replace(/^\/app/, '') !== '/' && location.pathname.startsWith(`${item.href.replace(/^\/app/, '')}/`))));
    if (activeGroup) setOpenGroup(activeGroup.key);
  }, [location.pathname, groups]);

  return (
    <div className="flex h-screen overflow-hidden bg-slate-50 text-slate-800">
      {mobileOpen && <button type="button" aria-label="Cerrar menú" className="fixed inset-0 z-30 bg-slate-900/30 md:hidden" onClick={() => setMobileOpen(false)} />}

      <aside
        id="main-sidebar"
        className={clsx('fixed inset-y-0 left-0 z-40 flex w-60 flex-col border-r border-slate-200/70 bg-white transition-all duration-300 md:static md:translate-x-0', mobileOpen ? 'translate-x-0' : '-translate-x-full', collapsed && 'sidebar-collapsed')}
      >
        <div className="sidebar-header flex h-16 items-center justify-between px-4 transition-all">
          <a href="/app/dashboard" className="flex min-w-0 items-center gap-2.5" title={bootstrap.agency.name}>
            {bootstrap.agency.logoUrl ? (
              <img src={bootstrap.agency.logoUrl} alt={bootstrap.agency.name} className="sidebar-logo h-8 w-auto max-w-[160px] flex-shrink-0 rounded-md object-contain" />
            ) : (
              <>
                <div className="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-navy-900 text-white"><DynamicIcon name="LayoutDashboard" className="h-4 w-4" /></div>
                <span className="sidebar-text truncate text-[15px] font-semibold tracking-tight text-slate-900">{bootstrap.agency.name}</span>
              </>
            )}
          </a>
          <div className="flex items-center gap-1">
            <button type="button" onClick={() => setCollapsed((value) => !value)} className="sidebar-toggle-collapse hidden items-center justify-center rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 md:flex" title="Colapsar menú lateral"><PanelLeftClose className="h-4 w-4" /></button>
            <button type="button" className="rounded-md p-1 text-slate-400 hover:bg-slate-100 md:hidden" onClick={() => setMobileOpen(false)}><X className="h-5 w-5" /></button>
          </div>
        </div>

        <div className="flex-1 space-y-6 overflow-y-auto px-3 pb-4 pt-2">
          <nav className="flex flex-col items-stretch space-y-0.5">
            {standalone.map((item) => {
              const to = item.href.replace(/^\/app/, '') || '/';
              return (
                <NavLink key={item.module} to={to} title={item.label} className={({ isActive }) => clsx('sidebar-nav-item flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors', isActive ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900')}>
                  {({ isActive }) => <><DynamicIcon name={item.icon} className={clsx('h-[18px] w-[18px] flex-shrink-0', isActive ? 'text-brand-600' : 'text-slate-400')} /><span className="sidebar-text truncate">{item.label}</span></>}
                </NavLink>
              );
            })}

            {groups.map((group) => {
              const isOpen = openGroup === group.key;
              const groupActive = group.items.some((item) => location.pathname === item.href.replace(/^\/app/, ''));
              return (
                <div key={group.key} className="sidebar-group">
                  <button type="button" onClick={() => setOpenGroup(isOpen ? '' : group.key)} aria-expanded={isOpen} className={clsx('sidebar-nav-item sidebar-group-toggle flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors', groupActive ? 'bg-slate-50 font-medium text-slate-900' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900')} title={group.label}>
                    <DynamicIcon name={group.icon ?? group.items[0]?.icon ?? 'FolderKanban'} className={clsx('h-[18px] w-[18px] flex-shrink-0', groupActive ? 'text-brand-600' : 'text-slate-400')} />
                    <span className="sidebar-text truncate">{group.label}</span>
                    <ChevronDown className={clsx('sidebar-group-chevron ml-auto h-4 w-4 flex-shrink-0', groupActive ? 'text-brand-500' : 'text-slate-400')} />
                  </button>
                  <div className={clsx('sidebar-group-panel ml-5 mt-1 space-y-0.5 border-l border-slate-200/80 pl-3', !isOpen && 'hidden')} role="group" aria-label={group.label}>
                    {group.items.map((item) => {
                      const to = item.href.replace(/^\/app/, '') || '/';
                      return (
                        <NavLink key={item.module} to={to} title={item.label} className={({ isActive }) => clsx('sidebar-nav-subitem flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-[13px] transition-colors', isActive ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900')}>
                          {({ isActive }) => <><DynamicIcon name={item.icon} className={clsx('h-4 w-4 flex-shrink-0', isActive ? 'text-brand-600' : 'text-slate-400')} /><span className="truncate">{item.label}</span></>}
                        </NavLink>
                      );
                    })}
                  </div>
                </div>
              );
            })}
          </nav>

          <div>
            <div className="mb-1.5 flex items-center justify-between px-3">
              <p className="sidebar-section-title text-xs font-medium text-slate-400">Proyectos</p>
              {(bootstrap.user.role === 'admin' || bootstrap.user.role === 'project_manager') && (
                <Link to="/projects?new=1" title="Nuevo proyecto" className="sidebar-section-title rounded p-0.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-900"><Plus className="h-3.5 w-3.5" /></Link>
              )}
            </div>
            <div className="sidebar-divider-collapsed mx-auto my-2 h-px w-6 bg-slate-200" />

            {bootstrap.projectsGroups.map((group) => (
              <div key={group.key} className="sidebar-agency-group">
                <button type="button" onClick={() => setProjectGroups((current) => ({ ...current, [group.key]: !current[group.key] }))} aria-expanded={projectGroups[group.key]} className="sidebar-agency-toggle mb-1 flex w-full items-center gap-1.5 rounded-md px-3 pt-1 text-left transition-colors hover:bg-slate-50" title={group.name}>
                  <span className="sidebar-agency-badge h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-slate-100 text-[11px] font-semibold text-slate-600" aria-hidden="true">{group.name.slice(0, 1).toUpperCase()}</span>
                  <span className="sidebar-section-title truncate text-[10px] font-semibold uppercase tracking-wider text-slate-400">{group.name}{group.shared ? ' · compartida' : ''}</span>
                  <span className="sidebar-section-title flex-shrink-0 rounded-full bg-slate-100 px-1.5 py-0.5 text-[9px] font-semibold text-slate-400">{group.projects.length}</span>
                  <ChevronDown className="sidebar-agency-chevron ml-auto h-3.5 w-3.5 flex-shrink-0 text-slate-400" />
                </button>
                <nav className={clsx('sidebar-agency-panel flex flex-col items-stretch space-y-0.5', !projectGroups[group.key] && 'hidden')}>
                  {group.projects.map((project) => (
                    <NavLink key={project.id} to={`/projects/${project.id}`} title={project.completed ? `${project.name} · Todas las tareas completadas` : project.name} className={({ isActive }) => clsx('sidebar-nav-subitem flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-[13px] transition-colors', isActive ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900')}>
                      <span className={clsx('sidebar-project-icon relative flex h-[18px] w-[18px] flex-shrink-0 items-center justify-center', project.completed && 'sidebar-project-complete')}>
                        <span className="sidebar-project-dot h-2 w-2 rounded-full" style={{ backgroundColor: project.color }} />
                        <span className="sidebar-project-initial" style={{ backgroundColor: project.color }} aria-hidden="true">{project.name.slice(0, 1).toUpperCase()}</span>
                      </span>
                      <span className="truncate">{project.name}</span>
                      {project.completed && (
                        <span className="sidebar-text ml-auto inline-flex flex-shrink-0 items-center gap-0.5 rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-600" aria-label="Todas las tareas completadas">
                          <Check className="h-3 w-3" />100%
                        </span>
                      )}
                    </NavLink>
                  ))}
                </nav>
              </div>
            ))}
          </div>
        </div>

        <div className="border-t border-slate-100 p-3">
          <div className="sidebar-toggle-expand mb-2 w-full justify-center">
            <button type="button" onClick={() => setCollapsed(false)} className="flex h-8 w-8 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-900" title="Expandir menú lateral"><PanelLeftOpen className="h-4 w-4" /></button>
          </div>
          <div className="sidebar-footer-card">
            <div className="sidebar-footer-inner flex w-full items-center justify-between gap-2 px-1">
              <a href="/app/settings" className="flex min-w-0 items-center gap-2.5 rounded-lg p-0.5 transition hover:bg-slate-50" title="Editar mi perfil">
                <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-100 text-xs font-medium text-slate-700">
                  {bootstrap.user.avatarUrl ? <img src={bootstrap.user.avatarUrl} alt="" className="h-full w-full object-cover" /> : initials(bootstrap.user.name)}
                </span>
                <span className="sidebar-text min-w-0">
                  <span className="block truncate text-[13px] font-medium leading-tight text-slate-900">{bootstrap.user.name}</span>
                  <span className="block truncate text-xs text-slate-400">{bootstrap.user.roleLabel}</span>
                </span>
              </a>
              <a href="/app/settings" title="Configuración" className="flex-shrink-0 rounded-md p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-900"><Settings className="h-4 w-4" /></a>
              <a href="/logout" title="Cerrar sesión" className="flex-shrink-0 rounded-md p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-900"><LogOut className="h-4 w-4" /></a>
            </div>
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        <header className="hidden h-14 flex-shrink-0 items-center border-b border-slate-200/70 bg-white/90 backdrop-blur md:flex">
          <div className="mx-auto flex w-full max-w-7xl items-center justify-between gap-3 px-4 sm:px-8">
            <GlobalSearch />
            <NotificationBell />
          </div>
        </header>

        <header className="sticky top-0 z-10 flex h-14 flex-shrink-0 items-center gap-3 border-b border-slate-200/70 bg-white/90 px-4 backdrop-blur md:hidden">
          <button type="button" className="-ml-1.5 rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-900" onClick={() => setMobileOpen(true)} title="Abrir menú"><Menu className="h-5 w-5" /></button>
          <div className="min-w-0">
            <p className="truncate text-sm font-semibold text-slate-900">{bootstrap.agency.name}</p>
            <p className="truncate text-[11px] text-slate-400">{bootstrap.user.roleLabel}</p>
          </div>
          <div className="ml-auto flex flex-shrink-0 items-center gap-1">
            <GlobalSearch compact />
            <NotificationBell />
          </div>
        </header>
        <main
          ref={mainRef}
          onTouchStart={handleTouchStart}
          onTouchMove={handleTouchMove}
          onTouchEnd={handleTouchEnd}
          className="flex-1 overflow-y-auto"
        >
          <div className="mx-auto w-full max-w-7xl space-y-6 p-4 pb-24 sm:p-8 md:pb-8"><Outlet /></div>
        </main>

        <div className="pointer-events-none fixed inset-x-0 top-14 z-20 flex justify-center md:hidden" style={{ transform: `translateY(${pullDistance - 46}px)`, opacity: pullDistance > 4 ? 1 : 0, transition: pullStart.current === null ? 'transform 180ms ease, opacity 180ms ease' : 'none' }}>
          <span className="flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 bg-white text-brand-600 shadow-md">
            <RefreshCw className={clsx('h-4 w-4', (refreshing || pullDistance >= 45) && 'animate-spin')} />
          </span>
        </div>

        <nav className="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200/80 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden" aria-label="Navegación principal móvil">
          <div className="grid grid-cols-5">
            {bottomItems.map((item) => {
              const Icon = item.icon;
              return (
                <NavLink key={item.to} to={item.to} className={({ isActive }) => clsx('flex flex-col items-center gap-0.5 px-1 py-2.5 text-[10px] font-semibold transition-colors', isActive ? 'text-brand-700' : 'text-slate-400 hover:text-slate-700')}>
                  {({ isActive }) => <><Icon className={clsx('h-5 w-5', isActive && 'text-brand-600')} /><span>{item.label}</span></>}
                </NavLink>
              );
            })}
            <button type="button" onClick={() => setMobileOpen(true)} className="flex flex-col items-center gap-0.5 px-1 py-2.5 text-[10px] font-semibold text-slate-400 transition-colors hover:text-slate-700">
              <Menu className="h-5 w-5" />
              <span>Menú</span>
            </button>
          </div>
        </nav>
      </div>
    </div>
  );
}
