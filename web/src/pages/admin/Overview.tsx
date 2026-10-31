import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useOne } from '../../api/hooks'
import { Card, Meter, PageHeader, StatTile, Empty } from '../../components/Card'
import { Icon } from '../../components/Icon'
import { Badge, StatusBadge, toneFor } from '../../components/Badge'
import { LineChart } from '../../components/Charts'
import { ago } from '../../lib/format'
import { num, pick, type R } from '../../lib/util'

const RANGES = ['1h', '6h', '24h', '7d'] as const
type Range = (typeof RANGES)[number]
const RANGE_LABEL: Record<Range, string> = { '1h': 'last hour', '6h': 'last 6 h', '24h': 'last 24 h', '7d': 'last 7 days' }

function xLabels(range: Range, n: number): string[] {
  const span = { '1h': 3600e3, '6h': 6 * 3600e3, '24h': 86400e3, '7d': 7 * 86400e3 }[range]
  const now = Date.now()
  const k = Math.min(7, Math.max(2, n))
  return Array.from({ length: k }, (_, i) => {
    const d = new Date(now - span + (span * i) / (k - 1))
    return range === '7d' ? d.toLocaleDateString(undefined, { weekday: 'short' }) : d.toTimeString().slice(0, 5)
  })
}

/** Donut colours match the state legend beside it. */
const STATES: [string, string, string][] = [
  ['online', 'Online', 'var(--g-fg)'],
  ['warning', 'Warning', 'var(--y-fg)'],
  ['maintenance', 'Maintenance', 'var(--b-fg)'],
  ['offline', 'Offline', 'var(--muted)'],
  ['pending', 'Pending', 'var(--line)'],
]

/** A score reads red below 60, amber below 90, green above. */
function scoreStyle(score: number) {
  const tone = score < 60 ? 'r' : score < 90 ? 'y' : 'g'
  return { background: `var(--${tone}-bg)`, color: `var(--${tone}-fg)` }
}

function Donut({ pct, label }: { pct: number; label: string }) {
  const r = 62, circ = 2 * Math.PI * r
  const on = (Math.max(0, Math.min(100, pct)) / 100) * circ
  return (
    <div className="relative h-[150px] w-[150px] flex-none">
      <svg width="150" height="150" viewBox="0 0 150 150" role="img" aria-label={`${Math.round(pct)}% of servers ${label}`}>
        <circle cx="75" cy="75" r={r} fill="none" stroke="var(--track)" strokeWidth="16" />
        <circle cx="75" cy="75" r={r} fill="none" stroke="var(--g-fg)" strokeWidth="16"
          strokeDasharray={`${on.toFixed(1)} ${(circ - on).toFixed(1)}`} transform="rotate(-90 75 75)" />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="text-[26px] font-bold tabular-nums">{Math.round(pct)}%</span>
        <span className="text-xs text-muted">{label}</span>
      </div>
    </div>
  )
}

