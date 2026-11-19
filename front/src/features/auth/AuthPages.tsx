import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { AlertCircle, ArrowRight, Building2, CheckCircle2, Eye, EyeOff, FolderKanban, Handshake, LifeBuoy, ListChecks, Lock, Mail, ShieldCheck, UserRound, Wallet } from 'lucide-react';
import { Button, Input } from '../../components/ui';

async function authPost<T>(body: Record<string, string>): Promise<T> {
  const csrfResponse = await fetch('/api/auth.php', { credentials: 'same-origin' });
  const csrfData = await csrfResponse.json();
  const form = new URLSearchParams({ ...body, csrf_token: csrfData.csrfToken });
  const response = await fetch('/api/auth.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
    body: form.toString(),
  });
  const data = await response.json();
  if (!response.ok) throw new Error(data.error || 'No se pudo completar la operación.');
  return data as T;
}

const AUTH_MODULES: { label: string; icon: ReactNode; featured?: boolean }[] = [
  { label: 'Proyectos', icon: <FolderKanban className="h-6 w-6" /> },
  { label: 'Tareas', icon: <ListChecks className="h-6 w-6" /> },
  { label: 'CRM', icon: <Handshake className="h-6 w-6" />, featured: true },
  { label: 'Finanzas', icon: <Wallet className="h-6 w-6" /> },
  { label: 'Tickets', icon: <LifeBuoy className="h-6 w-6" /> },
];

const AUTH_POINTS = [
  'Proyectos, tareas y Gantt con todo tu equipo',
  'CRM, cotizaciones y finanzas conectados',
  'Comparte proyectos con agencias aliadas y clientes',
];

