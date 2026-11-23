import { useQuery } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Download, ExternalLink, FileText, Globe, Link2, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { useDialog } from '../../../components/feedback';
import { Skeleton } from '../../../components/ui';
import { formatDate } from '../../../lib/format';
import type { ProjectMeeting } from './MeetingsTab';
import { formatBytes } from './shared';

const TEXT_PREVIEW_LIMIT = 200_000;

export interface LinkEmbed {
  provider: string;
  src: string;
}

/**
 * Convierte enlaces de servicios conocidos en su URL de inserción (iframe).
 * Devuelve null si el sitio no permite incrustarse: la mayoría bloquea iframes con X-Frame-Options.
 */
export function linkEmbed(raw: string | null): LinkEmbed | null {
  if (!raw) return null;
  let url: URL;
  try { url = new URL(raw); } catch { return null; }
  if (url.protocol !== 'https:' && url.protocol !== 'http:') return null;
  const host = url.hostname.replace(/^www\./, '').toLowerCase();
  const path = url.pathname;

  if (host === 'docs.google.com') {
    const match = path.match(/^\/(document|spreadsheets|presentation|forms)\/d\/([\w-]+)/);
    if (match) {
      const labels: Record<string, string> = { document: 'Google Docs', spreadsheets: 'Google Sheets', presentation: 'Google Slides', forms: 'Google Forms' };
      const suffix = match[1] === 'forms' ? 'viewform?embedded=true' : 'preview';
      return { provider: labels[match[1]], src: `https://docs.google.com/${match[1]}/d/${match[2]}/${suffix}` };
    }
  }
  if (host === 'drive.google.com') {
    const id = path.match(/^\/file\/d\/([\w-]+)/)?.[1] ?? (path === '/open' ? url.searchParams.get('id') : null);
    if (id) return { provider: 'Google Drive', src: `https://drive.google.com/file/d/${id}/preview` };
    const folder = path.match(/^\/drive\/(?:u\/\d+\/)?folders\/([\w-]+)/)?.[1];
    if (folder) return { provider: 'Google Drive', src: `https://drive.google.com/embeddedfolderview?id=${folder}#list` };
  }
  if (host === 'youtube.com' || host === 'm.youtube.com' || host === 'youtu.be') {
    const id = host === 'youtu.be' ? path.slice(1) : url.searchParams.get('v') ?? path.match(/^\/(?:shorts|live|embed)\/([\w-]+)/)?.[1];
    if (id && /^[\w-]{6,}$/.test(id)) return { provider: 'YouTube', src: `https://www.youtube-nocookie.com/embed/${id}` };
  }
  if (host === 'loom.com') {
    const id = path.match(/^\/(?:share|embed)\/([\w-]+)/)?.[1];
    if (id) return { provider: 'Loom', src: `https://www.loom.com/embed/${id}` };
  }
  if (host === 'vimeo.com') {
    const id = path.match(/^\/(\d+)/)?.[1];
    if (id) return { provider: 'Vimeo', src: `https://player.vimeo.com/video/${id}` };
  }
  if (host === 'figma.com' && /^\/(file|design|proto|board|slides)\//.test(path)) {
    return { provider: 'Figma', src: `https://www.figma.com/embed?embed_host=vertice&url=${encodeURIComponent(raw)}` };
  }
  if (host === 'canva.com' && /^\/design\/[\w-]+/.test(path)) {
    const base = path.replace(/\/(edit|view)\/?$/, '');
    return { provider: 'Canva', src: `https://www.canva.com${base}/view?embed` };
  }
  if (host.endsWith('.notion.site')) {
    return { provider: 'Notion', src: url.toString() };
  }
  return null;
}

type FileKind = 'pdf' | 'image' | 'audio' | 'video' | 'text';

export function fileKind(type: string | null): FileKind | null {
  const mime = (type ?? '').toLowerCase();
  if (mime === 'application/pdf') return 'pdf';
  if (mime.startsWith('image/')) return 'image';
  if (mime.startsWith('audio/')) return 'audio';
  if (mime.startsWith('video/')) return 'video';
  if (mime.startsWith('text/') || mime === 'application/json') return 'text';
  return null;
}

type Source = 'file' | 'link';

/** Archivo que el visor puede mostrar. previewUrl es null si el formato no se puede ver en el navegador. */
export interface PreviewFile {
  name: string;
  size: number | null;
  type: string | null;
  previewUrl: string | null;
  downloadUrl: string | null;
}