function kpiTiles(k: R | R[] | undefined) {
  if (Array.isArray(k)) return k.map((x) => ({ label: String(x.label), value: String(x.value), delta: x.delta as string | undefined, pct: x.pct != null ? num(x.pct) : undefined }))
  if (!k) return []
  const online = num(pick(k, 'servers_connected', 'servers_online', 'connected_servers'))
  const total = num(pick(k, 'servers_total', 'total_servers'))
  const run = num(pick(k, 'containers_running', 'running_containers'))
  const ctot = num(pick(k, 'containers_total', 'total_containers')) || run
  const cpu = num(pick(k, 'cpu_pct', 'cpu'))
  const ram = num(pick(k, 'ram_pct', 'memory_pct', 'ram'))
  const offline = Math.max(0, total - online)
  const disk = num(pick(k, 'disk_pct', 'disk'))
  const dep = num(pick(k, 'deployments_24h'))
  const depFail = num(pick(k, 'deployments_failed_24h'))
  const jobs = num(pick(k, 'jobs_pending'))
  const alerts = num(pick(k, 'alerts_open', 'open_alerts', 'active_alerts'))
  // Each figure carries its own state, so the grid reads at a glance rather
  // than label by label.
  return [
    { label: 'Servers', icon: 'server', iconTone: 'b' as const, value: String(total || 0), delta: 'enrolled', tone: 'n' as const },
    { label: 'Online', icon: 'check', iconTone: 'g' as const, value: String(online), delta: total ? `${Math.round((online / total) * 100)}% of fleet` : undefined, tone: (online > 0 ? 'g' : 'r') as 'g' | 'r', pct: total ? (online / total) * 100 : 0, bar: 'var(--g-fg)' },
    { label: 'Offline', icon: 'server', iconTone: 'r' as const, value: String(offline), delta: offline ? 'needs attention' : 'none', tone: (offline > 0 ? 'r' : 'n') as 'r' | 'n' },
    { label: 'Active alerts', icon: 'pulse', iconTone: 'y' as const, value: String(alerts), delta: alerts ? 'open now' : 'all clear', tone: (alerts > 0 ? 'r' : 'g') as 'r' | 'g' },
    { label: 'Running containers', icon: 'box', iconTone: 'b' as const, value: String(run), delta: ctot ? `of ${ctot}` : undefined, tone: 'n' as const, pct: ctot ? (run / ctot) * 100 : 0, bar: 'var(--accent)' },
    { label: 'CPU usage', icon: 'pulse', iconTone: 'b' as const, value: `${Math.round(cpu)}%`, delta: 'fleet avg', tone: (cpu >= 85 ? 'r' : cpu >= 60 ? 'y' : 'n') as 'r' | 'y' | 'n', pct: cpu },
    { label: 'Memory usage', icon: 'db', iconTone: 'b' as const, value: `${Math.round(ram)}%`, delta: 'fleet avg', tone: (ram >= 85 ? 'r' : ram >= 60 ? 'y' : 'n') as 'r' | 'y' | 'n', pct: ram },
    { label: 'Disk usage', icon: 'disk', iconTone: 'b' as const, value: `${Math.round(disk)}%`, delta: 'fleet avg', tone: (disk >= 90 ? 'r' : disk >= 75 ? 'y' : 'n') as 'r' | 'y' | 'n', pct: disk },
    { label: 'Deployments', icon: 'rocket', iconTone: 'b' as const, value: String(dep), delta: depFail ? `${depFail} failed · 24 h` : 'last 24 h', tone: (depFail > 0 ? 'r' : 'n') as 'r' | 'n' },
    { label: 'Jobs in queue', icon: 'clock', iconTone: 'y' as const, value: String(jobs), delta: jobs ? 'queued or running' : 'idle', tone: 'n' as const },
  ]
}

function statusCards(s: R | R[] | undefined): { label: string; state: string; value: string; detail?: string }[] {
  if (!s) return []
  if (Array.isArray(s)) return s.map((x) => ({ label: String(x.label), state: String(x.state ?? x.status), value: String(x.value ?? ''), detail: x.detail }))
  const out = []
  // API shape (docs/API.md appendix): status{servers{<status>:n}, backups_24h{succeeded,failed,running}, certificates_expiring, image_scans{scanned,critical,high,not_scanned}}
  const srv = s.servers as R | undefined
  if (srv && typeof srv === 'object') {
    const total = Object.values(srv).reduce((a: number, v) => a + num(v), 0)
    const off = num(srv.offline)
    out.push({ label: 'Servers', state: off ? `${off} offline` : 'healthy', value: `${num(srv.online)} online of ${total}`, detail: Object.entries(srv).map(([k, v]) => `${num(v)} ${k}`).join(' · ') })
  }
  const b = (s.backups_24h ?? s.backups) as R | undefined
  if (b) out.push({ label: 'Backups (24 h)', state: num(b.failed) ? `${num(b.failed)} failed` : 'healthy', value: `${num(b.succeeded)} succeeded · ${num(b.failed)} failed`, detail: b.detail ?? (num(b.running) ? `${num(b.running)} running` : undefined) })
  if (s.certificates_expiring != null) {
    const n = num(s.certificates_expiring)
    out.push({ label: 'Certificates', state: n ? 'expiring' : 'healthy', value: n ? `${n} expiring soon` : 'None expiring soon', detail: 'Expiring within 14 days' })
  }
  const i = (s.image_scans ?? s.images) as R | undefined
  if (i) out.push({ label: 'Image security', state: num(i.critical) ? 'critical' : 'healthy', value: `${num(i.critical)} critical CVEs`, detail: `${num(i.high)} high · ${num(i.scanned)} scanned · ${num(i.not_scanned)} not scanned` })
  return out
}