function AuthShell({ title, subtitle, children, footer }: { title: string; subtitle: string; children: ReactNode; footer?: ReactNode }) {
  const base = import.meta.env.BASE_URL;
  return (
    <div className="grid min-h-screen bg-white lg:grid-cols-[2fr_3fr]">
      {/* Columna del formulario (40%) */}
      <div className="flex min-h-screen flex-col px-6 py-8 sm:px-12 lg:px-14 xl:px-20">
        <div className="flex flex-1 items-center py-10">
          <div className="mx-auto w-full max-w-sm space-y-7">
            <div>
              <h1 className="text-2xl font-semibold tracking-tight text-slate-900 sm:text-[1.7rem]">{title}</h1>
              <p className="mt-1.5 text-sm text-slate-500">{subtitle}</p>
            </div>
            {children}
            {footer}
          </div>
        </div>
        <p className="text-[11px] text-slate-400">
          © {new Date().getFullYear()} Vértice · <Link to="/legal/terms" className="hover:text-slate-600">Términos</Link> · <Link to="/legal/privacy" className="hover:text-slate-600">Privacidad</Link>
        </p>
      </div>

      {/* Columna visual (60%) */}
      <aside className="relative hidden lg:block" aria-hidden="true">
        <div className="sticky top-0 flex h-screen flex-col overflow-hidden bg-[radial-gradient(ellipse_80%_60%_at_50%_100%,#0E66FF_0%,#0A3A8C_35%,#062352_60%,#041C3D_100%)]">
          <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(rgb(255_255_255/.07)_1px,transparent_1px)] [background-size:22px_22px] [mask-image:linear-gradient(to_bottom,#000,transparent_70%)]" />

          {/* Texto */}
          <div className="relative mx-auto w-full max-w-xl px-10 pt-[9vh] xl:px-0">
            <div className="flex items-center gap-3">
              <img src={`${base}logo-light.png`} alt="" width={894} height={183} className="h-6 w-auto" />
              <span className="rounded-full bg-cyan-400 px-2.5 py-0.5 text-xs font-semibold text-navy-950">Red de agencias</span>
            </div>
            <h2 className="mt-6 text-[2.1rem] font-bold leading-[1.15] tracking-tight text-white xl:text-[2.5rem]">
              Toda tu agencia <span className="text-cyan-300">en una sola plataforma</span>
            </h2>
            <ul className="mt-7 space-y-2.5">
              {AUTH_POINTS.map((point) => (
                <li key={point} className="flex items-center gap-3 text-[15px] text-white/85">
                  <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10 text-cyan-300 ring-1 ring-white/15"><CheckCircle2 className="h-3.5 w-3.5" /></span>
                  {point}
                </li>
              ))}
            </ul>
          </div>

          {/* Módulos + laptop */}
          <div className="relative mt-auto flex flex-col items-center pt-12">
            <div className="relative z-10 mb-[-1.75rem] flex items-end gap-4 xl:gap-6">
              {AUTH_MODULES.map((m, i) => (
                <div key={m.label} className="auth-float flex flex-col items-center gap-2" style={{ animationDelay: `${-i * 0.7}s` }}>
                  <span
                    className={`flex h-14 w-14 items-center justify-center rounded-full shadow-xl shadow-navy-950/40 ring-4 xl:h-16 xl:w-16 ${
                      m.featured
                        ? 'bg-gradient-to-br from-brand-500 to-cyan-400 text-white ring-white/25'
                        : 'bg-white text-brand-600 ring-white/10'
                    }`}
                  >
                    {m.icon}
                  </span>
                  <span className="text-[11px] font-medium text-white/70">{m.label}</span>
                </div>
              ))}
            </div>
            <img
              src={`${base}auth-laptop.webp`}
              alt=""
              width={1663}
              height={932}
              className="relative w-[min(92%,46rem,calc((91vh-28rem)*2))] min-w-[22rem] translate-y-[14%] drop-shadow-[0_-10px_60px_rgb(21_115_255/.45)]"
            />
          </div>
        </div>
      </aside>
    </div>
  );
}

export function LoginPage() {
  const navigate = useNavigate();
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [googleEnabled, setGoogleEnabled] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  useEffect(() => {
    fetch('/api/auth.php', { credentials: 'same-origin' }).then((response) => response.json()).then((data) => setGoogleEnabled(Boolean(data.googleEnabled))).catch(() => null);
  }, []);
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setLoading(true);
    setError(null);
    const data = new FormData(event.currentTarget);
    try {
      const result = await authPost<{ requires2fa: boolean }>({ action: 'login', email: String(data.get('email')), password: String(data.get('password')) });
      if (result.requires2fa) navigate('/2fa');
      else window.location.assign('/app/dashboard');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo iniciar sesión.');
    } finally {
      setLoading(false);
    }
  };
  return (
    <AuthShell
      title="Bienvenido a Vértice"
      subtitle="Gestión ágil de proyectos, tareas, tickets y recursos"
      footer={<p className="text-center text-sm text-slate-500">¿No tienes una cuenta? <Link to="/register" className="font-semibold text-slate-900 underline-offset-4 hover:underline">Regístrate gratis</Link></p>}
    >
      {error && (
        <div className="mb-5 flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-3.5 text-xs text-rose-700 sm:text-sm">
          <AlertCircle className="h-5 w-5 flex-shrink-0" />
          <span>{error}</span>
        </div>
      )}

      <form className="space-y-4" onSubmit={submit}>
        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">Correo electrónico</label>
          <div className="relative">
            <Mail className="absolute left-3.5 top-3.5 h-4 w-4 text-slate-400" />
            <input name="email" type="email" required autoComplete="email" placeholder="nombre@ejemplo.com" className="w-full rounded-xl border border-slate-200 py-2.5 pl-10 pr-4 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
        </div>

        <div>
          <div className="mb-1.5 flex items-center justify-between">
            <label className="block text-sm font-medium text-slate-700">Contraseña</label>
            <Link to="/forgot-password" className="text-[11px] font-semibold text-brand-600 hover:text-brand-700">¿La olvidaste?</Link>
          </div>
          <div className="relative">
            <Lock className="absolute left-3.5 top-3.5 h-4 w-4 text-slate-400" />
            <input name="password" type={showPassword ? 'text' : 'password'} required autoComplete="current-password" placeholder="••••••••" className="w-full rounded-xl border border-slate-200 py-2.5 pl-10 pr-10 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500" />
            <button type="button" onClick={() => setShowPassword((value) => !value)} className="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 transition hover:text-slate-600" aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}>
              {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </div>
        </div>

        <div className="pt-2">
          <button type="submit" disabled={loading} className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition-all active:scale-[0.99] hover:bg-brand-700 disabled:opacity-60">
            <span>{loading ? 'Entrando...' : 'Iniciar sesión'}</span>
            {!loading && <ArrowRight className="h-4 w-4" />}
          </button>
        </div>

        {googleEnabled && (
          <>
            <div className="flex items-center gap-3 pt-1"><span className="h-px flex-1 bg-slate-200" /><span className="text-[10px] font-medium uppercase tracking-wider text-slate-400">o</span><span className="h-px flex-1 bg-slate-200" /></div>
            <a href="/google-login" className="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
              <span className="text-base font-bold text-[#4285F4]">G</span>
              Continuar con Google
            </a>
          </>
        )}
      </form>
    </AuthShell>
  );
}

