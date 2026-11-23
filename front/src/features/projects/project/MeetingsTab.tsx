import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, ChevronDown, Download, ExternalLink, FileText, Link2, NotebookPen, Plus, Search, Trash2, UploadCloud, Users, X } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { useConfirm, useToast } from '../../../components/feedback';
import { Card, ErrorState, Input, Skeleton } from '../../../components/ui';
import { apiGet, apiPost, apiUploadRoot } from '../../../lib/api';
import { formatDate } from '../../../lib/format';
import { errorMessage, FieldLabel, formatBytes, ModalActions, ModalShell, todayIso, useProject } from './shared';

const MAX_SIZE = 20 * 1024 * 1024;

export interface ProjectMeeting {
  id: number;
  title: string;
  meetingDate: string;
  attendees: string | null;
  summary: string | null;
  linkUrl: string | null;
  fileName: string | null;
  fileSize: number | null;
  downloadUrl: string | null;
  user: string | null;
  createdAt: string;
}

interface MeetingsResponse {
  meetings: ProjectMeeting[];
  canManage: boolean;
}

export function MeetingsTab() {
  const { projectId, preview } = useProject();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [creating, setCreating] = useState(false);
  const [search, setSearch] = useState('');
  const [expanded, setExpanded] = useState<number | null>(null);

  const queryKey = ['project-meetings', String(projectId)];
  const query = useQuery({ queryKey, queryFn: () => apiGet<MeetingsResponse>('meetings.php', { project_id: projectId }) });
  const canManage = Boolean(query.data?.canManage) && !preview;

  const remove = useMutation({
    mutationFn: (meetingId: number) => apiPost<{ message: string }>('meetings.php', { action: 'delete', project_id: projectId, meeting_id: meetingId }),
    onSuccess: () => { void queryClient.invalidateQueries({ queryKey }); toast('Acta eliminada.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo eliminar el acta.'), 'error'),
  });

  if (query.isLoading) return <Skeleton className="h-64" />;
  if (query.error || !query.data) return <ErrorState message={errorMessage(query.error, 'No se pudieron cargar las actas.')} />;

  const term = search.trim().toLowerCase();
  const meetings = query.data.meetings.filter((meeting) => !term
    || [meeting.title, meeting.attendees, meeting.summary].some((value) => value?.toLowerCase().includes(term)));

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="relative w-full sm:max-w-xs">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" />
          <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar por título, asistentes o acuerdos" className="pl-8" aria-label="Buscar actas" />
        </div>
        {canManage && (
          <button type="button" onClick={() => setCreating(true)} className="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand-600 px-3 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-brand-700"><Plus className="h-4 w-4" />Nueva acta</button>
        )}
      </div>

      <Card className="overflow-hidden">
        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3">
          <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800"><NotebookPen className="h-4 w-4 text-slate-400" />Actas de reunión</h2>
          <span className="text-[11px] text-slate-400">{meetings.length} acta{meetings.length === 1 ? '' : 's'}</span>
        </div>
        {meetings.length === 0 ? (
          <p className="px-5 py-10 text-center text-xs text-slate-400">
            {term ? 'Ninguna acta coincide con la búsqueda.' : canManage ? 'Aún no hay actas. Registra la primera con un enlace (Drive, Notion, Meet…) o subiendo el archivo.' : 'Aún no hay actas de reunión.'}
          </p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {meetings.map((meeting) => {
              const open = expanded === meeting.id;
              const hasDetails = Boolean(meeting.summary || meeting.attendees);
              return (
                <li key={meeting.id} className="group px-5 py-3 hover:bg-slate-50/70">
                  <div className="flex items-start gap-3">
                    <span className="flex h-10 w-10 flex-shrink-0 flex-col items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                      <CalendarDays className="h-4 w-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                      <button type="button" disabled={!hasDetails} onClick={() => setExpanded(open ? null : meeting.id)} className="flex max-w-full items-center gap-1 text-left disabled:cursor-default" aria-expanded={hasDetails ? open : undefined}>
                        <span className="truncate text-xs font-semibold text-slate-800">{meeting.title}</span>
                        {hasDetails && <ChevronDown className={`h-3.5 w-3.5 flex-shrink-0 text-slate-400 transition ${open ? 'rotate-180' : ''}`} />}
                      </button>
                      <span className="block truncate text-[11px] text-slate-400">
                        {formatDate(meeting.meetingDate)}{meeting.user ? ` · registrada por ${meeting.user}` : ''}
                      </span>
                      <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {meeting.linkUrl && (
                          <a href={meeting.linkUrl} target="_blank" rel="noreferrer" className="inline-flex max-w-[16rem] items-center gap-1 rounded-lg bg-indigo-50 px-2 py-1 text-[11px] font-semibold text-indigo-700 ring-1 ring-indigo-200/60 hover:bg-indigo-100" title={meeting.linkUrl}>
                            <Link2 className="h-3 w-3 flex-shrink-0" /><span className="truncate">Abrir enlace</span><ExternalLink className="h-3 w-3 flex-shrink-0" />
                          </a>
                        )}
                        {meeting.downloadUrl && (
                          <a href={meeting.downloadUrl} className="inline-flex max-w-[18rem] items-center gap-1 rounded-lg bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-700 ring-1 ring-slate-200/70 hover:bg-slate-200/70" title={`Descargar ${meeting.fileName}`}>
                            <FileText className="h-3 w-3 flex-shrink-0" /><span className="truncate">{meeting.fileName}</span>
                            {meeting.fileSize !== null && <span className="flex-shrink-0 font-normal text-slate-400">{formatBytes(meeting.fileSize)}</span>}
                            <Download className="h-3 w-3 flex-shrink-0" />
                          </a>
                        )}
                      </div>
                      {open && (
                        <div className="mt-3 space-y-2 rounded-xl border border-slate-100 bg-white p-3 text-xs text-slate-600">
                          {meeting.attendees && <p className="flex items-start gap-1.5"><Users className="mt-0.5 h-3.5 w-3.5 flex-shrink-0 text-slate-400" /><span><strong className="font-semibold text-slate-700">Asistentes:</strong> {meeting.attendees}</span></p>}
                          {meeting.summary && <p className="whitespace-pre-line leading-relaxed">{meeting.summary}</p>}
                        </div>
                      )}
                    </div>
                    {canManage && (
                      <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar acta', description: `"${meeting.title}". También se borrará el archivo adjunto, si lo tiene.`, confirmLabel: 'Eliminar', tone: 'danger' })) remove.mutate(meeting.id); }} className="rounded-lg p-1.5 text-slate-300 opacity-0 transition hover:bg-white hover:text-rose-600 focus:opacity-100 group-hover:opacity-100" title="Eliminar acta"><Trash2 className="h-4 w-4" /></button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </Card>

      {creating && <NewMeetingModal projectId={projectId} onClose={() => setCreating(false)} onCreated={() => { setCreating(false); void queryClient.invalidateQueries({ queryKey }); toast('Acta registrada.'); }} />}
    </div>
  );
}

function NewMeetingModal({ projectId, onClose, onCreated }: { projectId: number; onClose: () => void; onCreated: () => void }) {
  const toast = useToast();
  const inputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);

  const create = useMutation({
    mutationFn: (form: FormData) => apiUploadRoot<{ success: boolean; message?: string }>('/api/app/meetings.php', form),
    onSuccess: onCreated,
    onError: (err) => setError(errorMessage(err, 'No se pudo registrar el acta.')),
  });

  const pickFile = (picked: File | undefined) => {
    if (!picked) return;
    if (picked.size > MAX_SIZE) { toast('El archivo supera el límite de 20 MB.', 'error'); return; }
    setFile(picked);
  };

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    const source = new FormData(event.currentTarget);
    const link = String(source.get('link_url') ?? '').trim();
    if (!link && !file) { setError('Adjunta un archivo o indica un enlace al acta.'); return; }
    const form = new FormData();
    form.set('action', 'create');
    form.set('project_id', String(projectId));
    ['title', 'meeting_date', 'attendees', 'summary'].forEach((key) => form.set(key, String(source.get(key) ?? '')));
    form.set('link_url', link);
    if (file) form.append('file', file);
    create.mutate(form);
  };

  return (
    <ModalShell title="Nueva acta de reunión" subtitle="Guarda el enlace al acta, sube el archivo o ambos." onClose={onClose} wide>
      <form onSubmit={submit} className="space-y-4 p-6">
        <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
          <div>
            <FieldLabel>Título</FieldLabel>
            <Input name="title" required autoFocus maxLength={150} placeholder="Ej: Kickoff con el cliente" />
          </div>
          <div>
            <FieldLabel>Fecha</FieldLabel>
            <Input name="meeting_date" type="date" required defaultValue={todayIso()} />
          </div>
        </div>
        <div>
          <FieldLabel>Asistentes (opcional)</FieldLabel>
          <Input name="attendees" maxLength={500} placeholder="Ana (cliente), Luis (PM), Sofía (diseño)" />
        </div>
        <div>
          <FieldLabel>Resumen y acuerdos (opcional)</FieldLabel>
          <textarea name="summary" rows={4} maxLength={20000} placeholder="Puntos tratados, acuerdos y próximos pasos…" className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15" />
        </div>
        <div>
          <FieldLabel>Enlace al acta</FieldLabel>
          <Input name="link_url" type="url" maxLength={500} placeholder="https://docs.google.com/…" />
        </div>
        <div>
          <FieldLabel>Archivo del acta</FieldLabel>
          {file ? (
            <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs">
              <FileText className="h-4 w-4 flex-shrink-0 text-brand-600" />
              <span className="min-w-0 flex-1 truncate font-medium text-slate-700">{file.name}</span>
              <span className="text-[11px] text-slate-400">{formatBytes(file.size)}</span>
              <button type="button" onClick={() => setFile(null)} className="rounded p-0.5 text-slate-400 hover:text-rose-600" aria-label="Quitar archivo"><X className="h-3.5 w-3.5" /></button>
            </div>
          ) : (
            <button type="button" onClick={() => inputRef.current?.click()} className="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-200 px-4 py-4 text-xs font-medium text-slate-500 transition hover:border-brand-300 hover:bg-slate-50 hover:text-slate-700">
              <UploadCloud className="h-4 w-4" />Elegir archivo (PDF, Word, imagen… máx. 20 MB)
            </button>
          )}
          <input ref={inputRef} type="file" className="hidden" accept=".pdf,.doc,.docx,.odt,.rtf,.txt,.md,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp,.mp3,.mp4,.webm,.zip" onChange={(event) => { pickFile(event.target.files?.[0]); event.target.value = ''; }} />
          <p className="mt-1.5 text-[11px] text-slate-400">Debes indicar al menos un enlace o un archivo.</p>
        </div>
        {error && <p className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">{error}</p>}
        <ModalActions onCancel={onClose}>
          <button type="submit" disabled={create.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-brand-700 disabled:opacity-60">{create.isPending ? 'Guardando…' : 'Guardar acta'}</button>
        </ModalActions>
      </form>
    </ModalShell>
  );
}
