/**
 * Staff SSO auto sign-in: the sign-in page tries a silent WorkSuite Cloud sign-in (prompt=none) once per
 * browser tab, so an admin already signed in to Cloud Admin lands in the panel without a click.
 * Skipped after an explicit sign-out (until the next sign-in) and when the URL has ?local=1.
 * The page the visitor was going to (`next`, same-origin paths only) survives the round trip.
 */
const TRIED = 'wsc_silent_tried'
const SIGNED_OUT = 'wsc_signed_out'
const NEXT = 'wsc_next'

const get = (s: () => Storage, k: string) => { try { return s().getItem(k) } catch { return null } }
const set = (s: () => Storage, k: string, v: string | null) => { try { if (v === null) s().removeItem(k); else s().setItem(k, v) } catch { /* storage blocked */ } }
const ss = () => window.sessionStorage
const ls = () => window.localStorage

/** A same-origin path ("/admin/x?y"), never "//host" or a full URL. */
export function safeNext(v: unknown): string | null {
  if (typeof v !== 'string' || !v.startsWith('/') || v.startsWith('//') || v.startsWith('/\\') || v.length > 2000) return null
  if (/^\/(login|auth\/)/.test(v)) return null
  return v
}

/** Whether the sign-in page should try the silent flow now; marks the attempt when it says yes. */
export function claimSilentAttempt(params: URLSearchParams): boolean {
  if (params.get('local') === '1' || get(ss, TRIED) || get(ls, SIGNED_OUT)) return false
  set(ss, TRIED, '1')
  return true
}

export function rememberNext(next: string | null) { set(ss, NEXT, safeNext(next)) }
export function takeNext(): string | null { const n = safeNext(get(ss, NEXT)); set(ss, NEXT, null); return n }
/** After an explicit sign-out: no silent sign-in until the person signs in again. */
export function markSignedOut() { set(ls, SIGNED_OUT, '1') }
export function markSignedIn() { set(ls, SIGNED_OUT, null); set(ss, TRIED, null) }
