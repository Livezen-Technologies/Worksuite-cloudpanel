/** API client for the Docker Manager Portal contract (docs/API.md). */

export type ErrorCode =
  | 'unauthenticated' | 'forbidden' | 'not_found' | 'validation_failed' | 'conflict'
  | 'quota_exceeded' | 'rate_limited' | 'server_error' | 'network_error'

const STATUS_CODES: Record<number, ErrorCode> = {
  401: 'unauthenticated', 403: 'forbidden', 404: 'not_found', 409: 'conflict',
  402: 'quota_exceeded', 422: 'validation_failed', 429: 'rate_limited',
}

const FRIENDLY: Record<ErrorCode, string> = {
  unauthenticated: 'Your session has ended. Please sign in again.',
  forbidden: 'You do not have permission to do that. Ask an owner or admin for access.',
  not_found: 'We could not find that item. It may have been removed.',
  validation_failed: 'Some fields need attention. Check the highlighted fields and try again.',
  conflict: 'This conflicts with something that already exists. Change it and try again.',
  quota_exceeded: 'This would go over your plan limits. Upgrade your plan to continue.',
  rate_limited: 'Too many requests. Wait a moment and try again.',
  server_error: 'Something went wrong on our side. Try again in a minute.',
  network_error: 'Could not reach the server. Check your connection and try again.',
}

export class ApiError extends Error {
  code: ErrorCode
  status: number
  fields: Record<string, string[]>
  requestId?: string
  constructor(opts: { code: ErrorCode; status: number; message?: string; fields?: Record<string, string[]>; requestId?: string }) {
    super(opts.message || FRIENDLY[opts.code] || 'Request failed')
    this.name = 'ApiError'
    this.code = opts.code
    this.status = opts.status
    this.fields = opts.fields ?? {}
    this.requestId = opts.requestId
  }
  /** First message for a field, if the server sent one. */
  field(name: string): string | undefined { return this.fields[name]?.[0] }
}

export function isApiError(e: unknown): e is ApiError { return e instanceof ApiError }
export function errorMessage(e: unknown): string {
  if (isApiError(e)) return e.message
  if (e instanceof Error) return e.message
  return 'Something went wrong.'
}

const TOKEN_KEY = 'dmp.token'
let memToken: string | null = null
export const tokenStore = {
  get(): string | null {
    if (memToken) return memToken
    try { memToken = localStorage.getItem(TOKEN_KEY) } catch { /* storage unavailable */ }
    return memToken
  },
  set(t: string) { memToken = t; try { localStorage.setItem(TOKEN_KEY, t) } catch { /* ignore */ } },
  clear() { memToken = null; try { localStorage.removeItem(TOKEN_KEY) } catch { /* ignore */ } },
}

type UnauthListener = () => void
const unauthListeners = new Set<UnauthListener>()
export function onUnauthenticated(fn: UnauthListener) { unauthListeners.add(fn); return () => { unauthListeners.delete(fn) } }

export function apiBase(): string {
  const env = (import.meta.env?.VITE_API_URL as string | undefined) ?? ''
  return env.replace(/\/$/, '') + '/api/v1'
}

export function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return 'k-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2)
}

export type Query = Record<string, string | number | boolean | undefined | null>
export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  body?: unknown
  query?: Query
  idempotencyKey?: string
  signal?: AbortSignal
  /** Retries on network failure (POST reuses the same Idempotency-Key). Default 1 for POST, 0 otherwise. */
  retries?: number
}

export function buildUrl(path: string, query?: Query): string {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(query ?? {})) if (v !== undefined && v !== null && v !== '') qs.set(k, String(v))
  const s = qs.toString()
  return apiBase() + (path.startsWith('/') ? path : '/' + path) + (s ? '?' + s : '')
}

export async function parseError(res: Response): Promise<ApiError> {
  let body: unknown = null
  try { body = await res.json() } catch { /* non-JSON */ }
  const err = (body as { error?: { code?: string; message?: string; fields?: Record<string, string[]>; request_id?: string } } | null)?.error
  const code = (err?.code as ErrorCode) || STATUS_CODES[res.status] || 'server_error'
  return new ApiError({ code, status: res.status, message: err?.message, fields: err?.fields, requestId: err?.request_id })
}

export async function request<T = unknown>(path: string, opts: RequestOptions = {}): Promise<T> {
  const method = opts.method ?? 'GET'
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = tokenStore.get()
  if (token) headers.Authorization = `Bearer ${token}`
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json'
  if (method === 'POST') headers['Idempotency-Key'] = opts.idempotencyKey ?? newIdempotencyKey()
  let attempts = (opts.retries ?? (method === 'POST' ? 1 : 0)) + 1
  let res: Response | undefined
  while (attempts-- > 0) {
    try {
      res = await fetch(buildUrl(path, opts.query), {
        method, headers, signal: opts.signal,
        body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
      })
      break
    } catch (e) {
      if ((e as Error)?.name === 'AbortError') throw e
      if (attempts <= 0) throw new ApiError({ code: 'network_error', status: 0 })
    }
  }
  if (!res) throw new ApiError({ code: 'network_error', status: 0 })
  if (!res.ok) {
    const err = await parseError(res)
    if (err.status === 401) { tokenStore.clear(); unauthListeners.forEach((l) => l()) }
    throw err
  }
  if (res.status === 204) return undefined as T
  const text = await res.text()
  return (text ? JSON.parse(text) : undefined) as T
}

export const api = {
  get: <T = unknown>(path: string, query?: Query, signal?: AbortSignal) => request<T>(path, { query, signal }),
  post: <T = unknown>(path: string, body?: unknown, o: Omit<RequestOptions, 'method' | 'body'> = {}) => request<T>(path, { ...o, method: 'POST', body: body ?? {} }),
  patch: <T = unknown>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body: body ?? {} }),
  del: <T = unknown>(path: string, query?: Query) => request<T>(path, { method: 'DELETE', query }),
}