export function TwoFactorPage() {
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setLoading(true);
    setError(null);
    try {
      await authPost({ action: 'verify_2fa', code: String(new FormData(event.currentTarget).get('code') ?? '') });
      window.location.assign('/app/dashboard');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Código incorrecto.');
    } finally {
      setLoading(false);
    }
  };
  return (
    <AuthShell title="Verificación en dos pasos" subtitle="Introduce el código de tu aplicación autenticadora">
      <form className="space-y-3" onSubmit={submit}>
        <Input name="code" inputMode="numeric" maxLength={6} required placeholder="000000" className="text-center text-lg tracking-[0.3em]" />
        {error && <p className="rounded-lg bg-rose-50 px-3 py-2 text-[11px] text-rose-700">{error}</p>}
        <Button type="submit" disabled={loading} className="w-full">Verificar</Button>
      </form>
      <Link to="/login" className="mt-4 block text-center text-[11px] font-semibold text-slate-500">Volver al inicio de sesión</Link>
    </AuthShell>
  );
}

export function ForgotPasswordPage() {
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await authPost({ action: 'forgot_password', email: String(new FormData(event.currentTarget).get('email') ?? '') });
      setSent(true);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo enviar.');
    } finally {
      setLoading(false);
    }
  };
  return (
    <AuthShell
      title="Recuperar contraseña"
      subtitle="Te enviaremos un enlace de un solo uso válido durante 60 minutos"
      footer={<p className="text-center text-sm text-slate-500">¿Ya la recordaste? <Link to="/login" className="font-semibold text-slate-900 underline-offset-4 hover:underline">Inicia sesión</Link></p>}
    >
      {sent ? (
        <div className="space-y-4 text-center">
          <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600"><CheckCircle2 className="h-6 w-6" /></div>
          <div>
            <h2 className="text-sm font-semibold text-slate-900">Revisa tu correo</h2>
            <p className="mt-1 text-xs leading-relaxed text-slate-500">Si el correo está registrado, recibirás un enlace para crear una nueva contraseña. Revisa también la carpeta de spam.</p>
          </div>
          <Link to="/login" className="inline-flex text-xs font-semibold text-brand-600 hover:text-brand-700">Volver a iniciar sesión</Link>
        </div>
      ) : (
        <>
          {error && (
            <div className="mb-5 flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-3.5 text-xs text-rose-700 sm:text-sm">
              <AlertCircle className="h-5 w-5 flex-shrink-0" />
              <span>{error}</span>
            </div>
          )}
          <form className="space-y-4" onSubmit={submit}>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Correo electrónico</label>
              <div className="relative">
                <Mail className="absolute left-3.5 top-3.5 h-4 w-4 text-slate-400" />
                <input name="email" type="email" required autoComplete="email" placeholder="nombre@ejemplo.com" className="w-full rounded-xl border border-slate-200 py-2.5 pl-10 pr-4 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500" />
              </div>
            </div>
            <div className="pt-2">
              <button type="submit" disabled={loading} className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition-all active:scale-[0.99] hover:bg-brand-700 disabled:opacity-60">
                <span>{loading ? 'Enviando...' : 'Enviar enlace'}</span>
                {!loading && <ArrowRight className="h-4 w-4" />}
              </button>
            </div>
          </form>
        </>
      )}
    </AuthShell>
  );
}

export function ResetPasswordPage() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const token = params.get('token') ?? '';
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    const data = new FormData(event.currentTarget);
    if (String(data.get('password')) !== String(data.get('password_confirm'))) { setError('Las contraseñas no coinciden.'); return; }
    setLoading(true);
    try {
      await authPost({ action: 'reset_password', token, password: String(data.get('password')), password_confirm: String(data.get('password_confirm')) });
      navigate('/login');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo cambiar la contraseña.');
    } finally {
      setLoading(false);
    }
  };
  return (
    <AuthShell
      title="Nueva contraseña"
      subtitle="Elige una contraseña segura de al menos 8 caracteres"
      footer={<p className="text-center text-sm text-slate-500">¿El enlace caducó? <Link to="/forgot-password" className="font-semibold text-slate-900 underline-offset-4 hover:underline">Solicita otro</Link></p>}
    >
      {!token && (
        <div className="mb-5 flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3.5 text-xs text-amber-800 sm:text-sm">
          <AlertCircle className="h-5 w-5 flex-shrink-0" />
          <span>El enlace no incluye un token válido. Solicita uno nuevo.</span>
        </div>
      )}
      {error && (
        <div className="mb-5 flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-3.5 text-xs text-rose-700 sm:text-sm">
          <AlertCircle className="h-5 w-5 flex-shrink-0" />
          <span>{error}</span>
        </div>
      )}
      <form className="space-y-4" onSubmit={submit}>
        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">Nueva contraseña</label>
          <div className="relative">
            <Lock className="absolute left-3.5 top-3.5 h-4 w-4 text-slate-400" />
            <input name="password" type="password" required minLength={8} autoComplete="new-password" placeholder="••••••••" className="w-full rounded-xl border border-slate-200 py-2.5 pl-10 pr-4 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
        </div>
        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">Confirmar contraseña</label>
          <div className="relative">
            <ShieldCheck className="absolute left-3.5 top-3.5 h-4 w-4 text-slate-400" />
            <input name="password_confirm" type="password" required minLength={8} autoComplete="new-password" placeholder="••••••••" className="w-full rounded-xl border border-slate-200 py-2.5 pl-10 pr-4 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
        </div>
        <div className="pt-2">
          <button type="submit" disabled={loading || !token} className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition-all active:scale-[0.99] hover:bg-brand-700 disabled:opacity-60">
            <span>{loading ? 'Guardando...' : 'Guardar contraseña'}</span>
            {!loading && <ArrowRight className="h-4 w-4" />}
          </button>
        </div>
      </form>
    </AuthShell>
  );
}