export function MeetingPreviewModal({ meeting, initial, onClose }: { meeting: ProjectMeeting; initial?: Source; onClose: () => void }) {
  const file: PreviewFile | null = meeting.fileName
    ? { name: meeting.fileName, size: meeting.fileSize, type: meeting.fileType, previewUrl: meeting.previewUrl, downloadUrl: meeting.downloadUrl }
    : null;
  return (
    <PreviewModal
      title={meeting.title}
      subtitle={`${formatDate(meeting.meetingDate)}${meeting.attendees ? ` · ${meeting.attendees}` : ''}`}
      file={file}
      linkUrl={meeting.linkUrl}
      initial={initial}
      onClose={onClose}
    />
  );
}

/**
 * Visor a pantalla casi completa para un archivo y/o un enlace.
 * Con onPrev/onNext permite recorrer una lista (también con las flechas del teclado).
 */
export function PreviewModal({ title, subtitle, file, linkUrl, initial, onClose, onPrev, onNext, position }: {
  title: string;
  subtitle?: string;
  file?: PreviewFile | null;
  linkUrl?: string | null;
  initial?: Source;
  onClose: () => void;
  onPrev?: (() => void) | null;
  onNext?: (() => void) | null;
  position?: string;
}) {
  const ref = useDialog(true, onClose);
  const sources: Source[] = [];
  if (file) sources.push('file');
  if (linkUrl) sources.push('link');
  const [chosen, setSource] = useState<Source | undefined>(initial);
  const source: Source = chosen && sources.includes(chosen) ? chosen : sources[0] ?? 'link';

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      const target = event.target as HTMLElement | null;
      if (target && ['INPUT', 'TEXTAREA', 'SELECT', 'VIDEO', 'AUDIO'].includes(target.tagName)) return;
      if (event.key === 'ArrowLeft' && onPrev) { event.preventDefault(); onPrev(); }
      if (event.key === 'ArrowRight' && onNext) { event.preventDefault(); onNext(); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onPrev, onNext]);

  const navButton = 'flex-shrink-0 rounded-lg p-1 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 disabled:cursor-default disabled:opacity-30 disabled:hover:bg-transparent';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-2 backdrop-blur-xs sm:p-4" onClick={onClose}>
      <div ref={ref} onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true" aria-label={`Vista previa: ${title}`} className="flex h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-2xl">
        <div className="flex items-center gap-2 border-b border-slate-100 bg-slate-50/50 px-3 py-3 sm:gap-3 sm:px-5">
          {(onPrev !== undefined || onNext !== undefined) && (
            <div className="flex flex-shrink-0 items-center gap-0.5">
              <button type="button" disabled={!onPrev} onClick={() => onPrev?.()} className={navButton} aria-label="Anterior" title="Anterior (←)"><ChevronLeft className="h-5 w-5" /></button>
              {position && <span className="hidden min-w-[3rem] text-center text-[11px] tabular-nums text-slate-400 sm:inline">{position}</span>}
              <button type="button" disabled={!onNext} onClick={() => onNext?.()} className={navButton} aria-label="Siguiente" title="Siguiente (→)"><ChevronRight className="h-5 w-5" /></button>
            </div>
          )}
          <div className="min-w-0 flex-1">
            <h3 className="truncate text-sm font-semibold text-slate-900">{title}</h3>
            {subtitle && <p className="truncate text-[11px] text-slate-400">{subtitle}</p>}
          </div>
          {sources.length > 1 && (
            <div className="flex flex-shrink-0 rounded-xl bg-slate-100 p-0.5 text-[11px] font-semibold" role="tablist">
              {sources.map((item) => (
                <button key={item} type="button" role="tab" aria-selected={source === item} onClick={() => setSource(item)} className={`inline-flex items-center gap-1 rounded-lg px-2.5 py-1 transition ${source === item ? 'bg-white text-slate-800 shadow-xs' : 'text-slate-500 hover:text-slate-700'}`}>
                  {item === 'file' ? <><FileText className="h-3 w-3" />Archivo</> : <><Link2 className="h-3 w-3" />Enlace</>}
                </button>
              ))}
            </div>
          )}
          {source === 'file' && file?.downloadUrl && (
            <a href={file.downloadUrl} className="hidden flex-shrink-0 items-center gap-1 rounded-lg px-2 py-1.5 text-[11px] font-semibold text-slate-600 hover:bg-slate-100 sm:inline-flex" title="Descargar"><Download className="h-3.5 w-3.5" />Descargar</a>
          )}
          {source === 'link' && linkUrl && (
            <a href={linkUrl} target="_blank" rel="noreferrer" className="hidden flex-shrink-0 items-center gap-1 rounded-lg px-2 py-1.5 text-[11px] font-semibold text-slate-600 hover:bg-slate-100 sm:inline-flex" title="Abrir en una pestaña nueva"><ExternalLink className="h-3.5 w-3.5" />Abrir</a>
          )}
          <button type="button" onClick={onClose} className="flex-shrink-0 rounded-lg p-1 text-slate-400 hover:text-slate-600" aria-label="Cerrar"><X className="h-5 w-5" /></button>
        </div>
        <div className="min-h-0 flex-1 bg-slate-100/60">
          {source === 'file' && file ? <FilePreview key={file.previewUrl ?? file.name} file={file} /> : <LinkPreview key={linkUrl ?? ''} url={linkUrl ?? ''} />}
        </div>
      </div>
    </div>
  );
}

