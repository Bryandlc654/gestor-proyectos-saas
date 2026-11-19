import { useMutation, useQuery } from '@tanstack/react-query';
import { CheckSquare, ExternalLink, Image as ImageIcon, LifeBuoy, MessageSquare, Plus, Reply, Send, Trash2, X } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import { LoadingButton, useConfirm, useToast } from '../../../components/feedback';
import { ErrorState, Input, Select } from '../../../components/ui';
import { apiPostRoot, apiUploadRoot } from '../../../lib/api';
import { COMMENT_IMAGE_ACCEPT, commentImageError } from '../../../lib/upload';
import type { ProjectTabsData } from '../../../types';
import { Avatar, errorMessage, FieldLabel, formatDateTime, ModalActions, ModalShell, PriorityBadge, relativeTime, SegmentedControl, useProject } from './shared';

type Channel = 'notas' | 'tickets';
type Ticket = ProjectTabsData['tickets'][number];
type ProjectComment = ProjectTabsData['comments'][number];
interface TicketReply { id: number; author_name: string; role_label: string; raw_message: string; created_at: string; can_delete?: boolean }

const TICKET_STATUS: Record<string, { label: string; className: string }> = {
  abierto: { label: 'Abierto', className: 'bg-amber-50 text-amber-700 ring-amber-200/70' },
  en_revision: { label: 'En revisión', className: 'bg-brand-50 text-brand-700 ring-brand-200/70' },
  resuelto: { label: 'Resuelto', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200/70' },
  cerrado: { label: 'Cerrado', className: 'bg-slate-100 text-slate-600 ring-slate-200/70' },
};

/** Los endpoints clásicos responden 200 con success:false ante errores; aquí se convierten en excepciones. */
async function ensureOk<T extends { success?: boolean; message?: string }>(promise: Promise<T>): Promise<T> {
  const result = await promise;
  if (result && result.success === false) throw new Error(result.message ?? 'No se pudo completar la acción.');
  return result;
}

export function ConversationTab() {
  const { tabs } = useProject();
  const [searchParams, setSearchParams] = useSearchParams();
  const channel: Channel = searchParams.get('canal') === 'tickets' ? 'tickets' : 'notas';
  const notes = (tabs?.comments ?? []).filter((item) => item.parentId === null).length;
  const openTickets = (tabs?.tickets ?? []).filter((ticket) => ticket.status === 'abierto' || ticket.status === 'en_revision').length;

  return (
    <section className="space-y-4">
      <SegmentedControl<Channel>
        value={channel}
        onChange={(value) => { const next = new URLSearchParams(searchParams); if (value === 'notas') next.delete('canal'); else next.set('canal', value); next.delete('ticket'); setSearchParams(next, { replace: true }); }}
        options={[
          { value: 'notas', label: `Notas y acuerdos${notes ? ` · ${notes}` : ''}` },
          { value: 'tickets', label: `Tickets${openTickets ? ` · ${openTickets} abiertos` : ''}` },
        ]}
      />
      {channel === 'notas' ? <NotesPanel /> : <TicketsPanel />}
    </section>
  );
}

/* ------------------------------------------------------------------ */
function NotesPanel() {
  const { projectId, tabs, perms, refresh } = useProject();
  const toast = useToast();
  const confirm = useConfirm();
  const draftKey = `vertice_note_draft_${projectId}`;
  const [text, setText] = useState(() => { try { return localStorage.getItem(draftKey) ?? ''; } catch { return ''; } });
  const [image, setImage] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [replyTo, setReplyTo] = useState<number | null>(null);
  const [replyText, setReplyText] = useState('');

  useEffect(() => {
    try { if (text.trim()) localStorage.setItem(draftKey, text); else localStorage.removeItem(draftKey); } catch { /* sin almacenamiento */ }
  }, [text, draftKey]);

  const publish = useMutation({
    mutationFn: ({ content, parentId, file }: { content: string; parentId: number | null; file?: File | null }) => {
      const form = new FormData();
      form.set('action', 'create');
      form.set('project_id', String(projectId));
      form.set('content', content);
      form.set('parent_id', parentId ? String(parentId) : '');
      if (file) form.set('image', file);
      return ensureOk(apiUploadRoot<{ success: boolean; message?: string }>('/api/comments.php', form));
    },
    onSuccess: (_result, vars) => {
      if (vars.parentId) { setReplyTo(null); setReplyText(''); } else { setText(''); setImage(null); setPreview(null); }
      refresh.tabs();
      toast(vars.parentId ? 'Respuesta publicada.' : 'Nota publicada.');
    },
    onError: (error) => toast(errorMessage(error, 'No se pudo publicar.'), 'error'),
  });
  const remove = useMutation({
    mutationFn: (commentId: number) => ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/comments.php', { action: 'delete', project_id: projectId, comment_id: commentId })),
    onSuccess: () => { refresh.tabs(); toast('Nota eliminada.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo eliminar.'), 'error'),
  });

  const comments = tabs?.comments ?? [];
  const threads = comments.filter((item) => item.parentId === null).reverse();
  const replies = comments.reduce<Record<number, ProjectComment[]>>((groups, item) => {
    if (item.parentId) (groups[item.parentId] ??= []).push(item);
    return groups;
  }, {});
  const canDelete = (item: ProjectComment) => perms.comment && (item.userId === tabs?.currentUserId || perms.moderate);

  return (
    <div className="space-y-4">
      {perms.comment ? (
        <form className="rounded-2xl border border-slate-200/80 bg-white p-3 shadow-2xs focus-within:border-brand-300" onSubmit={(event) => { event.preventDefault(); publish.mutate({ content: text, parentId: null, file: image }); }}>
          <textarea value={text} onChange={(event) => setText(event.target.value)} rows={2} placeholder="Escribe una nota, acuerdo o actualización para el equipo..." className="w-full resize-none border-0 p-1 text-sm leading-relaxed focus:outline-none focus:ring-0" />
          {preview && (
            <div className="relative mt-2 inline-block rounded-xl border border-slate-200 bg-slate-50 p-1.5">
              <img src={preview} alt="Vista previa" className="h-20 max-w-xs rounded-lg object-cover" />
              <button type="button" onClick={() => { setImage(null); setPreview(null); }} className="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-rose-600 text-white shadow" aria-label="Quitar imagen"><X className="h-3 w-3" /></button>
            </div>
          )}
          <div className="mt-2 flex items-center justify-between border-t border-slate-100 pt-2">
            <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-medium text-slate-500 hover:bg-brand-50 hover:text-brand-600">
              <ImageIcon className="h-4 w-4" />Imagen
              <input type="file" accept={COMMENT_IMAGE_ACCEPT} className="hidden" onChange={(event) => {
                const file = event.target.files?.[0] ?? null;
                const invalid = file ? commentImageError(file) : null;
                if (invalid) { toast(invalid, 'error'); event.target.value = ''; return; }
                setImage(file);
                if (file) { const reader = new FileReader(); reader.onload = () => setPreview(String(reader.result ?? '')); reader.readAsDataURL(file); } else setPreview(null);
                event.target.value = '';
              }} />
            </label>
            <LoadingButton type="submit" pending={publish.isPending && !publish.variables?.parentId} disabled={!text.trim() && !image} className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:opacity-50"><Send className="h-3 w-3" />Publicar</LoadingButton>
          </div>
        </form>
      ) : (
        <div className="rounded-2xl border border-amber-200/60 bg-amber-50/70 p-4 text-xs text-amber-800">Tienes acceso de solo lectura: puedes ver las notas, pero no publicar.</div>
      )}

      {threads.length === 0 && <p className="py-8 text-center text-xs italic text-slate-400">Todavía no hay notas en este proyecto.</p>}
      {threads.map((item) => {
        const thread = replies[item.id] ?? [];
        return (
          <article key={item.id} className="group rounded-2xl border border-slate-200/70 bg-white p-4 shadow-2xs">
            <div className="flex items-start gap-3">
              <Avatar name={item.user} url={item.avatarUrl} size="h-8 w-8 text-xs" />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center justify-between gap-1">
                  <div className="flex min-w-0 items-center gap-2">
                    <h4 className="text-xs font-bold text-slate-900">{item.user}</h4>
                    <span className="text-[11px] text-slate-400" title={formatDateTime(item.createdAt)}>{relativeTime(item.createdAt)}</span>
                    {item.taskId && item.taskTitle && <span className="inline-flex min-w-0 items-center gap-1 rounded-md bg-brand-50 px-2 py-0.5 text-[10px] font-semibold text-brand-700"><CheckSquare className="h-3 w-3" /><span className="truncate">{item.taskTitle}</span></span>}
                  </div>
                  {canDelete(item) && <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar nota', description: 'La nota se eliminará del proyecto.', confirmLabel: 'Eliminar', tone: 'danger' })) remove.mutate(item.id); }} className="rounded p-1 text-slate-300 opacity-0 transition hover:text-rose-600 group-hover:opacity-100" title="Eliminar nota"><Trash2 className="h-3.5 w-3.5" /></button>}
                </div>
                {item.content && <p className="mt-1 whitespace-pre-line text-[13px] leading-relaxed text-slate-600">{item.content}</p>}
                {item.imagePath && <a href={item.imagePath} target="_blank" rel="noreferrer"><img src={item.imagePath} alt="Imagen adjunta" className="mt-2.5 max-h-72 rounded-xl border border-slate-200" /></a>}
                {perms.comment && <button type="button" onClick={() => { setReplyTo(replyTo === item.id ? null : item.id); setReplyText(''); }} className="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold text-brand-600 hover:text-brand-700"><Reply className="h-3 w-3" />Responder{thread.length > 0 ? ` · ${thread.length}` : ''}</button>}
              </div>
            </div>

            {thread.length > 0 && (
              <div className="ml-11 mt-3 space-y-2 border-l-2 border-slate-100 pl-4">
                {thread.map((reply) => (
                  <div key={reply.id} className="group/reply flex items-start gap-2.5">
                    <Avatar name={reply.user} url={reply.avatarUrl} size="h-6 w-6 text-[9px]" />
                    <div className="min-w-0 flex-1 rounded-xl bg-slate-50 px-3 py-2">
                      <div className="flex items-center justify-between gap-2"><span className="text-xs font-bold text-slate-800">{reply.user}</span><span className="text-[10px] text-slate-400" title={formatDateTime(reply.createdAt)}>{relativeTime(reply.createdAt)}</span></div>
                      {reply.content && <p className="mt-0.5 whitespace-pre-line text-xs leading-relaxed text-slate-600">{reply.content}</p>}
                      {reply.imagePath && <img src={reply.imagePath} alt="" className="mt-2 max-h-48 rounded-lg border border-slate-200" />}
                    </div>
                    {canDelete(reply) && <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar respuesta', confirmLabel: 'Eliminar', tone: 'danger' })) remove.mutate(reply.id); }} className="rounded p-1 text-slate-300 opacity-0 transition hover:text-rose-600 group-hover/reply:opacity-100" title="Eliminar respuesta"><Trash2 className="h-3 w-3" /></button>}
                  </div>
                ))}
              </div>
            )}

            {replyTo === item.id && (
              <form className="ml-11 mt-3 flex items-end gap-2 border-l-2 border-slate-100 pl-4" onSubmit={(event) => { event.preventDefault(); if (replyText.trim()) publish.mutate({ content: replyText, parentId: item.id }); }}>
                <textarea value={replyText} onChange={(event) => setReplyText(event.target.value)} autoFocus rows={2} placeholder={`Responder a ${item.user}...`} className="w-full rounded-xl border border-slate-200 bg-slate-50/50 p-2.5 text-xs focus:border-brand-300 focus:outline-none" />
                <LoadingButton type="submit" pending={publish.isPending && publish.variables?.parentId === item.id} disabled={!replyText.trim()} className="inline-flex items-center gap-1 rounded-xl bg-brand-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50"><Send className="h-3 w-3" />Enviar</LoadingButton>
              </form>
            )}
          </article>
        );
      })}
    </div>
  );
}

/* ------------------------------------------------------------------ */
function TicketsPanel() {
  const { tabs, perms } = useProject();
  const [searchParams, setSearchParams] = useSearchParams();
  const [scope, setScope] = useState<'abiertos' | 'todos'>('abiertos');
  const [creating, setCreating] = useState(false);
  const tickets = tabs?.tickets ?? [];
  const visible = tickets.filter((ticket) => scope === 'todos' || ticket.status === 'abierto' || ticket.status === 'en_revision');
  const selectedId = Number(searchParams.get('ticket')) || visible[0]?.id || null;
  const selected = tickets.find((ticket) => ticket.id === selectedId) ?? null;
  const select = (ticketId: number) => { const next = new URLSearchParams(searchParams); next.set('ticket', String(ticketId)); setSearchParams(next, { replace: true }); };

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <SegmentedControl value={scope} onChange={setScope} options={[{ value: 'abiertos', label: 'Abiertos' }, { value: 'todos', label: `Todos (${tickets.length})` }]} />
        {perms.createTickets && <button type="button" onClick={() => setCreating(true)} className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-brand-700"><Plus className="h-3.5 w-3.5" />Nuevo ticket</button>}
      </div>

      {tickets.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center">
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><LifeBuoy className="h-6 w-6" /></div>
          <h3 className="text-sm font-semibold text-slate-800">No hay tickets</h3>
          <p className="mt-1 text-xs text-slate-500">Las incidencias y solicitudes de cambio aparecerán aquí.</p>
        </div>
      ) : (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,340px)_minmax(0,1fr)]">
          <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
            {visible.length === 0 && <p className="px-4 py-10 text-center text-xs text-slate-400">No hay tickets abiertos. 🎉</p>}
            <ul className="max-h-[32rem] divide-y divide-slate-100 overflow-y-auto">
              {visible.map((ticket) => {
                const status = TICKET_STATUS[ticket.status] ?? TICKET_STATUS.abierto;
                const active = ticket.id === selected?.id;
                return (
                  <li key={ticket.id}>
                    <button type="button" onClick={() => select(ticket.id)} className={`w-full px-4 py-3 text-left transition ${active ? 'bg-brand-50/70' : 'hover:bg-slate-50'}`}>
                      <div className="flex items-center justify-between gap-2">
                        <span className="font-mono text-[10px] font-semibold text-slate-400">#TK-{ticket.id}</span>
                        <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ring-1 ${status.className}`}>{status.label}</span>
                      </div>
                      <p className={`mt-1 truncate text-xs font-semibold ${active ? 'text-brand-800' : 'text-slate-800'}`}>{ticket.title}</p>
                      <p className="mt-0.5 flex items-center gap-2 text-[11px] text-slate-400">
                        <span>{relativeTime(ticket.createdAt)}</span>
                        {ticket.repliesCount > 0 && <span className="inline-flex items-center gap-0.5"><MessageSquare className="h-3 w-3" />{ticket.repliesCount}</span>}
                        <span className="capitalize">· {ticket.priority}</span>
                      </p>
                    </button>
                  </li>
                );
              })}
            </ul>
          </div>
          {selected ? <TicketDetail key={selected.id} ticket={selected} /> : <div className="rounded-2xl border border-dashed border-slate-200 p-10 text-center text-xs text-slate-400">Selecciona un ticket para ver la conversación.</div>}
        </div>
      )}

      {creating && <NewTicketModal onClose={() => setCreating(false)} onCreated={() => setScope('abiertos')} />}
    </div>
  );
}

function TicketDetail({ ticket }: { ticket: Ticket }) {
  const { projectId, perms, refresh, preview } = useProject();
  const toast = useToast();
  const confirm = useConfirm();
  const [message, setMessage] = useState('');
  const repliesQuery = useQuery({
    queryKey: ['ticket-replies', projectId, ticket.id],
    queryFn: () => ensureOk(apiPostRoot<{ success: boolean; replies: TicketReply[] }>('/api/tickets.php', { action: 'list_replies', project_id: projectId, ticket_id: ticket.id })).then((result) => result.replies ?? []),
  });
  const reply = useMutation({
    mutationFn: (text: string) => ensureOk(apiPostRoot<{ success: boolean }>('/api/tickets.php', { action: 'add_reply', project_id: projectId, ticket_id: ticket.id, message: text })),
    onSuccess: () => { setMessage(''); void repliesQuery.refetch(); refresh.tabs(); },
    onError: (error) => toast(errorMessage(error, 'No se pudo enviar la respuesta.'), 'error'),
  });
  const setStatus = useMutation({
    mutationFn: (status: string) => ensureOk(apiPostRoot<{ success: boolean }>('/api/tickets.php', { action: 'update_status', project_id: projectId, ticket_id: ticket.id, status })),
    onSuccess: () => { refresh.tabs(); toast('Estado del ticket actualizado.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo cambiar el estado.'), 'error'),
  });
  const remove = useMutation({
    mutationFn: () => ensureOk(apiPostRoot<{ success: boolean }>('/api/tickets.php', { action: 'delete', project_id: projectId, ticket_id: ticket.id })),
    onSuccess: () => { refresh.tabs(); toast('Ticket eliminado.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo eliminar el ticket.'), 'error'),
  });
  const removeReply = useMutation({
    mutationFn: (replyId: number) => ensureOk(apiPostRoot<{ success: boolean }>('/api/tickets.php', { action: 'delete_reply', project_id: projectId, reply_id: replyId })),
    onSuccess: () => { void repliesQuery.refetch(); refresh.tabs(); toast('Respuesta eliminada.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo eliminar la respuesta.'), 'error'),
  });
  const status = TICKET_STATUS[ticket.status] ?? TICKET_STATUS.abierto;
  const submit = (event: FormEvent) => { event.preventDefault(); if (message.trim()) reply.mutate(message.trim()); };

  return (
    <div className="flex min-h-[24rem] flex-col overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
      <div className="space-y-2 border-b border-slate-100 p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="font-mono text-[11px] font-semibold text-slate-400">#TK-{ticket.id}</p>
            <h3 className="text-base font-semibold text-slate-900">{ticket.title}</h3>
          </div>
          <div className="flex items-center gap-2">
            {perms.changeTicketStatus ? (
              <Select value={ticket.status} disabled={setStatus.isPending} onChange={(event) => setStatus.mutate(event.target.value)} aria-label="Estado del ticket" className="py-1.5 text-xs font-semibold">
                {Object.entries(TICKET_STATUS).map(([key, item]) => <option key={key} value={key}>{item.label}</option>)}
              </Select>
            ) : <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ${status.className}`}>{status.label}</span>}
            {perms.deleteTickets && <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar ticket', description: ticket.title, confirmLabel: 'Eliminar', tone: 'danger' })) remove.mutate(); }} className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Eliminar ticket"><Trash2 className="h-4 w-4" /></button>}
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
          <PriorityBadge priority={ticket.priority} />
          <span>Reportado por <strong className="text-slate-600">{ticket.externalRequesterName || ticket.user || 'Sistema'}</strong></span>
          <span>{formatDateTime(ticket.createdAt)}</span>
          {ticket.externalSource && <span className="rounded-full bg-indigo-50 px-2 py-0.5 font-semibold text-indigo-700">API · {ticket.externalSource}</span>}
          {ticket.externalUrl && <a href={ticket.externalUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-semibold text-brand-600"><ExternalLink className="h-3 w-3" />Ver en origen</a>}
        </div>
        <p className="whitespace-pre-line pt-1 text-[13px] leading-relaxed text-slate-600">{ticket.description}</p>
      </div>

      <div className="flex-1 space-y-3 overflow-y-auto bg-slate-50/50 p-5">
        {repliesQuery.isLoading && <p className="text-xs text-slate-400">Cargando conversación…</p>}
        {repliesQuery.data?.length === 0 && <p className="text-center text-xs text-slate-400">Sin respuestas todavía.</p>}
        {repliesQuery.data?.map((item) => (
          <div key={item.id} className="group/reply flex items-start gap-2.5">
            <Avatar name={item.author_name} size="h-7 w-7 text-[10px]" />
            <div className="min-w-0 flex-1 rounded-xl border border-slate-200/70 bg-white px-3 py-2">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-bold text-slate-800">{item.author_name} {item.role_label && <span className="ml-1 font-medium text-slate-400">· {item.role_label}</span>}</span>
                <span className="flex items-center gap-1.5">
                  <span className="text-[10px] text-slate-400" title={formatDateTime(item.created_at)}>{relativeTime(item.created_at)}</span>
                  {item.can_delete && !preview && <button type="button" disabled={removeReply.isPending} onClick={async () => { if (await confirm({ title: 'Eliminar respuesta', description: 'La respuesta se quitará de la conversación del ticket.', confirmLabel: 'Eliminar', tone: 'danger' })) removeReply.mutate(item.id); }} className="rounded p-0.5 text-slate-300 opacity-0 transition hover:text-rose-600 group-hover/reply:opacity-100 focus:opacity-100" title="Eliminar respuesta"><Trash2 className="h-3 w-3" /></button>}
                </span>
              </div>
              <p className="mt-0.5 whitespace-pre-line text-xs leading-relaxed text-slate-600">{item.raw_message}</p>
            </div>
          </div>
        ))}
      </div>

      {perms.createTickets && (
        <form onSubmit={submit} className="flex items-end gap-2 border-t border-slate-100 p-3">
          <textarea value={message} onChange={(event) => setMessage(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) submit(event); }} rows={2} placeholder="Escribe una respuesta… (Ctrl + Enter para enviar)" className="w-full resize-none rounded-xl border border-slate-200 p-2.5 text-xs focus:border-brand-300 focus:outline-none" />
          <LoadingButton type="submit" pending={reply.isPending} disabled={!message.trim()} className="inline-flex items-center gap-1 rounded-xl bg-brand-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50"><Send className="h-3 w-3" />Enviar</LoadingButton>
        </form>
      )}
    </div>
  );
}

function NewTicketModal({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const { projectId, refresh } = useProject();
  const toast = useToast();
  const create = useMutation({
    mutationFn: (body: Record<string, string>) => ensureOk(apiPostRoot<{ success: boolean }>('/api/tickets.php', { action: 'create', project_id: projectId, ...body })),
    onSuccess: () => { refresh.tabs(); toast('Ticket creado.'); onCreated(); onClose(); },
  });
  return (
    <ModalShell title="Nuevo ticket" subtitle="Reporta una incidencia o solicitud" onClose={onClose}>
      <form className="space-y-3 p-6" onSubmit={(event) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        create.mutate({ title: String(form.get('title') ?? ''), description: String(form.get('description') ?? ''), priority: String(form.get('priority') ?? 'media') });
      }}>
        <div><FieldLabel>Asunto *</FieldLabel><Input name="title" required maxLength={200} autoFocus className="px-3.5 py-2.5 text-sm" /></div>
        <div><FieldLabel>Descripción *</FieldLabel><textarea name="description" rows={4} required className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm" /></div>
        <div><FieldLabel>Prioridad</FieldLabel><Select name="priority" defaultValue="media" className="w-full px-3.5 py-2.5"><option value="baja">Baja</option><option value="media">Media</option><option value="alta">Alta</option><option value="critica">Crítica</option></Select></div>
        {create.isError && <ErrorState message={errorMessage(create.error, 'No se pudo crear el ticket.')} />}
        <ModalActions onCancel={onClose}><LoadingButton type="submit" pending={create.isPending} className="rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white disabled:opacity-60">Crear ticket</LoadingButton></ModalActions>
      </form>
    </ModalShell>
  );
}