export function RegisterPage() {
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setLoading(true);
    setError(null);
    const data = new FormData(event.currentTarget);
    if (String(data.get('password')) !== String(data.get('password_confirm'))) {
      setError('Las contraseñas no coinciden.');
      setLoading(false);
      return;
    }
    try {
      const result = await authPost<{ redirect: string }>({
        action: 'register',
        agency_name: String(data.get('agency_name')),
        name: String(data.get('name')),
        email: String(data.get('email')),
        password: String(data.get('password')),
        password_confirm: String(data.get('password_confirm')),
      });
      window.location.assign(result.redirect);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo crear la cuenta.');
    } finally {
      setLoading(false);
    }
  };
  return (
    <AuthShell
      title="Crea tu agencia"
      subtitle="Organiza tus proyectos, tu equipo y tus clientes en un solo lugar"
      footer={(
        <>
          <p className="text-center text-sm text-slate-500">¿Ya tienes una cuenta? <Link to="/login" className="font-semibold text-slate-900 underline-offset-4 hover:underline">Inicia sesión</Link></p>
          <p className="text-center text-xs text-slate-400">¿Te unes al equipo o eres cliente de una agencia? Pide a la agencia tu enlace de invitación.</p>
        </>
      )}
    >
      {error && (
        <div className="mb-5 flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-3.5 text-xs text-rose-700 sm:text-sm">
          <AlertCircle className="h-5 w-5 flex-shrink-0" />
          <span>{error}</span>
        </div>
      )}
      <form className="space-y-4" onSubmit={submit}>
        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">Nombre de la agencia</label>
          <div className="relative">
            <Building2 className="absolute left-3.5 top-3.5 h-4 w-4 text-slate-400" />
            <input name="agency_name" required maxLength={150} placeholder="Estudio Creativo" className="w-full rounded-xl border border-slate-200 py-2.5 pl-10 pr-4 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
        </div>
        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">Tu nombre completo</label>
          <div className="relative">
            <UserRound className="absolute left-3.5 top-3.5 h-4 w-4 text-slate-400" />
            <input name="name" required maxLength={100} placeholder="Carlos Mendoza" className="w-full rounded-xl border border-slate-200 py-2.5 pl-10 pr-4 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
        </div>
        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">Correo electrónico</label>
          <div className="relative">
            <Mail className="absolute left-3.5 top-3.5 h-4 w-4 text-slate-400" />
            <input name="email" type="email" required placeholder="carlos@empresa.com" className="w-full rounded-xl border border-slate-200 py-2.5 pl-10 pr-4 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
        </div>
        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">Contraseña (mínimo 8 caracteres)</label>
          <div className="relative">
            <Lock className="absolute left-3.5 top-3.5 h-4 w-4 text-slate-400" />
            <input name="password" type="password" required minLength={8} autoComplete="new-password" placeholder="••••••••" className="w-full rounded-xl border border-slate-200 py-2.5 pl-10 pr-4 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
        </div>
        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">Confirmar contraseña</label>
          <div className="relative">
            <ShieldCheck className="absolute left-3.5 top-3.5 h-4 w-4 text-slate-400" />
            <input name="password_confirm" type="password" required minLength={8} autoComplete="new-password" placeholder="••••••••" className="w-full rounded-xl border border-slate-200 py-2.5 pl-10 pr-4 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>
        </div>
        <div className="pt-2">
          <button type="submit" disabled={loading} className="flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-500/25 transition-all active:scale-[0.99] hover:bg-brand-700 disabled:opacity-60">
            <span>{loading ? 'Creando...' : 'Crear agencia'}</span>
            {!loading && <ArrowRight className="h-4 w-4" />}
          </button>
        </div>
      </form>
    </AuthShell>
  );
}

