import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useAction, useJob, useList, useOne, extractJobId } from '../../api/hooks'
import { Card, Empty, Field, PageHeader, StatTile, Toggle } from '../../components/Card'
import { Badge } from '../../components/Badge'
import { Icon } from '../../components/Icon'
import { Modal } from '../../components/Overlay'
import Select from '../../components/Select'
import { ago } from '../../lib/format'
import type { R } from '../../lib/util'

type Svc = { unit: string; description?: string; active?: string; sub?: string; enabled?: string; pid?: number; restarts?: number; since?: string; memory_bytes?: number; custom?: boolean; protected?: boolean; watched?: boolean }
type Page = { server: R; checked_at?: string; services: Svc[]; watch: string[]; summary: Record<string, number>; catalog: { key: string; label: string; unit: string; description: string; installed: boolean }[]; pending: number }

const mb = (b?: number) => b == null ? '—' : b >= 1 << 30 ? `${(b / (1 << 30)).toFixed(1)} GB` : `${Math.max(0, Math.round(b / (1 << 20)))} MB`
const tone = (s?: string) => s === 'active' ? 'g' : s === 'failed' ? 'r' : s === 'activating' || s === 'reloading' ? 'y' : 'n'

/** SVC-1..5: the systemd services on a server, with health, actions, set-up and custom services. */
export default function SystemServices() {
  const servers = useList<R>('/servers', { per_page: 200 })
  const [serverId, setServerId] = useState('')
  useEffect(() => { if (!serverId && servers.rows[0]) setServerId(String(servers.rows[0].id)) }, [servers.rows, serverId])
  const page = useOne<Page>(serverId ? `/servers/${serverId}/system-services` : null, undefined, { refetchInterval: (q) => ((q.state.data as { data?: Page } | undefined)?.data?.pending ? 3000 : 30000) })
  const d = page.item
  const { run, busy } = useAction()
  const base = `/servers/${serverId}/system-services`
  const [q, setQ] = useState('')
  const [show, setShow] = useState<'all' | 'running' | 'failed' | 'watched' | 'custom'>('all')
  const [setup, setSetup] = useState(false)
  const [create, setCreate] = useState(false)
  const [logUnit, setLogUnit] = useState<string | null>(null)
  const first = useMemo(() => !!d && !d.checked_at && !d.pending, [d])
  useEffect(() => { if (first && d?.server?.online) run({ path: `${base}/refresh`, label: 'Read services', silent: true }).catch(() => {}) }, [first]) // eslint-disable-line react-hooks/exhaustive-deps

  const rows = (d?.services ?? []).filter((s) => {
    if (q && !`${s.unit} ${s.description ?? ''}`.toLowerCase().includes(q.toLowerCase())) return false
    if (show === 'running') return s.active === 'active'
    if (show === 'failed') return s.active === 'failed'
    if (show === 'watched') return s.watched
    if (show === 'custom') return s.custom
    return true
  }).sort((a, b) => Number(b.active === 'failed') - Number(a.active === 'failed') || Number(!!b.watched) - Number(!!a.watched) || a.unit.localeCompare(b.unit))

  const act = (unit: string, action: string) => run({ path: `${base}/action`, body: { unit, action }, label: `${action[0].toUpperCase()}${action.slice(1)} ${unit}` }).catch(() => {})
  const toggleWatch = (unit: string, on: boolean) => {
    const next = on ? [...(d?.watch ?? []), unit] : (d?.watch ?? []).filter((u) => u !== unit)
    run({ method: 'PATCH', path: `${base}/watch`, body: { units: next }, label: on ? `Watch ${unit}` : `Stop watching ${unit}`, success: on ? `Watching ${unit}: you get an alert while it is down` : `No longer watching ${unit}` }).catch(() => {})
  }
  const s = d?.summary ?? {}

  return (
    <>
      <PageHeader title="System services" subtitle="Services running on the server itself, outside Docker"
        actions={<>
          <Select className="input !w-auto min-w-48" aria-label="Server" value={serverId} onChange={(e) => setServerId(e.target.value)}>
            {servers.rows.map((x) => <option key={String(x.id)} value={String(x.id)}>{String(x.name)}</option>)}
          </Select>
          <button type="button" className="btn" disabled={!serverId || busy || !d?.server?.online} onClick={() => run({ path: `${base}/refresh`, label: 'Refresh services' }).catch(() => {})}>
            <Icon name="pulse" size={16} />Refresh</button>
          <button type="button" className="btn" disabled={!d?.server?.online} onClick={() => setSetup(true)}><Icon name="store" size={16} />Set up</button>
          <button type="button" className="btn btn-primary" disabled={!d?.server?.online} onClick={() => setCreate(true)}>Create service</button>
        </>} />
      {!servers.isLoading && servers.rows.length === 0 ? <Card><Empty>Add a server first.</Empty></Card> : (
        <>
          <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(170px,1fr))] mb-4">
            <StatTile label="Services" value={String(s.total ?? 0)} delta={d?.checked_at ? `checked ${ago(d.checked_at)}` : d?.pending ? 'reading…' : 'not read yet'} icon={<Icon name="layers" size={16} />} />
            <StatTile label="Running" value={String(s.running ?? 0)} delta={s.total ? `${Math.round(((s.running ?? 0) / s.total) * 100)}% of services` : undefined} deltaTone="g" pct={s.total ? ((s.running ?? 0) / s.total) * 100 : 0} barColor="var(--g-fg)" icon={<Icon name="check" size={16} />} iconTone="g" />
            <StatTile label="Failed" value={String(s.failed ?? 0)} delta={s.failed ? 'needs attention' : 'none'} deltaTone={s.failed ? 'r' : 'n'} icon={<Icon name="pulse" size={16} />} iconTone={s.failed ? 'r' : 'g'} />
            <StatTile label="Watched" value={String(d?.watch.length ?? 0)} delta={s.watched_down ? `${s.watched_down} down` : 'all up'} deltaTone={s.watched_down ? 'r' : 'g'} icon={<Icon name="bell" size={16} />} iconTone={s.watched_down ? 'r' : 'b'} />
            <StatTile label="Created here" value={String(s.custom ?? 0)} delta="ws-* services" icon={<Icon name="gear" size={16} />} />
          </div>
          {d && !d.server?.online && <div className="card p-4 mb-4 text-sm"><Badge tone="y">offline</Badge> {String(d.server.name)} is offline, so services can’t be read or changed. The list shows the last snapshot.</div>}
          <Card pad={false} title="Services" subtitle="Watch a service to get an alert when it stops; checked every 5 minutes"
            actions={<>
              <label className="relative"><span className="sr-only">Search services</span>
                <span className="absolute left-2.5 top-1/2 -translate-y-1/2 text-muted"><Icon name="search" size={14} /></span>
                <input className="input !pl-8 !min-h-8 w-52 text-sm" placeholder="Search" value={q} onChange={(e) => setQ(e.target.value)} /></label>
              <span className="btn-group" role="group" aria-label="Show">
                {(['all', 'running', 'failed', 'watched', 'custom'] as const).map((k) => <button key={k} type="button" aria-pressed={show === k} className={`btn btn-sm ${show === k ? 'btn-primary' : ''}`} onClick={() => setShow(k)}>{k[0].toUpperCase() + k.slice(1)}</button>)}
              </span>
            </>}>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr className="text-left text-xs text-muted bg-head">
                  <th className="px-5 py-2.5 font-medium">Service</th><th className="px-3 py-2.5 font-medium">Status</th><th className="px-3 py-2.5 font-medium">Boot</th>
                  <th className="px-3 py-2.5 font-medium text-right">Memory</th><th className="px-3 py-2.5 font-medium text-right">Restarts</th><th className="px-3 py-2.5 font-medium">Watch</th><th className="px-5 py-2.5 font-medium text-right">Actions</th>
                </tr></thead>
                <tbody>
                  {rows.map((x) => (
                    <tr key={x.unit} className="border-t border-line hover:bg-head/60">
                      <td className="px-5 py-2.5 min-w-60"><div className="font-mono text-[13px] font-medium flex items-center gap-2">{x.unit}{x.custom && <Badge tone="b">created here</Badge>}{x.protected && <Badge tone="n">system</Badge>}</div>
                        <div className="text-xs text-muted truncate max-w-md">{x.description}</div></td>
                      <td className="px-3 py-2.5 whitespace-nowrap"><Badge tone={tone(x.active)}>{x.active ?? 'unknown'}{x.sub && x.sub !== x.active ? ` · ${x.sub}` : ''}</Badge></td>
                      <td className="px-3 py-2.5 text-muted">{x.enabled || '—'}</td>
                      <td className="px-3 py-2.5 text-right tabular-nums">{mb(x.memory_bytes)}</td>
                      <td className={`px-3 py-2.5 text-right tabular-nums ${(x.restarts ?? 0) > 0 ? 'text-[var(--y-fg)] font-semibold' : ''}`}>{x.restarts ?? 0}</td>
                      <td className="px-3 py-2.5"><Toggle checked={!!x.watched} onChange={(v) => toggleWatch(x.unit, v)} label={`Watch ${x.unit}`} disabled={busy} /></td>
                      <td className="px-5 py-2.5 text-right whitespace-nowrap">
                        <span className="inline-flex gap-1">
                          {x.active === 'active'
                            ? <button type="button" className="btn btn-sm" disabled={busy || !d?.server?.online} onClick={() => act(x.unit, 'restart')}>Restart</button>
                            : <button type="button" className="btn btn-sm btn-primary" disabled={busy || !d?.server?.online} onClick={() => act(x.unit, 'start')}>Start</button>}
                          <Select className="btn btn-sm !w-auto" aria-label={`More for ${x.unit}`} value="" disabled={busy || !d?.server?.online}
                            onChange={(e) => {
                              const v = e.target.value
                              if (v === 'logs') setLogUnit(x.unit)
                              else if (v === 'delete') { if (confirm(`Delete ${x.unit}? It is stopped and its unit file removed.`)) run({ method: 'DELETE', path: `${base}?unit=${encodeURIComponent(x.unit)}`, label: `Delete ${x.unit}` }).catch(() => {}) }
                              else if (v) act(x.unit, v)
                            }}>
                            <option value="">More</option>
                            <option value="logs">View log</option>
                            <option value="reload">Reload</option>
                            {x.active === 'active' && <option value="stop" disabled={x.protected}>Stop</option>}
                            {x.enabled === 'enabled' ? <option value="disable" disabled={x.protected}>Don’t start at boot</option> : <option value="enable">Start at boot</option>}
                            {x.custom && <option value="delete">Delete</option>}
                          </Select>
                        </span>
                      </td>
                    </tr>
                  ))}
                  {rows.length === 0 && <tr><td colSpan={7} className="px-5 py-8 text-center text-muted">{page.isLoading || d?.pending ? 'Reading services from the server…' : d?.services.length ? 'No service matches.' : 'No services read yet. Press Refresh.'}</td></tr>}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}
      <SetupModal open={setup} onClose={() => setSetup(false)} catalog={d?.catalog ?? []} onPick={(key, label) => { setSetup(false); run({ path: `${base}/install`, body: { key }, label: `Set up ${label}` }).catch(() => {}) }} />
      <CreateModal open={create} onClose={() => setCreate(false)} base={base} />
      <LogModal unit={logUnit} onClose={() => setLogUnit(null)} base={base} />
    </>
  )
}