export default function Overview() {
  const [range, setRange] = useState<Range>('6h')
  const nav = useNavigate()
  const { item: d, isLoading, error } = useOne<R>('/dashboard', { range }, { refetchInterval: 15000 })
  const cpu = ((d?.series?.cpu ?? []) as unknown[]).map(num)
  const ram = ((d?.series?.ram ?? []) as unknown[]).map(num)
  const tiles = kpiTiles(d?.kpis)
  const alerts = (d?.alerts ?? []) as R[]
  const deploys = (d?.deployments ?? []) as R[]
  const top = (d?.top ?? []) as R[]
  const fleet = d?.fleet as R | undefined
  const fleetTotal = num(fleet?.total)
  const attention = (d?.attention ?? []) as R[]
  const serverCount = d?.kpis && !Array.isArray(d.kpis) ? num(pick(d.kpis, 'servers_total', 'total_servers')) : undefined
  return (
    <>
      <PageHeader title="Overview" subtitle={`Fleet health${serverCount ? ` across ${serverCount} servers` : ''} · updated every 15 s`}
        actions={<><Link to="/admin/servers?add=1" className="btn no-underline">Add server</Link><button type="button" className="btn btn-primary" onClick={() => nav('/admin/applications?deploy=1')}>New deployment</button></>} />
      {error && <div role="alert" className="card p-4 text-sm text-danger">Could not load the dashboard: {(error as Error).message}</div>}
      <div className="grid gap-4 grid-cols-2 md:grid-cols-3 xl:grid-cols-5">
        {isLoading && !d ? [0, 1, 2, 3].map((i) => <div key={i} className="card p-5 h-[118px] animate-pulse" />) :
          tiles.map((k) => <StatTile key={k.label} label={k.label} value={k.value} delta={k.delta} deltaTone={'tone' in k ? (k as { tone: 'g' }).tone : 'n'} pct={k.pct} barColor={'bar' in k ? (k as { bar?: string }).bar : undefined}
            icon={'icon' in k ? <Icon name={(k as { icon: Parameters<typeof Icon>[0]['name'] }).icon} size={16} /> : undefined} iconTone={'iconTone' in k ? (k as { iconTone: 'b' }).iconTone : undefined} />)}
      </div>
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
        {([
          ['Install an application', 'Catalogue and one-click stacks', '/admin/catalog', 'store'],
          ['Add a server', 'Enrol a VPS or dedicated host', '/admin/servers?add=1', 'server'],
          ['Attach a domain', 'Route traffic and issue TLS', '/admin/domains', 'globe'],
          ['New hosting customer', 'Tenant, owner and package', '/admin/customers', 'users'],
        ] as const).map(([title, sub, to, icon]) => (
          <Link key={to} to={to} className="card flex items-center gap-3 p-4 no-underline text-fg transition-colors hover:border-accent">
            <span className="grid h-10 w-10 flex-none place-content-center rounded-xl bg-sel text-accent-fg"><Icon name={icon} size={20} /></span>
            <span className="min-w-0">
              <span className="block truncate text-sm font-semibold">{title}</span>
              <span className="block truncate text-xs text-muted">{sub}</span>
            </span>
          </Link>
        ))}
      </div>

      <div className="flex flex-wrap gap-4">
        <Card className="flex-[2_1_520px]" title={`Fleet CPU & RAM — ${RANGE_LABEL[range]}`}
          actions={
            <div className="flex gap-1 p-[3px] rounded-lg bg-track" role="group" aria-label="Time range">
              {RANGES.map((r) => (
                <button key={r} type="button" aria-pressed={range === r} onClick={() => setRange(r)}
                  className="border-0 min-h-7 px-2.5 rounded-md text-xs font-semibold cursor-pointer"
                  style={{ background: range === r ? 'var(--card)' : 'transparent', color: range === r ? 'var(--text)' : 'var(--muted)' }}>{r}</button>
              ))}
            </div>
          }>
          <LineChart label={`CPU and RAM usage over the ${RANGE_LABEL[range]}`} xLabels={xLabels(range, cpu.length)}
            series={[{ label: 'CPU', color: 'var(--accent)', data: cpu }, { label: 'RAM', color: 'var(--b-fg)', data: ram }]} />
        </Card>
        <Card className="flex-[1_1_300px]" title="Active alerts" actions={<Link to="/admin/monitoring" className="link-action">View all</Link>}>
          {/* Severity first: how bad matters before which. */}
          <div className="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
            {([['emergency', 'Emergency', 'text-r-fg'], ['critical', 'Critical', 'text-r-fg'], ['warning', 'Warning', 'text-y-fg'], ['info', 'Info', 'text-b-fg']] as const).map(([key, lbl, cls]) => {
              const n = alerts.filter((a) => String(a.severity ?? '').toLowerCase() === key).length
              return (
                <span key={key} className="flex items-center gap-1.5">
                  <span className={`font-semibold tabular-nums ${n ? cls : 'text-muted'}`}>{n}</span>
                  <span className="text-muted">{lbl}</span>
                </span>
              )
            })}
          </div>
          {alerts.length === 0 ? <Empty>No active alerts.</Empty> : (
            <ul className="list-none m-0 p-0">
              {alerts.slice(0, 6).map((a, i) => (
                <li key={a.id ?? i} className="flex gap-3 py-3 border-t border-line">
                  <Badge tone={toneFor(a.severity)}>{a.severity ?? 'info'}</Badge>
                  <div className="min-w-0"><div className="text-sm font-semibold">{a.title ?? a.name ?? a.rule}</div><div className="text-xs text-muted mt-0.5">{a.detail ?? a.message}</div></div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
      <div className="flex flex-wrap gap-4">
        <Card className="flex-[2_1_520px]" title="Fleet health" subtitle={fleetTotal ? `${fleetTotal} server${fleetTotal === 1 ? '' : 's'} in your scope` : undefined}
          actions={<Link to="/admin/servers" className="link-action">All servers</Link>}>
          {!fleet ? <Empty>No servers enrolled yet.</Empty> : (
            <>
              <div className="flex flex-wrap items-center gap-6">
                <Donut pct={num(fleet.online_pct)} label="online" />
                <ul className="list-none m-0 p-0 flex-1 min-w-[180px] flex flex-col gap-3">
                  {STATES.map(([key, label, color]) => {
                    const n = num((fleet.states as R)?.[key])
                    return (
                      <li key={key} className="flex items-center gap-2.5 text-sm">
                        <span className="h-2.5 w-2.5 flex-none rounded-full" style={{ background: color }} />
                        <span className="flex-1">{label}</span>
                        <span className="w-6 text-right font-semibold tabular-nums">{n}</span>
                        <span className="w-10 text-right text-[13px] text-muted tabular-nums">{fleetTotal ? Math.round((n / fleetTotal) * 100) : 0}%</span>
                      </li>
                    )
                  })}
                </ul>
              </div>
              <div className="mt-5">
                <div className="text-xs font-semibold tracking-[0.04em] text-muted">HEALTH SCORE DISTRIBUTION</div>
                <div className="mt-3 flex items-end gap-3" style={{ height: 76 }}>
                  {Object.entries((fleet.distribution ?? {}) as Record<string, unknown>).map(([band, v]) => {
                    const n = num(v)
                    return (
                      <div key={band} className="flex flex-1 flex-col items-center gap-1.5">
                        <span className="text-xs font-semibold tabular-nums">{n || ''}</span>
                        <div className="w-full max-w-[56px] rounded-t" aria-hidden="true"
                          style={{ height: n ? Math.max(6, (n / fleetTotal) * 52) : 2, background: n ? 'var(--g-fg)' : 'var(--line)' }} />
                        <span className="text-[11px] text-muted">{band.replace('-', '–')}</span>
                      </div>
                    )
                  })}
                </div>
              </div>
            </>
          )}
        </Card>
        <Card className="flex-[1_1_300px]" title="Needs attention" subtitle="Lowest health first"
          actions={<Link to="/admin/servers" className="link-action">All servers</Link>}>
          {attention.length === 0 ? <Empty>Every server is at full health.</Empty> : (
            <ul className="list-none m-0 p-0">
              {attention.map((a, i) => (
                <li key={String(a.id ?? i)} className="flex items-center gap-3 py-3 border-t border-line first:border-t-0 first:pt-0">
                  <span className="grid h-10 w-10 flex-none place-content-center rounded-full text-sm font-bold tabular-nums"
                    style={scoreStyle(num(a.score))}>{num(a.score)}</span>
                  <Link to={`/admin/servers/${a.id}`} className="min-w-0 flex-1 py-1 no-underline text-fg">
                    <span className="block truncate text-sm font-semibold">{String(a.name)}</span>
                    <span className="block truncate text-xs text-muted">
                      {[a.ip, a.provider, ...((a.reasons ?? []) as string[])].filter(Boolean).join(' · ')}
                    </span>
                  </Link>
                  <StatusBadge status={String(a.state)} />
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
      <div className="flex flex-wrap gap-4">
        <Card className="flex-[2_1_520px]" pad={false} title="Recent deployments" actions={<Link to="/admin/applications" className="link-action">History</Link>}>
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-[13px] min-w-[620px]">
              <thead><tr className="text-left text-muted text-xs bg-head"><th className="py-2.5 px-5">Application</th><th className="p-2.5">Trigger</th><th className="p-2.5">Source</th><th className="p-2.5">Status</th><th className="p-2.5">Author</th><th className="py-2.5 pr-5 pl-2">When</th></tr></thead>
              <tbody>
                {deploys.length === 0 && <tr><td colSpan={6} className="p-6 text-center text-muted">No deployments yet.</td></tr>}
                {deploys.slice(0, 8).map((x, i) => (
                  <tr key={x.id ?? i} className="border-t border-line">
                    <td className="py-3 px-5 font-semibold">{x.application_name ?? x.application ?? x.app}{x.version != null && <span className="font-mono font-normal text-[11px] text-muted"> {x.version}</span>}</td>
                    <td className="p-2.5 text-muted">{x.trigger ?? x.environment ?? '—'}</td>
                    <td className="p-2.5 text-muted">{x.source ?? '—'}</td>
                    <td className="p-2.5"><StatusBadge status={x.status} /></td>
                    <td className="p-2.5 text-muted">{x.author ?? x.user ?? '—'}</td>
                    <td className="py-3 pr-5 pl-2 text-muted">{ago(x.created_at ?? x.when)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
        <Card className="flex-[1_1_300px]" title="Top resource consumers">
          {top.length === 0 ? <Empty>No data yet.</Empty> : (
            <ul className="list-none m-0 p-0 flex flex-col gap-4">
              {top.slice(0, 5).map((t, i) => {
                const c = num(t.cpu_pct ?? t.cpu)
                return (
                  <li key={t.id ?? i}>
                    <div className="flex justify-between text-[13px] gap-2"><span className="font-semibold truncate">{t.name}{(t.server_name ?? t.mem_mb) != null && <span className="font-normal text-muted"> · {t.server_name ?? `${Math.round(num(t.mem_mb))} MB`}</span>}</span><span className="text-muted">{Math.round(c)}% CPU</span></div>
                    <div className="mt-1.5"><Meter value={c} label={`${t.name} CPU`} color="var(--accent)" /></div>
                  </li>
                )
              })}
            </ul>
          )}
        </Card>
      </div>
      <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))' }}>
        {statusCards(d?.status).map((s) => (
          <div key={s.label} className="card p-5">
            <div className="flex justify-between items-center gap-2"><span className="text-[13px] text-muted font-medium">{s.label}</span><Badge tone={toneFor(s.state.replace(/^\d+ /, ''))}>{s.state}</Badge></div>
            <div className="text-lg font-bold mt-2.5">{s.value}</div>
            {s.detail && <div className="text-[13px] text-muted mt-1">{s.detail}</div>}
          </div>
        ))}
      </div>
    </>
  )
}
