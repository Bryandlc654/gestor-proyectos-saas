import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertCircle, Check, ChevronDown, Image as ImageIcon, LifeBuoy, Mail, RotateCcw, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { Card, ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { useToast } from '../../components/feedback';
import { apiGet, apiPost, apiUploadRoot } from '../../lib/api';
import { formatDate } from '../../lib/format';
import type { SupportData } from '../../types';

const STATUS_META: Record<string, { label: string; className: string }> = {
  abierto: { label: 'Abierto', className: 'bg-amber-50 text-amber-700 border-amber-200' },
  en_proceso: { label: 'En proceso', className: 'bg-sky-50 text-sky-700 border-sky-200' },
  resuelto: { label: 'Resuelto', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
};

const PRIORITY_LABELS: Record<string, string> = { baja: 'Baja', media: 'Media', alta: 'Alta' };

const CATEGORY_LABELS: Record<string, string> = {
  tecnico: 'Técnico',
  facturacion: 'Facturación',
  cuenta: 'Cuenta y acceso',
  otro: 'Otro',
};

const CATEGORY_OPTIONS = Object.entries(CATEGORY_LABELS);
const PRIORITY_OPTIONS = Object.entries(PRIORITY_LABELS);

export function SupportPage() {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [page, setPage] = useState(1);
  const [formKey, setFormKey] = useState(0);
  const [subject, setSubject] = useState('');
  const [category, setCategory] = useState('tecnico');
  const [priority, setPriority] = useState('media');
  const [message, setMessage] = useState('');
  const [fileError, setFileError] = useState('');
  const [notice, setNotice] = useState<{ message: string; warning: boolean } | null>(null);

  const { data, isLoading, error } = useQuery({ queryKey: ['support', page], queryFn: () => apiGet<SupportData>('support.php', { page }) });

  const createRequest = useMutation({
    mutationFn: (formData: FormData) => apiUploadRoot<{ success: boolean; mailSent: boolean; message: string }>('/api/app/support.php', formData),
    onSuccess: (result) => {
      setNotice({ message: result.message, warning: !result.mailSent });
      setSubject('');
      setMessage('');
      setCategory('tecnico');
      setPriority('media');
      setFileError('');
      setFormKey((value) => value + 1);
      void queryClient.invalidateQueries({ queryKey: ['support'] });
      toast(result.message, result.mailSent ? 'success' : 'info');
    },
  });

  const setRequestStatus = useMutation({
    mutationFn: ({ requestId, action }: { requestId: number; action: 'resolve' | 'reopen' }) => apiPost<{ success: boolean }>('support.php', { action, request_id: requestId }),
    onSuccess: () => { void queryClient.invalidateQueries({ queryKey: ['support'] }); toast('Solicitud actualizada.'); },
  });

  if (isLoading) return <div className="space-y-4">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} className="h-24" />)}</div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudo cargar soporte.'} />;

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const formElement = event.currentTarget;
    const formData = new FormData(formElement);
    formData.set('action', 'create');
    createRequest.mutate(formData);
  };

  return (
    <div className="space-y-6">
      <div className="min-w-0">
        <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Soporte</h1>
        <p className="hidden text-[11px] text-slate-400 sm:block">Contacto con el equipo de Vértice</p>
      </div>

      {createRequest.isError && (
        <div className="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          <AlertCircle className="mt-0.5 h-5 w-5 flex-shrink-0" />
          <span>{createRequest.error instanceof Error ? createRequest.error.message : 'No se pudo enviar la solicitud.'}</span>
        </div>
      )}
      {notice && (
        <div className={`rounded-xl border px-4 py-3 text-sm ${notice.warning ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800'}`}>
          {notice.message}
        </div>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="space-y-4 p-6 lg:col-span-2">
          <div>
            <h2 className="text-sm font-semibold text-slate-800">Nueva solicitud</h2>
            <p className="mt-1 text-xs text-slate-500">Cuéntanos qué necesitas y te responderemos por correo.</p>
          </div>

          <form key={formKey} className="space-y-4" onSubmit={submit}>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Asunto *</label>
              <Input value={subject} onChange={(event) => setSubject(event.target.value)} required maxLength={150} placeholder="Ej: No puedo acceder a mi cuenta" className="px-3.5 py-2.5 text-sm" />
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Categoría</label>
                <Select value={category} onChange={(event) => setCategory(event.target.value)} className="w-full px-3.5 py-2.5 text-sm">
                  {CATEGORY_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </Select>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Prioridad</label>
                <Select value={priority} onChange={(event) => setPriority(event.target.value)} className="w-full px-3.5 py-2.5 text-sm">
                  {PRIORITY_OPTIONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </Select>
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Mensaje *</label>
              <textarea value={message} onChange={(event) => setMessage(event.target.value)} rows={6} required minLength={10} maxLength={5000} placeholder="Describe el problema, qué intentaste y cualquier detalle que ayude a reproducirlo…" className="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15" />
              <p className="mt-1 text-[10px] text-slate-400">Mínimo 10 caracteres · máximo 5000.</p>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Imagen adjunta <span className="font-normal text-slate-400">(opcional)</span></label>
              <input
                type="file"
                name="image"
                accept="image/png,image/jpeg,image/webp,image/gif"
                onChange={(event) => {
                  setFileError('');
                  const file = event.target.files?.[0];
                  if (file && file.size > 5 * 1024 * 1024) {
                    event.target.value = '';
                    setFileError('La imagen supera el límite de 5 MB.');
                  }
                }}
                className="block w-full cursor-pointer text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-brand-700 hover:file:bg-brand-100"
              />
              <p className="mt-1 text-[10px] text-slate-400">PNG, JPG, WEBP o GIF · máximo 5 MB.</p>
              {fileError && <p className="mt-1 text-[11px] text-rose-600">{fileError}</p>}
            </div>

            <div className="flex items-center justify-end pt-1">
              <button type="submit" disabled={createRequest.isPending} className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60">
                <Send className="h-4 w-4" />
                Enviar solicitud
              </button>
            </div>
          </form>
        </Card>

        <aside className="space-y-4">
          <div className="space-y-3 rounded-2xl bg-slate-900 p-6 text-white">
            <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-white/10"><LifeBuoy className="h-5 w-5" /></div>
            <div>
              <h2 className="text-sm font-semibold">Soporte {data.brandName}</h2>
              <p className="mt-1 text-xs leading-relaxed text-white/70">Te ayudamos con la cuenta, el acceso, la facturación o cualquier duda de la plataforma.</p>
            </div>
            {data.supportEmail && (
              <a href={`mailto:${data.supportEmail}`} className="inline-flex items-center gap-2 text-xs font-semibold text-cyan-200 transition hover:text-white">
                <Mail className="h-4 w-4" />
                {data.supportEmail}
              </a>
            )}
            <ul className="space-y-1.5 pt-1 text-[11px] text-white/60">
              <li className="flex gap-2"><span className="text-cyan-300">•</span> Incluye pasos para reproducir el problema.</li>
              <li className="flex gap-2"><span className="text-cyan-300">•</span> Indica el proyecto o factura afectados.</li>
              <li className="flex gap-2"><span className="text-cyan-300">•</span> Adjunta una imagen (máx. 5 MB) si ayuda a ver el problema.</li>
            </ul>
          </div>

          <div className="space-y-2 rounded-2xl border border-slate-200/80 bg-white p-5 text-xs text-slate-500">
            <p className="flex justify-between"><span className="font-medium text-slate-600">Horario</span> L–V · 9:00–18:00</p>
            <p className="flex justify-between"><span className="font-medium text-slate-600">Respuesta</span> 24–48 h laborables</p>
            <p className="flex justify-between"><span className="font-medium text-slate-600">Prioridad alta</span> Se atiende primero</p>
          </div>
        </aside>
      </div>

      <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
        <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
          <h2 className="text-sm font-semibold text-slate-800">Solicitudes recientes</h2>
          <span className="text-[11px] text-slate-400">{data.pagination.total} en total</span>
        </div>

        {data.requests.length === 0 ? (
          <p className="px-5 py-10 text-center text-sm text-slate-400">Aún no has enviado solicitudes de soporte.</p>
        ) : (
          <div className="divide-y divide-slate-100">
            {data.requests.map((request) => {
              const status = STATUS_META[request.status] ?? STATUS_META.abierto;
              return (
                <details key={request.id} className="group">
                  <summary className="flex cursor-pointer list-none items-center gap-3 px-5 py-4 transition hover:bg-slate-50/60">
                    <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold ${status.className}`}>{status.label}</span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold text-slate-800">{request.subject}</p>
                      <p className="truncate text-[11px] text-slate-400">
                        {CATEGORY_LABELS[request.category] ?? CATEGORY_LABELS.otro} · Prioridad {PRIORITY_LABELS[request.priority] ?? 'Media'} · {formatDate(request.createdAt, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
                        {request.user ? ` · ${request.user}` : ''}
                      </p>
                    </div>
                    {request.imagePath && <ImageIcon className="h-3.5 w-3.5 flex-shrink-0 text-slate-400" aria-label="Incluye imagen adjunta" />}
                    <ChevronDown className="h-4 w-4 flex-shrink-0 text-slate-400 transition-transform group-open:rotate-180" />
                  </summary>

                  <div className="space-y-4 px-5 pb-5">
                    <div className="whitespace-pre-line rounded-xl border border-slate-200/70 bg-slate-50 p-4 text-sm text-slate-600">{request.message}</div>
                    {request.imagePath && (
                      <a href={`/uploads/${request.imagePath}`} target="_blank" rel="noopener noreferrer" className="group/img inline-block" title="Abrir imagen en tamaño completo">
                        <img src={`/uploads/${request.imagePath}`} alt={`Imagen adjunta a la solicitud #${request.id}`} className="max-h-56 w-auto rounded-xl border border-slate-200 shadow-2xs transition group-hover/img:opacity-95" />
                      </a>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <p className="text-[11px] text-slate-400">
                        Referencia #{request.id}
                        {request.contactEmail ? ` · ${request.contactEmail}` : ''}
                      </p>
                      <button
                        type="button"
                        disabled={setRequestStatus.isPending}
                        onClick={() => setRequestStatus.mutate({ requestId: request.id, action: request.status === 'resuelto' ? 'reopen' : 'resolve' })}
                        className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[11px] font-semibold transition disabled:opacity-60 ${request.status === 'resuelto' ? 'border border-slate-200 text-slate-600 hover:bg-slate-50' : 'border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'}`}
                      >
                        {request.status === 'resuelto' ? <RotateCcw className="h-3.5 w-3.5" /> : <Check className="h-3.5 w-3.5" />}
                        {request.status === 'resuelto' ? 'Reabrir' : 'Marcar resuelta'}
                      </button>
                    </div>
                  </div>
                </details>
              );
            })}
          </div>
        )}
      </section>

      <PaginationControls pagination={data.pagination} onChange={setPage} />

      {setRequestStatus.isError && (
        <ErrorState message={setRequestStatus.error instanceof Error ? setRequestStatus.error.message : 'No se pudo actualizar la solicitud.'} />
      )}
    </div>
  );
}

function PaginationControls({
  pagination,
  onChange,
}: {
  pagination: SupportData['pagination'];
  onChange: (page: number) => void;
}) {
  if (pagination.pages <= 1) return null;

  const pages = Array.from(new Set([1, pagination.page - 1, pagination.page, pagination.page + 1, pagination.pages]))
    .filter((value) => value >= 1 && value <= pagination.pages)
    .sort((a, b) => a - b);

  return (
    <nav className="flex flex-col items-center justify-between gap-3 sm:flex-row" aria-label="Paginación">
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
