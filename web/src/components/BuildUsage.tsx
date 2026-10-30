import { useOne } from '../api/hooks'

type Usage = { builds: number; minutes_used: number; average_seconds: number | null; cache_hit_rate: number | null; stacks: Record<string, number>; limits: { build_minutes: number; timeout_minutes: number } }

/** BLD-3/9: build minutes this month, layer cache hit rate and detected stacks. */
export function BuildUsage({ path }: { path: string }) {
  const { item: u } = useOne<Usage>(path)
  if (!u) return null
  const limit = u.limits.build_minutes
  const stat = (label: string, value: string) => (
    <div className="min-w-[120px]"><div className="text-xs text-muted">{label}</div><div className="text-lg font-semibold tabular-nums">{value}</div></div>
  )
  return (
    <div className="card p-4 mb-4 flex flex-wrap gap-6 items-center" aria-label="Build usage this month">
      {stat('Build minutes', limit ? `${u.minutes_used} / ${limit}` : String(u.minutes_used))}
      {stat('Builds', String(u.builds))}
      {stat('Average build', u.average_seconds != null ? `${u.average_seconds}s` : '—')}
      {stat('Cache hit rate', u.cache_hit_rate != null ? `${u.cache_hit_rate}%` : '—')}
      {stat('Timeout', `${u.limits.timeout_minutes} min`)}
      {Object.keys(u.stacks).length > 0 && <div className="text-xs text-muted">Detected: {Object.entries(u.stacks).map(([s, n]) => `${s} ×${n}`).join(', ')}</div>}
    </div>
  )
}
