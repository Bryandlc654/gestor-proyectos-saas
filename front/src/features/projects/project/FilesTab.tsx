import { useMutation } from '@tanstack/react-query';
import { CheckSquare, Download, ExternalLink, FileText, Folder, FolderPlus, Image as ImageIcon, Link2, Plus, Trash2, UploadCloud, X } from 'lucide-react';
import { useRef, useState, type DragEvent, type FormEvent } from 'react';
import { useConfirm, useToast } from '../../../components/feedback';
import { Card, Input, Select } from '../../../components/ui';
import { apiPostRoot, apiUploadRoot } from '../../../lib/api';
import { errorMessage, formatBytes, relativeTime, useProject } from './shared';

const MAX_SIZE = 10 * 1024 * 1024;
type FolderFilter = 'todos' | 'sin_carpeta' | number;

async function ensureOk<T extends { success?: boolean; message?: string }>(promise: Promise<T>): Promise<T> {
  const result = await promise;
  if (result && result.success === false) throw new Error(result.message ?? 'No se pudo completar la acción.');
  return result;
}

export function FilesTab() {
  const { projectId, data, tabs, perms, preview, clientView, currentUserId, refresh, openTask } = useProject();
  const toast = useToast();
  const confirm = useConfirm();
  const inputRef = useRef<HTMLInputElement>(null);
  const [folder, setFolder] = useState<FolderFilter>('todos');
  const [dragging, setDragging] = useState(false);
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(null);
  const [newFolder, setNewFolder] = useState(false);
  const [newLink, setNewLink] = useState(false);
  // Vincular a tarea: los archivos y enlaces pueden asociarse a una tarea del proyecto.
  const [uploadTaskId, setUploadTaskId] = useState('');
  const taskOptions = [...data.tasks].sort((a, b) => a.title.localeCompare(b.title, 'es'));

  const folders = tabs?.folders ?? [];
  // El cliente solo puede descargar sus propios adjuntos.
  const allFiles = (tabs?.files ?? []).filter((file) => !clientView || (!preview && file.userId === currentUserId));
  const files = allFiles.filter((file) => folder === 'todos' || (folder === 'sin_carpeta' ? !file.folderId : file.folderId === folder));
  const links = tabs?.links ?? [];
  const countIn = (id: number) => allFiles.filter((file) => file.folderId === id).length;

  const uploadAll = async (list: FileList | File[]) => {
    const items = Array.from(list);
    if (items.length === 0) return;
    const tooBig = items.filter((file) => file.size > MAX_SIZE);
    const valid = items.filter((file) => file.size <= MAX_SIZE);
    if (tooBig.length) toast(`${tooBig.length} archivo(s) superan 10 MB y no se subieron.`, 'error');
    if (!valid.length) return;
    setProgress({ done: 0, total: valid.length });
    let failed = 0;
    for (const [index, file] of valid.entries()) {
      const form = new FormData();
      form.set('action', 'upload');
      form.set('project_id', String(projectId));
      if (typeof folder === 'number') form.set('folder_id', String(folder));
      if (uploadTaskId) form.set('task_id', uploadTaskId);
      form.append('attachment', file);
      try {
        await ensureOk(apiUploadRoot<{ success: boolean; message?: string }>('/api/files.php', form));
      } catch (error) {
        failed += 1;
        toast(`${file.name}: ${errorMessage(error, 'no se pudo subir.')}`, 'error');
      }
      setProgress({ done: index + 1, total: valid.length });
    }
    setProgress(null);
    refresh.tabs();
    if (valid.length - failed > 0) toast(valid.length - failed === 1 ? 'Archivo subido.' : `${valid.length - failed} archivos subidos.`);
  };

  const removeFile = useMutation({
    mutationFn: (fileId: number) => ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/files.php', { action: 'delete', project_id: projectId, file_id: fileId })),
    onSuccess: () => { refresh.tabs(); toast('Archivo eliminado.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo eliminar el archivo.'), 'error'),
  });
  const createFolder = useMutation({
    mutationFn: (name: string) => ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/folders.php', { action: 'create', project_id: projectId, name })),
    onSuccess: () => { setNewFolder(false); refresh.tabs(); toast('Carpeta creada.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo crear la carpeta.'), 'error'),
  });
  const removeFolder = useMutation({
    mutationFn: (folderId: number) => ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/folders.php', { action: 'delete', project_id: projectId, folder_id: folderId })),
    onSuccess: () => { setFolder('todos'); refresh.tabs(); toast('Carpeta eliminada. Sus archivos pasaron a "Sin carpeta".'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo eliminar la carpeta.'), 'error'),
  });
  const createLink = useMutation({
    mutationFn: (body: { title: string; url: string; task_id?: string }) => ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/links.php', { action: 'create', project_id: projectId, ...body })),
    onSuccess: () => { setNewLink(false); refresh.tabs(); toast('Enlace añadido.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo añadir el enlace.'), 'error'),
  });
  const removeLink = useMutation({
    mutationFn: (linkId: number) => ensureOk(apiPostRoot<{ success: boolean; message?: string }>('/api/links.php', { action: 'delete', project_id: projectId, link_id: linkId })),
    onSuccess: () => { refresh.tabs(); toast('Enlace eliminado.'); },
    onError: (error) => toast(errorMessage(error, 'No se pudo eliminar el enlace.'), 'error'),
  });

  const onDrop = (event: DragEvent) => {
    event.preventDefault();
    setDragging(false);
    if (perms.uploadFiles && event.dataTransfer.files.length) void uploadAll(event.dataTransfer.files);
  };
  const canDeleteFile = (file: { userId?: number | null }) => perms.manageStructure || (perms.uploadFiles && file.userId === currentUserId);
  const activeFolder = typeof folder === 'number' ? folders.find((item) => item.id === folder) : null;

  return (
    <div className="grid gap-5 lg:grid-cols-[220px_minmax(0,1fr)]">
      {/* Carpetas */}
      <aside className="space-y-1">
        <p className="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Carpetas</p>
        <FolderButton active={folder === 'todos'} onClick={() => setFolder('todos')} label="Todos los archivos" count={allFiles.length} />
        {folders.map((item) => (
          <div key={item.id} className="group relative">
            <FolderButton active={folder === item.id} onClick={() => setFolder(item.id)} label={item.name} count={countIn(item.id)} color={item.color} />
            {perms.manageStructure && <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar carpeta', description: `"${item.name}". Los archivos no se borran: pasan a "Sin carpeta".`, confirmLabel: 'Eliminar', tone: 'danger' })) removeFolder.mutate(item.id); }} className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded p-1 text-slate-300 opacity-0 hover:text-rose-600 group-hover:opacity-100" title="Eliminar carpeta"><X className="h-3 w-3" /></button>}
          </div>
        ))}
        {folders.length > 0 && <FolderButton active={folder === 'sin_carpeta'} onClick={() => setFolder('sin_carpeta')} label="Sin carpeta" count={allFiles.filter((file) => !file.folderId).length} muted />}
        {perms.manageStructure && (newFolder ? (
          <form onSubmit={(event: FormEvent<HTMLFormElement>) => { event.preventDefault(); const name = String(new FormData(event.currentTarget).get('name') ?? '').trim(); if (name) createFolder.mutate(name); }} className="flex items-center gap-1 px-1 pt-1">
            <Input name="name" autoFocus maxLength={100} placeholder="Nombre de carpeta" onKeyDown={(event) => { if (event.key === 'Escape') setNewFolder(false); }} className="py-1.5" />
            <button type="button" onClick={() => setNewFolder(false)} className="rounded p-1 text-slate-400 hover:text-slate-700" aria-label="Cancelar"><X className="h-4 w-4" /></button>
          </form>
        ) : (
          <button type="button" onClick={() => setNewFolder(true)} className="flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800"><FolderPlus className="h-4 w-4" />Nueva carpeta</button>
        ))}
      </aside>

      <div className="space-y-5">
        {perms.uploadFiles && taskOptions.length > 0 && (
          <div className="flex flex-wrap items-center justify-end gap-2 text-xs">
            <label htmlFor="upload-task" className="font-medium text-slate-600">Vincular a tarea</label>
            <Select id="upload-task" value={uploadTaskId} onChange={(event) => setUploadTaskId(event.target.value)} className="max-w-[16rem] py-1.5">
              <option value="">Ninguna (archivo del proyecto)</option>
              {taskOptions.map((task) => <option key={task.id} value={task.id}>{task.title}</option>)}
            </Select>
          </div>
        )}
        {perms.uploadFiles && (
          <div
            onDragOver={(event) => { event.preventDefault(); if (!dragging) setDragging(true); }}
            onDragLeave={(event) => { if (!event.currentTarget.contains(event.relatedTarget as Node)) setDragging(false); }}
            onDrop={onDrop}
            onClick={() => inputRef.current?.click()}
            className={`flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-2xl border-2 border-dashed px-6 py-7 text-center transition ${dragging ? 'border-brand-400 bg-brand-50' : 'border-slate-200 bg-white hover:border-brand-300 hover:bg-slate-50'}`}
          >
            <UploadCloud className={`h-7 w-7 ${dragging ? 'text-brand-600' : 'text-slate-400'}`} />
            {progress ? (
              <>
                <p className="text-xs font-semibold text-slate-700">Subiendo {progress.done} de {progress.total}…</p>
                <div className="h-1.5 w-48 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-brand-500 transition-all" style={{ width: `${(progress.done / progress.total) * 100}%` }} /></div>
              </>
            ) : (
              <>
                <p className="text-xs font-semibold text-slate-700">Arrastra archivos aquí o <span className="text-brand-600">haz clic para elegir</span></p>
                <p className="text-[11px] text-slate-400">Puedes subir varios a la vez · máximo 10 MB cada uno{activeFolder ? ` · se guardarán en "${activeFolder.name}"` : ''}</p>
              </>
            )}
            <input ref={inputRef} type="file" multiple className="hidden" onChange={(event) => { if (event.target.files) void uploadAll(event.target.files); event.target.value = ''; }} />
          </div>
        )}

        <Card className="overflow-hidden">
          <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3">
            <h2 className="text-sm font-semibold text-slate-800">{activeFolder ? activeFolder.name : folder === 'sin_carpeta' ? 'Sin carpeta' : 'Archivos'}</h2>
            <span className="text-[11px] text-slate-400">{files.length} archivo{files.length === 1 ? '' : 's'}</span>
          </div>
          {preview && <p className="px-5 py-3 text-[11px] text-slate-500">En la vista de cliente solo se muestran los archivos que el propio cliente subió.</p>}
          {files.length === 0 ? (
            <p className="px-5 py-10 text-center text-xs text-slate-400">{perms.uploadFiles ? 'No hay archivos aquí. Arrastra el primero a la zona de arriba.' : 'No hay archivos.'}</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {files.map((file) => {
                const isImage = file.type?.startsWith('image/');
                const folderName = folder === 'todos' && file.folderId ? folders.find((item) => item.id === file.folderId)?.name : null;
                return (
                  <li key={file.id} className="group flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50/70">
                    <span className={`flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl ${isImage ? 'bg-cyan-50 text-cyan-600' : 'bg-brand-50 text-brand-600'}`}>{isImage ? <ImageIcon className="h-4 w-4" /> : <FileText className="h-4 w-4" />}</span>
                    <a href={file.downloadUrl} className="min-w-0 flex-1">
                      <span className="block truncate text-xs font-semibold text-slate-800 group-hover:text-brand-700">{file.name}</span>
                      <span className="block truncate text-[11px] text-slate-400">{formatBytes(file.size)} · {file.user ?? 'Sistema'} · {relativeTime(file.createdAt)}{folderName ? ` · ${folderName}` : ''}</span>
                    </a>
                    {file.taskId && file.taskTitle && <TaskChip title={file.taskTitle} onClick={() => openTask(file.taskId!)} />}
                    <a href={file.downloadUrl} className="rounded-lg p-1.5 text-slate-400 hover:bg-white hover:text-brand-600" title="Descargar"><Download className="h-4 w-4" /></a>
                    {canDeleteFile(file) && <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar archivo', description: file.name, confirmLabel: 'Eliminar', tone: 'danger' })) removeFile.mutate(file.id); }} className="rounded-lg p-1.5 text-slate-300 opacity-0 transition hover:bg-white hover:text-rose-600 group-hover:opacity-100" title="Eliminar"><Trash2 className="h-4 w-4" /></button>}
                  </li>
                );
              })}
            </ul>
          )}
        </Card>

        <Card className="overflow-hidden">
          <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3">
            <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800"><Link2 className="h-4 w-4 text-slate-400" />Enlaces</h2>
            {perms.manageStructure && !newLink && <button type="button" onClick={() => setNewLink(true)} className="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 hover:text-brand-700"><Plus className="h-3.5 w-3.5" />Añadir enlace</button>}
          </div>
          {newLink && (
            <form onSubmit={(event: FormEvent<HTMLFormElement>) => { event.preventDefault(); const form = new FormData(event.currentTarget); createLink.mutate({ title: String(form.get('title') ?? ''), url: String(form.get('url') ?? ''), task_id: String(form.get('task_id') ?? '') || undefined }); }} className="flex flex-wrap items-center gap-2 border-b border-slate-100 bg-slate-50/60 px-5 py-3">
              <Input name="title" required autoFocus placeholder="Título (ej: Figma)" className="max-w-[12rem]" />
              <Input name="url" type="url" required placeholder="https://..." className="min-w-[12rem] flex-1" />
              {taskOptions.length > 0 && (
                <Select name="task_id" defaultValue="" aria-label="Vincular a tarea" className="max-w-[12rem]">
                  <option value="">Sin tarea</option>
                  {taskOptions.map((task) => <option key={task.id} value={task.id}>{task.title}</option>)}
                </Select>
              )}
              <button type="submit" disabled={createLink.isPending} className="rounded-xl bg-brand-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-60">Añadir</button>
              <button type="button" onClick={() => setNewLink(false)} className="rounded-xl px-2 py-2 text-xs text-slate-500 hover:bg-slate-100">Cancelar</button>
            </form>
          )}
          {links.length === 0 ? <p className="px-5 py-6 text-center text-xs text-slate-400">Sin enlaces. Guarda aquí Figma, Drive, el sitio en staging…</p> : (
            <ul className="divide-y divide-slate-100">
              {links.map((link) => (
                <li key={link.id} className="group flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50/70">
                  <a href={link.url} target="_blank" rel="noreferrer" className="min-w-0 flex-1">
                    <span className="block truncate text-xs font-semibold text-brand-700">{link.title}</span>
                    <span className="block truncate text-[11px] text-slate-400">{link.url}</span>
                  </a>
                  {link.taskId && link.taskTitle && <TaskChip title={link.taskTitle} onClick={() => openTask(link.taskId!)} />}
                  <a href={link.url} target="_blank" rel="noreferrer" className="rounded-lg p-1.5 text-slate-400 hover:text-brand-600" title="Abrir"><ExternalLink className="h-4 w-4" /></a>
                  {perms.manageStructure && <button type="button" onClick={async () => { if (await confirm({ title: 'Eliminar enlace', description: link.title, confirmLabel: 'Eliminar', tone: 'danger' })) removeLink.mutate(link.id); }} className="rounded-lg p-1.5 text-slate-300 opacity-0 transition hover:text-rose-600 group-hover:opacity-100" title="Eliminar enlace"><Trash2 className="h-4 w-4" /></button>}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  );
}

function TaskChip({ title, onClick }: { title: string; onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className="inline-flex max-w-[11rem] flex-shrink-0 items-center gap-1 rounded bg-brand-50 px-1.5 py-0.5 text-[10px] font-semibold text-brand-700 transition hover:bg-brand-100" title={`Vinculado a la tarea: ${title}`}>
      <CheckSquare className="h-3 w-3 flex-shrink-0" /><span className="truncate">{title}</span>
    </button>
  );
}

function FolderButton({ active, onClick, label, count, color, muted }: { active: boolean; onClick: () => void; label: string; count: number; color?: string | null; muted?: boolean }) {
  return (
    <button type="button" onClick={onClick} className={`flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 pr-7 text-left text-xs transition ${active ? 'bg-brand-50 font-semibold text-brand-700' : `${muted ? 'text-slate-400' : 'text-slate-600'} hover:bg-slate-100`}`}>
      <Folder className="h-4 w-4 flex-shrink-0" style={color && !active ? { color } : undefined} />
      <span className="min-w-0 flex-1 truncate">{label}</span>
      <span className="text-[10px] tabular-nums text-slate-400">{count}</span>
    </button>
  );
}
