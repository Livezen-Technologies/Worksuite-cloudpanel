import { useEffect, useState } from 'react'
import { api, isApiError } from '../../api/client'

type Day = { date: string; uptime_pct: number | null }
type Component = { name: string; state: string; uptime_pct: number | null; days: Day[] }
type Incident = { component: string; state: string; started_at: string; resolved_at: string | null }
type Page = { title: string; notice: string | null; overall: string; components: Component[]; incidents: Incident[]; updated_at: string }

const STATE: Record<string, [string, string]> = {
  operational: ['Operational', 'var(--g-fg)'],
  maintenance: ['Under maintenance', 'var(--b-fg)'],
  down: ['Disruption', 'var(--r-fg)'],
}
const TONE: Record<string, string> = { operational: 'g', maintenance: 'b', down: 'r' }
const OVERALL: Record<string, string> = { operational: 'All systems operational', maintenance: 'Maintenance in progress', down: 'Some systems are disrupted' }

const dayColor = (p: number | null) => (p == null ? 'var(--line)' : p >= 99.9 ? 'var(--g-fg)' : p >= 98 ? 'var(--y-fg)' : 'var(--r-fg)')
const when = (s: string) => new Date(s).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
const lasted = (a: string, b: string | null) => {
  const m = Math.max(1, Math.round(((b ? new Date(b).getTime() : Date.now()) - new Date(a).getTime()) / 60000))
  return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${m % 60} min`
}

/** Public status page: no sign-in; refreshes every minute. */
export default function Status() {
  const [page, setPage] = useState<Page | null>(null)
  const [missing, setMissing] = useState(false)
  useEffect(() => {
    let live = true
    const load = () => api.get<{ data: Page }>('/status').then((r) => { if (live) { setPage(r.data); document.title = r.data.title } })
      .catch((e) => { if (live && isApiError(e) && e.status === 404) setMissing(true) })
    load()
    const t = setInterval(load, 60000)
    return () => { live = false; clearInterval(t) }
  }, [])

  if (missing) return <main className="min-h-screen grid place-items-center bg-bg text-fg p-4"><p className="text-muted">There is no status page here.</p></main>
  if (!page) return <main className="min-h-screen grid place-items-center bg-bg text-fg p-4"><p className="text-muted">Loading…</p></main>
  return (
    <main className="min-h-screen bg-bg text-fg px-4 py-10">
      <div className="mx-auto max-w-3xl flex flex-col gap-6">
        <h1 className="m-0 text-2xl font-semibold">{page.title}</h1>
        <div role="status" className="rounded-xl p-4 font-semibold border" style={{ background: `var(--${TONE[page.overall] ?? 'g'}-bg)`, color: `var(--${TONE[page.overall] ?? 'g'}-fg)`, borderColor: 'currentColor' }}>{OVERALL[page.overall] ?? page.overall}</div>
        {page.notice && <p className="m-0 rounded-xl border border-line bg-card p-4 text-sm">{page.notice}</p>}

        <section aria-label="Components" className="rounded-xl border border-line bg-card divide-y divide-line">
          {page.components.map((c) => (
            <div key={c.name} className="p-4 flex flex-col gap-3">
              <div className="flex flex-wrap items-center gap-3">
                <span className="font-semibold flex-1 min-w-0">{c.name}</span>
                <span className="text-xs text-muted">{c.uptime_pct == null ? 'No data yet' : `${c.uptime_pct}% uptime, ${c.days.length} days`}</span>
                <span className="text-sm font-semibold" style={{ color: STATE[c.state]?.[1] }}>{STATE[c.state]?.[0] ?? c.state}</span>
              </div>
              <div role="img" aria-label={`${c.name}: daily uptime over ${c.days.length} days`} className="flex gap-px h-8 overflow-hidden">
                {c.days.map((d) => <span key={d.date} className="flex-1 min-w-0 rounded-sm" style={{ background: dayColor(d.uptime_pct) }} title={`${d.date}: ${d.uptime_pct == null ? 'no data' : `${d.uptime_pct}%`}`} />)}
              </div>
            </div>
          ))}
          {page.components.length === 0 && <p className="m-0 p-4 text-sm text-muted">Nothing is listed yet.</p>}
        </section>

        <section aria-label="Recent incidents" className="flex flex-col gap-2">
          <h2 className="m-0 text-lg font-semibold">Past 14 days</h2>
          {page.incidents.length === 0 ? <p className="m-0 text-sm text-muted">No incidents.</p> : (
            <ul className="m-0 p-0 list-none flex flex-col gap-2">
              {page.incidents.map((i, n) => (
                <li key={n} className="rounded-xl border border-line bg-card p-3 text-sm flex flex-wrap gap-x-3 gap-y-1">
                  <span className="font-semibold">{i.component}</span>
                  <span className="text-muted">{i.state === 'ongoing' ? `Disrupted since ${when(i.started_at)}` : `${when(i.started_at)} · resolved after ${lasted(i.started_at, i.resolved_at)}`}</span>
                </li>
              ))}
            </ul>
          )}
        </section>
        <p className="m-0 text-xs text-muted">Updated {when(page.updated_at)}. Checked every 5 minutes.</p>
      </div>
    </main>
  )
}