function SetupModal({ open, onClose, catalog, onPick }: { open: boolean; onClose: () => void; catalog: Page['catalog']; onPick: (key: string, label: string) => void }) {
  return (
    <Modal open={open} onClose={onClose} title="Set up a service" width={720}>
      <p className="m-0 mb-4 text-muted">Installs the package with apt, starts it and starts it at boot. Debian and Ubuntu servers only.</p>
      <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(200px,1fr))]">
        {catalog.map((c) => (
          <button key={c.key} type="button" disabled={c.installed} onClick={() => onPick(c.key, c.label)}
            className="card text-left p-4 flex flex-col gap-1 cursor-pointer hover:border-accent disabled:cursor-default disabled:opacity-70 transition-colors">
            <span className="flex items-center justify-between gap-2"><span className="font-semibold">{c.label}</span>{c.installed ? <Badge tone="g">installed</Badge> : <span className="text-accent text-xs font-semibold">Install →</span>}</span>
            <span className="text-xs text-muted">{c.description}</span>
            <span className="font-mono text-[11px] text-muted">{c.unit}</span>
          </button>
        ))}
      </div>
    </Modal>
  )
}

function CreateModal({ open, onClose, base }: { open: boolean; onClose: () => void; base: string }) {
  const { run, busy } = useAction()
  const blank = { name: '', description: '', exec_start: '', working_dir: '', user: 'www-data', restart: 'on-failure', env: '', watch: true }
  const [f, setF] = useState(blank)
  useEffect(() => { if (open) setF(blank) }, [open]) // eslint-disable-line react-hooks/exhaustive-deps
  const submit = (e: FormEvent) => {
    e.preventDefault()
    const env = Object.fromEntries(f.env.split('\n').map((l) => l.trim()).filter(Boolean).map((l) => [l.split('=')[0].trim(), l.slice(l.indexOf('=') + 1)]))
    run({ path: base, label: `Create ws-${f.name}.service`, body: { ...f, env, working_dir: f.working_dir || null, description: f.description || null, start: true } }).then(onClose).catch(() => {})
  }
  return (
    <Modal open={open} onClose={onClose} title="Create a service" width={600}
      footer={<><button type="button" className="btn" onClick={onClose}>Cancel</button><button type="submit" form="svc-create" className="btn btn-primary" disabled={busy || !f.name || !f.exec_start}>Create and start</button></>}>
      <form id="svc-create" className="flex flex-col gap-4" onSubmit={submit}>
        <Field label="Name" hint={f.name ? <>Saved as <span className="font-mono">ws-{f.name}.service</span></> : 'Lower-case letters, digits and dashes.'}>
          <input className="input" required pattern="[a-z0-9][a-z0-9-]{0,40}" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value.toLowerCase() })} placeholder="queue-worker" /></Field>
        <Field label="Description"><input className="input" maxLength={120} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} placeholder="Laravel queue worker" /></Field>
        <Field label="Command" hint="Start with the full path to the program."><input className="input font-mono" required value={f.exec_start} onChange={(e) => setF({ ...f, exec_start: e.target.value })} placeholder="/usr/bin/php /srv/app/artisan queue:work" /></Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Working directory"><input className="input font-mono" value={f.working_dir} onChange={(e) => setF({ ...f, working_dir: e.target.value })} placeholder="/srv/app" /></Field>
          <Field label="Run as user" hint="Not root."><input className="input font-mono" required value={f.user} onChange={(e) => setF({ ...f, user: e.target.value })} /></Field>
        </div>
        <Field label="Restart">
          <Select className="input" value={f.restart} onChange={(e) => setF({ ...f, restart: e.target.value })}>
            <option value="on-failure">When it fails</option><option value="always">Always</option><option value="no">Never</option>
          </Select></Field>
        <Field label="Environment" hint="One KEY=value per line."><textarea className="input font-mono min-h-20" value={f.env} onChange={(e) => setF({ ...f, env: e.target.value })} placeholder={'APP_ENV=production'} /></Field>
        <label className="flex items-center justify-between gap-3 text-sm"><span>Watch it and alert me when it stops</span><Toggle checked={f.watch} onChange={(v) => setF({ ...f, watch: v })} label="Watch" /></label>
      </form>
    </Modal>
  )
}

function LogModal({ unit, onClose, base }: { unit: string | null; onClose: () => void; base: string }) {
  const { run } = useAction()
  const [jobId, setJobId] = useState<string | null>(null)
  useEffect(() => {
    setJobId(null)
    if (unit) run({ path: `${base}/logs`, body: { unit, lines: 300 }, label: `Log of ${unit}`, silent: true }).then((r) => setJobId(extractJobId(r))).catch(() => {})
  }, [unit]) // eslint-disable-line react-hooks/exhaustive-deps
  const { job, done } = useJob(jobId)
  const log = String((job?.result as R | undefined)?.log ?? '')
  return (
    <Modal open={!!unit} onClose={onClose} title={`Log · ${unit ?? ''}`} width={900}>
      {!done ? <p className="m-0 text-muted">Reading the journal…</p> : job?.status === 'failed' ? <p className="m-0 text-[var(--r-fg)]">{String(job.error ?? 'Could not read the log.')}</p>
        : <pre className="m-0 max-h-[60vh] overflow-auto rounded-lg bg-head p-3 text-xs font-mono whitespace-pre-wrap">{log || 'The log is empty.'}</pre>}
    </Modal>
  )
}
