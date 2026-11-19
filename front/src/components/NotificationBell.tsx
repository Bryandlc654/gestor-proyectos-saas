import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, CheckCheck, Inbox } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiPostRoot } from '../lib/api';

interface NotificationItem {
  id: number;
  type: string;
  title: string;
  message: string | null;
  url: string | null;
  read_at: string | null;
  is_read: boolean;
  created_at: string;
}

interface NotificationListResponse {
  success: boolean;
  unread: number;
  notifications: NotificationItem[];
}

interface NotificationCountResponse {
  success: boolean;
  unread: number;
}

function notificationHref(url: string | null): string | null {
  if (url === null || url === '') return null;
  if (url.startsWith('/app')) return url;

  const cleaned = url.replace(/^\/+/, '');
  const project = cleaned.match(/^project\/(\d+)(.*)$/);
  if (project) return `/app/projects/${project[1]}${project[2] ?? ''}`;
  if (/^invoices?(?:[/?#]|$)/.test(cleaned)) return '/app/billing';
  if (/^tasks(?:[/?#]|$)/.test(cleaned)) return '/app/tasks';
  if (/^crm(?:[/?#]|$)/.test(cleaned)) return '/app/crm';
  if (/^cash(?:[/?#]|$)/.test(cleaned)) return '/app/cash';
  if (/^reports(?:[/?#]|$)/.test(cleaned)) return '/app/reports';
  if (/^support(?:[/?#]|$)/.test(cleaned)) return '/app/support';
  if (/^settings(?:[/?#]|$)/.test(cleaned)) return '/app/settings';
  if (/^finance(?:[/?#]|$)/.test(cleaned)) return `/app/${cleaned}`;
  return `/app/${cleaned}`;
}

export function NotificationBell() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const wrapperRef = useRef<HTMLDivElement>(null);

  const countQuery = useQuery({
    queryKey: ['notifications', 'count'],
    queryFn: () => apiPostRoot<NotificationCountResponse>('/api/notifications.php', { action: 'count' }),
    refetchInterval: 60_000,
    staleTime: 30_000,
  });

  const listQuery = useQuery({
    queryKey: ['notifications', 'list'],
    queryFn: () => apiPostRoot<NotificationListResponse>('/api/notifications.php', { action: 'list' }),
    enabled: open,
    staleTime: 15_000,
  });

  const unread = listQuery.data?.unread ?? countQuery.data?.unread ?? 0;
  const notifications = listQuery.data?.notifications ?? [];

  const markRead = useMutation({
    mutationFn: (notificationId: number) => apiPostRoot<{ success: boolean; unread: number }>('/api/notifications.php', { action: 'mark_read', notification_id: notificationId }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['notifications'] });
    },
  });

  const markAll = useMutation({
    mutationFn: () => apiPostRoot<{ success: boolean; unread: number }>('/api/notifications.php', { action: 'mark_all_read' }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['notifications'] });
    },
  });

  useEffect(() => {
    if (!open) return;
    const onPointerDown = (event: MouseEvent) => {
      if (wrapperRef.current && !wrapperRef.current.contains(event.target as Node)) setOpen(false);
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('mousedown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  const openNotification = (notification: NotificationItem) => {
    if (!notification.is_read) markRead.mutate(notification.id);
    const href = notificationHref(notification.url);
    setOpen(false);
    if (href !== null) navigate(href);
  };

  return (
    <div ref={wrapperRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((current) => !current)}
        className="relative flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
        aria-label={unread > 0 ? `Notificaciones, ${unread} sin leer` : 'Notificaciones'}
        aria-expanded={open}
        aria-haspopup="true"
        title="Notificaciones"
      >
        <Bell className="h-[18px] w-[18px]" />
        {unread > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-bold leading-none text-white ring-2 ring-white">
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 top-full z-40 mt-2 flex max-h-[70vh] w-[min(22rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
          <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <div className="flex items-center gap-2">
              <h3 className="text-sm font-semibold text-slate-800">Notificaciones</h3>
              {unread > 0 && <span className="rounded-full bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold text-rose-600">{unread}</span>}
            </div>
            {unread > 0 && (
              <button type="button" onClick={() => markAll.mutate()} disabled={markAll.isPending} className="inline-flex items-center gap-1 text-[10px] font-semibold text-brand-700 transition hover:text-brand-800 disabled:opacity-60">
                <CheckCheck className="h-3.5 w-3.5" /> Marcar todas
              </button>
            )}
          </div>

          <div className="min-h-0 flex-1 overflow-y-auto">
            {listQuery.isLoading && <p className="px-4 py-8 text-center text-xs text-slate-400">Cargando notificaciones…</p>}
            {!listQuery.isLoading && notifications.length === 0 && (
              <div className="px-4 py-10 text-center">
                <span className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-slate-100 text-slate-400"><Inbox className="h-5 w-5" /></span>
                <p className="text-xs font-semibold text-slate-700">Todo al día</p>
                <p className="mt-1 text-[11px] text-slate-400">No tienes notificaciones pendientes.</p>
              </div>
            )}
            {notifications.map((notification) => (
              <button
                key={notification.id}
                type="button"
                onClick={() => openNotification(notification)}
                className={`flex w-full items-start gap-3 border-b border-slate-100 px-4 py-3 text-left transition last:border-0 hover:bg-slate-50 ${notification.is_read ? 'opacity-65' : ''}`}
              >
                <span className={`mt-1 h-2 w-2 flex-shrink-0 rounded-full ${notification.is_read ? 'bg-slate-200' : 'bg-brand-500'}`} />
                <span className="min-w-0 flex-1">
                  <span className={`block truncate text-xs ${notification.is_read ? 'font-medium text-slate-600' : 'font-semibold text-slate-900'}`}>{notification.title}</span>
                  {notification.message && <span className="mt-0.5 block text-[11px] leading-snug text-slate-500">{notification.message}</span>}
                  <span className="mt-1 block text-[10px] text-slate-400">{notification.created_at}</span>
                </span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
