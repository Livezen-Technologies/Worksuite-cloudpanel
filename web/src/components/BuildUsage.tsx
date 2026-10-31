import { useOne } from '../api/hooks'

type Usage = { builds: number; minutes_used: number; average_seconds: number | null; cache_hit_rate: number | null; stacks: Record<string, number>; limits: { build_minutes: number; timeout_minutes: number } }

/** BLD-3/9: build minutes this month, layer cache hit rate and detected stacks. */
export function BuildUsage({ path }: { path: string }) {
  const { item: u } = useOne<Usage>(path)
  if (!u) return null
  const limit = u.limits.build_minutes
  const stat = (label: string, value: string, hint?: string) => (
    <div className="card px-4 py-3 min-w-0">
      <div className="text-xs text-muted">{label}</div>
      <div className="text-xl font-semibold tabular-nums mt-0.5">{value}</div>
      {hint && <div className="text-xs text-muted mt-0.5 truncate">{hint}</div>}
    </div>
  )
  return (
    <section aria-label="Build usage this month" className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(160px,1fr))]">
      {stat('Build minutes', limit ? `${u.minutes_used} / ${limit}` : String(u.minutes_used), 'this month')}
      {stat('Builds', String(u.builds), Object.keys(u.stacks).length ? Object.entries(u.stacks).map(([s, n]) => `${s} ×${n}`).join(', ') : 'this month')}
      {stat('Average build', u.average_seconds != null ? `${u.average_seconds} s` : '—')}
      {stat('Cache hit rate', u.cache_hit_rate != null ? `${u.cache_hit_rate}%` : '—', 'image layers reused')}
      {stat('Build timeout', `${u.limits.timeout_minutes} min`)}
    </section>
  )
}