export function VerifyEmailPage() {
  const [params] = useSearchParams();
  const [status, setStatus] = useState<'loading' | 'ok' | 'error'>('loading');
  const [message, setMessage] = useState('Verificando tu correo...');
  useEffect(() => {
    const token = params.get('token') ?? '';
    if (!token) { setStatus('error'); setMessage('Falta el token de verificación.'); return; }
    fetch(`/api/auth.php?verify=${encodeURIComponent(token)}`, { credentials: 'same-origin' })
      .then(async (response) => { const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Enlace no válido.'); setStatus('ok'); setMessage('Correo verificado correctamente.'); })
      .catch((error) => { setStatus('error'); setMessage(error instanceof Error ? error.message : 'No se pudo verificar.'); });
  }, [params]);
  return (
    <AuthShell title="Verificación de correo" subtitle="Estamos comprobando tu enlace">
      <div className="space-y-4 text-center">
        {status === 'loading' && <div className="mx-auto h-6 w-6 animate-spin rounded-full border-2 border-slate-200 border-t-brand-600" />}
        <p className={`text-xs ${status === 'error' ? 'text-rose-700' : 'text-slate-600'}`}>{message}</p>
        <Link to="/login" className="inline-flex text-xs font-semibold text-brand-600">Ir al inicio de sesión</Link>
      </div>
    </AuthShell>
  );
}

export function InvitePage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const [info, setInfo] = useState<{ email: string; agency: string | null; role: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    if (!token) { setError('Falta el token de invitación.'); return; }
    fetch(`/api/auth.php?invite=${encodeURIComponent(token)}`, { credentials: 'same-origin' })
      .then(async (response) => { const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Invitación no válida.'); setInfo(data); })
      .catch((e) => setError(e instanceof Error ? e.message : 'Invitación no válida.'));
  }, [token]);
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setLoading(true);
    setError(null);
    const data = new FormData(event.currentTarget);
    try {
      const result = await authPost<{ redirect: string }>({ action: 'accept_invitation', token, name: String(data.get('name')), password: String(data.get('password')) });
      window.location.assign(result.redirect);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo aceptar la invitación.');
    } finally {
      setLoading(false);
    }
  };
  return (
    <AuthShell title="Aceptar invitación" subtitle={info ? `Te invitaron a ${info.agency || 'una agencia'} como ${info.role}` : 'Completa tus datos para unirte'}>
      {info ? (
        <form className="space-y-3" onSubmit={submit}>
          <Input value={info.email} readOnly className="bg-slate-50" />
          <Input name="name" required maxLength={100} placeholder="Tu nombre completo" />
          <Input name="password" type="password" required minLength={8} placeholder="Contraseña (mínimo 8)" />
          {error && <p className="text-[11px] text-rose-700">{error}</p>}
          <Button type="submit" disabled={loading} className="w-full">Aceptar invitación</Button>
        </form>
      ) : <p className={`text-center text-xs ${error ? 'text-rose-700' : 'text-slate-500'}`}>{error || 'Cargando invitación...'}</p>}
    </AuthShell>
  );
}

export function LegalPage() {
  const { page } = useParams();
  const isPrivacy = page === 'privacy';
  return (
    <div className="min-h-screen bg-slate-50 p-6">
      <div className="mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-8">
        <a href="/" className="text-xs font-semibold text-brand-600">← Volver</a>
        <h1 className="mt-4 text-2xl font-semibold text-slate-900">{isPrivacy ? 'Política de privacidad' : 'Términos y condiciones'}</h1>
        <div className="mt-4 space-y-3 text-sm leading-relaxed text-slate-600">
          <p>Vértice es una plataforma de gestión de proyectos, tareas, tickets y recursos para agencias.</p>
          <p>{isPrivacy ? 'Los datos se utilizan para prestar el servicio, gestionar tu cuenta y mantener la seguridad de la plataforma. No se venden a terceros.' : 'Al usar Vértice aceptas un uso lícito de la plataforma, mantener la confidencialidad de tus credenciales y respetar los límites de tu plan.'}</p>
          <p>Para consultas sobre estos términos escribe a soporte desde el módulo de Soporte.</p>
        </div>
      </div>
    </div>
  );
}
