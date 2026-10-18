import Constants from 'expo-constants';

/**
 * Cliente de la API REST v1 de WISPCONTROL (Sanctum, JSON en español).
 * El servidor por defecto viene de EXPO_PUBLIC_API_URL / app.json (extra.apiUrl) y se puede
 * cambiar desde la pantalla de inicio de sesión (útil para pruebas en red local).
 */

export const DEFAULT_SERVER: string =
  process.env.EXPO_PUBLIC_API_URL || (Constants.expoConfig?.extra as { apiUrl?: string } | undefined)?.apiUrl || '';

let server = DEFAULT_SERVER;
let token: string | null = null;
let onUnauthorized: (() => void) | null = null;

export const api = {
  setServer(url: string) {
    server = normalizeServer(url);
  },
  getServer() {
    return server;
  },
  setToken(value: string | null) {
    token = value;
  },
  onUnauthorized(handler: (() => void) | null) {
    onUnauthorized = handler;
  },
  get<T>(path: string, query?: Query) {
    return request<T>('GET', path, { query });
  },
  post<T>(path: string, body?: Record<string, unknown>) {
    return request<T>('POST', path, { body });
  },
  patch<T>(path: string, body?: Record<string, unknown>) {
    return request<T>('PATCH', path, { body });
  },
  delete<T>(path: string, body?: Record<string, unknown>) {
    return request<T>('DELETE', path, { body });
  },
  /** multipart/form-data: valores simples y archivos { uri, name, type }. */
  upload<T>(path: string, fields: Record<string, string | number | null | undefined | UploadFile>) {
    const form = new FormData();
    Object.entries(fields).forEach(([k, v]) => {
      if (v === null || v === undefined || v === '') return;
      if (typeof v === 'object') {
        form.append(k, { uri: v.uri, name: v.name, type: v.type } as unknown as Blob);
      } else {
        form.append(k, String(v));
      }
    });
    return request<T>('POST', path, { form });
  },
};

export type Query = Record<string, string | number | boolean | null | undefined>;
export type UploadFile = { uri: string; name: string; type: string };

export class ApiError extends Error {
  status: number;
  errors: Record<string, string[]>;
  data: Record<string, unknown>;

  constructor(status: number, message: string, errors: Record<string, string[]> = {}, data: Record<string, unknown> = {}) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.data = data;
  }

  /** Primer mensaje de validación de un campo (o el mensaje general). */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

export function normalizeServer(url: string): string {
  let u = url.trim().replace(/\/+$/, '').replace(/\/api\/v1$/, '');
  if (u && !/^https?:\/\//i.test(u)) u = 'http://' + u;
  return u;
}

async function request<T>(method: string, path: string, opts: { query?: Query; body?: Record<string, unknown>; form?: FormData }): Promise<T> {
  let url = `${server}/api/v1${path}`;
  if (opts.query) {
    const qs = Object.entries(opts.query)
      .filter(([, v]) => v !== undefined && v !== null && v !== '')
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
      .join('&');
    if (qs) url += `?${qs}`;
  }

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  let body: string | FormData | undefined;
  if (opts.form) {
    body = opts.form;
  } else if (opts.body) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(opts.body);
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), opts.form ? 90000 : 30000);
  let res: Response;
  try {
    res = await fetch(url, { method, headers, body, signal: controller.signal });
  } catch {
    throw new ApiError(0, 'Sin conexión con el servidor. Revisa tu señal de internet e intenta de nuevo.');
  } finally {
    clearTimeout(timer);
  }

  let data: Record<string, unknown> = {};
  const text = await res.text();
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = { message: res.ok ? text : `Error ${res.status} del servidor.` };
    }
  }

  if (!res.ok) {
    if (res.status === 401 && token) onUnauthorized?.();
    const message = (data.message as string) || `Error ${res.status} del servidor.`;
    throw new ApiError(res.status, message, (data.errors as Record<string, string[]>) || {}, data);
  }

  return data as T;
}

/** Mensaje para mostrar de cualquier error. */
export function errorMessage(e: unknown): string {
  if (e instanceof ApiError) {
    const first = Object.values(e.errors)[0]?.[0];
    return first && e.status === 422 ? first : e.message;
  }
  return e instanceof Error ? e.message : 'Ocurrió un error inesperado.';
}
