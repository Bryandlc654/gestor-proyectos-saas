import { X } from 'lucide-react';
import { createContext, useContext, type ReactNode } from 'react';
import { useDialog } from '../../../components/feedback';
import { initials } from '../../../lib/format';
import type { ProjectDetail, ProjectInsightsData, ProjectTabsData } from '../../../types';

export type ProjectTask = ProjectDetail['tasks'][number];
export type TabKey = 'resumen' | 'tareas' | 'conversacion' | 'archivos' | 'actas' | 'finanzas';
export type TaskView = 'tablero' | 'lista' | 'cronograma' | 'checklists';

/** Permisos efectivos de la vista (en "Ver como cliente" todo queda en solo lectura). */
export interface ProjectPermissions {
  manage: boolean;
  editTasks: boolean;
  changeStatus: boolean;
  deleteTasks: boolean;
  comment: boolean;
  moderate: boolean;
  createTickets: boolean;
  changeTicketStatus: boolean;
  deleteTickets: boolean;
  uploadFiles: boolean;
  manageStructure: boolean;
  manageChecklists: boolean;
  manageMilestones: boolean;
}

export interface ProjectContextValue {
  projectId: number;
  data: ProjectDetail;
  tabs: ProjectTabsData | undefined;
  insights: ProjectInsightsData | undefined;
  /** El usuario es cliente o la agencia está usando "Ver como cliente". */
  clientView: boolean;
  preview: boolean;
  perms: ProjectPermissions;
  currentUserId: number | null;
  doneKeys: string[];
  openTask: (taskId: number) => void;
  openNewTask: () => void;
  setTaskStatus: (taskId: number, status: string) => void;
  goTo: (tab: TabKey, params?: Record<string, string>) => void;
  refresh: { project: () => void; tabs: () => void; insights: () => void; all: () => void };
}

export const ProjectContext = createContext<ProjectContextValue | null>(null);

export function useProject(): ProjectContextValue {
  const value = useContext(ProjectContext);
  if (!value) throw new Error('useProject debe usarse dentro de ProjectPage');
  return value;
}

export const KIND_DOT: Record<string, string> = { open: 'bg-slate-400', in_progress: 'bg-amber-500', done: 'bg-emerald-500' };
export const KIND_BAR: Record<string, string> = { open: 'bg-slate-400', in_progress: 'bg-amber-500', done: 'bg-emerald-500' };

export const PRIORITY_STYLE: Record<string, string> = {
  urgente: 'bg-rose-50 text-rose-700 ring-rose-200/70',
  critica: 'bg-rose-50 text-rose-700 ring-rose-200/70',
  alta: 'bg-amber-50 text-amber-700 ring-amber-200/70',
  media: 'bg-indigo-50 text-indigo-700 ring-indigo-200/70',
  baja: 'bg-slate-50 text-slate-600 ring-slate-200/70',
};

export function PriorityBadge({ priority }: { priority: string }) {
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium capitalize ring-1 ${PRIORITY_STYLE[priority] ?? PRIORITY_STYLE.baja}`}>{priority}</span>;
}

export function todayIso(): string {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function daysUntil(value: string): number {
  return Math.ceil((new Date(`${value}T23:59:59`).getTime() - Date.now()) / 86400000);
}

/** Convierte "2026-11-16 22:15:03" o "2026-11-16" en Date sin depender del formato del navegador. */
export function parseDateTime(value: string | null | undefined): Date | null {
  if (!value) return null;
  const normalized = value.includes('T') ? value : value.length <= 10 ? `${value}T00:00:00` : value.replace(' ', 'T');
  const date = new Date(normalized);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDateTime(value: string | null | undefined): string {
  const date = parseDateTime(value);
  if (!date) return value ?? '';
  return date.toLocaleString('es', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export function relativeTime(value: string): string {
  const date = parseDateTime(value);
  if (!date) return value;
  const minutes = Math.round((Date.now() - date.getTime()) / 60000);
  if (minutes < 1) return 'ahora';
  if (minutes < 60) return `hace ${minutes} min`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `hace ${hours} h`;
  const days = Math.round(hours / 24);
  if (days < 30) return `hace ${days} d`;
  return date.toLocaleDateString('es', { day: '2-digit', month: 'short' });
}

export function formatBytes(size: number): string {
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${Math.round(size / 1024)} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

export function Avatar({ name, url, size = 'h-7 w-7 text-[10px]' }: { name: string; url?: string | null; size?: string }) {
  return (
    <span className={`flex flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-100 font-bold text-slate-600 ring-2 ring-white ${size}`} title={name}>
      {url ? <img src={url} alt="" className="h-full w-full object-cover" /> : initials(name)}
    </span>
  );
}

export function SegmentedControl<T extends string>({ value, options, onChange }: { value: T; options: Array<{ value: T; label: string; icon?: ReactNode }>; onChange: (value: T) => void }) {
  return (
    <div className="flex items-center rounded-xl bg-slate-100 p-0.5" role="tablist">
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          role="tab"
          aria-selected={value === option.value}
          onClick={() => onChange(option.value)}
          className={`flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition ${value === option.value ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-500 hover:text-slate-800'}`}
        >
          {option.icon}
          <span className={option.icon ? 'hidden sm:inline' : ''}>{option.label}</span>
        </button>
      ))}
    </div>
  );
}

export function ModalShell({ title, subtitle, onClose, children, wide }: { title: string; subtitle?: string; onClose: () => void; children: ReactNode; wide?: boolean }) {
  const ref = useDialog(true, onClose);
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-xs" onClick={onClose}>
      <div ref={ref} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" className={`max-h-[92vh] w-full overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl ${wide ? 'max-w-xl' : 'max-w-lg'}`}>
        <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-6 py-4">
          <div>
            <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
            {subtitle && <p className="text-[11px] text-slate-400">{subtitle}</p>}
          </div>
          <button type="button" onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:text-slate-600" aria-label="Cerrar"><X className="h-5 w-5" /></button>
        </div>
        <div className="max-h-[80vh] overflow-y-auto">{children}</div>
      </div>
    </div>
  );
}

export function FieldLabel({ children }: { children: ReactNode }) {
  return <label className="mb-1.5 block text-xs font-medium text-slate-700">{children}</label>;
}

export function ModalActions({ onCancel, children }: { onCancel: () => void; children: ReactNode }) {
  return (
    <div className="flex justify-end gap-3 border-t border-slate-100 pt-3">
      <button type="button" onClick={onCancel} className="rounded-xl px-4 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100">Cancelar</button>
      {children}
    </div>
  );
}

/** Las APIs PHP clásicas responden 200 con success=false: lo convertimos en error. */
export async function ensureOk<T extends { success?: boolean; message?: string }>(promise: Promise<T>): Promise<T> {
  const result = await promise;
  if (result && result.success === false) throw new Error(result.message || 'No se pudo completar la acción.');
  return result;
}

export function errorMessage(error: unknown, fallback: string): string {
  return error instanceof Error ? error.message : fallback;
}
