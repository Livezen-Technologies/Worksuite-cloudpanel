// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type R = Record<string, any>
export function num(x: unknown): number {
  if (typeof x === 'number') return x
  if (typeof x === 'string') return Number(x) || 0
  if (x && typeof x === 'object') { const o = x as R; return num(o.value ?? o.v ?? o.y ?? o.pct ?? 0) }
  return 0
}
export function pick(o: R | undefined | null, ...keys: string[]): unknown {
  if (!o) return undefined
  for (const k of keys) if (o[k] !== undefined && o[k] !== null) return o[k]
  return undefined
}
export function serverName(r: R): string {
  return String(r.server_name ?? r.server?.name ?? r.server ?? r.server_id ?? '—')
}
