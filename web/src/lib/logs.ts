import type { R } from './util'
/** Normalise a logs response (string, string[], {lines}, [{ts,message}]) into display lines. */
export function logLines(res: unknown): string[] {
  const d = (res as R)?.data ?? res
  const v = (d as R)?.lines ?? (d as R)?.logs ?? (d as R)?.output ?? d
  if (typeof v === 'string') return v.split('\n').filter(Boolean)
  if (Array.isArray(v)) return v.map((x) => (typeof x === 'string' ? x : [x.ts ?? x.time ?? x.timestamp, x.message ?? x.line ?? x.text].filter(Boolean).join(' ')))
  return []
}
