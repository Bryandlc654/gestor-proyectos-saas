import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Ban, BookOpen, Copy, KeyRound, Plus, RefreshCw, Save, Webhook } from 'lucide-react';
import { useState } from 'react';
import { Card, ErrorState, Input, Select, Skeleton } from '../../components/ui';
import { useConfirm, useToast } from '../../components/feedback';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate } from '../../lib/format';
import type { ApiTokensData } from '../../types';

const DELIVERY_META: Record<string, { label: string; className: string }> = {
  delivered: { label: 'Entregado', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  pending: { label: 'Pendiente', className: 'bg-amber-50 text-amber-700 border-amber-200' },
  processing: { label: 'Procesando', className: 'bg-sky-50 text-sky-700 border-sky-200' },
  failed: { label: 'Fallido', className: 'bg-rose-50 text-rose-700 border-rose-200' },
};

export function ApiTokensPage() {
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const toast = useToast();

  const [name, setName] = useState('');
  const [scopes, setScopes] = useState<string[]>(['projects:read', 'tickets:read', 'tickets:write']);
  const [projectIds, setProjectIds] = useState<string[]>([]);
  const [rateLimit, setRateLimit] = useState('60');
  const [expires, setExpires] = useState('365');
  const [webhookUrl, setWebhookUrl] = useState('');
  const [webhookEvents, setWebhookEvents] = useState<string[]>(['ticket.status_changed']);
  const [webhookActive, setWebhookActive] = useState(true);

  const [newToken, setNewToken] = useState<{ token: string; name: string } | null>(null);
  const [copied, setCopied] = useState(false);
  const [queueMessage, setQueueMessage] = useState('');

  const { data, isLoading, error } = useQuery({ queryKey: ['apiTokens'], queryFn: () => apiGet<ApiTokensData>('api-tokens.php') });

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['apiTokens'] });

  const createToken = useMutation({
    mutationFn: () => apiPost<{ success: boolean; token: string; record: { name: string } }>('api-tokens.php', {
      action: 'create',
      name,
      rate_limit: rateLimit,
      expires_in_days: expires,
      webhook_url: webhookUrl,
      webhook_active: webhookActive ? 1 : 0,
      scopes: JSON.stringify(scopes),
      project_ids: JSON.stringify(projectIds.map(Number)),
      webhook_events: JSON.stringify(webhookEvents),
    }),
    onSuccess: (result) => {
      setNewToken({ token: result.token, name: result.record.name });
      setCopied(false);
      setName('');
      setWebhookUrl('');
      setProjectIds([]);
      refresh();
      toast('Token creado. Cópialo ahora.');
    },
  });

  const revoke = useMutation({
    mutationFn: (tokenId: number) => apiPost<{ success: boolean }>('api-tokens.php', { action: 'revoke', token_id: tokenId }),
    onSuccess: () => { refresh(); toast('Token revocado.'); },
  });

  const saveWebhook = useMutation({
    mutationFn: ({ tokenId, url, events, active }: { tokenId: number; url: string; events: string[]; active: boolean }) => apiPost<{ success: boolean }>('api-tokens.php', {
      action: 'update_webhook',
      token_id: tokenId,
      webhook_url: url,
      webhook_active: active ? 1 : 0,
      webhook_events: JSON.stringify(events),
    }),
    onSuccess: () => { refresh(); toast('Webhook guardado.'); },
  });

  const retryDelivery = useMutation({
    mutationFn: (deliveryId: number) => apiPost<{ success: boolean }>('api-tokens.php', { action: 'retry_delivery', delivery_id: deliveryId }),
    onSuccess: () => { refresh(); toast('Entrega reenviada a la cola.'); },
  });

  const processQueue = useMutation({
    mutationFn: () => apiPost<{ success: boolean; message: string }>('api-tokens.php', { action: 'process_queue' }),
    onSuccess: (result) => { setQueueMessage(result.message); refresh(); toast(result.message); },
  });

  if (isLoading) return <div className="space-y-4">{Array.from({ length: 4 }).map((_, index) => <Skeleton key={index} className="h-24" />)}</div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudieron cargar los tokens.'} />;

  const toggle = (list: string[], value: string): string[] => list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
  const projectName = (id: number) => data.projects.find((project) => project.id === id)?.name ?? `#${id}`;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Integraciones API</h1>
          <p className="hidden text-[11px] text-slate-400 sm:block">Conecta sistemas externos con tus proyectos</p>
        </div>
        <a href="#docs" className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50">
          <BookOpen className="h-3.5 w-3.5" />
          Documentación
        </a>
      </div>

      {newToken && (
        <div className="space-y-3 rounded-2xl border border-amber-200 bg-amber-50 p-5 sm:p-6">
          <div className="flex items-start gap-3">
            <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-white text-amber-600"><KeyRound className="h-4 w-4" /></div>
            <div>
              <h2 className="font-semibold text-amber-900">Token creado: {newToken.name}</h2>
              <p className="mt-0.5 text-xs text-amber-800">Cópialo ahora. Por seguridad, no se volverá a mostrar completo.</p>
            </div>
          </div>
          <div className="flex flex-col gap-2 sm:flex-row">
            <input type="text" readOnly value={newToken.token} className="flex-1 rounded-xl border border-amber-200 bg-white px-3.5 py-2.5 font-mono text-xs text-slate-800" />
            <button type="button" onClick={async () => {
              try { await navigator.clipboard.writeText(newToken.token); } catch { /* portapapeles no disponible */ }
              setCopied(true);
              window.setTimeout(() => setCopied(false), 2000);
            }} className="inline-flex items-center justify-center gap-2 rounded-xl bg-amber-600 px-4 py-2.5 text-xs font-semibold text-white transition hover:bg-amber-700">
              <Copy className="h-3.5 w-3.5" />
              {copied ? 'Copiado' : 'Copiar token'}
            </button>
          </div>
          <p className="text-[11px] text-amber-700">Guárdalo en tu gestor de secretos. Si lo pierdes, revócalo y crea otro.</p>
        </div>
      )}

      <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[370px_minmax(0,1fr)]">
        <Card className="space-y-4 p-5 sm:p-6">
          <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Plus className="h-4 w-4" /></div>
            <div>
              <h2 className="text-sm font-semibold text-slate-900">Nuevo token</h2>
              <p className="text-[11px] text-slate-400">Permisos mínimos por integración</p>
            </div>
          </div>

          <form className="space-y-4" onSubmit={(event) => { event.preventDefault(); createToken.mutate(); }}>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Nombre de la integración *</label>
              <Input value={name} onChange={(event) => setName(event.target.value)} required minLength={3} maxLength={80} placeholder="Ej: Sistema de tickets Zendesk" className="px-3.5 py-2.5 text-sm" />
            </div>

            <div>
              <span className="mb-1.5 block text-xs font-medium text-slate-700">Permisos</span>
              <div className="space-y-2 rounded-xl border border-slate-200 bg-slate-50/50 p-3">
                {Object.entries(data.scopes).map(([scope, label]) => (
                  <label key={scope} className="flex cursor-pointer items-start gap-2.5 text-xs text-slate-600">
                    <input type="checkbox" checked={scopes.includes(scope)} onChange={() => setScopes((current) => toggle(current, scope))} className="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                    <span>
                      <strong className="block text-slate-800">{label}</strong>
                      <span className="font-mono text-[10px] text-slate-400">{scope}</span>
                    </span>
                  </label>
                ))}
              </div>
              <p className="mt-1 text-[10px] text-slate-400">Para registrar tickets, <code>tickets:write</code> es suficiente. Añade <code>projects:read</code> si el sistema externo necesita descubrir los IDs de proyecto.</p>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">Proyectos permitidos</label>
              <select multiple size={5} value={projectIds} onChange={(event) => setProjectIds(Array.from(event.target.selectedOptions, (option) => option.value))} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15">
                {data.projects.map((project) => <option key={project.id} value={project.id}>{project.name}</option>)}
              </select>
              <p className="mt-1 text-[10px] text-slate-400">Sin selección = todos los proyectos de la agencia. Para máxima seguridad, selecciona solo el proyecto necesario.</p>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Límite/minuto</label>
                <Input type="number" min={10} max={600} value={rateLimit} onChange={(event) => setRateLimit(event.target.value)} className="px-3.5 py-2.5 text-sm" />
                <p className="mt-1 text-[10px] text-slate-400">Entre 10 y 600.</p>
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Caducidad</label>
                <Select value={expires} onChange={(event) => setExpires(event.target.value)} className="w-full px-3.5 py-2.5 text-sm">
                  <option value="30">30 días</option>
                  <option value="90">90 días</option>
                  <option value="365">1 año</option>
                  <option value="0">Sin caducidad</option>
                </Select>
              </div>
            </div>

            <div className="space-y-3 border-t border-slate-100 pt-3">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">Webhook saliente (opcional)</label>
                <Input type="url" maxLength={500} value={webhookUrl} onChange={(event) => setWebhookUrl(event.target.value)} placeholder="https://tu-sistema.com/webhooks/vertice" className="px-3.5 py-2.5 text-sm" />
                <p className="mt-1 text-[10px] text-slate-400">Vértice enviará eventos firmados a esta URL. El secreto de firma aparece en la configuración del token.</p>
              </div>
              <div>
                <span className="mb-1.5 block text-xs font-medium text-slate-700">Eventos del webhook</span>
                <div className="space-y-1.5">
                  {Object.entries(data.webhookEvents).map(([event, label]) => (
                    <label key={event} className="flex cursor-pointer items-center gap-2 text-[11px] text-slate-600">
                      <input type="checkbox" checked={webhookEvents.includes(event)} onChange={() => setWebhookEvents((current) => toggle(current, event))} className="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                      <span><strong className="text-slate-700">{label}</strong> <span className="font-mono text-slate-400">{event}</span></span>
                    </label>
                  ))}
                </div>
              </div>
              <label className="flex cursor-pointer items-center gap-2 text-xs text-slate-600">
                <input type="checkbox" checked={webhookActive} onChange={(event) => setWebhookActive(event.target.checked)} className="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                Webhook activo
              </label>
            </div>

            {createToken.isError && <ErrorState message={createToken.error instanceof Error ? createToken.error.message : 'No se pudo crear el token.'} />}

            <button type="submit" disabled={createToken.isPending || name.trim() === ''} className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition hover:bg-brand-700 disabled:opacity-60">
              <KeyRound className="h-4 w-4" />
              Crear token
            </button>
          </form>
        </Card>

        <section className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
          <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
            <div>
              <h2 className="text-sm font-semibold text-slate-900">Tokens de la agencia</h2>
              <p className="mt-0.5 text-[11px] text-slate-400">{data.tokens.length} tokens registrados</p>
            </div>
            <div className="flex items-center gap-2">
              <button type="button" disabled={processQueue.isPending} onClick={() => processQueue.mutate()} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50 disabled:opacity-60" title="Procesar entregas pendientes ahora">
                <RefreshCw className="h-3.5 w-3.5" />
                Procesar cola
              </button>
              <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500">Bearer token</span>
            </div>
          </div>

          {queueMessage && <p className="border-b border-slate-100 bg-emerald-50/60 px-5 py-2 text-[11px] font-medium text-emerald-700">{queueMessage}</p>}

          {data.tokens.length === 0 ? (
            <div className="p-10 text-center">
              <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 text-slate-400"><KeyRound className="h-5 w-5" /></div>
              <p className="mt-3 text-sm font-semibold text-slate-800">Aún no hay integraciones</p>
              <p className="mt-1 text-xs text-slate-500">Crea un token para que otro sistema pueda registrar tickets por API.</p>
            </div>
          ) : (
            <div className="divide-y divide-slate-100">
              {data.tokens.map((token) => {
                const active = !token.isRevoked && !token.isExpired;
                const statusLabel = token.isRevoked ? 'Revocado' : token.isExpired ? 'Caducado' : 'Activo';
                const webhookConfigured = Boolean(token.webhookUrl);
                const webhookReady = webhookConfigured && token.webhookActive && active;
                return (
                  <article key={token.id} className="space-y-3 p-5">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <h3 className="font-semibold text-slate-900">{token.name}</h3>
                          <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold ${active ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200'}`}>{statusLabel}</span>
                        </div>
                        <p className="mt-1 font-mono text-[11px] text-slate-400">{token.prefix}••••••••••••</p>
                      </div>
                      {active && (
                        <button type="button" disabled={revoke.isPending} onClick={async () => { if (await confirm({ title: 'Revocar token', description: 'Las integraciones que lo usen dejarán de funcionar.', confirmLabel: 'Revocar', tone: 'danger' })) revoke.mutate(token.id); }} className="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-[11px] font-semibold text-rose-600 transition hover:bg-rose-100 disabled:opacity-60">
                          <Ban className="h-3.5 w-3.5" />
                          Revocar
                        </button>
                      )}
                    </div>

                    <div className="flex flex-wrap gap-1.5">
                      {token.scopes.map((scope) => <span key={scope} className="rounded-full border border-brand-200 bg-brand-50 px-2 py-0.5 font-mono text-[10px] font-semibold text-brand-700">{scope}</span>)}
                    </div>

                    <div className="flex flex-wrap gap-x-5 gap-y-1 text-[11px] text-slate-500">
                      <span><strong className="text-slate-600">Proyectos:</strong> {token.projects.length === 0 ? 'todos' : token.projects.map(projectName).join(', ')}</span>
                      <span><strong className="text-slate-600">Límite:</strong> {token.rateLimit}/min</span>
                      <span><strong className="text-slate-600">Último uso:</strong> {token.lastUsedAt ? formatDate(token.lastUsedAt, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Nunca'}</span>
                      <span><strong className="text-slate-600">Caduca:</strong> {token.expiresAt ? formatDate(token.expiresAt, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'Sin caducidad'}</span>
                      {token.lastUsedIp && <span><strong className="text-slate-600">IP:</strong> {token.lastUsedIp}</span>}
                    </div>

                    <div className="space-y-2.5 rounded-xl border border-slate-200/80 bg-slate-50/50 p-3.5">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                          <Webhook className="h-4 w-4 text-slate-400" />
                          <span className="text-xs font-semibold text-slate-700">Webhook saliente</span>
                          {webhookConfigured ? (
                            <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold ${webhookReady ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200'}`}>{webhookReady ? 'Activo' : 'Inactivo'}</span>
                          ) : (
                            <span className="inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500">Sin configurar</span>
                          )}
                        </div>
                        {webhookConfigured && <span className="max-w-[300px] truncate font-mono text-[10px] text-slate-400" title={token.webhookUrl ?? ''}>{token.webhookUrl}</span>}
                      </div>

                      {token.webhookEvents.length > 0 && (
                        <div className="flex flex-wrap gap-1">
                          {token.webhookEvents.map((event) => <span key={event} className="rounded border border-slate-200 bg-white px-1.5 py-0.5 font-mono text-[9px] font-semibold text-slate-500">{event}</span>)}
                        </div>
                      )}

                      <details className="group" key={`${token.id}-${token.webhookUrl ?? ''}-${token.webhookEvents.join(',')}-${token.webhookActive}`}>
                        <summary className="cursor-pointer select-none text-[11px] font-semibold text-brand-600 hover:text-brand-700">Configurar webhook y ver entregas</summary>
                        <div className="mt-3 space-y-3">
                          <form className="space-y-3" onSubmit={(event) => {
                            event.preventDefault();
                            const form = new FormData(event.currentTarget);
                            saveWebhook.mutate({
                              tokenId: token.id,
                              url: String(form.get('webhook_url') ?? ''),
                              events: form.getAll('webhook_events').map(String),
                              active: form.get('webhook_active') === '1',
                            });
                          }}>
                            <div>
                              <label className="mb-1 block text-[10px] font-medium text-slate-600">URL del webhook</label>
                              <Input name="webhook_url" type="url" maxLength={500} defaultValue={token.webhookUrl ?? ''} placeholder="https://tu-sistema.com/webhooks/vertice" className="rounded-lg px-3 py-2" />
                            </div>
                            <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                              {Object.entries(data.webhookEvents).map(([event, label]) => (
                                <label key={event} className="flex cursor-pointer items-center gap-2 text-[10px] text-slate-600">
                                  <input type="checkbox" name="webhook_events" value={event} defaultChecked={token.webhookEvents.includes(event)} className="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                                  <span>{label}</span>
                                </label>
                              ))}
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                              <label className="flex cursor-pointer items-center gap-2 text-[11px] text-slate-600">
                                <input type="checkbox" name="webhook_active" value="1" defaultChecked={webhookReady} className="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                                Activo
                              </label>
                              <button type="submit" disabled={saveWebhook.isPending} className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-[11px] font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">
                                <Save className="h-3.5 w-3.5" />
                                Guardar webhook
                              </button>
                            </div>
                            {saveWebhook.isError && <ErrorState message={saveWebhook.error instanceof Error ? saveWebhook.error.message : 'No se pudo guardar el webhook.'} />}
                          </form>

                          <div className="rounded-lg border border-slate-200/70 bg-white p-2.5">
                            <p className="text-[10px] font-semibold text-slate-600">Secreto de firma</p>
                            <code className="mt-1 block select-all break-all font-mono text-[9px] text-slate-500">{token.webhookSecret}</code>
                            <p className="mt-1 text-[9px] text-slate-400">Firma: <code>X-Vertice-Signature: sha256=HMAC_SHA256(secreto, timestamp + "." + cuerpo)</code></p>
                          </div>

                          <div>
                            <p className="mb-1.5 text-[10px] font-semibold text-slate-600">Últimas entregas</p>
                            {token.deliveries.length === 0 ? (
                              <p className="text-[10px] italic text-slate-400">Aún no se ha enviado ningún evento.</p>
                            ) : (
                              <div className="overflow-x-auto">
                                <table className="w-full text-[10px]">
                                  <thead className="text-slate-400">
                                    <tr>
                                      <th className="py-1 pr-2 text-left font-medium">Evento</th>
                                      <th className="px-2 py-1 text-left font-medium">Estado</th>
                                      <th className="px-2 py-1 text-left font-medium">Intentos</th>
                                      <th className="px-2 py-1 text-left font-medium">HTTP</th>
                                      <th className="px-2 py-1 text-left font-medium">Fecha</th>
                                      <th className="py-1 pl-2 text-right font-medium"></th>
                                    </tr>
                                  </thead>
                                  <tbody className="divide-y divide-slate-100">
                                    {token.deliveries.map((delivery) => {
                                      const meta = DELIVERY_META[delivery.status] ?? DELIVERY_META.pending;
                                      return (
                                        <tr key={delivery.id}>
                                          <td className="py-1.5 pr-2 font-mono text-slate-600">{delivery.eventType}</td>
                                          <td className="px-2 py-1.5"><span className={`inline-flex rounded border px-1.5 py-0.5 font-semibold ${meta.className}`}>{meta.label}</span></td>
                                          <td className="px-2 py-1.5 text-slate-500">{delivery.attempts}</td>
                                          <td className="px-2 py-1.5 text-slate-500">{delivery.responseCode ?? '—'}</td>
                                          <td className="whitespace-nowrap px-2 py-1.5 text-slate-400" title={delivery.responseBody ?? ''}>{formatDate(delivery.lastAttemptAt ?? delivery.createdAt, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</td>
                                          <td className="py-1.5 pl-2 text-right">
                                            {(delivery.status === 'failed' || delivery.status === 'pending') && (
                                              <button type="button" disabled={retryDelivery.isPending} onClick={() => retryDelivery.mutate(delivery.id)} className="text-[10px] font-semibold text-brand-600 hover:text-brand-700 disabled:opacity-60">Reintentar</button>
                                            )}
                                          </td>
                                        </tr>
                                      );
                                    })}
                                  </tbody>
                                </table>
                              </div>
                            )}
                          </div>
                        </div>
                      </details>
                    </div>
                  </article>
                );
              })}
            </div>
          )}
        </section>
      </div>

      {(revoke.isError || retryDelivery.isError || processQueue.isError) && (
        <ErrorState message={(() => {
          const mutationError = revoke.error ?? retryDelivery.error ?? processQueue.error;
          return mutationError instanceof Error ? mutationError.message : 'No se pudo completar la operación.';
        })()} />
      )}

      <section id="docs" className="space-y-5 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs sm:p-6">
        <div className="flex items-start gap-3">
          <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-700"><BookOpen className="h-4 w-4" /></div>
          <div>
            <h2 className="text-base font-semibold text-slate-900">Cómo conectar un sistema externo</h2>
            <p className="mt-0.5 text-xs text-slate-500">API JSON con autenticación Bearer. No requiere cookies ni CSRF.</p>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="overflow-x-auto rounded-xl bg-slate-900 p-4 text-xs text-slate-100">
            <p className="mb-2 text-slate-400">Autenticación</p>
            <pre className="whitespace-pre-wrap font-mono">{`Authorization: Bearer vrt_tu_token
Accept: application/json
Content-Type: application/json`}</pre>
          </div>
          <div className="overflow-x-auto rounded-xl bg-slate-900 p-4 text-xs text-slate-100">
            <p className="mb-2 text-slate-400">Crear ticket</p>
            <pre className="whitespace-pre-wrap font-mono">{`POST ${data.appUrl}/api/v1/tickets
{
  "project_id": 105,
  "title": "No carga el panel",
  "description": "El cliente reporta un error al entrar.",
  "priority": "alta",
  "requester_name": "Ana Torres",
  "requester_email": "ana@empresa.com",
  "external_id": "ZD-12345",
  "source": "zendesk"
}`}</pre>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
          <div className="rounded-xl border border-slate-200 p-3"><p className="font-mono font-semibold text-brand-700">GET /api/v1/projects</p><p className="mt-1 text-slate-500">Proyectos permitidos por el token.</p></div>
          <div className="rounded-xl border border-slate-200 p-3"><p className="font-mono font-semibold text-brand-700">GET /api/v1/tickets</p><p className="mt-1 text-slate-500">Lista filtrable por proyecto, estado, prioridad y updated_since.</p></div>
          <div className="rounded-xl border border-slate-200 p-3"><p className="font-mono font-semibold text-brand-700">GET /api/v1/tickets/{'{id}'}</p><p className="mt-1 text-slate-500">Consulta un ticket concreto.</p></div>
          <div className="rounded-xl border border-slate-200 p-3"><p className="font-mono font-semibold text-brand-700">PATCH /api/v1/tickets/{'{id}'}</p><p className="mt-1 text-slate-500">Actualiza estado, prioridad o URL externa.</p></div>
        </div>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="overflow-x-auto rounded-xl bg-slate-900 p-4 text-xs text-slate-100">
            <p className="mb-2 text-slate-400">Webhook saliente (ejemplo)</p>
            <pre className="whitespace-pre-wrap font-mono">{`POST https://tu-sistema.com/webhooks/vertice
X-Vertice-Event: ticket.status_changed
X-Vertice-Event-Id: evt_...
X-Vertice-Delivery: 123
X-Vertice-Timestamp: 1794326400
X-Vertice-Signature: sha256=...

{
  "id": "evt_...",
  "event": "ticket.status_changed",
  "data": { "id": 42, "status": "resuelto", ... },
  "previous": { "status": "abierto" }
}`}</pre>
          </div>
          <div className="rounded-xl border border-slate-200 p-4 text-xs text-slate-600">
            <p className="font-semibold text-slate-800">Eventos disponibles</p>
            <p className="mt-1 font-mono text-[11px] text-brand-700">ticket.created · ticket.updated · ticket.status_changed · ticket.deleted</p>
            <p className="mt-3 font-semibold text-slate-800">Verificación recomendada</p>
            <p className="mt-1">Calcula <code>HMAC_SHA256(secreto, timestamp + "." + cuerpo)</code> y compáralo con <code>X-Vertice-Signature</code>. Rechaza timestamps con más de 5 minutos.</p>
            <p className="mt-3 font-semibold text-slate-800">Reintentos</p>
            <p className="mt-1">Las entregas fallidas se reintentan automáticamente con backoff exponencial (hasta 8 intentos) mediante <code>php database/webhooks.php</code>.</p>
          </div>
        </div>

        <div className="rounded-xl border border-amber-200/80 bg-amber-50 p-4 text-xs text-amber-900">
          <p className="font-semibold">Reintentos seguros</p>
          <p className="mt-1">Si envías <code>external_id</code> y <code>source</code>, un reintento con los mismos valores devuelve el ticket existente en lugar de duplicarlo. Los errores usan HTTP estándar y un cuerpo <code>{'{"error":{"code","message"}}'}</code>. El límite de peticiones se controla por token.</p>
        </div>
      </section>
    </div>
  );
}
