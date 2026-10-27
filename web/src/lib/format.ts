export function unwrap<T>(res: unknown): T {
  const r = res as { data?: unknown }
  return (r && typeof r === 'object' && 'data' in r && r.data !== undefined ? r.data : res) as T
}
export function ago(iso?: string | null): string {
  if (!iso) return '—'
  const t = new Date(iso).getTime()
  if (Number.isNaN(t)) return iso
  const s = Math.round((Date.now() - t) / 1000)
  const f = (n: number, u: string) => `${n} ${u}${n === 1 ? '' : 's'}`
  if (s < -60) { const m = Math.round(-s / 60); return m < 60 ? `in ${f(m, 'min')}` : m < 1440 ? `in ${f(Math.round(m / 60), 'hour')}` : `in ${f(Math.round(m / 1440), 'day')}` }
  if (s < 45) return 'just now'
  if (s < 3600) return `${Math.round(s / 60)} min ago`
  if (s < 86400) return `${Math.round(s / 3600)} h ago`
  return `${Math.round(s / 86400)} d ago`
}
export function daysUntil(iso?: string | null): number | null {
  if (!iso) return null
  const t = new Date(iso).getTime()
  return Number.isNaN(t) ? null : Math.ceil((t - Date.now()) / 86400000)
}
export function pct(v?: number | null): string { return v == null ? '—' : `${Math.round(v)}%` }
export function mb(v?: number | null): string {
  if (v == null) return '—'
  return v >= 1024 ? `${(v / 1024).toFixed(1)} GB` : `${Math.round(v)} MB`
}
export function money(v?: number | string | null, cur = 'USD'): string {
  if (v == null || v === '') return '—'
  const n = typeof v === 'string' ? Number(v) : v
  if (Number.isNaN(n)) return String(v)
  try { return new Intl.NumberFormat('en-US', { style: 'currency', currency: cur }).format(n) } catch { return `$${n.toFixed(2)}` }
}
export function str(v: unknown): string {
  if (v == null || v === '') return '—'
  if (Array.isArray(v)) return v.join(', ') || '—'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}
/** DNS-safe app name: 3–40 chars, lowercase letters, digits and hyphens, starting with a letter, not ending with a hyphen. */
export function validateAppName(name: string): string | null {
  const n = name.trim()
  if (!n) return 'Give your app a name.'
  if (n.length < 3) return 'Use at least 3 characters.'
  if (n.length > 40) return 'Use 40 characters or fewer.'
  if (/[A-Z]/.test(n)) return 'Use lowercase letters only.'
  if (!/^[a-z]/.test(n)) return 'Start with a letter.'
  if (!/^[a-z0-9-]+$/.test(n)) return 'Use only lowercase letters, numbers and hyphens (-).'
  if (n.endsWith('-')) return 'Do not end with a hyphen.'
  if (n.includes('--')) return 'Do not use two hyphens in a row.'
  return null
}
