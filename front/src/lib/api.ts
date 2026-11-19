const API_BASE = '/api/app';

let csrfToken = '';

export class ApiError extends Error {
  status: number;

  constructor(message: string, status: number) {
    super(message);
    this.status = status;
  }
}

export function setCsrfToken(token: string): void {
  csrfToken = token;
}

async function parseResponse<T>(response: Response): Promise<T> {
  const contentType = response.headers.get('content-type') ?? '';
  const payload = contentType.includes('application/json') ? await response.json() : null;

  if (!response.ok) {
    const message = payload?.error ?? payload?.message ?? `Error ${response.status}`;
    throw new ApiError(message, response.status);
  }

  return payload as T;
}

export async function apiGet<T>(path: string, params?: Record<string, string | number | undefined>): Promise<T> {
  const query = new URLSearchParams();
  Object.entries(params ?? {}).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value));
  });
  const suffix = query.toString() ? `?${query.toString()}` : '';
  const response = await fetch(`${API_BASE}/${path}${suffix}`, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });

  return parseResponse<T>(response);
}

export async function apiPost<T>(path: string, body: Record<string, string | number | boolean | undefined>): Promise<T> {
  return apiPostUrl<T>(`${API_BASE}/${path}`, body);
}

export async function apiPostRoot<T>(path: string, body: Record<string, string | number | boolean | undefined>): Promise<T> {
  return apiPostUrl<T>(path, body);
}

export async function apiUploadRoot<T>(path: string, formData: FormData): Promise<T> {
  formData.set('csrf_token', csrfToken);
  const response = await fetch(path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
    body: formData,
  });
  return parseResponse<T>(response);
}

async function apiPostUrl<T>(url: string, body: Record<string, string | number | boolean | undefined>): Promise<T> {
  const form = new URLSearchParams();
  form.set('csrf_token', csrfToken);
  Object.entries(body).forEach(([key, value]) => {
    if (value === undefined) return;
    form.set(key, String(value));
  });

  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-CSRF-Token': csrfToken,
    },
    body: form.toString(),
  });

  return parseResponse<T>(response);
}
