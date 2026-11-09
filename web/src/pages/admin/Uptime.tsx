import { useState } from 'react'
import { useOne } from '../../api/hooks'
import { Card, PageHeader, StatTile } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { DataTable } from '../../components/DataTable'
import Select from '../../components/Select'
import { ago } from '../../lib/format'
import { num, type R } from '../../lib/util'

const RANGES: [string, string][] = [['24h', 'Last 24 hours'], ['7d', 'Last 7 days'], ['30d', 'Last 30 days']]

const pct = (v: unknown) => (v == null ? '—' : `${Number(v).toFixed(Number(v) >= 99.995 ? 0 : 2)}%`)
const tone = (v: unknown) => (v == null ? 'n' : num(v) >= 99.9 ? 'g' : num(v) >= 99 ? 'y' : 'r') as 'g' | 'y' | 'r' | 'n'
const lasted = (m: number) => (m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${m % 60} min`)

/** Uptime percentage per site, response times and incident history, from the 5-minute checks. */
export default function Uptime() {
  const [range, setRange] = useState('30d')
  const [open, setOpen] = useState<string | null>(null)
  const page = useOne<R>('/uptime', { range }, { refetchInterval: 60000 })
  const rows = ((page.item?.domains as R[]) ?? [])
  const sum = (page.item?.summary ?? {}) as R
  const sel = rows.find((r) => r.domain_id === open)

  return (
    <>
      <PageHeader title="Uptime" subtitle="Every verified site is checked every 5 minutes. Worst uptime first."
        actions={<Select className="input !w-auto" aria-label="Range" value={range} onChange={(e) => setRange(e.target.value)}>{RANGES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</Select>} />
      <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
        <StatTile label="Average uptime" value={pct(sum.avg_uptime_pct)} valueTone={tone(sum.avg_uptime_pct)} />
        <StatTile label="Sites measured" value={num(sum.measured)} delta={num(sum.unmeasured) ? `${num(sum.unmeasured)} without checks yet` : undefined} />
        <StatTile label="Open incidents" value={num(sum.open_incidents)} valueTone={num(sum.open_incidents) ? 'r' : 'g'} />
      </div>
      <Card pad={false} title="Sites">
        <DataTable label="Uptime per site" rows={rows} loading={page.isLoading} rowKey={(r) => String(r.domain_id)}
          searchKeys={(r) => String(r.domain)} searchPlaceholder="Search sites"
          onRowClick={(r) => setOpen(open === r.domain_id ? null : String(r.domain_id))} selectedRowId={open}
          columns={[
            { key: 'domain', header: 'Site', render: (r) => <span className="font-semibold">{String(r.domain)}</span> },
            { key: 'uptime_pct', header: 'Uptime', render: (r) => <Badge tone={tone(r.uptime_pct)}>{pct(r.uptime_pct)}</Badge> },
            { key: 'failed', header: 'Failed checks', render: (r) => `${num(r.failed)} of ${num(r.checks)}` },
            { key: 'avg_ms', header: 'Avg response', render: (r) => (r.avg_ms == null ? '—' : `${num(r.avg_ms)} ms`) },
            { key: 'p95_ms', header: 'p95', render: (r) => (r.p95_ms == null ? '—' : `${num(r.p95_ms)} ms`) },
            { key: 'incidents', header: 'Incidents', render: (r) => ((r.incidents as R[]) ?? []).length },
          ]} />
      </Card>
      {sel && (
        <Card title={`Incidents · ${String(sel.domain)}`} subtitle={RANGES.find(([v]) => v === range)?.[1]}>
          {((sel.incidents as R[]) ?? []).length === 0 ? <p className="m-0 text-sm text-muted">No incidents in this period.</p> : (
            <ul className="m-0 p-0 list-none flex flex-col text-sm">
              {(sel.incidents as R[]).map((i) => (
                <li key={String(i.id)} className="flex flex-wrap items-center gap-3 py-2 border-t border-line first:border-t-0">
                  <Badge tone={i.status === 'open' ? 'r' : 'n'}>{i.status === 'open' ? 'ongoing' : 'resolved'}</Badge>
                  <span className="flex-1 min-w-0">{String(i.title)}</span>
                  <span className="text-xs text-muted">{ago(String(i.started_at))} · {lasted(num(i.minutes))}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}
    </>
  )
}
