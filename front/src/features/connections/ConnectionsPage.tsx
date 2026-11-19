import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Card, ErrorState, Input, Skeleton } from '../../components/ui';
import { useConfirm, useToast } from '../../components/feedback';
import { apiGet, apiPost } from '../../lib/api';
import { formatDate } from '../../lib/format';
import type { ConnectionsData } from '../../types';

type Connection = ConnectionsData['connected'][number];

export function ConnectionsPage() {
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const toast = useToast();
  const [code, setCode] = useState('');
  const [copied, setCopied] = useState(false);

  const { data, isLoading, error } = useQuery({ queryKey: ['connections'], queryFn: () => apiGet<ConnectionsData>('connections.php') });

  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['connections'] });

  const request = useMutation({
    mutationFn: (value: string) => apiPost<{ success: boolean }>('connections.php', { action: 'request', code: value }),
    onSuccess: () => { setCode(''); refresh(); toast('Solicitud de conexión enviada.'); },
  });

  const accept = useMutation({
    mutationFn: (id: number) => apiPost<{ success: boolean }>('connections.php', { action: 'accept', connection_id: id }),
    onSuccess: () => { refresh(); toast('Agencia conectada.'); },
  });

  const remove = useMutation({
    mutationFn: (id: number) => apiPost<{ success: boolean }>('connections.php', { action: 'remove', connection_id: id }),
    onSuccess: () => { refresh(); toast('Conexión actualizada.'); },
  });

  const regenerate = useMutation({
    mutationFn: () => apiPost<{ success: boolean; code: string }>('connections.php', { action: 'regenerate_code' }),
    onSuccess: () => { refresh(); toast('Código regenerado.'); },
  });

  if (isLoading) return <div className="space-y-4">{Array.from({ length: 3 }).map((_, index) => <Skeleton key={index} className="h-32" />)}</div>;
  if (error || !data) return <ErrorState message={error instanceof Error ? error.message : 'No se pudieron cargar las conexiones.'} />;

  const connectCode = regenerate.data?.code ?? data.code;
  const busy = accept.isPending || remove.isPending;

  const copyCode = async () => {
    try { await navigator.clipboard.writeText(connectCode); } catch { /* portapapeles no disponible */ }
    setCopied(true);
    window.setTimeout(() => setCopied(false), 2000);
  };

  const AgencyRow = ({ connection, meta, actions }: { connection: Connection; meta: string; actions: Array<{ label: string; primary?: boolean; confirm?: string; onClick: () => void }> }) => (
    <li className="flex items-center justify-between gap-3 px-4 py-3">
      <div className="flex min-w-0 items-center gap-3">
        <div className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs font-medium text-slate-700">{connection.agencyName.slice(0, 2).toUpperCase()}</div>
        <div className="min-w-0">
          <p className="truncate text-sm font-medium text-slate-900">{connection.agencyName}</p>
          <p className="text-xs text-slate-400">{meta}</p>
        </div>
      </div>
      <div className="flex flex-shrink-0 items-center gap-2">
        {actions.map((action) => (
          <button
            key={action.label}
            type="button"
            disabled={busy}
            onClick={async () => {
              if (action.confirm && !(await confirm({ title: 'Confirmar acción', description: action.confirm, confirmLabel: 'Continuar', tone: 'danger' }))) return;
              action.onClick();
            }}
            className={`rounded-lg px-3 py-1.5 text-xs font-medium transition disabled:opacity-60 ${action.primary ? 'bg-brand-600 text-white hover:bg-brand-700' : 'border border-slate-200 text-slate-600 hover:bg-slate-50'}`}
          >
            {action.label}
          </button>
        ))}
      </div>
    </li>
  );

  return (
    <div className="space-y-6">
      <h1 className="text-base font-semibold tracking-tight text-slate-900 sm:text-lg">Agencias conectadas</h1>

      <p className="text-sm text-slate-500">
        Conéctate con otras agencias para trabajar juntas en proyectos. Conectarse no comparte nada por sí solo:
        cada agencia sigue viendo únicamente su propio contenido hasta que decida compartir un proyecto.
      </p>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Card className="space-y-3 p-5">
          <div>
            <h2 className="text-sm font-semibold text-slate-900">Tu código de agencia</h2>
            <p className="text-xs text-slate-400">Compártelo con la agencia que quieras conectar.</p>
          </div>
          <div className="flex items-center gap-2">
            <span className="flex-1 rounded-xl border border-slate-200 bg-slate-50 py-2.5 text-center font-mono text-xl font-semibold tracking-[0.2em] text-slate-900">{connectCode}</span>
            <button type="button" onClick={copyCode} className="rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50">{copied ? 'Copiado' : 'Copiar'}</button>
          </div>
          <button
            type="button"
            disabled={regenerate.isPending}
            onClick={async () => {
              if (await confirm({ title: 'Generar código nuevo', description: 'El código actual dejará de servir para nuevas solicitudes. Las conexiones existentes se mantienen.', confirmLabel: 'Generar' })) regenerate.mutate();
            }}
            className="text-xs text-slate-500 transition hover:text-slate-900 hover:underline disabled:opacity-60"
          >
            Generar un código nuevo
          </button>
        </Card>

        <Card className="space-y-3 p-5">
          <div>
            <h2 className="text-sm font-semibold text-slate-900">Conectar con otra agencia</h2>
            <p className="text-xs text-slate-400">Introduce su código. La otra agencia tendrá que aceptar la solicitud.</p>
          </div>
          <form className="flex items-center gap-2" onSubmit={(event) => {
            event.preventDefault();
            request.mutate(code);
          }}>
            <Input value={code} onChange={(event) => setCode(event.target.value.toUpperCase())} required maxLength={12} autoComplete="off" placeholder="ABCD-2345" className="min-w-0 flex-1 px-3.5 py-2.5 font-mono text-sm uppercase tracking-widest" />
            <button type="submit" disabled={request.isPending || code.trim() === ''} className="rounded-xl bg-brand-600 px-4 py-2.5 text-xs font-semibold text-white shadow-2xs transition hover:bg-brand-700 disabled:opacity-60">Conectar</button>
          </form>
          {request.isError && <ErrorState message={request.error instanceof Error ? request.error.message : 'No se pudo enviar la solicitud.'} />}
        </Card>
      </div>

      {data.incoming.length > 0 && (
        <section className="space-y-2">
          <h2 className="text-xs font-semibold text-slate-500">Solicitudes recibidas ({data.incoming.length})</h2>
          <ul className="divide-y divide-slate-100 rounded-2xl border border-brand-200/80 bg-white shadow-2xs">
            {data.incoming.map((connection) => (
              <AgencyRow
                key={connection.id}
                connection={connection}
                meta={`Quiere conectarse · ${formatDate(connection.createdAt)}`}
                actions={[
                  { label: 'Aceptar', primary: true, onClick: () => accept.mutate(connection.id) },
                  { label: 'Rechazar', confirm: `¿Rechazar la solicitud de ${connection.agencyName}?`, onClick: () => remove.mutate(connection.id) },
                ]}
              />
            ))}
          </ul>
        </section>
      )}

      <section className="space-y-2">
        <h2 className="text-xs font-semibold text-slate-500">Agencias conectadas ({data.connected.length})</h2>
        {data.connected.length > 0 ? (
          <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
            {data.connected.map((connection) => (
              <AgencyRow
                key={connection.id}
                connection={connection}
                meta={`Conectadas desde ${formatDate(connection.acceptedAt)}`}
                actions={[{ label: 'Desconectar', confirm: `¿Desconectar de ${connection.agencyName}?`, onClick: () => remove.mutate(connection.id) }]}
              />
            ))}
          </ul>
        ) : (
          <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-6 text-center text-sm text-slate-400">Aún no estás conectado con ninguna agencia.</div>
        )}
      </section>

      {data.outgoing.length > 0 && (
        <section className="space-y-2">
          <h2 className="text-xs font-semibold text-slate-500">Solicitudes enviadas</h2>
          <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200/80 bg-white shadow-2xs">
            {data.outgoing.map((connection) => (
              <AgencyRow
                key={connection.id}
                connection={connection}
                meta={`Pendiente de respuesta · ${formatDate(connection.createdAt)}`}
                actions={[{ label: 'Cancelar', confirm: `¿Cancelar la solicitud a ${connection.agencyName}?`, onClick: () => remove.mutate(connection.id) }]}
              />
            ))}
          </ul>
        </section>
      )}

      {(accept.isError || remove.isError || regenerate.isError) && (
        <ErrorState message={(() => {
          const mutationError = accept.error ?? remove.error ?? regenerate.error;
          return mutationError instanceof Error ? mutationError.message : 'No se pudo completar la acción.';
        })()} />
      )}
    </div>
  );
}