function FilePreview({ file }: { file: PreviewFile }) {
  const kind = file.previewUrl ? fileKind(file.type) : null;
  const src = file.previewUrl ?? '';

  if (kind === 'pdf') return <iframe src={src} title={file.name} className="h-full w-full border-0 bg-white" />;
  if (kind === 'image') return <div className="flex h-full items-center justify-center overflow-auto p-4"><img src={src} alt={file.name} className="max-h-full max-w-full rounded-lg object-contain shadow-sm" /></div>;
  if (kind === 'video') return <div className="flex h-full items-center justify-center bg-black"><video src={src} controls className="max-h-full max-w-full" /></div>;
  if (kind === 'audio') return <div className="flex h-full flex-col items-center justify-center gap-4 p-6"><FileText className="h-10 w-10 text-slate-300" /><p className="text-xs font-medium text-slate-600">{file.name}</p><audio src={src} controls className="w-full max-w-md" /></div>;
  if (kind === 'text') return <TextPreview src={src} />;

  return (
    <Fallback
      icon={<FileText className="h-10 w-10 text-slate-300" />}
      title={file.name}
      detail={`${file.size !== null ? `${formatBytes(file.size)} · ` : ''}Este formato (Word, Excel, PowerPoint, ZIP…) no se puede mostrar en el navegador. Descárgalo para abrirlo.`}
      action={file.downloadUrl ? <a href={file.downloadUrl} className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"><Download className="h-4 w-4" />Descargar archivo</a> : null}
    />
  );
}

function TextPreview({ src }: { src: string }) {
  const query = useQuery({
    queryKey: ['meeting-text', src],
    queryFn: async () => {
      const response = await fetch(src, { credentials: 'same-origin' });
      if (!response.ok) throw new Error('No se pudo cargar el archivo');
      const text = await response.text();
      return text.length > TEXT_PREVIEW_LIMIT ? `${text.slice(0, TEXT_PREVIEW_LIMIT)}\n\n… (vista previa recortada; descarga el archivo para verlo completo)` : text;
    },
    staleTime: 5 * 60_000,
  });
  if (query.isLoading) return <div className="p-6"><Skeleton className="h-64" /></div>;
  if (query.error) return <Fallback icon={<FileText className="h-10 w-10 text-slate-300" />} title="No se pudo cargar la vista previa" detail="Prueba a descargar el archivo." />;
  return <pre className="h-full overflow-auto whitespace-pre-wrap break-words bg-white p-6 font-mono text-xs leading-relaxed text-slate-700">{query.data}</pre>;
}

function LinkPreview({ url }: { url: string }) {
  const embed = linkEmbed(url);
  if (embed) {
    return (
      <div className="flex h-full flex-col">
        <iframe src={embed.src} title={`Vista previa (${embed.provider})`} className="min-h-0 w-full flex-1 border-0 bg-white" allow="autoplay; fullscreen; picture-in-picture; clipboard-write" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" />
        <p className="border-t border-slate-100 bg-white px-4 py-2 text-[11px] text-slate-400">
          Vista previa de {embed.provider}. Si no carga, revisa que el documento esté compartido con tu cuenta o <a href={url} target="_blank" rel="noreferrer" className="font-semibold text-brand-700 hover:underline">ábrelo en una pestaña nueva</a>.
        </p>
      </div>
    );
  }
  let host = url;
  try { host = new URL(url).hostname.replace(/^www\./, ''); } catch { /* enlace no válido: se muestra tal cual */ }
  return (
    <Fallback
      icon={<Globe className="h-10 w-10 text-slate-300" />}
      title={host}
      detail={<>Este sitio no permite mostrarse dentro de la app.<span className="mt-1 block break-all text-slate-400">{url}</span></>}
      action={<a href={url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-700"><ExternalLink className="h-4 w-4" />Abrir enlace</a>}
    />
  );
}

function Fallback({ icon, title, detail, action }: { icon: ReactNode; title: string; detail: ReactNode; action?: ReactNode }) {
  return (
    <div className="flex h-full flex-col items-center justify-center gap-3 p-6 text-center">
      {icon}
      <p className="max-w-md truncate text-sm font-semibold text-slate-700">{title}</p>
      <p className="max-w-md text-xs text-slate-500">{detail}</p>
      {action}
    </div>
  );
}
